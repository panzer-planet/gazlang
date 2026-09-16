<?php

namespace GazLang\Tests;

use GazLang\Lexer\Lexer;

/**
 * lib/chars.gaz must classify every byte exactly as the PHP lexer does
 */
class LibCharsTest extends GazLangTestCase
{
    public function test_every_byte_is_classified_like_the_php_lexer()
    {
        $all_bytes = '';
        $expected = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $char = chr($byte);
            $all_bytes .= $char;
            $flags = [];
            foreach (['space', 'digit', 'alpha', 'alnum'] as $class) {
                $flags[] = Lexer::{"is_{$class}"}($char) ? $class : '-';
            }
            $expected .= "{$byte} ".implode(' ', $flags)."\n";
        }

        // Every byte goes into a string literal as is, except the few quote() escapes
        $code = 'include "'.self::ROOT.'/lib/chars.gaz";'
            .' $bytes = '.Lexer::quote($all_bytes).';'
            .' for ($i = 0; $i < 256; $i = $i + 1) {'
            .'   $c = $bytes[$i]; $flags = [];'
            .'   if (is_space($c)) { $flags[] = "space"; } else { $flags[] = "-"; }'
            .'   if (is_digit($c)) { $flags[] = "digit"; } else { $flags[] = "-"; }'
            .'   if (is_alpha($c)) { $flags[] = "alpha"; } else { $flags[] = "-"; }'
            .'   if (is_alnum($c)) { $flags[] = "alnum"; } else { $flags[] = "-"; }'
            .'   echo to_string($i) + " " + $flags[0] + " " + $flags[1] + " " + $flags[2] + " " + $flags[3];'
            .' }';

        $this->assertSame($expected, $this->executeCode($code));
    }
}
