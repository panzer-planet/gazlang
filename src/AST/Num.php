<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * Num represents a number literal in the AST
 */
class Num extends AST
{
    /**
     * @var Token The token representing the number
     */
    public $token;
    
    /**
     * @var int The numeric value
     */
    public $value;
    
    /**
     * Constructor
     *
     * @param Token $token The token representing the number
     */
    public function __construct(Token $token)
    {
        $this->token = $token;
        $this->value = $token->value;
    }
} 