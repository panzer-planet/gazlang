<?php

namespace GazLang\Tests;

/**
 * utf8_width() (vm/unicode.c) against Python's unicodedata, which shares no code with it or with
 * tools/unicode.php that wrote its tables: every code point on its own, by the rule written out
 * in docs/library.md over Python's general categories, East Asian widths and character names
 * (the Hangul vowels and trailing consonants are named, as Python has no Hangul_Syllable_Type),
 * and the whole run of them as one string, so the loop over a string is checked as well as the
 * lookup. Python's Unicode may be older than the tables', so a code point it doesn't know yet is
 * left out, as is one whose width a version after Python's changed (CHANGED), and the test says
 * how many it did check.
 */
class Utf8WidthTest extends GazLangTestCase
{
    private const FIRST = 0x80;

    private const LAST = 0x10FFFF;

    /**
     * The code points whose width a version of Unicode changed, by that version: 16.0 made the
     * I Ching monograms, digrams, trigrams and hexagrams, the Tai Xuan Jing symbols and the
     * counting rod numerals wide, for their use with CJK, and made the Ahom medial ra (U+1171E)
     * a spacing mark. A Python whose Unicode is older than the version can't be asked about them.
     *
     * @var array<string, list<array{int, int}>>
     */
    private const CHANGED = [
        '16.0.0' => [[0x2630, 0x2637], [0x268A, 0x268F], [0x4DC0, 0x4DFF], [0x1171E, 0x1171E], [0x1D300, 0x1D356], [0x1D360, 0x1D376]],
    ];

    /**
     * Prints the width the rule gives each code point from FIRST to LAST, surrogates left out,
     * one digit each, or ? where Python's Unicode has no character yet; then the Unicode version
     */
    private const ORACLE = '
import sys, unicodedata
out = []
for code in range(%d, %d + 1):
    if 0xD800 <= code <= 0xDFFF:
        continue
    c = chr(code)
    category = unicodedata.category(c)
    if category == "Cn":
        out.append("?")
    elif category in ("Mn", "Me", "Cf"):
        out.append("0")
    elif unicodedata.name(c, "").startswith(("HANGUL JUNGSEONG", "HANGUL JONGSEONG")):
        out.append("0")
    elif unicodedata.east_asian_width(c) in ("W", "F"):
        out.append("2")
    else:
        out.append("1")
sys.stdout.write("".join(out) + "\n" + unicodedata.unidata_version + "\n")
';

    /**
     * The oracle's digits and its Unicode version, or null when there is no python3 to run it
     *
     * @return array{string, string}|null
     */
    private static function oracle(): ?array
    {
        $script = sprintf(self::ORACLE, self::FIRST, self::LAST);
        $process = @proc_open(['python3', '-I', '-c', $script], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::ROOT);
        if (! is_resource($process)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $out === false || $out === '') {
            if (str_contains($err, 'No such file') || $err === '') {
                return null;
            }
            self::fail("python3 failed: {$err}");
        }
        [$digits, $version] = explode("\n", $out);

        return [$digits, $version];
    }

    /**
     * Whether a version of Unicode after $version changed the width of $code
     */
    private static function changedAfter(string $version, int $code): bool
    {
        foreach (self::CHANGED as $changed => $ranges) {
            if (version_compare($version, $changed, '>=')) {
                continue;
            }
            foreach ($ranges as [$first, $last]) {
                if ($code >= $first && $code <= $last) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every code point from FIRST to LAST as one UTF-8 string, surrogates left out
     */
    private static function everyCodePoint(): string
    {
        $text = '';
        for ($code = self::FIRST; $code <= self::LAST; $code++) {
            if ($code < 0xD800 || $code > 0xDFFF) {
                $text .= mb_chr($code, 'UTF-8');
            }
        }

        return $text;
    }

    public function test_it_agrees_with_python_on_every_code_point()
    {
        $oracle = self::oracle();
        if ($oracle === null) {
            $this->markTestSkipped('python3 is not installed');
        }
        [$expected, $version] = $oracle;
        $text = self::everyCodePoint();
        $this->assertSame(mb_strlen($text, 'UTF-8'), strlen($expected), 'the oracle gave one digit per code point');

        // Not executeCode(): the input is megabytes, and would be recorded for the sanitized harness
        $program = '$text = read_stdin();
            $widths = map(utf8_chars($text), $c -> utf8_width($c));
            print(join($widths, "") .. "\n" .. utf8_width($text) .. "\n");';
        [$widths, $whole] = explode("\n", self::succeed(['-e', $program], $text));
        $this->assertSame(strlen($expected), strlen($widths), 'gaz gave one width per code point');

        $differences = [];
        $checked = 0;
        $code = self::FIRST;
        for ($i = 0; $i < strlen($expected); $i++, $code++) {
            if ($code === 0xD800) {
                $code = 0xE000;
            }
            if ($expected[$i] === '?' || self::changedAfter($version, $code)) {
                continue;
            }
            $checked++;
            if ($expected[$i] !== $widths[$i] && count($differences) < 20) {
                $differences[] = sprintf('U+%04X: gaz %s, Python %s', $code, $widths[$i], $expected[$i]);
            }
        }
        $this->assertSame([], $differences, "against Python's Unicode {$version}");
        // Python's Unicode could be so old that this checks little
        $this->assertGreaterThan(250000, $checked, "code points Python's Unicode {$version} knows");
        $this->assertSame(array_sum(str_split($widths)), (int) $whole, 'the whole string is the sum of its characters');
    }
}
