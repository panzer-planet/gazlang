<?php

namespace GazLang\Tests;

use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;
use PHPUnit\Framework\TestCase;

class LexerTest extends TestCase
{
    /**
     * Lex a whole source string into [type, value] pairs, without the EOF token
     */
    private function lex(string $source): array
    {
        $lexer = new Lexer($source);
        $tokens = [];
        while (($token = $lexer->get_next_token())->type !== Token::EOF) {
            $tokens[] = [$token->type, $token->value];
        }

        return $tokens;
    }

    public function test_string_escapes()
    {
        $this->assertSame([[Token::STRING, "a\nb\tc\rd\"e\\f"]], $this->lex('"a\nb\tc\rd\"e\\\\f"'));
    }

    public function test_quote_is_the_inverse_of_string_literals()
    {
        $value = "tab\t cr\r nl\n quote\" backslash\\ plain";
        $this->assertSame([[Token::STRING, $value]], $this->lex(Lexer::quote($value)));
    }

    public function test_all_escape_sequences()
    {
        $this->assertSame(
            [[Token::STRING, "\n\t\r\v\f\e\0\\\"AzAz\u{e9}\u{E9}\u{1F600}\u{0}"]],
            $this->lex('"\n\t\r\v\f\e\0\\\\\"\x41\x7a\x41\x7A\u{e9}\u{E9}\u{1F600}\u{0}"')
        );
    }

    /**
     * @dataProvider invalidEscapes
     */
    public function test_invalid_escapes(string $source, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->lex($source);
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

        $this->assertSame([[Token::STRING, $all_bytes]], $this->lex(Lexer::quote($all_bytes)));
    }

    public function test_quote_writes_readable_escapes()
    {
        // NUL is \x00 so a following digit can't turn it into an octal-looking \01
        $this->assertSame('"\n\t\r\v\f\e\\\\\"\x0012 \x01\x7F é"', Lexer::quote("\n\t\r\v\f\e\\\"\x0012 \x01\x7F é"));
    }

    /**
     * @dataProvider singleQuotedStrings
     */
    public function test_single_quoted_strings_are_raw(string $source, string $value)
    {
        $this->assertSame([[Token::STRING, $value]], $this->lex($source));
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
        $this->lex("1;\n'open\n\nnever");
    }

    public function test_interpolated_strings_are_split_into_parts_and_expression_tokens()
    {
        $this->assertSame(
            [
                [Token::STRING_START, 'Hi '], [Token::VAR_IDENTIFIER, '$name'],
                [Token::STRING_MIDDLE, ', '], [Token::GLOBAL_VAR_IDENTIFIER, '@n'], [Token::PLUS, '+'], [Token::INTEGER, 1],
                [Token::STRING_MIDDLE, ' '], [Token::VAR_IDENTIFIER, '$m'], [Token::LEFT_BRACKET, '['],
                [Token::STRING_START, 'k'], [Token::VAR_IDENTIFIER, '$x'], [Token::STRING_END, ''],
                [Token::RIGHT_BRACKET, ']'], [Token::STRING_END, '!'],
            ],
            $this->lex('"Hi $name, {@n + 1} {$m["k$x"]}!"')
        );
    }

    public function test_strings_without_interpolation_stay_one_token()
    {
        $this->assertSame(
            [[Token::STRING, 'costs $5, me@x.com { $y} {} $ $a {$b']],
            $this->lex('"costs $5, me@x.com { \\$y} {} $ \\$a \\{\\$b"')
        );
    }

    public function test_interpolation_tokens_keep_their_lines()
    {
        $lexer = new Lexer("\"a\n{\$x\n}b\n\$y\"");
        $lines = [];
        while (($token = $lexer->get_next_token())->type !== Token::EOF) {
            $lines[] = [$token->type, $token->line];
        }

        $this->assertSame([[Token::STRING_START, 1], [Token::VAR_IDENTIFIER, 2], [Token::STRING_MIDDLE, 3], [Token::VAR_IDENTIFIER, 4], [Token::STRING_END, 4]], $lines);
    }

    /**
     * @dataProvider unterminatedInterpolations
     */
    public function test_unterminated_interpolation_reports_where_the_string_starts(string $source)
    {
        $this->expectExceptionMessage('Unterminated string on line 2');
        $this->lex($source);
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
        $this->assertSame('"\\$a \\{\\$b \\{@c $5 { \\$d} $ {"', Lexer::quote($value));
        $this->assertSame([[Token::STRING, $value]], $this->lex(Lexer::quote($value)));
    }

    public function test_shorthand_interpolation_reads_one_php_style_index()
    {
        $this->assertSame(
            [
                [Token::STRING_START, ''], [Token::VAR_IDENTIFIER, '$a'], [Token::LEFT_BRACKET, '['], [Token::INTEGER, -1], [Token::RIGHT_BRACKET, ']'],
                [Token::STRING_MIDDLE, ' '], [Token::VAR_IDENTIFIER, '$a'], [Token::LEFT_BRACKET, '['], [Token::STRING, 'k_1'], [Token::RIGHT_BRACKET, ']'],
                [Token::STRING_MIDDLE, ' '], [Token::VAR_IDENTIFIER, '$a'], [Token::LEFT_BRACKET, '['], [Token::VAR_IDENTIFIER, '$i'], [Token::RIGHT_BRACKET, ']'],
                [Token::STRING_MIDDLE, ' '], [Token::VAR_IDENTIFIER, '$a'], [Token::LEFT_BRACKET, '['], [Token::STRING, '01'], [Token::RIGHT_BRACKET, ']'],
                [Token::STRING_MIDDLE, ' '], [Token::VAR_IDENTIFIER, '$a'], [Token::LEFT_BRACKET, '['], [Token::INTEGER, 0], [Token::RIGHT_BRACKET, ']'],
                [Token::STRING_END, '[1]'],
            ],
            $this->lex('"$a[-1] $a[k_1] $a[$i] $a[01] $a[0][1]"')
        );
    }

    /**
     * @dataProvider invalidInterpolatedIndexes
     */
    public function test_invalid_shorthand_index_is_an_error(string $index)
    {
        $this->expectExceptionMessage('Invalid array index in interpolated string');
        $this->lex('"$a'.$index.'"');
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
        $this->lex("\n\"a\\qb\"");
    }

    public function test_multi_character_operators_take_the_longest_match()
    {
        $this->assertSame(
            [
                Token::STRICT_EQUALS, Token::EQUALS, Token::ASSIGN, Token::DOUBLE_ARROW, Token::STRICT_NOT_EQUALS, Token::NOT_EQUALS, Token::NOT, Token::LESS_EQUALS, Token::GREATER_EQUALS,
                Token::PLUS_ASSIGN, Token::INCREMENT, Token::PLUS, Token::MINUS_ASSIGN, Token::DECREMENT, Token::MINUS, Token::MULTIPLY_ASSIGN, Token::DIVIDE_ASSIGN, Token::MODULO_ASSIGN,
                Token::INCREMENT, Token::PLUS, Token::DECREMENT, Token::MINUS,
            ],
            array_column($this->lex('=== == = => !== != ! <= >= += ++ + -= -- - *= /= %= +++ ---'), 0)
        );
    }

    public function test_comments_and_whitespace_are_skipped_and_lines_counted()
    {
        $lexer = new Lexer("// first\n\n  echo // trailing\n\t1 / 2;");
        $lines = [];
        while (($token = $lexer->get_next_token())->type !== Token::EOF) {
            $lines[] = [$token->type, $token->line];
        }

        $this->assertSame([[Token::ECHO, 3], [Token::INTEGER, 4], [Token::DIVIDE, 4], [Token::INTEGER, 4], [Token::SEMICOLON, 4]], $lines);
    }

    public function test_keywords_are_case_insensitive_and_names_are_not()
    {
        $this->assertSame(
            [[Token::ECHO, 'ECHO'], [Token::TRUE, 'True'], [Token::IDENTIFIER, '_Helper2'], [Token::VAR_IDENTIFIER, '$If'], [Token::GLOBAL_VAR_IDENTIFIER, '@x_1']],
            $this->lex('ECHO True _Helper2 $If @x_1')
        );
    }

    /**
     * @dataProvider invalidWords
     */
    public function test_invalid_numbers_and_variable_names_show_the_whole_word(string $source, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->lex($source);
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
            [[Token::INTEGER, 1], [Token::PLUS, '+'], [Token::INTEGER, 2], [Token::INTEGER, 3], [Token::VAR_IDENTIFIER, '$x']],
            $this->lex('1+2 3$x')
        );
    }

    public function test_hex_literals()
    {
        $this->assertSame(
            [[Token::INTEGER, 255], [Token::INTEGER, 57005], [Token::INTEGER, PHP_INT_MAX], [Token::INTEGER, 485], [Token::INTEGER, 1], [Token::INTEGER, 0]],
            $this->lex('0xff 0XdEaD 0x7FFFFFFFFFFFFFFF 0x1e5 0x0000000000000000001 0x0')
        );
    }

    /**
     * @dataProvider invalidHex
     */
    public function test_invalid_hex_literals(string $source, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->lex($source);
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
            [[Token::FLOAT, 1.5], [Token::FLOAT, 0.25], [Token::FLOAT, 1e10], [Token::FLOAT, 2.5E-3], [Token::FLOAT, 3e+2], [Token::FLOAT, 1e-999], [Token::INTEGER, 7]],
            $this->lex('1.5 0.25 1e10 2.5E-3 3e+2 1e-999 7')
        );
    }

    /**
     * @dataProvider invalidFloats
     */
    public function test_invalid_float_literals(string $source, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->lex($source);
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
        ];
    }

    public function test_format_float_round_trips()
    {
        foreach ([0.1, 0.1 + 0.2, 1.0, -0.0, 1e25, 1.5e-7, 123456789.125, PHP_FLOAT_MAX, PHP_FLOAT_MIN, -2.5] as $float) {
            $text = Lexer::format_float($float);
            $this->assertMatchesRegularExpression('/[.E]/', $text, "{$text} must not look like an int");
            $this->assertSame($float, (float) Lexer::parse_number(ltrim($text, '-')) * ($text[0] === '-' ? -1 : 1), $text);
        }
    }

    public function test_integer_literals_must_fit()
    {
        $this->assertSame([[Token::INTEGER, PHP_INT_MAX], [Token::INTEGER, 7]], $this->lex('9223372036854775807 007'));

        $this->expectExceptionMessage('Integer literal too large: 9223372036854775808 on line 1');
        $this->lex('9223372036854775808');
    }

    public function test_tokenization()
    {
        $lexer = new Lexer('3 + 4 * 2 - 1 / 5;');

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::INTEGER, $token->type);
        $this->assertEquals(3, $token->value);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::PLUS, $token->type);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::INTEGER, $token->type);
        $this->assertEquals(4, $token->value);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::MULTIPLY, $token->type);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::INTEGER, $token->type);
        $this->assertEquals(2, $token->value);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::MINUS, $token->type);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::INTEGER, $token->type);
        $this->assertEquals(1, $token->value);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::DIVIDE, $token->type);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::INTEGER, $token->type);
        $this->assertEquals(5, $token->value);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::SEMICOLON, $token->type);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::EOF, $token->type);
    }

    public function test_multiple_statements()
    {
        $lexer = new Lexer('5 + 3; 10 * 2;');

        // First statement
        $token = $lexer->get_next_token();
        $this->assertEquals(Token::INTEGER, $token->type);
        $this->assertEquals(5, $token->value);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::PLUS, $token->type);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::INTEGER, $token->type);
        $this->assertEquals(3, $token->value);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::SEMICOLON, $token->type);

        // Second statement
        $token = $lexer->get_next_token();
        $this->assertEquals(Token::INTEGER, $token->type);
        $this->assertEquals(10, $token->value);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::MULTIPLY, $token->type);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::INTEGER, $token->type);
        $this->assertEquals(2, $token->value);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::SEMICOLON, $token->type);

        $token = $lexer->get_next_token();
        $this->assertEquals(Token::EOF, $token->type);
    }
}
