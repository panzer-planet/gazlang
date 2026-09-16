<?php

namespace GazLang\AST;

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

    /**
     * Whether this is a global (@name) rather than a local ($name) variable
     */
    public function isGlobal(): bool
    {
        return $this->value[0] === '@';
    }
}
