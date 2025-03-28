<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * Variable node represents a variable reference in the AST
 */
class VariableAST extends TokenValueNodeAST
{
    /**
     * Get the variable name (including $ prefix)
     * 
     * @return string The variable name
     */
    public function getName(): string
    {
        return $this->value;
    }
} 