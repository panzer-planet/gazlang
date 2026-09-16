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

    public function test_unknown_escape_is_an_error()
    {
        $this->expectExceptionMessage('Unknown escape sequence \q in string on line 2');
        $this->lex("\n\"a\\qb\"");
    }

    public function test_multi_character_operators_take_the_longest_match()
    {
        $this->assertSame(
            [Token::STRICT_EQUALS, Token::EQUALS, Token::ASSIGN, Token::DOUBLE_ARROW, Token::STRICT_NOT_EQUALS, Token::NOT_EQUALS, Token::NOT, Token::LESS_EQUALS, Token::GREATER_EQUALS],
            array_column($this->lex('=== == = => !== != ! <= >='), 0)
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
            'number running into letters' => ['12abc', 'Invalid integer literal: 12abc on line 1'],
            'number running into an underscore' => ['7_000', 'Invalid integer literal: 7_000 on line 1'],
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
