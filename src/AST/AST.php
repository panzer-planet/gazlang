<?php

namespace GazLang\AST;

/**
 * Base class for all Abstract Syntax Tree nodes
 */
abstract class AST
{
    /**
     * @var int|null The line the node starts on, set by the parser
     */
    public $line;

    /**
     * @var string|null The file the node came from, as shown in errors, or null for piped or inline source
     */
    public $file;

    /**
     * The variable or # a write path starts at: $a in $a[0].total, # in #items[0], or null if it starts at anything else
     *
     * @param  AST  $node  A variable, #, or an index or property of a path
     */
    public static function pathRoot(AST $node): VariableAST|ThisAST|null
    {
        while ($node instanceof IndexAST || $node instanceof PropertyAST) {
            $node = $node->target;
        }

        return $node instanceof VariableAST || $node instanceof ThisAST ? $node : null;
    }
}
