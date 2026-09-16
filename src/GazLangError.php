<?php

namespace GazLang;

use Exception;

/**
 * An error in a GazLang program, with the file and line it happened at when known
 */
class GazLangError extends Exception
{
    /**
     * @var string The message without the location
     */
    public $reason;

    /**
     * @var string|null The file, as shown to the user, or null for piped or inline source
     *                  (not $file, which Exception uses for the PHP file that threw)
     */
    public $path;

    /**
     * @var int|null The line number, starting at 1, or null when unknown
     */
    public $line_number;

    /**
     * Constructor
     *
     * @param  string  $reason  The message without the location
     * @param  string|null  $path  The file, as shown to the user
     * @param  int|null  $line_number  The line number, starting at 1
     */
    public function __construct(string $reason, ?string $path = null, ?int $line_number = null)
    {
        $location = match (true) {
            $line_number === null => '',
            $path === null => " on line {$line_number}",
            default => " at {$path}:{$line_number}",
        };
        parent::__construct($reason.$location);

        $this->reason = $reason;
        $this->path = $path;
        $this->line_number = $line_number;
    }
}
