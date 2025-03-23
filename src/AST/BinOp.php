<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * BinOp represents a binary operation in the AST (e.g., 1 + 2)
 */
class BinOp extends AST
{
    /**
     * @var AST Left operand
     */
    public $left;
    
    /**
     * @var Token Operation token
     */
    public $token;
    
    /**
     * @var Token Operation token (alias for $token)
     */
    public $op;
    
    /**
     * @var AST Right operand
     */
    public $right;
    
    /**
     * Constructor
     *
     * @param AST   $left  Left operand
     * @param Token $op    Operation token
     * @param AST   $right Right operand
     */
    public function __construct(AST $left, Token $op, AST $right)
    {
        $this->left = $left;
        $this->token = $this->op = $op;
        $this->right = $right;
    }
} 