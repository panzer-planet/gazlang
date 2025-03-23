<?php

namespace GazLang\Tests;

use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;
use PHPUnit\Framework\TestCase;

class LexerTest extends TestCase
{
    public function testTokenization()
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
    
    public function testMultipleStatements()
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