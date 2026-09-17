<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;

/**
 * Increment represents ++ or -- on a variable or an element of one, prefix or postfix, in the AST
 */
class IncrementAST extends AST
{
    /**
     * @var VariableAST|IndexAST|PropertyAST What is incremented or decremented
     */
    public $target;

    /**
     * @var Token The INCREMENT or DECREMENT token
     */
    public $op;

    /**
     * @var bool Whether the operator comes first (++$x gives the new value) or after ($x++ gives the old one)
     */
    public $prefix;

    /**
     * Constructor
     *
     * @param  VariableAST|IndexAST|PropertyAST  $target  What is incremented or decremented
     * @param  Token  $op  The INCREMENT or DECREMENT token
     * @param  bool  $prefix  Whether the operator comes first
     */
    public function __construct(VariableAST|IndexAST|PropertyAST $target, Token $op, bool $prefix)
    {
        $this->target = $target;
        $this->op = $op;
        $this->prefix = $prefix;
    }
}
