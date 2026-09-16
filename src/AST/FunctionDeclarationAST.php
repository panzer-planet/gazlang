<?php

namespace GazLang\AST;

/**
 * FunctionDeclaration represents a top level named function in the AST
 */
class FunctionDeclarationAST extends AST
{
    /**
     * @var string The function name
     */
    public $name;

    /**
     * @var string[] The parameter names, including the $ prefix
     */
    public $params;

    /**
     * @var CompoundAST The function body
     */
    public $body;

    /**
     * Constructor
     *
     * @param  string  $name  The function name
     * @param  string[]  $params  The parameter names, including the $ prefix
     * @param  CompoundAST  $body  The function body
     */
    public function __construct(string $name, array $params, CompoundAST $body)
    {
        $this->name = $name;
        $this->params = $params;
        $this->body = $body;
    }
}
