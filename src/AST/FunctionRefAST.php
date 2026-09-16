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
     * Constructor
     *
     * @param  string  $name  The function name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
