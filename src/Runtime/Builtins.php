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
        'min' => 2,
        'max' => 2,
        'to_string' => 1,
        'in_array' => 2,
        'has_key' => 2,
        'keys' => 1,
        'values' => 1,
        'type_of' => 1,
        'is_a' => 2,
        'class_of' => 1,
        'fields' => 1,
        'error' => 1,
        'exit' => [0, 1],
        'read_file' => 1,
        'write_file' => 2,
        'file_exists' => 1,
        'real_path' => 1,
        'cwd' => 0,
        'print' => 1,
        'print_error' => 1,
        'read_stdin' => 0,
        'args' => 0,
        'builtins' => 0,
    ];

    /**
     * @var resource|null Where print_error() writes, standard error unless a test swaps it: a
     *                    write to the stream behind standard output isn't caught by output
     *                    buffering, which is how the tests read what a program printed
     */
    public static $error_stream = null;

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
     * @param  string  $what  What is called, as the message starts: "Function add", "Method Point.area", "Class Point"
     * @param  int|array{0: int, 1: int}  $arity  A count, or [fewest, most]
     * @param  int  $argc  How many arguments were passed
     */
    public static function arityError(string $what, int|array $arity, int $argc): ?string
    {
        if (self::fitsArity($arity, $argc)) {
            return null;
        }
        [$fewest, $most] = is_int($arity) ? [$arity, $arity] : $arity;
        $expected = $fewest === $most ? $fewest : "{$fewest} to {$most}";

        return "{$what} expects {$expected} arguments, {$argc} given";
    }

    /**
     * Whether an argument count fits an arity, cheaply: the hot path of a call on a value
     *
     * @param  int|array{0: int, 1: int}  $arity  A count, or [fewest, most]
     * @param  int  $argc  How many arguments were passed
     */
    public static function fitsArity(int|array $arity, int $argc): bool
    {
        return is_int($arity) ? $argc === $arity : ($argc >= $arity[0] && $argc <= $arity[1]);
    }

    /**
     * An arity as [fewest, most]
     *
     * @param  int|array{0: int, 1: int}  $arity  A count, or [fewest, most] with optional parameters
     * @return array{0: int, 1: int} The bounds
     */
    public static function bounds(int|array $arity): array
    {
        return is_int($arity) ? [$arity, $arity] : $arity;
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
            'len' => match (true) {
                is_array($this->argument($name, $args[0], 'list', 'map', 'string')) => count($args[0]),
                is_string($args[0]) => strlen($args[0]),
                default => count($args[0]->items),
            },
            'slice' => $this->slice($args[0], $this->argument($name, $args[1], 'int'), $this->argument($name, $args[2] ?? null, 'int', 'null')),
            'lower' => strtolower($this->argument($name, $args[0], 'string')),
            'upper' => strtoupper($this->argument($name, $args[0], 'string')),
            // The same whitespace the lexer skips: space, tab, newline, carriage return
            'trim' => trim($this->argument($name, $args[0], 'string'), " \t\n\r"),
            'split' => $this->split($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'string')),
            'join' => implode($this->argument($name, $args[1], 'string'), array_map(Values::toString(...), $this->argument($name, $args[0], 'list'))),
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
            'min' => $this->extreme($name, $args[0], $args[1]) <= 0 ? $args[0] : $args[1],
            'max' => $this->extreme($name, $args[0], $args[1]) >= 0 ? $args[0] : $args[1],
            'to_string' => Values::toString($args[0]),
            'in_array' => $this->inArray($args[0], $this->argument($name, $args[1], 'list')),
            'has_key' => $this->hasKey($this->argument($name, $args[0], 'list', 'map'), Values::arrayKey($args[1])),
            // A list's keys are its indexes, which foreach over a list uses
            'keys' => is_array($this->argument($name, $args[0], 'list', 'map')) ? array_keys($args[0]) : $args[0]->keys(),
            // A list's values are the list itself, in order; a map's are its values in insertion order
            'values' => is_array($this->argument($name, $args[0], 'list', 'map')) ? $args[0] : array_values($args[0]->items),
            'type_of' => Values::typeOf($args[0]),
            'is_a' => $this->isA($args[0], $this->argument($name, $args[1], 'class')),
            // The class itself, so it can be compared (== is identity, so match dispatches on it),
            // passed to is_a, called to construct another, or printed
            'class_of' => $this->argument($name, $args[0], 'object')->class,
            'fields' => $this->fields($this->argument($name, $args[0], 'object')),
            // The program's own message, printed as is: it describes a location in the program's input,
            // not here. The interpreter still records where error() was called, for catch.
            // A string is the message of an Error; any other value is thrown as it is
            'error' => throw is_string($args[0]) ? new GazLangError($args[0], null, null, false) : GazLangError::thrown($args[0]),
            'exit' => throw new ExitSignal($this->exitCode($this->argument($name, $args[0] ?? 0, 'int'))),
            'read_file' => $this->readFile($this->argument($name, $args[0], 'string')),
            'write_file' => $this->writeFile($this->argument($name, $args[0], 'string'), $this->argument($name, $args[1], 'string')),
            'file_exists' => self::resolve($this->argument($name, $args[0], 'string')) !== false,
            'real_path' => self::resolve($this->argument($name, $args[0], 'string'))
                ?: throw new Exception('No such file or directory: '.Lexer::quote($args[0])),
            'cwd' => getcwd() ?: throw new Exception('Cannot get the working directory'),
            // Printing, as echo does it but without the newline: any value, converted the same way
            'print' => $this->write(false, $args[0]),
            'print_error' => $this->write(true, $args[0]),
            'read_stdin' => stream_get_contents(STDIN),
            'args' => $this->args,
            // This table, as a map: what the running runtime has, which is what a compiler
            // running on it checks calls against
            'builtins' => new MapValue(self::ARITIES),
            default => throw new Exception("Unknown builtin: {$name}"),
        };
    }

    /**
     * print($value) and print_error($value): write the value as echo would, without a newline
     *
     * @param  bool  $error  Whether to write to standard error rather than standard output
     * @param  mixed  $value  The value, converted as echo converts it
     * @return null Nothing, as write_file() gives nothing
     */
    private function write(bool $error, $value): null
    {
        $text = Values::toString($value);
        // Printing never fails, as echo never does: a program that has lost its output stream
        // has nowhere to report that anyway. write_file() is the one that says so.
        if ($error) {
            fwrite(self::$error_stream ?? STDERR, $text);
        } else {
            echo $text;
        }

        return null;
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
     * is_a($value, $class): whether the value is an object whose class is the class or extends it
     *
     * @param  mixed  $value  Any value; only objects are ever one
     * @param  ClassValue  $class  The class
     */
    private function isA($value, ClassValue $class): bool
    {
        return $value instanceof ObjectValue && $value->class->isA($class);
    }

    /**
     * slice($x, $start, $length = to the end): part of a string or list, with PHP's substr/array_slice rules
     *
     * A negative start counts from the end, a negative length stops that many from the end.
     *
     * @param  mixed  $value  A string or list
     * @param  int  $start  The first position
     * @param  int|null  $length  How many characters or elements to take, or null for the rest
     * @return string|array The slice
     */
    private function slice($value, int $start, ?int $length): string|array
    {
        return is_array($this->argument('slice', $value, 'string', 'list'))
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
        if (is_bool($value)) {
            return (int) $value;
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
        if (is_int($value) || is_float($value) || is_bool($value)) {
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
     * Order the arguments of min() or max() as < does: two numbers by value, or two strings byte by byte
     *
     * Anything else, a number with a string included, is an error rather than a guess. On a tie
     * both return the first argument, so min(1, 1.0) is 1.
     *
     * @param  string  $name  min or max, for the error
     * @param  mixed  $a  The first argument
     * @param  mixed  $b  The second argument
     * @return int -1, 0 or 1
     *
     * @throws Exception If they aren't two numbers or two strings
     */
    private function extreme(string $name, $a, $b): int
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return Values::compare($a, $b);
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        throw new Exception("{$name}() expects two numbers or two strings, got ".Values::typeOf($a).' and '.Values::typeOf($b));
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
     * Check an exit code is one the operating system can carry
     *
     * @param  int  $code  The code
     *
     * @throws Exception If it is outside 0 to 255
     */
    private function exitCode(int $code): int
    {
        if ($code < 0 || $code > 255) {
            throw new Exception("exit() expects a code from 0 to 255, got {$code}");
        }

        return $code;
    }

    /**
     * has_key($x, $key): whether a map has the key, or a list the index
     *
     * @param  array|MapValue  $target  The list or map
     * @param  int|string  $key  The key or index
     *
     * @throws Exception If a list is given a string index
     */
    private function hasKey(array|MapValue $target, int|string $key): bool
    {
        if ($target instanceof MapValue) {
            return array_key_exists(MapValue::key($key), $target->items);
        }
        if (! is_int($key)) {
            throw new Exception('List indexes must be int, got string');
        }

        return array_key_exists($key, $target);
    }

    /**
     * in_array($value, $list): whether the list has an element equal (==) to the value
     *
     * @param  mixed  $value  The value
     * @param  array  $list  The list
     */
    private function inArray($value, array $list): bool
    {
        // An identical element is always equal, and only a number, list or map can be equal to
        // an element that isn't identical (1 == 1.0, [1] == [1.0], two maps with the same
        // entries), so after the strict scan only those need comparing
        if (in_array($value, $list, true)) {
            return true;
        }
        if (! is_int($value) && ! is_float($value) && ! is_array($value) && ! $value instanceof MapValue) {
            return false;
        }
        foreach ($list as $item) {
            $comparable = match (true) {
                is_array($value) => is_array($item),
                $value instanceof MapValue => $item instanceof MapValue,
                default => (is_int($item) || is_float($item)) && gettype($item) !== gettype($value),
            };
            if ($comparable && Values::equals($value, $item)) {
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
     * fields($object): the fields that are set, by name without the #, in layout order (the
     * parent's first, each class's in declaration order), as echo prints them. A map is a value,
     * so writing to it doesn't change the object.
     *
     * @param  ObjectValue  $object  The object
     */
    private function fields(ObjectValue $object): MapValue
    {
        $fields = [];
        foreach ($object->class->fields as $field => $_) {
            if (array_key_exists($field, $object->fields)) {
                $fields[$field] = $object->fields[$field];
            }
        }

        return new MapValue($fields);
    }

    /**
     * real_path($path): the absolute path with every symlink, "." and ".." resolved, as realpath(3)
     * gives it, relative paths resolved from the working directory; file_exists($path) is whether there is one
     *
     * @param  string  $path  The path
     * @return string|false The real path, or false when nothing is there. "" is false, where PHP's
     *                      realpath() gives the working directory, and so is a NUL byte, which no
     *                      name can hold and a C string would cut short
     */
    private static function resolve(string $path): string|false
    {
        return $path === '' || str_contains($path, "\0") ? false : realpath($path);
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
