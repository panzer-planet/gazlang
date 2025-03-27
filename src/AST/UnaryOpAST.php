<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * UnaryOp AST node represents a unary operation
 */
class UnaryOpAST extends AST
{
    /**
     * @var Token The operator token
     */
    public $op;

    /**
     * @var AST The expression
     */
    public $expr;

    /**
     * Constructor
     *
     * @param  Token  $op  The operator token
     * @param  AST  $expr  The expression
     */
    public function __construct(Token $op, AST $expr)
    {
        $this->op = $op;
        $this->expr = $expr;
    }
}
