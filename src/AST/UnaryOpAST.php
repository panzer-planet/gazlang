<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * UnaryOp represents a unary operation in the AST (e.g., -5 or !$x)
 */
class UnaryOpAST extends AST
{
    /**
     * @var Token Operation token
     */
    public $op;

    /**
     * @var AST Operand
     */
    public $expr;

    /**
     * Constructor
     *
     * @param  Token  $op  Operation token
     * @param  object  $expr  Operand
     */
    public function __construct(Token $op, $expr)
    {
        $this->op = $op;
        $this->expr = $expr;
    }
}
