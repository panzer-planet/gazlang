<?php

namespace GazLang;

use Exception;
use GazLang\Runtime\ClassValue;
use GazLang\Runtime\ObjectValue;

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
     * @var bool Whether the program threw a value with error() (other than a string), which catch gets as it is
     */
    public $has_value = false;

    /**
     * @var mixed The value the program threw, when
     */
    public $value = null;

    /**
     * @var mixed What catch sees, once worked out by caught()
     */
    private $caught = null;

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

    /**
     * An error for a value thrown with error(), which catch gets as it is
     *
     * @param  mixed  $value  The value
     * @param  string  $message  The value as text, shown if nothing catches it
     */
    public static function thrown($value, string $message): self
    {
        $error = new self($message, null, null, false);
        [$error->has_value, $error->value] = [true, $value];

        return $error;
    }

    /**
     * The same error at a location, keeping a thrown value
     *
     * @param  string|null  $path  The file, as shown to the user
     * @param  int  $line_number  The line number
     */
    public function located(?string $path, int $line_number): self
    {
        $error = new self($this->reason, $path, $line_number, $this->show_location);
        [$error->has_value, $error->value] = [$this->has_value, $this->value];

        return $error;
    }

    /**
     * The error as catch sees it: the thrown value, or an Error object with the message (without the location), file and line
     *
     * A thrown Error (or subclass) that has no line yet gets this error's file and line, so it
     * says where error() threw it; one thrown again keeps where it was first thrown. Worked
     * out once, so every catch clause that looks sees the same object.
     *
     * @param  ClassValue  $error_class  The running program's Error class
     */
    public function caught(ClassValue $error_class)
    {
        if ($this->caught !== null) {
            return $this->caught;
        }
        if (! $this->has_value) {
            $error = new ObjectValue($error_class);
            $error->fields = ['message' => $this->reason, 'file' => $this->path, 'line' => $this->line_number];

            return $this->caught = $error;
        }

        $value = $this->value;
        if ($value instanceof ObjectValue && $value->class->isA($error_class) && ! array_key_exists('line', $value->fields)) {
            $value->fields['file'] = $this->path;
            $value->fields['line'] = $this->line_number;
        }
        // A thrown null is caught as null each time; nothing about it needs remembering
        $this->caught = $value;

        return $value;
    }
}
