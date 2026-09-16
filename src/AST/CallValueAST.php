<?php

namespace GazLang\AST;

/**
 * CallValue represents a call on any expression, like $f(1), $h["save"]($doc) or pick()(2)
 *
 * Unlike FunctionCall, nothing is known about the callee until it is evaluated, so
 * whether it is a function and takes that many arguments is checked at runtime.
 */
class CallValueAST extends AST
{
    /**
     * @var AST The expression being called
     */
    public $callee;

    /**
     * @var AST[] The argument expressions, in order
     */
    public $args;

    /**
     * Constructor
     *
     * @param  AST  $callee  The expression being called
     * @param  AST[]  $args  The argument expressions, in order
     */
    public function __construct(AST $callee, array $args)
    {
        $this->callee = $callee;
        $this->args = $args;
    }
}
