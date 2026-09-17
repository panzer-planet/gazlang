<?php

namespace GazLang\AST;

/**
 * Ternary represents $condition ? $then : $else in the AST; only the taken branch is evaluated
 */
class TernaryAST extends AST
{
    /**
     * @var AST The condition
     */
    public $condition;

    /**
     * @var AST The value when the condition is true
     */
    public $then;

    /**
     * @var AST The value when it is false
     */
    public $else;

    /**
     * Constructor
     *
     * @param  AST  $condition  The condition
     * @param  AST  $then  The value when the condition is true
     * @param  AST  $else  The value when it is false
     */
    public function __construct(AST $condition, AST $then, AST $else)
    {
        $this->condition = $condition;
        $this->then = $then;
        $this->else = $else;
    }
}
