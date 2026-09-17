<?php

namespace GazLang\AST;

/**
 * Property represents a member of an object: #name, or .name on any expression ($user.name)
 *
 * Reading gives a field's value, or a method bound to the object.
 */
class PropertyAST extends AST
{
    /**
     * @var AST The object: a ThisAST for #name
     */
    public $target;

    /**
     * @var string The member name, without # or .
     */
    public $name;

    /**
     * @var bool Whether reading requires a field that is set (Values::propertyExisting); set by the code
     *           generator for the reads of a lowered compound update, never by the parser
     */
    public $existing = false;

    /**
     * Constructor
     *
     * @param  AST  $target  The object
     * @param  string  $name  The member name
     */
    public function __construct(AST $target, string $name)
    {
        $this->target = $target;
        $this->name = $name;
    }
}
