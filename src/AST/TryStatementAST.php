<?php

namespace GazLang\AST;

/**
 * TryStatement represents try { ... } catch ($error) { ... } in the AST
 */
class TryStatementAST extends AST
{
    /**
     * @var CompoundAST The statements whose errors are caught
     */
    public $body;

    /**
     * @var VariableAST The variable the caught error is assigned to
     */
    public $variable;

    /**
     * @var CompoundAST The statements run when an error is caught
     */
    public $catch_body;

    /**
     * Constructor
     *
     * @param  CompoundAST  $body  The statements whose errors are caught
     * @param  VariableAST  $variable  The variable the caught error is assigned to
     * @param  CompoundAST  $catch_body  The statements run when an error is caught
     */
    public function __construct(CompoundAST $body, VariableAST $variable, CompoundAST $catch_body)
    {
        $this->body = $body;
        $this->variable = $variable;
        $this->catch_body = $catch_body;
    }
}
