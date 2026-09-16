<?php

namespace GazLang\AST;

/**
 * ReturnStatement represents a return from a function in the AST
 */
class ReturnStatementAST extends AST
{
    /**
     * @var AST|null The returned expression, or null for a bare return
     */
    public $expr;

    /**
     * Constructor
     *
     * @param  AST|null  $expr  The returned expression, or null for a bare return
     */
    public function __construct(?AST $expr)
    {
        $this->expr = $expr;
    }
}
