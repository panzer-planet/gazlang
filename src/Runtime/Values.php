<?php

namespace GazLang\Runtime;

use Exception;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;

/**
 * GazLang's value semantics: truthiness, printing, array keys, operators and indexing
 *
 * Values are plain PHP values: int, float (always finite), string, bool, null and array, plus
 * FunctionValue for a function. Everything here is a
 * pure function of values, so any backend that runs GazLang (the interpreter now, a VM
 * later) gets identical behaviour. Errors are plain Exceptions; the interpreter adds
 * the source location.
 */
final class Values
{
    /**
     * Deepest allowed function call nesting in either backend, so runaway recursion is a
     * GazLang error instead of PHP running out of memory (a fatal error nothing can catch)
     */
    public const MAX_CALL_DEPTH = 10000;

    /**
     * The name of a value's type, as type_of() reports it and errors describe it
     *
     * @param  mixed  $value  The value
     * @return string int, float, string, bool, null, array or function
     */
    public static function typeOf($value): string
    {
        return $value instanceof FunctionValue ? 'function' : get_debug_type($value);
    }

    /**
     * Decide whether a value counts as true in conditions and logical operators
     *
     * Strings and arrays are true unless empty, null is false, functions are true; everything
     * else is C-like, true unless 0.
     *
     * @param  mixed  $value  The value to test
     */
    public static function isTruthy($value): bool
    {
        return match (true) {
            is_string($value) => $value !== '',
            is_array($value) => $value !== [],
            is_object($value) => true,
            default => $value !== null && $value != 0,
        };
    }

    /**
     * Convert a value to the text echo prints and .. concatenates
     *
     * @param  mixed  $value  The value to convert
     * @return string The string representation
     *
     * @throws Exception If the value is not a GazLang value
     */
    public static function toString($value): string
    {
        if (is_string($value)) {
            return $value;
        } elseif (is_int($value)) {
            return (string) $value;
        } elseif (is_float($value)) {
            return Lexer::format_float($value);
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif ($value === null) {
            return 'null';
        } elseif ($value instanceof FunctionValue) {
            return "function {$value->name}";
        } elseif (is_array($value)) {
            // Printed as a literal: [1, "a"] for lists, ["key" => 1, 5 => 2] otherwise
            $is_list = array_is_list($value);
            $parts = [];
            foreach ($value as $key => $item) {
                $item = is_string($item) ? Lexer::quote($item) : self::toString($item);
                $parts[] = $is_list ? $item : (is_string($key) ? Lexer::quote($key) : $key).' => '.$item;
            }

            return '['.implode(', ', $parts).']';
        }

        throw new Exception('Cannot convert '.self::typeOf($value).' to string');
    }

    /**
     * Check a value can be used as an array key
     *
     * PHP stores numeric string keys like "1" as the integer 1, so they name the same element.
     *
     * @param  mixed  $key  The key value
     * @return int|string The key
     *
     * @throws Exception If the key is not an int or string
     */
    public static function arrayKey($key): int|string
    {
        if (! is_int($key) && ! is_string($key)) {
            throw new Exception('Array keys must be int or string, got '.self::typeOf($key));
        }

        return $key;
    }

    /**
     * Apply a binary operator other than && and ||, which short-circuit and are left to the caller
     *
     * @param  Token  $op  The operator token (its value is used in error messages)
     * @param  mixed  $left  The left operand
     * @param  mixed  $right  The right operand
     * @return int|float|string|bool The result
     *
     * @throws Exception If the operator can't be applied to these values
     */
    public static function binary(Token $op, $left, $right): int|float|string|bool
    {
        $type = $op->type;

        // Concatenation converts both sides the way echo does
        if ($type === Token::CONCAT) {
            return self::toString($left).self::toString($right);
        }

        if ($type === Token::EQUALS) {
            return self::equals($left, $right);
        } elseif ($type === Token::NOT_EQUALS) {
            return ! self::equals($left, $right);
        }

        // null, arrays and functions only compare for equality
        foreach ([$left, $right] as $operand) {
            if ($operand === null || is_array($operand) || is_object($operand)) {
                throw new Exception("Cannot use {$op->value} on ".self::typeOf($operand));
            }
        }

        // Everywhere else booleans act as 1/0, so true + 1 is 2 and true == 1
        $left = is_bool($left) ? (int) $left : $left;
        $right = is_bool($right) ? (int) $right : $right;

        if (in_array($type, [Token::PLUS, Token::MINUS, Token::MULTIPLY, Token::DIVIDE, Token::MODULO], true)) {
            return self::arithmetic($op, $left, $right);
        }

        // A string never orders against a number: there is no conversion
        if (is_string($left) !== is_string($right)) {
            $other = self::typeOf(is_string($left) ? $right : $left);

            throw new Exception("Cannot use {$op->value} on string and {$other}");
        }

        // Two strings compare byte by byte, so "10" < "9"; numbers numerically
        $cmp = is_string($left) ? strcmp($left, $right) : $left <=> $right;

        return match ($type) {
            Token::LESS_THAN => $cmp < 0,
            Token::LESS_EQUALS => $cmp <= 0,
            Token::GREATER_THAN => $cmp > 0,
            Token::GREATER_EQUALS => $cmp >= 0,
            default => throw new Exception("Unknown operator: {$type}"),
        };
    }

    /**
     * Decide whether two values are equal (==), with no conversion between strings and numbers
     *
     * null only equals null. Numbers compare by value (1 == 1.0) with booleans as 1/0.
     * Strings compare byte by byte, so "1" != "01", and a string never equals a number.
     * Arrays are equal when they have the same keys in the same order and their
     * elements are equal by this rule. A function is equal only to itself.
     *
     * @param  mixed  $left  One value
     * @param  mixed  $right  The other
     */
    public static function equals($left, $right): bool
    {
        if (is_array($left) && is_array($right)) {
            if (array_keys($left) !== array_keys($right)) {
                return false;
            }
            foreach ($left as $key => $item) {
                if (! self::equals($item, $right[$key])) {
                    return false;
                }
            }

            return true;
        }

        $left = is_bool($left) ? (int) $left : $left;
        $right = is_bool($right) ? (int) $right : $right;
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left == $right;
        }

        return $left === $right;
    }

    /**
     * Negate a value (unary -)
     *
     * @param  mixed  $value  The operand
     *
     * @throws Exception If the value isn't a number or bool, or negating it overflows
     */
    public static function negate($value): int|float
    {
        if (is_float($value)) {
            return -$value;
        }
        if (! is_int($value) && ! is_bool($value)) {
            throw new Exception('Cannot use - on '.self::typeOf($value));
        }
        if ($value === PHP_INT_MIN) {
            throw new Exception('Integer overflow');
        }

        return -(int) $value;
    }

    /**
     * Add or subtract one (++ and --); numbers only
     *
     * @param  mixed  $value  The current value
     * @param  Token  $op  The INCREMENT or DECREMENT token
     *
     * @throws Exception If the value isn't an int or float, or the result overflows
     */
    public static function step($value, Token $op): int|float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new Exception("Cannot use {$op->value} on ".self::typeOf($value));
        }

        return self::binary(new Token($op->type === Token::INCREMENT ? Token::PLUS : Token::MINUS, $op->value), $value, 1);
    }

    /**
     * Read an element of an array, or a character of a string
     *
     * @param  mixed  $target  The array or string
     * @param  mixed  $index  The key or position
     * @return mixed The element, a one character string, or null if the key or position does not exist
     *
     * @throws Exception If the target can't be indexed, or the index has the wrong type
     */
    public static function index($target, $index)
    {
        if (is_array($target)) {
            return $target[self::arrayKey($index)] ?? null;
        } elseif (is_string($target)) {
            if (! is_int($index)) {
                throw new Exception('String positions must be int, got '.self::typeOf($index));
            }

            return $index >= 0 && $index < strlen($target) ? $target[$index] : null;
        }

        throw new Exception('Cannot use [] on '.self::typeOf($target));
    }

    /**
     * Write to a variable or an element of one: =, a compound assignment, or ++/--
     *
     * Shared by the interpreter (variables by name) and the VM (by slot), so both create,
     * check and fail in exactly the same way. Plain assignment ($op null) may create the
     * variable and the last key. A compound assignment (a binary operator token, + for +=)
     * or ++/-- (an INCREMENT or DECREMENT token) combines with the current value, so the
     * variable and every key must exist: reading a missing key as null would make
     * $a["n"] += "x" quietly give "nullx". Missing keys along the way are never created,
     * and nothing is written if computing the new value fails.
     *
     * Arrays are values: writing in place through a PHP reference only changes this
     * variable's copy, and appending stays linear.
     *
     * @param  array  $table  The variables, by reference: locals or globals
     * @param  int|string  $slot  The variable's key in $table
     * @param  string  $name  The variable's name, for error messages
     * @param  array  $keys  The evaluated index keys, outermost first; null for an append ([])
     * @param  Token|null  $op  How to combine with the current value, or null to replace it
     * @param  mixed  $value  The right hand side (unused for ++ and --)
     * @return array{0: mixed, 1: mixed} The old value (null if there was none) and the new value
     *
     * @throws Exception If the variable or a key along the way is missing, or the operation fails
     */
    public static function store(array &$table, int|string $slot, string $name, array $keys, ?Token $op, $value): array
    {
        if (($keys !== [] || $op !== null) && ! array_key_exists($slot, $table)) {
            throw new Exception("Undefined variable: {$name}");
        }

        $container = &$table;
        $key = $slot;
        foreach ($keys as $i => $next_key) {
            if ($i > 0 && ! array_key_exists($key, $container)) {
                throw new Exception("Undefined key: {$key}");
            }
            $container = &$container[$key];
            if (! is_array($container)) {
                throw new Exception('Cannot use [] on '.self::typeOf($container));
            }
            if ($next_key === null) {
                $container[] = $value;

                return [null, $value];
            }
            $key = $next_key;
        }

        if ($op !== null && ! array_key_exists($key, $container)) {
            throw new Exception("Undefined key: {$key}");
        }

        $old = $container[$key] ?? null;
        $new = match (true) {
            $op === null => $value,
            $op->type === Token::INCREMENT || $op->type === Token::DECREMENT => self::step($old, $op),
            default => self::binary($op, $old, $value),
        };
        $container[$key] = $new;

        return [$old, $new];
    }

    /**
     * Read an element that must exist, as a compound update (+=, ++) reads the value it combines with
     *
     * Stricter than index(): the target must be an array and the key must be there, with the
     * same messages as the interpreter's own updates.
     *
     * @param  mixed  $target  The array
     * @param  mixed  $index  The key
     * @return mixed The element
     *
     * @throws Exception If the target is not an array or the key is missing
     */
    public static function indexExisting($target, $index)
    {
        if (! is_array($target)) {
            throw new Exception('Cannot use [] on '.self::typeOf($target));
        }
        $key = self::arrayKey($index);
        if (! array_key_exists($key, $target)) {
            throw new Exception("Undefined key: {$key}");
        }

        return $target[$key];
    }

    /**
     * Apply + - * / % to two numbers (booleans already converted)
     *
     * Two ints give an int, and a float on either side gives a float. / follows PHP: an
     * exact int division stays an int (6 / 2 is 3), any other gives a float (7 / 2 is
     * 3.5). % is for ints only. Nothing silently overflows: an int result that doesn't
     * fit and a float result that is infinite are both errors.
     *
     * @param  Token  $op  The operator token
     * @param  mixed  $left  The left operand
     * @param  mixed  $right  The right operand
     *
     * @throws Exception On strings, % on floats, division by zero or overflow
     */
    private static function arithmetic(Token $op, $left, $right): int|float
    {
        if (is_string($left) || is_string($right)) {
            throw new Exception("Cannot use {$op->value} on string");
        }
        if ($op->type === Token::MODULO && (is_float($left) || is_float($right))) {
            throw new Exception('Cannot use % on float');
        }
        if ($right == 0 && ($op->type === Token::DIVIDE || $op->type === Token::MODULO)) {
            throw new Exception($op->type === Token::DIVIDE ? 'Division by zero' : 'Modulo by zero');
        }

        $result = match ($op->type) {
            Token::PLUS => $left + $right,
            Token::MINUS => $left - $right,
            Token::MULTIPLY => $left * $right,
            // Always a float, as in Python 3 and Lua 5.3; intdiv() divides ints
            Token::DIVIDE => (float) $left / $right,
            // The sign follows the left operand, as in PHP and C: -7 % 3 is -1
            Token::MODULO => $left % $right,
            default => throw new Exception("Unknown operator: {$op->type}"),
        };

        // PHP turns an int that overflows into a float, and a float that overflows into INF
        if (is_int($left) && is_int($right) && $op->type !== Token::DIVIDE && ! is_int($result)) {
            throw new Exception('Integer overflow');
        }
        if (is_float($result) && ! is_finite($result)) {
            throw new Exception('Float overflow');
        }

        return $result;
    }
}
