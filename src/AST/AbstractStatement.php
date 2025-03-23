<?php

namespace GazLang\AST;

/**
 * AbstractStatement represents a base class for all statement nodes in the AST
 */
abstract class AbstractStatement extends AST
{
    /**
     * @var object The expression in this statement
     */
    public $expr;
    
    /**
     * Constructor
     *
     * @param object $expr The expression in this statement
     */
    public function __construct($expr)
    {
        $this->expr = $expr;
    }
} 