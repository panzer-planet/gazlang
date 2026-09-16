<?php

namespace GazLang\Interpreter;

use Exception;

/**
 * Thrown by break and continue, and caught by the innermost enclosing loop
 */
class LoopSignal extends Exception
{
    /**
     * @var string Token::BREAK or Token::CONTINUE
     */
    public $type;

    /**
     * Constructor
     *
     * @param  string  $type  Token::BREAK or Token::CONTINUE
     */
    public function __construct(string $type)
    {
        parent::__construct("{$type} outside of a loop");
        $this->type = $type;
    }
}
