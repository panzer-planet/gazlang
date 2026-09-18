<?php

namespace GazLang\Runtime;

use Exception;

/**
 * Thrown by exit() to stop the program with an exit code
 *
 * Not an error, so try/catch doesn't catch it: both backends let it through, like return
 * and break, and bin/gazlang-php (or the test harness) turns it into the process exit code.
 */
class ExitSignal extends Exception
{
    /**
     * @var int The exit code, 0 to 255
     */
    public $code;

    /**
     * Constructor
     *
     * @param  int  $code  The exit code
     */
    public function __construct(int $code)
    {
        parent::__construct("exit({$code})");
        $this->code = $code;
    }
}
