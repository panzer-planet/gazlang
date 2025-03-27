<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * Num represents a number literal in the AST
 */
class NumAST extends TokenValueNodeAST
{
    /**
     * @var int The numeric value
     */
    public $value;

    /**
     * Constructor
     *
     * @param  Token  $token  The token representing the number
     */
    public function __construct(Token $token)
    {
        parent::__construct($token);
        // Ensure value is an integer
        $this->value = (int) $this->value;
    }
}
