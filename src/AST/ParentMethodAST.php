<?php

namespace GazLang\AST;

/**
 * ParentMethod represents the parent class's version of a method: ##to_string(), ##_(...), or ##area as a bound method
 *
 * Which class's version runs is known at parse time: the one the parent of the class it is
 * written in would run.
 */
class ParentMethodAST extends AST
{
    /**
     * @var string The method name
     */
    public $name;

    /**
     * @var AST[]|null The argument expressions, or null when it isn't called (a bound method)
     */
    public $args;

    /**
     * @var string The class whose version of the method runs, set by the parser once the classes are resolved
     */
    public $definer = '';

    /**
     * Constructor
     *
     * @param  string  $name  The method name
     * @param  AST[]|null  $args  The argument expressions, or null for a bound method
     */
    public function __construct(string $name, ?array $args)
    {
        $this->name = $name;
        $this->args = $args;
    }
}
