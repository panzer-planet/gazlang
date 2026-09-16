<?php

namespace GazLang\AST;

/**
 * ArrayLiteral represents an array literal like [1, 2] or ["a" => 1] in the AST
 */
class ArrayLiteralAST extends AST
{
    /**
     * @var array<array{0: AST|null, 1: AST}> [key, value] pairs in order; the key is null when omitted
     */
    public $entries;

    /**
     * Constructor
     *
     * @param  array<array{0: AST|null, 1: AST}>  $entries  [key, value] pairs in order; the key is null when omitted
     */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }
}
