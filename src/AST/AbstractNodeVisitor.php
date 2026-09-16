<?php

namespace GazLang\AST;

use Exception;

/**
 * Abstract base visitor class that implements common visitor pattern functionality
 *
 * This abstract class provides a common implementation of the visit method
 * that dispatches to the appropriate node-specific visitor methods.
 */
abstract class AbstractNodeVisitor implements NodeVisitorInterface
{
    /**
     * @var array<class-string, string> Visitor method names by node class
     */
    private static $methods = [];

    /**
     * Visit a node and dispatch to the appropriate node-specific visitor method
     *
     * @param  object  $node  The node to visit
     * @return mixed The result of visiting the node
     *
     * @throws Exception If there's no visitor method for the node type
     */
    public function visit(object $node)
    {
        // The method name is worked out once per node class: visit() runs for every node evaluated
        $method = self::$methods[$node::class] ??= 'visit'.str_replace('AST', '', substr(strrchr('\\'.$node::class, '\\'), 1));

        if (method_exists($this, $method)) {
            return $this->$method($node);
        }

        throw new Exception('No visitor method found for node type: '.$node::class);
    }
}
