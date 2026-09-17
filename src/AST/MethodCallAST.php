<?php

namespace GazLang\AST;

/**
 * MethodCall represents calling a member: #save($x) or $doc.save($x)
 *
 * The same as calling the value the property reads (a bound method, or a field holding a
 * function) in the same order, without making a bound method value first.
 */
class MethodCallAST extends AST
{
    /**
     * @var PropertyAST The member being called
     */
    public $property;

    /**
     * @var AST[] The argument expressions, in order
     */
    public $args;

    /**
     * Constructor
     *
     * @param  PropertyAST  $property  The member being called
     * @param  AST[]  $args  The argument expressions, in order
     */
    public function __construct(PropertyAST $property, array $args)
    {
        $this->property = $property;
        $this->args = $args;
    }
}
