<?php

namespace GazLang\Tests;

/**
 * utf8_valid(), utf8_length() and utf8_chars() (vm/builtins.c) against PCRE's UTF-8 handling,
 * which follows RFC 3629 and shares no code with them: whether a string is well formed is what
 * `preg_match('//u')` says, and a well formed string's characters are what `preg_split('//u')`
 * finds. The strings are random bytes, well formed text, and well formed text cut short or with
 * one byte changed, which is where a validator is wrong, and the forms RFC 3629 forbids written
 * out by hand.
 */
class Utf8Test extends GazLangTestCase
{
    /**
     * A code point as UTF-8, written out so no extension is needed; surrogates too, which have no
     * well formed encoding, since encoding them anyway is the point
     */
    private static function encode(int $code): string
    {
        return match (true) {
            $code < 0x80 => chr($code),
            $code < 0x800 => chr(0xC0 | $code >> 6).chr(0x80 | $code & 0x3F),
            $code < 0x10000 => chr(0xE0 | $code >> 12).chr(0x80 | $code >> 6 & 0x3F).chr(0x80 | $code & 0x3F),
            default => chr(0xF0 | $code >> 18).chr(0x80 | $code >> 12 & 0x3F).chr(0x80 | $code >> 6 & 0x3F).chr(0x80 | $code & 0x3F),
        };
    }

    /**
     * Code points either side of each size's limits, and the surrogates' and the last one's
     */
    private const EDGES = [0x00, 0x7F, 0x80, 0x7FF, 0x800, 0xD7FF, 0xD800, 0xDFFF, 0xE000, 0xFFFF, 0x10000, 0x10FFFF];

    /**
     * What RFC 3629 forbids, by hand: overlong forms of each size, surrogates and their pair
     * written as two characters, what is above U+10FFFF, and bytes that never lead
     */
    private const FORBIDDEN = [
        "\xC0\x80", "\xC1\xBF", "\xE0\x80\x80", "\xE0\x9F\xBF", "\xF0\x80\x80\x80", "\xF0\x8F\xBF\xBF",
        "\xED\xA0\x80", "\xED\xBF\xBF", "\xED\xA0\xBD\xED\xB8\x80", "\xF4\x90\x80\x80", "\xF5\x80\x80\x80",
        "\xF8\x88\x80\x80\x80", "\xFE", "\xFF", "\x80", "\xBF",
    ];

    /**
     * @return list<string>
     */
    private static function strings(): array
    {
        mt_srand(20261001);
        $strings = [''];
        foreach (self::FORBIDDEN as $bytes) {
            $strings[] = $bytes;
            $strings[] = 'a'.$bytes.'b';
        }
        foreach (self::EDGES as $code) {
            $text = self::encode($code);
            $strings[] = $text;
            $strings[] = substr($text, 0, -1);
            $strings[] = $text.$text;
        }
        for ($n = 0; $n < 600; $n++) {
            $bytes = '';
            for ($i = mt_rand(0, 8); $i > 0; $i--) {
                $bytes .= chr(mt_rand(0, 255));
            }
            $strings[] = $bytes;
        }
        for ($n = 0; $n < 600; $n++) {
            $text = '';
            for ($i = mt_rand(1, 6); $i > 0; $i--) {
                $code = mt_rand(0, 3) === 0 ? self::EDGES[mt_rand(0, count(self::EDGES) - 1)] : mt_rand(0, 0x10FFFF);
                $text .= self::encode($code);
            }
            $strings[] = $text;
            $strings[] = substr($text, 0, mt_rand(0, strlen($text)));
            $changed = $text;
            $changed[mt_rand(0, strlen($text) - 1)] = chr(mt_rand(0, 255));
            $strings[] = $changed;
        }

        return $strings;
    }

    /**
     * A GazLang string literal for any bytes, every one but a letter or digit as \xHH
     */
    private static function literal(string $bytes): string
    {
        return '"'.preg_replace_callback('/[^A-Za-z0-9]/', fn ($m) => sprintf('\x%02x', ord($m[0])), $bytes).'"';
    }

    /**
     * Where the first character that isn't well formed starts: how far whole well formed
     * characters reach, each one checked by PCRE on its own
     */
    private static function firstBad(string $text): int
    {
        $at = 0;
        while ($at < strlen($text)) {
            $size = 1;
            while ($size <= 4 && preg_match('//u', substr($text, $at, $size)) !== 1) {
                $size++;
            }
            if ($size > 4) {
                return $at;
            }
            $at += $size;
        }

        return $at;
    }

    public function test_it_agrees_with_pcre_on_what_is_well_formed_and_what_its_characters_are()
    {
        $strings = self::strings();
        $program = '$strings = ['.implode(', ', array_map(self::literal(...), $strings))."];\n"
            .'foreach ($strings as $text) {
                if (utf8_valid($text)) {
                    echo "1 " .. utf8_length($text) .. " " .. join(map(utf8_chars($text), len), ",");
                } else {
                    try { utf8_length($text); echo "no error"; } catch (Error $e) { print("0 " .. $e.message .. " / "); }
                    try { utf8_chars($text); echo "no error"; } catch (Error $e) { echo $e.message; }
                }
            }';
        // Not executeCode(): the program is generated, and would be recorded for the sanitized harness
        $lines = explode("\n", rtrim(self::succeed([], $program), "\n"));
        $this->assertCount(count($strings), $lines);

        $valid = 0;
        foreach ($strings as $i => $text) {
            if (preg_match('//u', $text) === 1) {
                $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
                $expected = '1 '.count($characters).' '.implode(',', array_map(strlen(...), $characters));
                $valid++;
            } else {
                $why = 'expects well formed UTF-8, but byte '.self::firstBad($text).' doesn\'t start a well formed character';
                $expected = "0 utf8_length() {$why} / utf8_chars() {$why}";
            }
            $this->assertSame($expected, $lines[$i], 'for '.bin2hex($text));
        }
        // Both kinds must be there in numbers, or this checks little
        $this->assertGreaterThan(300, $valid);
        $this->assertGreaterThan(300, count($strings) - $valid);
    }
}
