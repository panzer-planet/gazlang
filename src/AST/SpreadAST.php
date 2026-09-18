<?php

namespace GazLang\AST;

/**
 * An element of a list literal written ...$expr: the elements of the list $expr gives, in order
 *
 * Only a list literal holds one, so a backend reaches it only from there.
 */
class SpreadAST extends AST
{
    /**
     * @var AST The list to spread
     */
    public $expr;

    /**
     * Constructor
     *
     * @param  AST  $expr  The list to spread
     */
    public function __construct(AST $expr)
    {
        $this->expr = $expr;
    }
}
