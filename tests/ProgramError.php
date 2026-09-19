<?php

namespace GazLang\Tests;

use RuntimeException;

/**
 * A GazLang program that failed under a test helper: the error gazlang printed, without its
 * "Error: " (with the trace under it, when it printed one), and the exit code
 */
final class ProgramError extends RuntimeException
{
    /**
     * @param  string  $message  The error as printed, without "Error: "
     * @param  int  $exit_code  What gazlang exited with
     * @param  string  $output  What the program printed before failing
     */
    public function __construct(string $message, public readonly int $exit_code, public readonly string $output)
    {
        parent::__construct($message);
    }
}
