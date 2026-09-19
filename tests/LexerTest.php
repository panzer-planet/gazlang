<?php

namespace GazLang\Tests;

/**
 * The lexer, through `gazlang --tokens`
 */
class LexerTest extends GazLangTestCase
{
    /**
     * Lex a whole source string into [type, value] pairs, without the EOF token
     */
    private function pairs(string $source): array
    {
        return array_map(fn ($token) => [$token[0], $token[1]], $this->lex($source));
    }

    /**
     * Lex a whole source string into [type, line] pairs, without the EOF token
     */
    private function lines(string $source): array
    {
        return array_map(fn ($token) => [$token[0], $token[2]], $this->lex($source));
    }

    /**
     * How --tokens prints a string's value, which is quote()
     */
    private function printedString(string $value): string
    {
        return substr(explode("\n", self::succeed(['--tokens'], self::quote($value)))[0], strlen('1 STRING '));
    }

    public function test_string_escapes()
    {
        $this->assertSame([['STRING', "a\nb\tc\rd\"e\\f"]], $this->pairs('"a\nb\tc\rd\"e\\\\f"'));
    }

    public function test_quote_is_the_inverse_of_string_literals()
    {
        $value = "tab\t cr\r nl\n quote\" backslash\\ plain";
        $this->assertSame([['STRING', $value]], $this->pairs(self::quote($value)));
    }

    public function test_all_escape_sequences()
    {
        $this->assertSame(
            [['STRING', "\n\t\r\v\f\e\0\\\"AzAz\u{e9}\u{E9}\u{1F600}\u{0}"]],
            $this->pairs('"\n\t\r\v\f\e\0\\\\\"\x41\x7a\x41\x7A\u{e9}\u{E9}\u{1F600}\u{0}"')
        );
    }

    /**
     * @dataProvider invalidEscapes
     */
    public function test_invalid_escapes(string $source, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->pairs($source);
    }

    public static function invalidEscapes(): array
    {
        return [
            'one hex digit' => ['"\x4"', 'Invalid escape \x4: expected two hex digits on line 1'],
            'no hex digits' => ['"\xZZ"', 'Invalid escape \x: expected two hex digits on line 1'],
            // Built from parts: the escape must reach the lexer as backslash, u, 00e9
            'unicode without braces' => ['"\\'.'u00e9"', 'Invalid escape \u: expected \u{...} with 1 to 6 hex digits on line 1'],
            'unicode empty braces' => ['"\u{}"', 'Invalid escape \u: expected \u{...} with 1 to 6 hex digits on line 1'],
            'unicode seven digits' => ['"\u{1234567}"', 'Invalid escape \u: expected \u{...} with 1 to 6 hex digits on line 1'],
            'unicode unclosed' => ['"\u{41"', 'Invalid escape \u: expected \u{...} with 1 to 6 hex digits on line 1'],
            'unicode past the last code point' => ['"\u{110000}"', 'Invalid escape \u{110000}: not a Unicode code point on line 1'],
            'unicode surrogate' => ['"\u{D800}"', 'Invalid escape \u{D800}: not a Unicode code point on line 1'],
            'octal' => ['"\012"', 'Octal escapes are not supported: \01 (use \x) on line 1'],
            'uppercase escape letter' => ['"\N"', 'Unknown escape sequence \N in string on line 1'],
            'backslash at the end, reported where the string starts' => ["\"one\ntwo\\", 'Unterminated string on line 1'],
        ];
    }

    public function test_quote_round_trips_every_byte()
    {
        $all_bytes = implode('', array_map('chr', range(0, 255)));

        $this->assertSame([['STRING', $all_bytes]], $this->pairs(self::quote($all_bytes)));
    }

    public function test_quote_writes_readable_escapes()
    {
        // NUL is \x00 so a following digit can't turn it into an octal-looking \01
        $this->assertSame('"\n\t\r\v\f\e\\\\\"\x0012 \x01\x7F é"', $this->printedString("\n\t\r\v\f\e\\\"\x0012 \x01\x7F é"));
    }

    /**
     * @dataProvider singleQuotedStrings
     */
    public function test_single_quoted_strings_are_raw(string $source, string $value)
    {
        $this->assertSame([['STRING', $value]], $this->pairs($source));
    }

    public static function singleQuotedStrings(): array
    {
        return [
            'plain' => ["'plain'", 'plain'],
            'empty' => ["''", ''],
            'other backslashes are kept' => ["'C:\\path\\n'", 'C:\\path\\n'],
            'escaped quote' => ["'it\\'s'", "it's"],
            'escaped backslash' => ["'a\\\\b'", 'a\\b'],
            'backslash before the closing quote' => ["'end\\\\'", 'end\\'],
            'double quotes and escapes are literal' => ["'\"x\\t\"'", '"x\\t"'],
            'newlines are kept' => ["'two\nlines'", "two\nlines"],
        ];
    }

    public function test_unterminated_single_quoted_string_reports_where_it_starts()
    {
        $this->expectExceptionMessage('Unterminated string on line 2');
        $this->pairs("1;\n'open\n\nnever");
    }

    public function test_interpolated_strings_are_split_into_parts_and_expression_tokens()
    {
        $this->assertSame(
            [
                ['STRING_START', 'Hi '], ['VAR_IDENTIFIER', '$name'],
                ['STRING_MIDDLE', ', '], ['GLOBAL_VAR_IDENTIFIER', '@n'], ['PLUS', '+'], ['INTEGER', 1],
                ['STRING_MIDDLE', ' '], ['VAR_IDENTIFIER', '$m'], ['LEFT_BRACKET', '['],
                ['STRING_START', 'k'], ['VAR_IDENTIFIER', '$x'], ['STRING_END', ''],
                ['RIGHT_BRACKET', ']'], ['STRING_END', '!'],
            ],
            $this->pairs('"Hi $name, {@n + 1} {$m["k$x"]}!"')
        );
    }

    public function test_strings_without_interpolation_stay_one_token()
    {
        $this->assertSame(
            [['STRING', 'costs $5, me@x.com { $y} {} $ $a {$b']],
            $this->pairs('"costs $5, me@x.com { \\$y} {} $ \\$a \\{\\$b"')
        );
    }

    public function test_interpolation_tokens_keep_their_lines()
    {
        $this->assertSame([['STRING_START', 1], ['VAR_IDENTIFIER', 2], ['STRING_MIDDLE', 3], ['VAR_IDENTIFIER', 4], ['STRING_END', 4]], $this->lines("\"a\n{\$x\n}b\n\$y\""));
    }

    /**
     * @dataProvider unterminatedInterpolations
     */
    public function test_unterminated_interpolation_reports_where_the_string_starts(string $source)
    {
        $this->expectExceptionMessage('Unterminated string on line 2');
        $this->pairs($source);
    }

    public static function unterminatedInterpolations(): array
    {
        return [
            'end of file inside braces' => ["1;\n\"a {\$x\n\n"],
            'end of file after the braces' => ["1;\n\"a {\$x}\n\n"],
            'end of file after a bare variable' => ["1;\n\"a \$x\n\n"],
        ];
    }

    public function test_quote_escapes_only_what_would_interpolate()
    {
        $value = '$a {$b {@c $5 { $d} $ {';
        $this->assertSame('"\\$a \\{\\$b \\{@c $5 { \\$d} $ {"', $this->printedString($value));
        $this->assertSame([['STRING', $value]], $this->pairs(self::quote($value)));
    }

    public function test_shorthand_interpolation_reads_one_php_style_index()
    {
        $this->assertSame(
            [
                ['STRING_START', ''], ['VAR_IDENTIFIER', '$a'], ['LEFT_BRACKET', '['], ['INTEGER', -1], ['RIGHT_BRACKET', ']'],
                ['STRING_MIDDLE', ' '], ['VAR_IDENTIFIER', '$a'], ['LEFT_BRACKET', '['], ['STRING', 'k_1'], ['RIGHT_BRACKET', ']'],
                ['STRING_MIDDLE', ' '], ['VAR_IDENTIFIER', '$a'], ['LEFT_BRACKET', '['], ['VAR_IDENTIFIER', '$i'], ['RIGHT_BRACKET', ']'],
                ['STRING_MIDDLE', ' '], ['VAR_IDENTIFIER', '$a'], ['LEFT_BRACKET', '['], ['STRING', '01'], ['RIGHT_BRACKET', ']'],
                ['STRING_MIDDLE', ' '], ['VAR_IDENTIFIER', '$a'], ['LEFT_BRACKET', '['], ['INTEGER', 0], ['RIGHT_BRACKET', ']'],
                ['STRING_END', '[1]'],
            ],
            $this->pairs('"$a[-1] $a[k_1] $a[$i] $a[01] $a[0][1]"')
        );
    }

    /**
     * @dataProvider invalidInterpolatedIndexes
     */
    public function test_invalid_shorthand_index_is_an_error(string $index)
    {
        $this->expectExceptionMessage('Invalid array index in interpolated string');
        $this->pairs('"$a'.$index.'"');
    }

    public static function invalidInterpolatedIndexes(): array
    {
        return [
            'space' => ['[ 0]'],
            'empty' => ['[]'],
            'lone minus' => ['[-]'],
            'digits then letters' => ['[1x]'],
            'quoted key' => ['["k"]'],
            'expression' => ['[$i + 1]'],
            'unclosed' => ['[0'],
        ];
    }

    public function test_unknown_escape_is_an_error()
    {
        $this->expectExceptionMessage('Unknown escape sequence \q in string on line 2');
        $this->pairs("\n\"a\\qb\"");
    }

    public function test_multi_character_operators_take_the_longest_match()
    {
        $this->assertSame(
            [
                'EQUALS', 'ASSIGN', 'EQUALS', 'ASSIGN', 'DOUBLE_ARROW', 'NOT_EQUALS', 'ASSIGN', 'NOT_EQUALS', 'NOT', 'LESS_EQUALS', 'GREATER_EQUALS',
                'PLUS_ASSIGN', 'INCREMENT', 'PLUS', 'MINUS_ASSIGN', 'DECREMENT', 'MINUS', 'MULTIPLY_ASSIGN', 'DIVIDE_ASSIGN', 'MODULO_ASSIGN',
                'INCREMENT', 'PLUS', 'DECREMENT', 'MINUS', 'COALESCE', 'COALESCE_ASSIGN', 'COALESCE', 'ASSIGN',
                'CONCAT', 'CONCAT_ASSIGN', 'CONCAT', 'ASSIGN', 'INTEGER', 'CONCAT', 'INTEGER', 'FLOAT', 'CONCAT', 'INTEGER',
                'QUESTION', 'COLON', 'COALESCE', 'QUESTION', 'QUESTION', 'COLON',
                'SPACESHIP', 'LESS_EQUALS', 'GREATER_THAN', 'SPACESHIP', 'ASSIGN',
            ],
            array_column($this->pairs('=== == = => !== != ! <= >= += ++ + -= -- - *= /= %= +++ --- ?? ??= ?? = .. ..= .. = 1..2 1.5..2 ? : ??? ?: <=> <= > <=>='), 0)
        );
    }

    public function test_object_sigils_and_properties()
    {
        $this->assertSame(
            [
                ['HASH', '#'], ['HASH_IDENTIFIER', '#name'], ['PARENT', '##'], ['PARENT_IDENTIFIER', '##_'],
                ['HASH', '#'], ['PROPERTY', '.x'], ['VAR_IDENTIFIER', '$a'], ['PROPERTY', '.b_1'], ['PROPERTY', '.c'],
                ['HASH_IDENTIFIER', '#class'], ['PROPERTY', '.final'], ['HASH', '#'], ['INTEGER', 1],
                ['VAR_IDENTIFIER', '$a'], ['CONCAT', '..'], ['IDENTIFIER', 'b'],
            ],
            $this->pairs("# #name ## ##_ #.x \$a.b_1\n  .c #class .final #1 \$a..b")
        );
    }

    public function test_class_keywords_and_reserved_words()
    {
        $this->assertSame(
            ['CLASS', 'EXTENDS', 'ABSTRACT', 'INTERFACE', 'IMPLEMENTS', 'FINAL', 'PUBLIC', 'PRIVATE', 'PROTECTED'],
            array_column($this->pairs('class extends abstract interface implements final public private protected'), 0)
        );
    }

    public function test_hash_interpolates_only_inside_braces()
    {
        $this->assertSame(
            [
                ['STRING_START', ''], ['HASH_IDENTIFIER', '#name'], ['STRING_MIDDLE', ' #fff #1 '],
                ['PARENT_IDENTIFIER', '##to_string'], ['LEFT_PAREN', '('], ['RIGHT_PAREN', ')'], ['STRING_MIDDLE', ' '],
                ['VAR_IDENTIFIER', '$file'], ['STRING_END', '.txt {} { #x}'],
            ],
            $this->pairs('"{#name} #fff #1 {##to_string()} $file.txt {} { #x}"')
        );
        $value = '{#name} #x';
        $this->assertSame('"\\{#name} #x"', $this->printedString($value));
        $this->assertSame([['STRING', $value]], $this->pairs(self::quote($value)));
    }

    public function test_comments_and_whitespace_are_skipped_and_lines_counted()
    {
        $this->assertSame([['ECHO', 3], ['INTEGER', 4], ['DIVIDE', 4], ['INTEGER', 4], ['SEMICOLON', 4]], $this->lines("// first\n\n  echo // trailing\n\t1 / 2;"));
    }

    public function test_block_comments_are_skipped_and_lines_counted()
    {
        $this->assertSame([['ECHO', 2], ['INTEGER', 2], ['SEMICOLON', 2]], $this->lines('/* one
   two */ echo /* here */ 1;'));
    }

    public function test_block_comments_nest()
    {
        // The first */ closes only the inner one, so commenting out a commented region works
        $this->assertSame([['INTEGER', 1]], $this->pairs('/* a /* b */ c */ 1'));
        $this->assertSame([['INTEGER', 1]], $this->pairs('/* /* /* */ */ */ 1'));
        $this->assertSame([['INTEGER', 1]], $this->pairs('/**/ 1'));
    }

    public function test_a_slash_is_only_an_opener_before_a_star()
    {
        $this->assertSame([['INTEGER', 8], ['DIVIDE', '/'], ['INTEGER', 2]], $this->pairs('8 / 2'));
        $this->assertSame([['DIVIDE_ASSIGN', '/='], ['INTEGER', 2]], $this->pairs('/= 2'));
        // A line comment holding an opener is still just a line comment
        $this->assertSame([['INTEGER', 1]], $this->pairs('// /* never opened
1'));
    }

    public function test_an_unterminated_block_comment_reports_the_line_it_opened_on()
    {
        $this->expectExceptionMessage('Unterminated block comment on line 2');
        $this->pairs('echo 1;
/* opened here
/* and here */
');
    }

    public function test_keywords_are_lowercase_and_matched_exactly()
    {
        // Any other capitalisation is an ordinary name, so class If and fn match() are fine
        $this->assertSame(
            [['ECHO', 'echo'], ['IDENTIFIER', 'ECHO'], ['TRUE', 'true'], ['IDENTIFIER', 'True'],
                ['IDENTIFIER', '_Helper2'], ['VAR_IDENTIFIER', '$If'], ['GLOBAL_VAR_IDENTIFIER', '@x_1']],
            $this->pairs('echo ECHO true True _Helper2 $If @x_1')
        );
    }

    /**
     * @dataProvider invalidWords
     */
    public function test_invalid_numbers_and_variable_names_show_the_whole_word(string $source, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->pairs($source);
    }

    public static function invalidWords(): array
    {
        return [
            'number running into letters' => ['12abc', 'Invalid number literal: 12abc on line 1'],
            'number running into an underscore' => ['7_000', 'Invalid number literal: 7_000 on line 1'],
            'variable starting with a digit' => ['$1abc', 'Invalid variable name: $1abc on line 1'],
            'global starting with a digit' => ['@2x_y', 'Invalid variable name: @2x_y on line 1'],
            'lone sigil' => ['$ = 1', 'Invalid variable name: $ on line 1'],
        ];
    }

    public function test_numbers_may_touch_operators_and_sigils()
    {
        $this->assertSame(
            [['INTEGER', 1], ['PLUS', '+'], ['INTEGER', 2], ['INTEGER', 3], ['VAR_IDENTIFIER', '$x']],
            $this->pairs('1+2 3$x')
        );
    }

    public function test_hex_literals()
    {
        $this->assertSame(
            [['INTEGER', 255], ['INTEGER', 57005], ['INTEGER', PHP_INT_MAX], ['INTEGER', 485], ['INTEGER', 1], ['INTEGER', 0]],
            $this->pairs('0xff 0XdEaD 0x7FFFFFFFFFFFFFFF 0x1e5 0x0000000000000000001 0x0')
        );
    }

    /**
     * @dataProvider invalidHex
     */
    public function test_invalid_hex_literals(string $source, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->pairs($source);
    }

    public static function invalidHex(): array
    {
        return [
            'no digits' => ['0x', 'Invalid number literal: 0x on line 1'],
            'letters after the digits' => ['0xFG', 'Invalid number literal: 0xFG on line 1'],
            'too large' => ['0x8000000000000000', 'Integer literal too large: 0x8000000000000000 on line 1'],
            'only after a single zero' => ['00x1', 'Invalid number literal: 00x1 on line 1'],
        ];
    }

    public function test_float_literals()
    {
        $this->assertSame(
            [['FLOAT', 1.5], ['FLOAT', 0.25], ['FLOAT', 1e10], ['FLOAT', 2.5E-3], ['FLOAT', 3e+2], ['FLOAT', 1e-999], ['INTEGER', 7]],
            $this->pairs('1.5 0.25 1e10 2.5E-3 3e+2 1e-999 7')
        );
    }

    /**
     * @dataProvider invalidFloats
     */
    public function test_invalid_float_literals(string $source, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->pairs($source);
    }

    public static function invalidFloats(): array
    {
        return [
            'dot without digits after' => ['1.', "Unexpected character '.' on line 1"],
            'dot without digits before' => ['.5', "Unexpected character '.' on line 1"],
            'exponent without digits' => ['1e', 'Invalid number literal: 1e on line 1'],
            'exponent sign without digits' => ['1E+', 'Invalid number literal: 1E on line 1'],
            'letters after a float' => ['1.5x', 'Invalid number literal: 1.5x on line 1'],
            'too large' => ['1e999', 'Float literal too large: 1e999 on line 1'],
            'second dot' => ['1.5.3', "Unexpected character '.' on line 1"],
            'member of a number' => ['1.x', 'Invalid number literal: 1.x on line 1'],
            'member of a float' => ['1.5._y', 'Invalid number literal: 1.5._y on line 1'],
        ];
    }

    public function test_format_float_round_trips()
    {
        // Printed with the shortest digits that read back as the same float, never as an int
        $floats = [0.1, 0.1 + 0.2, 1.0, -0.0, 1e25, 1.5e-7, 123456789.125, PHP_FLOAT_MAX, PHP_FLOAT_MIN, -2.5];
        $printed = explode("\n", rtrim($this->executeCode(implode(' ', array_map(fn ($float) => 'echo '.var_export($float, true).';', $floats))), "\n"));
        foreach ($floats as $i => $float) {
            $this->assertMatchesRegularExpression('/[.E]/', $printed[$i], "{$printed[$i]} must not look like an int");
            $this->assertSame($float, (float) $printed[$i], $printed[$i]);
            $this->assertSame(str_starts_with(var_export($float, true), '-'), str_starts_with($printed[$i], '-'), $printed[$i]);
        }
    }

    public function test_integer_literals_must_fit()
    {
        $this->assertSame([['INTEGER', PHP_INT_MAX], ['INTEGER', 7]], $this->pairs('9223372036854775807 007'));

        $this->expectExceptionMessage('Integer literal too large: 9223372036854775808 on line 1');
        $this->pairs('9223372036854775808');
    }

    public function test_tokenization()
    {
        $this->assertSame(
            [['INTEGER', 3], ['PLUS', '+'], ['INTEGER', 4], ['MULTIPLY', '*'], ['INTEGER', 2], ['MINUS', '-'], ['INTEGER', 1], ['DIVIDE', '/'], ['INTEGER', 5], ['SEMICOLON', ';']],
            $this->pairs('3 + 4 * 2 - 1 / 5;')
        );
    }

    public function test_multiple_statements()
    {
        $this->assertSame(
            [['INTEGER', 5], ['PLUS', '+'], ['INTEGER', 3], ['SEMICOLON', ';'], ['INTEGER', 10], ['MULTIPLY', '*'], ['INTEGER', 2], ['SEMICOLON', ';']],
            $this->pairs('5 + 3; 10 * 2;')
        );
    }
}
