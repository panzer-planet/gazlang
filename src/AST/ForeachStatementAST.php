<?php

namespace GazLang\AST;

/**
 * ForeachStatement represents foreach ($array as [$key =>] $value) { ... } in the AST
 */
class ForeachStatementAST extends AST
{
    /**
     * @var AST The array expression, evaluated once before the loop
     */
    public $iterable;

    /**
     * @var VariableAST|null The variable each key is assigned to, if given
     */
    public $key;

    /**
     * @var VariableAST The variable each value is assigned to
     */
    public $value;

    /**
     * @var CompoundAST The loop body
     */
    public $body;

    /**
     * Constructor
     *
     * @param  AST  $iterable  The array expression
     * @param  VariableAST|null  $key  The key variable, if given
     * @param  VariableAST  $value  The value variable
     * @param  CompoundAST  $body  The loop body
     */
    public function __construct(AST $iterable, ?VariableAST $key, VariableAST $value, CompoundAST $body)
    {
        $this->iterable = $iterable;
        $this->key = $key;
        $this->value = $value;
        $this->body = $body;
    }
}
