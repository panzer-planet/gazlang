<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * String AST node represents a string literal
 */
class StringAST extends TokenValueNodeAST
{
    /**
     * Constructor
     *
     * @param Token $token The token containing the string value
     */
    public function __construct(Token $token)
    {
        parent::__construct($token);
    }
}