<?php

namespace GazLang\AST;

/**
 * WhileStatement node represents a while loop (for loops are desugared into this)
 */
class WhileStatementAST extends AbstractStatementAST
{
    /**
     * @var object The condition expression, checked before each iteration
     */
    public $condition;

    /**
     * @var CompoundAST The loop body
     */
    public $body;

    /**
     * Constructor
     *
     * @param  object  $condition  The condition expression
     * @param  CompoundAST  $body  The loop body
     */
    public function __construct(object $condition, CompoundAST $body)
    {
        $this->condition = $condition;
        $this->body = $body;
    }
}
