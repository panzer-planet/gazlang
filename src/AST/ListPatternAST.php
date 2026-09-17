<?php

namespace GazLang\AST;

/**
 * ListPattern represents [$a, $b] on the left of = or after foreach's as: take a list apart into targets
 *
 * The list must have exactly as many elements as there are targets; the targets are
 * assigned left to right.
 */
class ListPatternAST extends AST
{
    /**
     * @var list<VariableAST|IndexAST|PropertyAST> The targets, one for each element (only variables in foreach)
     */
    public $targets;

    /**
     * Constructor
     *
     * @param  list<VariableAST|IndexAST|PropertyAST>  $targets  The targets, one for each element
     */
    public function __construct(array $targets)
    {
        $this->targets = $targets;
    }
}
