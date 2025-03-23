<?php

namespace GazLang\AST;

/**
 * Statement represents a single statement in the AST
 */
class Statement extends AST
{
    /**
     * @var AST The expression in this statement
     */
    public $expr;
    
    /**
     * Constructor
     *
     * @param AST $expr The expression in this statement
     */
    public function __construct(AST $expr)
    {
        $this->expr = $expr;
    }
} 