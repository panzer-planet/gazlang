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
     * Visit a node and dispatch to the appropriate node-specific visitor method
     *
     * @param  object  $node  The node to visit
     * @return mixed The result of visiting the node
     *
     * @throws Exception If there's no visitor method for the node type
     */
    public function visit(object $node)
    {
        // Get the class name
        $className = get_class($node);

        // Extract just the class name without namespace
        $parts = explode('\\', $className);
        $shortClassName = end($parts);

        // Remove the AST suffix
        $methodName = 'visit'.str_replace('AST', '', $shortClassName);

        if (method_exists($this, $methodName)) {
            return $this->$methodName($node);
        }

        throw new Exception("No visitor method found for node type: $shortClassName");
    }
}
