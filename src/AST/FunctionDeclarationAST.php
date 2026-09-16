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
     * @var array<int, AST|null> Each parameter's default value expression, by position, or null if it is required
     */
    public $defaults;

    /**
     * @var CompoundAST The function body
     */
    public $body;

    /**
     * Constructor
     *
     * @param  string  $name  The function name
     * @param  string[]  $params  The parameter names, including the $ prefix
     * @param  array<int, AST|null>  $defaults  Each parameter's default value expression, or null if required
     * @param  CompoundAST  $body  The function body
     */
    public function __construct(string $name, array $params, array $defaults, CompoundAST $body)
    {
        $this->name = $name;
        $this->params = $params;
        $this->defaults = $defaults;
        $this->body = $body;
    }
}
