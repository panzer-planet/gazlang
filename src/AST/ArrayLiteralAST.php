<?php

namespace GazLang\AST;

/**
 * ArrayLiteral represents a list literal like [1, 2] or a map literal like {"a" => 1} in the AST
 */
class ArrayLiteralAST extends AST
{
    /**
     * @var array<array{0: AST|null, 1: AST}> [key, value] pairs in order; the key is null in a list
     */
    public $entries;

    /**
     * @var bool Whether this is a map literal, whose entries all have keys
     */
    public $map;

    /**
     * Constructor
     *
     * @param  array<array{0: AST|null, 1: AST}>  $entries  [key, value] pairs in order; the key is null in a list
     * @param  bool  $map  Whether this is a map literal
     */
    public function __construct(array $entries, bool $map = false)
    {
        $this->entries = $entries;
        $this->map = $map;
    }
}
