<?php

namespace GazLang\Parser;

use Exception;
use GazLang\AST\BinOpAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\NumAST;
use GazLang\AST\StatementAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\VariableAST;
use GazLang\AST\AssignAST;
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
        $token = $this->current_token ? $this->current_token->type . '(' . $this->current_token->value . ')' : 'null';
        throw new Exception('Invalid syntax near token: ' . $token);
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
     * Parse a variable
     *
     * @return VariableAST
     * @throws Exception
     */
    public function variable()
    {
        $node = new VariableAST($this->current_token);
        $this->eat(Token::VAR_IDENTIFIER);
        return $node;
    }
    
    /**
     * Parse a factor (INTEGER | LPAREN expr RPAREN | variable)
     *
     * @return NumAST|BinOpAST|VariableAST
     * @throws Exception
     */
    public function factor()
    {
        $token = $this->current_token;
        
        if ($token->type === Token::INTEGER) {
            $this->eat(Token::INTEGER);
            return new NumAST($token);
        } elseif ($token->type === Token::LEFT_PAREN) {
            $this->eat(Token::LEFT_PAREN);
            $node = $this->expr();
            $this->eat(Token::RIGHT_PAREN);
            return $node;
        } elseif ($token->type === Token::VAR_IDENTIFIER) {
            return $this->variable();
        }
        
        $this->error();
    }
    
    /**
     * Parse a term (factor ((MUL | DIV) factor)*)
     *
     * @return BinOpAST|NumAST|VariableAST
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
            
            $node = new BinOpAST($node, $token, $this->factor());
        }
        
        return $node;
    }
    
    /**
     * Parse an expression (term ((PLUS | MINUS) term)* | variable ASSIGN expr)
     *
     * @return BinOpAST|NumAST|VariableAST|AssignAST
     * @throws Exception
     */
    public function expr()
    {
        // First handle simple expressions (including variables in expressions)
        $node = $this->term();
        
        // Handle assignment if the node is a variable
        if ($node instanceof VariableAST && $this->current_token->type === Token::ASSIGN) {
            $var_node = $node;
            $token = $this->current_token;
            $this->eat(Token::ASSIGN);
            $right = $this->expr();
            return new AssignAST($var_node, $token, $right);
        }
        
        // Handle addition/subtraction
        while (in_array($this->current_token->type, [Token::PLUS, Token::MINUS])) {
            $token = $this->current_token;
            if ($token->type === Token::PLUS) {
                $this->eat(Token::PLUS);
            } else if ($token->type === Token::MINUS) {
                $this->eat(Token::MINUS);
            }
            
            $node = new BinOpAST($node, $token, $this->term());
        }
        
        return $node;
    }
    
    /**
     * Parse an if statement (IF LPAREN expr RPAREN LBRACE statement* RBRACE
     *                       [ELSE IF LPAREN expr RPAREN LBRACE statement* RBRACE]*
     *                       [ELSE LBRACE statement* RBRACE])
     * 
     * @return IfStatementAST
     * @throws Exception
     */
    public function if_statement()
    {
        $this->eat(Token::IF);
        $this->eat(Token::LEFT_PAREN);
        $condition = $this->expr();
        $this->eat(Token::RIGHT_PAREN);
        $this->eat(Token::LEFT_BRACE);
        
        // Parse if body statements
        $if_body = new CompoundAST();
        while ($this->current_token->type !== Token::RIGHT_BRACE) {
            $statement = $this->statement();
            $if_body->statements[] = $statement;
        }
        $this->eat(Token::RIGHT_BRACE);
        
        // Check for else-if or else clause
        $else_if = null;
        $else_body = null;
        
        if ($this->current_token->type === Token::ELSE) {
            $this->eat(Token::ELSE);
            
            // Check if this is an else-if or a regular else
            if ($this->current_token->type === Token::IF) {
                // This is an else-if, parse it as a nested if statement
                $else_if = $this->if_statement();
            } else {
                // This is a regular else
                $this->eat(Token::LEFT_BRACE);
                
                // Parse else body statements
                $else_body = new CompoundAST();
                while ($this->current_token->type !== Token::RIGHT_BRACE) {
                    $statement = $this->statement();
                    $else_body->statements[] = $statement;
                }
                $this->eat(Token::RIGHT_BRACE);
            }
        }
        
        return new IfStatementAST($condition, $if_body, $else_if, $else_body);
    }

    /**
     * Parse a statement (expr SEMICOLON | echo_statement | if_statement)
     *
     * @return StatementAST|EchoStatementAST|IfStatementAST
     * @throws Exception
     */
    public function statement()
    {
        if ($this->current_token->type === Token::ECHO) {
            return $this->echo_statement();
        } else if ($this->current_token->type === Token::IF) {
            return $this->if_statement();
        }
        
        $expr = $this->expr();
        $this->eat(Token::SEMICOLON);
        return new StatementAST($expr);
    }
    
    /**
     * Parse an echo statement (ECHO expr SEMICOLON)
     * 
     * @return EchoStatementAST
     * @throws Exception
     */
    public function echo_statement()
    {
        $this->eat(Token::ECHO);
        $expr = $this->expr();
        $this->eat(Token::SEMICOLON);
        return new EchoStatementAST($expr);
    }
    
    /**
     * Parse a program (statement+)
     *
     * @return CompoundAST
     * @throws Exception
     */
    public function program()
    {
        $root = new CompoundAST();
        
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
     * @return CompoundAST
     * @throws Exception
     */
    public function parse()
    {
        return $this->program();
    }
} 