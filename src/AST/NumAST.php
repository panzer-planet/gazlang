<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * Num represents a number literal in the AST
 */
class NumAST extends TokenValueNodeAST
{
    /**
     * @var int|float The numeric value
     */
    public $value;

    /**
     * Constructor
     *
     * @param  Token  $token  The INTEGER or FLOAT token
     */
    public function __construct(Token $token)
    {
        parent::__construct($token);
    }
}
