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
}
