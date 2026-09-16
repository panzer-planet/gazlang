<?php

namespace GazLang\AST;

/**
 * Index represents indexing into an array or string, like $a[0] or $a["key"], in the AST
 */
class IndexAST extends AST
{
    /**
     * @var AST The array or string being indexed
     */
    public $target;

    /**
     * @var AST|null The index expression, or null for an append target ($a[] = ...)
     */
    public $index;

    /**
     * Constructor
     *
     * @param  AST  $target  The array or string being indexed
     * @param  AST|null  $index  The index expression, or null for an append target
     */
    public function __construct(AST $target, ?AST $index)
    {
        $this->target = $target;
        $this->index = $index;
    }

    /**
     * Get the variable an assignment through this index ultimately writes to, if any
     */
    public function rootVariable(): ?VariableAST
    {
        $node = $this->target;
        while ($node instanceof IndexAST) {
            $node = $node->target;
        }

        return $node instanceof VariableAST ? $node : null;
    }
}
