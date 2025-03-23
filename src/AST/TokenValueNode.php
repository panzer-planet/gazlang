<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * Base class for AST nodes that hold a token and extract a value from it
 */
abstract class TokenValueNode extends AST
{
    /**
     * @var Token The token representing this node
     */
    public $token;
    
    /**
     * @var mixed The value extracted from the token
     */
    public $value;
    
    /**
     * Constructor
     *
     * @param Token $token The token
     */
    public function __construct(Token $token)
    {
        $this->token = $token;
        $this->value = $token->value;
    }
} 