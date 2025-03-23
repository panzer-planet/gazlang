<?php

namespace GazLang\AST;

/**
 * EchoStatement represents an echo statement in the AST
 */
class EchoStatement extends AST
{
    /**
     * @var AST The expression in this echo statement
     */
    public $expr;
    
    /**
     * Constructor
     *
     * @param AST $expr The expression to echo
     */
    public function __construct(AST $expr)
    {
        $this->expr = $expr;
    }
} 