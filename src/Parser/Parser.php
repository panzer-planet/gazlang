<?php

namespace GazLang\Parser;

use Exception;
use GazLang\AST\BinOp;
use GazLang\AST\Compound;
use GazLang\AST\Num;
use GazLang\AST\Statement;
use GazLang\AST\EchoStatement;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;

/**
 * Parser class builds an AST from tokens
 */
class Parser
{
    /**
     * @var Lexer The lexer that provides tokens
     */
    private $lexer;
    
    /**
     * @var Token The current token being processed
     */
    private $current_token;
    
    /**
     * Constructor
     *
     * @param Lexer $lexer The lexer to get tokens from
     */
    public function __construct(Lexer $lexer)
    {
        $this->lexer = $lexer;
        $this->current_token = $this->lexer->get_next_token();
    }
    
    /**
     * Raise an error for invalid syntax
     *
     * @throws Exception
     */
    public function error(): void
    {
        throw new Exception('Invalid syntax');
    }
    
    /**
     * Compare the current token type with the passed token type and
     * if they match, "eat" the current token and get the next one
     *
     * @param string $token_type The token type to match
     * 
     * @throws Exception If the token types don't match
     */
    public function eat(string $token_type): void
    {
        if ($this->current_token->type === $token_type) {
            $this->current_token = $this->lexer->get_next_token();
        } else {
            $this->error();
        }
    }
    
    /**
     * Parse a factor (INTEGER | LPAREN expr RPAREN)
     *
     * @return Num|BinOp
     * @throws Exception
     */
    public function factor()
    {
        $token = $this->current_token;
        
        if ($token->type === Token::INTEGER) {
            $this->eat(Token::INTEGER);
            return new Num($token);
        } elseif ($token->type === Token::LEFT_PAREN) {
            $this->eat(Token::LEFT_PAREN);
            $node = $this->expr();
            $this->eat(Token::RIGHT_PAREN);
            return $node;
        }
        
        $this->error();
    }
    
    /**
     * Parse a term (factor ((MUL | DIV) factor)*)
     *
     * @return BinOp|Num
     * @throws Exception
     */
    public function term()
    {
        $node = $this->factor();
        
        while (in_array($this->current_token->type, [Token::MULTIPLY, Token::DIVIDE])) {
            $token = $this->current_token;
            if ($token->type === Token::MULTIPLY) {
                $this->eat(Token::MULTIPLY);
            } else if ($token->type === Token::DIVIDE) {
                $this->eat(Token::DIVIDE);
            }
            
            $node = new BinOp($node, $token, $this->factor());
        }
        
        return $node;
    }
    
    /**
     * Parse an expression (term ((PLUS | MINUS) term)*)
     *
     * @return BinOp|Num
     * @throws Exception
     */
    public function expr()
    {
        $node = $this->term();
        
        while (in_array($this->current_token->type, [Token::PLUS, Token::MINUS])) {
            $token = $this->current_token;
            if ($token->type === Token::PLUS) {
                $this->eat(Token::PLUS);
            } else if ($token->type === Token::MINUS) {
                $this->eat(Token::MINUS);
            }
            
            $node = new BinOp($node, $token, $this->term());
        }
        
        return $node;
    }
    
    /**
     * Parse a statement (expr SEMICOLON | echo_statement)
     *
     * @return Statement|EchoStatement
     * @throws Exception
     */
    public function statement()
    {
        if ($this->current_token->type === Token::ECHO) {
            return $this->echo_statement();
        }
        
        $expr = $this->expr();
        $this->eat(Token::SEMICOLON);
        return new Statement($expr);
    }
    
    /**
     * Parse an echo statement (ECHO expr SEMICOLON)
     * 
     * @return EchoStatement
     * @throws Exception
     */
    public function echo_statement()
    {
        $this->eat(Token::ECHO);
        $expr = $this->expr();
        $this->eat(Token::SEMICOLON);
        return new EchoStatement($expr);
    }
    
    /**
     * Parse a program (statement+)
     *
     * @return Compound
     * @throws Exception
     */
    public function program()
    {
        $root = new Compound();
        
        // Parse all statements
        while ($this->current_token->type !== Token::EOF) {
            $statement = $this->statement();
            $root->statements[] = $statement;
        }
        
        return $root;
    }
    
    /**
     * Parse the input and return an AST
     *
     * @return Compound
     * @throws Exception
     */
    public function parse()
    {
        return $this->program();
    }
} 