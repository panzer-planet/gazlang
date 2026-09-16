<?php

namespace GazLang\Runtime;

use Exception;
use GazLang\GazLangError;
use GazLang\Lexer\Lexer;

/**
 * GazLang's builtin functions
 *
 * The parser checks calls against ARITIES, so call() can trust the name and argument
 * count; argument types are checked here, using type_of() names.
 */
final class Builtins
{
    /**
     * Builtin function names mapped to their parameter counts
     */
    public const ARITIES = [
        'len' => 1,
        'slice' => 3,
        'lower' => 1,
        'upper' => 1,
        'trim' => 1,
        'split' => 2,
        'join' => 2,
        'replace' => 3,
        'contains' => 2,
        'starts_with' => 2,
        'ends_with' => 2,
        'index_of' => 2,
        'repeat' => 2,
        'chr' => 1,
        'ord' => 1,
        'to_int' => 1,
        'to_string' => 1,
        'in_array' => 2,
        'has_key' => 2,
        'keys' => 1,
        'type_of' => 1,
        'error' => 1,
        'read_file' => 1,
        'write_file' => 2,
        'read_stdin' => 0,
        'args' => 0,
    ];

    /**
     * @var string[] Command line arguments passed to the program, returned by args()
     */
    private $args;

    /**
     * Constructor
     *
     * @param  string[]  $args  Command line arguments for the program, returned by args()
     */
    public function __construct(array $args = [])
    {
        $this->args = $args;
    }

    /**
     * Run a builtin function
     *
     * @param  string  $name  The builtin name, a key of ARITIES
     * @param  array  $args  The evaluated arguments
     * @return mixed The result
     *
     * @throws Exception If an argument has the wrong type or the builtin fails
     */
    public function call(string $name, array $args)
    {
        return match ($name) {
            'len' => is_array($this->argument($name, $args[0], 'array', 'string')) ? count($args[0]) : strlen($args[0]),
            'slice' => $this->slice($args[0], $this->argument($name, $args[1], 'int'), $this->argument($name, $args[2], 'int')),
            'lower' => strtolower($this->argument($name, $args[0], 'string')),
            'upper' => strtoupper($this->argument($name, $args[0], 'string')),
            // The same whitespace the lexer skips: space, tab, newline, carriage return
            'trim' => trim($this->argument($name, $args[0], 'string'), " \t\n\r"),
            'split' => $this->split($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'string')),
            'join' => implode($this->argument($name, $args[1], 'string'), array_map(Values::toString(...), $this->argument($name, $args[0], 'array'))),
            'replace' => $this->replace($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'string'), $this->argument($name, $args[2], 'string')),
            'contains' => str_contains($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'string')),
            'starts_with' => str_starts_with($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'string')),
            'ends_with' => str_ends_with($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'string')),
            // null when not found, rather than PHP's false or a -1
            'index_of' => ($position = strpos($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'string'))) === false ? null : $position,
            'repeat' => $this->repeat($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'int')),
            'chr' => $this->chr($this->argument($name, $args[0], 'int')),
            'ord' => $this->ord($this->argument($name, $args[0], 'string')),
            'to_int' => $this->toInt($args[0]),
            'to_string' => Values::toString($args[0]),
            // Strict, like ===
            'in_array' => in_array($args[0], $this->argument($name, $args[1], 'array'), true),
            'has_key' => array_key_exists(Values::arrayKey($args[1]), $this->argument($name, $args[0], 'array')),
            'keys' => array_keys($this->argument($name, $args[0], 'array')),
            'type_of' => get_debug_type($args[0]),
            // The program's own message, printed as is: it describes a location in the program's input, not here
            'error' => throw new GazLangError(Values::toString($args[0])),
            'read_file' => $this->readFile($this->argument($name, $args[0], 'string')),
            'write_file' => $this->writeFile($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'string')),
            'read_stdin' => stream_get_contents(STDIN),
            'args' => $this->args,
            default => throw new Exception("Unknown builtin: {$name}"),
        };
    }

    /**
     * Check a builtin argument has one of the allowed types
     *
     * @param  string  $builtin  The builtin name, for the error message
     * @param  mixed  $value  The argument
     * @param  string  ...$types  Allowed type names, as type_of() reports them
     * @return mixed The argument
     *
     * @throws Exception If the argument has another type
     */
    private function argument(string $builtin, $value, string ...$types)
    {
        if (! in_array(get_debug_type($value), $types, true)) {
            throw new Exception("{$builtin}() expects ".implode(' or ', $types).', got '.get_debug_type($value));
        }

        return $value;
    }

    /**
     * slice($x, $start, $length): part of a string or array, with PHP's substr/array_slice rules
     *
     * A negative start counts from the end, a negative length stops that many from the end.
     * Array string keys are kept, integer keys are renumbered from 0.
     *
     * @param  mixed  $value  A string or array
     * @param  int  $start  The first position
     * @param  int  $length  How many characters or elements to take
     * @return string|array The slice
     */
    private function slice($value, int $start, int $length): string|array
    {
        return is_array($this->argument('slice', $value, 'string', 'array'))
            ? array_slice($value, $start, $length)
            : substr($value, $start, $length);
    }

    /**
     * split($s, $separator): the pieces of $s between separators; an empty separator splits into characters
     *
     * @param  string  $string  The string to split
     * @param  string  $separator  What to split on
     * @return string[] The pieces (splitting "" on a separator gives [""], on "" gives [])
     */
    private function split(string $string, string $separator): array
    {
        if ($separator === '') {
            return $string === '' ? [] : str_split($string);
        }

        return explode($separator, $string);
    }

    /**
     * replace($s, $search, $replacement): $s with every occurrence of $search replaced
     *
     * @param  string  $string  The string to search
     * @param  string  $search  What to replace, not empty
     * @param  string  $replacement  What to put in its place
     *
     * @throws Exception If $search is empty, which has no sensible meaning
     */
    private function replace(string $string, string $search, string $replacement): string
    {
        if ($search === '') {
            throw new Exception('replace() cannot search for an empty string');
        }

        return str_replace($search, $replacement, $string);
    }

    /**
     * repeat($s, $count): $s repeated $count times
     *
     * @param  string  $string  The string to repeat
     * @param  int  $count  How many times, 0 or more
     *
     * @throws Exception If $count is negative
     */
    private function repeat(string $string, int $count): string
    {
        if ($count < 0) {
            throw new Exception("repeat() count must not be negative, got {$count}");
        }

        return str_repeat($string, $count);
    }

    /**
     * chr($byte): the one character string for a byte value
     *
     * @param  int  $byte  0 to 255
     *
     * @throws Exception If the value is outside 0 to 255
     */
    private function chr(int $byte): string
    {
        if ($byte < 0 || $byte > 255) {
            throw new Exception("chr() expects a byte value from 0 to 255, got {$byte}");
        }

        return chr($byte);
    }

    /**
     * ord($char): the byte value of a one character string
     *
     * @param  string  $char  Exactly one character (byte)
     *
     * @throws Exception If the string isn't exactly one character
     */
    private function ord(string $char): int
    {
        if (strlen($char) !== 1) {
            throw new Exception('ord() expects a one character string, got '.Lexer::quote($char));
        }

        return ord($char);
    }

    /**
     * to_int($x): an int as is, or a string of decimal digits with an optional leading minus
     *
     * @param  mixed  $value  The value to convert
     *
     * @throws Exception If the value is not an int or a string holding one that fits
     */
    private function toInt($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ($int = Lexer::parse_integer($value)) !== null) {
            return $int;
        }

        throw new Exception('to_int() cannot convert '.(is_string($value) ? Lexer::quote($value) : get_debug_type($value)));
    }

    /**
     * read_file($path): the contents of a file, relative paths resolved from the working directory
     *
     * @param  string  $path  The file path
     *
     * @throws Exception If the file can't be read
     */
    private function readFile(string $path): string
    {
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new Exception("Cannot read file: {$path}");
        }

        return $contents;
    }

    /**
     * write_file($path, $contents): create or overwrite a file, relative paths resolved from the working directory
     *
     * @param  string  $path  The file path
     * @param  string  $contents  What to write
     * @return null write_file has no result
     *
     * @throws Exception If the file can't be written
     */
    private function writeFile(string $path, string $contents)
    {
        // @: the failure is reported as a GazLang error instead of a PHP warning
        if (@file_put_contents($path, $contents) === false) {
            throw new Exception("Cannot write file: {$path}");
        }

        return null;
    }
}
