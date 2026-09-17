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
     * @var bool Whether reading requires an array with this key (Values::indexExisting); set by
     *           the code generator for the reads of a lowered compound update, never by the parser
     */
    public $existing = false;

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
}
