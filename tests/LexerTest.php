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
