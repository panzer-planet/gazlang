<?php

namespace GazLang\Tests;

/**
 * lib/chars.gaz must classify every byte exactly as the lexer does: whitespace is space, tab,
 * newline and carriage return; letters and digits are ASCII
 */
class LibCharsTest extends GazLangTestCase
{
    public function test_every_byte_is_classified_like_the_lexer()
    {
        $classes = [
            'space' => "/^[ \t\n\r]$/", 'digit' => '/^[0-9]$/', 'hex_digit' => '/^[0-9a-fA-F]$/',
            'alpha' => '/^[a-zA-Z]$/', 'alnum' => '/^[a-zA-Z0-9]$/',
        ];
        $all_bytes = '';
        $expected = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $char = chr($byte);
            $all_bytes .= $char;
            $flags = [];
            foreach ($classes as $class => $pattern) {
                $flags[] = preg_match($pattern, $char) ? $class : '-';
            }
            $expected .= "{$byte} ".implode(' ', $flags)."\n";
        }

        // Every byte goes into a string literal as is, except the few quote() escapes
        $code = 'include "'.self::ROOT.'/lib/chars.gaz";'
            .' $bytes = '.self::quote($all_bytes).';'
            .' for ($i = 0; $i < 256; $i = $i + 1) {'
            .'   $c = $bytes[$i]; $flags = [];'
            .'   if (is_space($c)) { $flags[] = "space"; } else { $flags[] = "-"; }'
            .'   if (is_digit($c)) { $flags[] = "digit"; } else { $flags[] = "-"; }'
            .'   if (is_hex_digit($c)) { $flags[] = "hex_digit"; } else { $flags[] = "-"; }'
            .'   if (is_alpha($c)) { $flags[] = "alpha"; } else { $flags[] = "-"; }'
            .'   if (is_alnum($c)) { $flags[] = "alnum"; } else { $flags[] = "-"; }'
            .'   echo to_string($i) .. " " .. join($flags, " ");'
            .' }';

        $this->assertSame($expected, $this->executeCode($code));
    }
}
