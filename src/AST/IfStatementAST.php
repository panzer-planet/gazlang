<?php

namespace GazLang\AST;

/**
 * IfStatement node represents an if/else control structure
 */
class IfStatementAST extends AbstractStatementAST
{
    /**
     * @var object The condition expression
     */
    public $condition;
    
    /**
     * @var CompoundAST The body of the if block
     */
    public $if_body;
    
    /**
     * @var IfStatementAST|null The else-if statement, if provided
     */
    public $else_if;
    
    /**
     * @var CompoundAST|null The body of the else block, if provided
     */
    public $else_body;
    
    /**
     * Constructor
     *
     * @param object      $condition The condition expression to evaluate
     * @param CompoundAST $if_body   The body of the if block
     * @param IfStatementAST|null $else_if The else-if statement, if provided
     * @param CompoundAST|null $else_body The body of the else block, if provided
     */
    public function __construct(object $condition, CompoundAST $if_body, ?IfStatementAST $else_if = null, ?CompoundAST $else_body = null)
    {
        $this->condition = $condition;
        $this->if_body = $if_body;
        $this->else_if = $else_if;
        $this->else_body = $else_body;
    }
}