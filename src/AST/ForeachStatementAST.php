<?php

namespace GazLang\AST;

/**
 * ForeachStatement represents foreach ($array as [$key =>] $value) { ... } in the AST, the value possibly a pattern ([$a, $b])
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
     * @var VariableAST|ListPatternAST The variable each value is assigned to, or the pattern it is taken apart into
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
     * @param  VariableAST|ListPatternAST  $value  The value variable or pattern
     * @param  CompoundAST  $body  The loop body
     */
    public function __construct(AST $iterable, ?VariableAST $key, VariableAST|ListPatternAST $value, CompoundAST $body)
    {
        $this->iterable = $iterable;
        $this->key = $key;
        $this->value = $value;
        $this->body = $body;
    }
}
