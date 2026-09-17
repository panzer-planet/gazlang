<?php

namespace GazLang\AST;

/**
 * Delete represents removing an element of a list or map: delete $a[0];
 *
 * The target is an index path rooted at a variable or #, as an assignment's is, and its
 * last step is the element to remove, so $a[] and a field are parse errors.
 */
class DeleteStatementAST extends AST
{
    /**
     * @var IndexAST The element to remove
     */
    public $target;

    /**
     * Constructor
     *
     * @param  IndexAST  $target  The element to remove
     */
    public function __construct(IndexAST $target)
    {
        $this->target = $target;
    }
}
