<?php

namespace GazLang\AST;

/**
 * FunctionCall represents a call to a named function in the AST
 */
class FunctionCallAST extends AST
{
    /**
     * @var string The function name
     */
    public $name;

    /**
     * @var AST[] The argument expressions, in order
     */
    public $args;

    /**
     * Constructor
     *
     * @param  string  $name  The function name
     * @param  AST[]  $args  The argument expressions, in order
     */
    public function __construct(string $name, array $args)
    {
        $this->name = $name;
        $this->args = $args;
    }
}
