<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * LoopControl represents a break or continue statement in the AST
 */
class LoopControlAST extends AST
{
    /**
     * @var Token The BREAK or CONTINUE token
     */
    public $token;

    /**
     * Constructor
     *
     * @param  Token  $token  The BREAK or CONTINUE token
     */
    public function __construct(Token $token)
    {
        $this->token = $token;
    }
}
