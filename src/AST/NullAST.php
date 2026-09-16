<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * Null represents the null literal in the AST
 */
class NullAST extends TokenValueNodeAST
{
    /**
     * Constructor
     *
     * @param  Token  $token  The NULL token
     */
    public function __construct(Token $token)
    {
        parent::__construct($token);
        $this->value = null;
    }
}
