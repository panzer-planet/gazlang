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
     * Builtin function names mapped to their parameter counts: an int, or [fewest, most]
     * for builtins with optional parameters
     */
    public const ARITIES = [
        'len' => 1,
        'slice' => [2, 3],
        'lower' => 1,
        'upper' => 1,
        'trim' => 1,
        'split' => 2,
        'join' => 2,
        'replace' => 3,
        'contains' => 2,
        'starts_with' => 2,
        'ends_with' => 2,
        'index_of' => [2, 3],
        'repeat' => 2,
        'chr' => 1,
        'ord' => 1,
        'to_int' => 1,
        'to_float' => 1,
        'floor' => 1,
        'ceil' => 1,
        'round' => [1, 2],
        'abs' => 1,
        'intdiv' => 2,
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
     * Describe a call with the wrong number of arguments, or null if the count fits the arity
     *
     * The parser uses this for calls by name and both backends for calls on function values.
     *
     * @param  string  $name  The function name
     * @param  int|array{0: int, 1: int}  $arity  A count, or [fewest, most]
     * @param  int  $argc  How many arguments were passed
     */
    public static function arityError(string $name, int|array $arity, int $argc): ?string
    {
        [$fewest, $most] = is_int($arity) ? [$arity, $arity] : $arity;
        if ($argc >= $fewest && $argc <= $most) {
            return null;
        }
        $expected = $fewest === $most ? $fewest : "{$fewest} to {$most}";

        return "Function {$name} expects {$expected} arguments, {$argc} given";
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
            'slice' => $this->slice($args[0], $this->argument($name, $args[1], 'int'), $this->argument($name, $args[2] ?? null, 'int', 'null')),
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
            'index_of' => $this->indexOf($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'string'), $this->argument($name, array_key_exists(2, $args) ? $args[2] : 0, 'int')),
            'repeat' => $this->repeat($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'int')),
            'chr' => $this->chr($this->argument($name, $args[0], 'int')),
            'ord' => $this->ord($this->argument($name, $args[0], 'string')),
            'to_int' => $this->toInt($args[0]),
            'to_float' => $this->toFloat($args[0]),
            // floor, ceil and round return floats, as in PHP; to_int() makes an int of the result
            'floor' => floor($this->argument($name, $args[0], 'int', 'float')),
            'ceil' => ceil($this->argument($name, $args[0], 'int', 'float')),
            // PHP's round: halves away from zero (round(2.5) is 3.0), to a number of decimal places
            // (round(1.005, 2) is 1.01, correcting for 1.005 being stored as 1.00499...), or to tens,
            // hundreds... with a negative precision (round(1234, -2) is 1200.0)
            'round' => round($this->argument($name, $args[0], 'int', 'float'), $this->argument($name, array_key_exists(1, $args) ? $args[1] : 0, 'int')),
            'abs' => $this->abs($this->argument($name, $args[0], 'int', 'float')),
            'intdiv' => $this->intdiv($this->argument($name, $args[0], 'int'), $this->argument($name, $args[1], 'int')),
            'to_string' => Values::toString($args[0]),
            'in_array' => $this->inArray($args[0], $this->argument($name, $args[1], 'array')),
            'has_key' => array_key_exists(Values::arrayKey($args[1]), $this->argument($name, $args[0], 'array')),
            'keys' => array_keys($this->argument($name, $args[0], 'array')),
            'type_of' => Values::typeOf($args[0]),
            // The program's own message, printed as is: it describes a location in the program's input,
            // not here. The interpreter still records where error() was called, for catch.
            'error' => throw new GazLangError(Values::toString($args[0]), null, null, false),
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
        if (! in_array(Values::typeOf($value), $types, true)) {
            throw new Exception("{$builtin}() expects ".implode(' or ', $types).', got '.Values::typeOf($value));
        }

        return $value;
    }

    /**
     * slice($x, $start, $length = to the end): part of a string or array, with PHP's substr/array_slice rules
     *
     * A negative start counts from the end, a negative length stops that many from the end.
     * Array string keys are kept, integer keys are renumbered from 0.
     *
     * @param  mixed  $value  A string or array
     * @param  int  $start  The first position
     * @param  int|null  $length  How many characters or elements to take, or null for the rest
     * @return string|array The slice
     */
    private function slice($value, int $start, ?int $length): string|array
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
     * index_of($s, $needle, $offset = 0): the position of the first $needle at or after $offset, or null
     *
     * A negative offset counts from the end of $s, as in PHP's strpos.
     *
     * @param  string  $string  The string to search
     * @param  string  $needle  What to find, not empty
     * @param  int  $offset  Where to start searching
     * @return int|null The position, or null when not found (rather than PHP's false or a -1)
     *
     * @throws Exception If $needle is empty (it would be found anywhere, which hides bugs such
     *                   as searching for the "" that char_at() returns past the end), or $offset is outside $s
     */
    private function indexOf(string $string, string $needle, int $offset): ?int
    {
        if ($needle === '') {
            throw new Exception('index_of() cannot search for an empty string');
        }
        if ($offset > strlen($string) || $offset < -strlen($string)) {
            throw new Exception("index_of() offset {$offset} is outside the string");
        }
        $position = strpos($string, $needle, $offset);

        return $position === false ? null : $position;
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
        // Truncated toward zero, when the result fits: -2^63 <= value < 2^63
        if (is_float($value) && $value >= -9.2233720368547758E+18 && $value < 9.2233720368547758E+18) {
            return (int) $value;
        }

        throw new Exception('to_int() cannot convert '.match (true) {
            is_string($value) => Lexer::quote($value),
            is_float($value) => Lexer::format_float($value),
            default => Values::typeOf($value),
        });
    }

    /**
     * to_float($x): a number as a float, or a string holding a GazLang number literal (with optional minus)
     *
     * @param  mixed  $value  The value to convert
     *
     * @throws Exception If the value is not a number or a string holding one that fits
     */
    private function toFloat($value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && ($number = Lexer::parse_number($value)) !== null) {
            return (float) $number;
        }
        // Integer digits too large for an int are still a fine float: "99999999999999999999" is 1.0E+20
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value)) {
            return (float) $value;
        }

        throw new Exception('to_float() cannot convert '.(is_string($value) ? Lexer::quote($value) : Values::typeOf($value)));
    }

    /**
     * abs($x): the absolute value, an int for an int and a float for a float
     *
     * @param  int|float  $value  The number
     *
     * @throws Exception If the value is the smallest int, whose absolute value doesn't fit
     */
    private function abs(int|float $value): int|float
    {
        if ($value === PHP_INT_MIN) {
            throw new Exception('Integer overflow');
        }

        return abs($value);
    }

    /**
     * intdiv($a, $b): integer division, truncated toward zero, for when / would give a float
     *
     * @param  int  $left  The dividend
     * @param  int  $right  The divisor
     *
     * @throws Exception On division by zero, or intdiv(smallest int, -1), which doesn't fit
     */
    private function intdiv(int $left, int $right): int
    {
        if ($right === 0) {
            throw new Exception('Division by zero');
        }
        if ($left === PHP_INT_MIN && $right === -1) {
            throw new Exception('Integer overflow');
        }

        return intdiv($left, $right);
    }

    /**
     * in_array($value, $array): whether the array has an element equal (==) to the value
     *
     * @param  mixed  $value  The value
     * @param  array  $array  The array
     */
    private function inArray($value, array $array): bool
    {
        foreach ($array as $item) {
            if (Values::equals($value, $item)) {
                return true;
            }
        }

        return false;
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
