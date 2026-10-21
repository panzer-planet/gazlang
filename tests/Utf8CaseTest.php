<?php

namespace GazLang\Tests;

/**
 * utf8_upper(), utf8_lower(), utf8_fold() and utf8_slice() (vm/unicode.c, vm/builtins.c) against
 * PHP's mbstring and ICU, which share no code with them or with tools/unicode.php that wrote
 * their tables: every character on its own, then random text heavy in what makes case mapping
 * hard (ß and ligatures that grow, İ, the capital sigma whose lowercase depends on the word, and
 * case-ignorable apostrophes, combining marks and modifier letters around it), and slices of
 * random text at positions inside, outside and at the ends of it, against mb_substr().
 */
class Utf8CaseTest extends GazLangTestCase
{
    /**
     * Prints its input three times, uppercased, lowercased and folded, each ending at a NUL
     */
    private const MAP_ALL = '$text = read_stdin();
        print(utf8_upper($text) .. "\0" .. utf8_lower($text) .. "\0" .. utf8_fold($text) .. "\0");';

    /**
     * What makes case mapping hard, and plain letters, spaces and punctuation around it
     */
    private const PIECES = [
        'A', 'a', 'z', ' ', '.', '1', "'", 'ß', 'ẞ', 'ﬁ', 'ŉ', 'İ', 'ı', 'ΐ', 'Σ', 'Σ', 'Σ', 'σ', 'ς',
        'Ο', 'δ', 'Ω', 'ͅ', 'ʰ', "\u{0301}", "\u{2019}", "\u{00AD}", 'Ж', 'ж', 'Ǆ', 'ǅ', 'ǆ', 'K', 'Å',
        'ﬀ', 'Ꭰ', 'ꭰ', '😀', '€', '中', '𐐀', '𐐨',
    ];

    /**
     * $text uppercased, lowercased and folded by gaz, and by mbstring and ICU, one line each
     *
     * @return array{0: list<list<string>>, 1: list<list<string>>} gaz's three lists, then the oracles'
     */
    private static function mapped(array $lines): array
    {
        $out = self::succeed(['-e', self::MAP_ALL], implode("\n", $lines));
        $gaz = array_map(fn ($part) => explode("\n", $part), array_slice(explode("\0", $out), 0, 3));
        /*
         * The oracles map a line at a time: given the whole text, mbstring converts it in pieces
         * and can lose sight of what follows a sigma at the end of one, where ICU and gaz don't
         */
        $oracle = [
            array_map(fn ($line) => mb_strtoupper($line, 'UTF-8'), $lines),
            array_map(fn ($line) => mb_strtolower($line, 'UTF-8'), $lines),
            array_map(fn ($line) => mb_convert_case($line, MB_CASE_FOLD, 'UTF-8'), $lines),
        ];
        // ICU has the full mappings too, though not the full folding
        $upper = \Transliterator::create('Any-Upper');
        $lower = \Transliterator::create('Any-Lower');
        self::assertSame($oracle[0], array_map(fn ($line) => $upper->transliterate($line), $lines), 'mbstring and ICU disagree');
        self::assertSame($oracle[1], array_map(fn ($line) => $lower->transliterate($line), $lines), 'mbstring and ICU disagree');

        return [$gaz, $oracle];
    }

    /**
     * Every line where gaz and the oracle differ, each as hex, up to a few
     *
     * @param  list<string>  $lines
     * @return list<list<string>> What gaz gave
     */
    private function assertMapsAsOracle(array $lines): array
    {
        [$gaz, $oracle] = self::mapped($lines);
        $names = ['utf8_upper', 'utf8_lower', 'utf8_fold'];
        $differ = [];
        foreach ($names as $n => $name) {
            $this->assertCount(count($lines), $gaz[$n]);
            foreach ($lines as $i => $line) {
                if ($gaz[$n][$i] !== $oracle[$n][$i] && count($differ) < 10) {
                    $differ[] = "{$name}({$line}) [".bin2hex($line).'] is '.bin2hex($gaz[$n][$i]).', not '.bin2hex($oracle[$n][$i]);
                }
            }
        }
        $this->assertSame([], $differ);

        return $gaz;
    }

    public function test_every_character_maps_as_mbstring_and_icu_map_it()
    {
        // A plane at a time, so the lists stay small under a CI runner's memory_limit
        for ($plane = 0; $plane <= 0x10; $plane++) {
            $characters = [];
            for ($code = $plane << 16; $code <= ($plane << 16 | 0xFFFF); $code++) {
                // Surrogates have no UTF-8, and a newline and a NUL are what separate the output
                if (($code < 0xD800 || $code > 0xDFFF) && $code !== 0x0A && $code !== 0x00) {
                    $characters[] = mb_chr($code, 'UTF-8');
                }
            }
            $this->assertMapsAsOracle($characters);
        }
    }

    public function test_text_maps_as_mbstring_and_icu_map_it_final_sigma_too()
    {
        mt_srand(20261020);
        $lines = [];
        for ($n = 0; $n < 3000; $n++) {
            $line = '';
            for ($i = mt_rand(1, 8); $i > 0; $i--) {
                $line .= self::PIECES[mt_rand(0, count(self::PIECES) - 1)];
            }
            $lines[] = $line;
        }
        $gaz = $this->assertMapsAsOracle($lines);
        // Both lowercases of the sigma must be there in numbers, or this checks little
        $lower = implode("\n", $gaz[1]);
        $this->assertGreaterThan(300, substr_count($lower, 'ς'));
        $this->assertGreaterThan(300, substr_count($lower, 'σ'));
    }

    public function test_slicing_counts_characters_as_mb_substr_does()
    {
        mt_srand(20261021);
        $cases = [];
        for ($n = 0; $n < 3000; $n++) {
            $text = '';
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $text .= self::PIECES[mt_rand(0, count(self::PIECES) - 1)];
            }
            $length = mb_strlen($text, 'UTF-8');
            $start = mt_rand(-$length - 2, $length + 2);
            $count = mt_rand(0, 3) === 0 ? null : mt_rand(-$length - 2, $length + 2);
            $cases[] = [$text, $start, $count];
        }
        // A line each: start, length or "-" for none, and the text, which holds no tab or newline
        $input = implode("\n", array_map(fn ($c) => "{$c[1]}\t".($c[2] ?? '-')."\t{$c[0]}", $cases));
        $program = 'foreach (split(read_stdin(), "\n") as $line) {
                [$start, $length, $text] = split($line, "\t", 3);
                print(utf8_slice($text, to_int($start), $length == "-" ? null : to_int($length)) .. "\n");
            }';
        // Every line ends in a newline, so the last piece is the nothing after the last one
        $lines = explode("\n", self::succeed(['-e', $program], $input));
        array_pop($lines);
        $this->assertCount(count($cases), $lines);
        foreach ($cases as $i => [$text, $start, $count]) {
            $this->assertSame(mb_substr($text, $start, $count, 'UTF-8'), $lines[$i], "utf8_slice of {$text} from {$start}, ".($count ?? 'null').' long');
        }
    }
}
