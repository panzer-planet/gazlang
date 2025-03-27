<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * Boolean AST node represents a boolean literal
 */
class BoolAST extends TokenValueNodeAST
{
    /**
     * Constructor
     *
     * @param  Token  $token  The token containing the boolean value
     */
    public function __construct(Token $token)
    {
        parent::__construct($token);
        // Convert the string 'true' or 'false' to a boolean value
        $this->value = $token->value === 'true';
    }
}
