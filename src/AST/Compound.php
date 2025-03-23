<?php

namespace GazLang\AST;

/**
 * Compound represents multiple statements in the AST
 */
class Compound extends AST
{
    /**
     * @var array List of statements
     */
    public $statements;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->statements = [];
    }
} 