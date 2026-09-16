<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * Boolean represents a true or false literal in the AST
 */
class BooleanAST extends TokenValueNodeAST
{
    /**
     * @var bool The boolean value
     */
    public $value;

    /**
     * Constructor
     *
     * @param  Token  $token  The TRUE or FALSE token
     */
    public function __construct(Token $token)
    {
        parent::__construct($token);
        $this->value = $token->type === Token::TRUE;
    }
}
