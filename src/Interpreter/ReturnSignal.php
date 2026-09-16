<?php

namespace GazLang\Interpreter;

use Exception;

/**
 * Thrown by return, and caught by the function call it returns from
 */
class ReturnSignal extends Exception
{
    /**
     * @var mixed The returned value
     */
    public $value;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('return outside of a function');
    }
}
