<?php

namespace GazLang\AST;

/**
 * FunctionDeclaration represents a top level named function, or a method of a class, in the AST
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
     * @var int|array{0: int, 1: int} How many arguments a call takes: a count, or [fewest, most] with defaults
     */
    public $arity;

    /**
     * @var string|null The class a method belongs to, or null for a function
     */
    public $class = null;

    /**
     * @var bool Whether this is an abstract method, whose body is empty and never runs
     */
    public $abstract = false;

    /**
     * Constructor
     *
     * @param  string  $name  The function name
     * @param  string[]  $params  The parameter names, including the $ prefix
     * @param  array<int, AST|null>  $defaults  Each parameter's default value expression, or null if required
     * @param  CompoundAST  $body  The function body
     * @param  int|array{0: int, 1: int}  $arity  How many arguments a call takes
     */
    public function __construct(string $name, array $params, array $defaults, CompoundAST $body, int|array $arity)
    {
        $this->name = $name;
        $this->params = $params;
        $this->defaults = $defaults;
        $this->body = $body;
        $this->arity = $arity;
    }
}
