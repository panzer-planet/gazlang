<?php

namespace GazLang\AST;

/**
 * Variable node represents a variable reference in the AST
 */
class VariableAST extends TokenValueNodeAST
{
    /**
     * Whether this is a global (@name) rather than a local ($name) variable
     */
    public function isGlobal(): bool
    {
        return $this->value[0] === '@';
    }
}
