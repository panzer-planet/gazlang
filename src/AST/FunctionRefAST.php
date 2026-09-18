<?php

namespace GazLang\AST;

/**
 * FunctionRef represents a bare function name used as a value, like $f = add;
 */
class FunctionRefAST extends AST
{
    /**
     * @var string The function name
     */
    public $name;

    /**
     * @var bool Whether the name is a constant's, which the parser knows once the whole program is read
     */
    public $constant = false;

    /**
     * @var mixed The constant's value, worked out by the parser: a use of a constant is its value
     */
    public $value = null;

    /**
     * Constructor
     *
     * @param  string  $name  The function name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
