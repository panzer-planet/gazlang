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
     * @var bool Whether the message ends in the location; false for error() messages, which
     *           describe a place in the program's input rather than in the program
     */
    public $show_location;

    /**
     * Constructor
     *
     * @param  string  $reason  The message without the location
     * @param  string|null  $path  The file, as shown to the user
     * @param  int|null  $line_number  The line number, starting at 1
     * @param  bool  $show_location  Whether the message ends in the location
     */
    public function __construct(string $reason, ?string $path = null, ?int $line_number = null, bool $show_location = true)
    {
        $location = $line_number === null || ! $show_location ? '' : ' '.self::location($path, $line_number);
        parent::__construct($reason.$location);

        $this->reason = $reason;
        $this->path = $path;
        $this->line_number = $line_number;
        $this->show_location = $show_location;
    }

    /**
     * A place in the program as messages spell it: "at path:12", or "on line 12" for piped or inline source
     *
     * @param  string|null  $path  The file, as shown to the user
     * @param  int  $line_number  The line number
     */
    public static function location(?string $path, int $line_number): string
    {
        return $path === null ? "on line {$line_number}" : "at {$path}:{$line_number}";
    }
}
