<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * Assign node represents a variable assignment in the AST
 */
class Assign extends AST
{
    /**
     * @var Variable The variable being assigned to
     */
    public $left;
    
    /**
     * @var Token The assignment token
     */
    public $token;
    
    /**
     * @var object The expression being assigned
     */
    public $right;
    
    /**
     * Constructor
     *
     * @param Variable $left  The variable being assigned to
     * @param Token    $token The assignment token
     * @param object   $right The expression being assigned
     */
    public function __construct(Variable $left, Token $token, $right)
    {
        $this->left = $left;
        $this->token = $token;
        $this->right = $right;
    }
} 