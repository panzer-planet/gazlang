<?php

namespace GazLang\AST;

/**
 * WhileStatement node represents a while loop (for loops are desugared into this, with a step)
 */
class WhileStatementAST extends AST
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
     * @var StatementAST|null Runs after the body and on continue; set for desugared for loops
     */
    public $step;

    /**
     * Constructor
     *
     * @param  object  $condition  The condition expression
     * @param  CompoundAST  $body  The loop body
     * @param  StatementAST|null  $step  Runs after the body and on continue
     */
    public function __construct(object $condition, CompoundAST $body, ?StatementAST $step = null)
    {
        $this->condition = $condition;
        $this->body = $body;
        $this->step = $step;
    }
}
