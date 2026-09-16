<?php

namespace GazLang\Runtime;

use Exception;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;

/**
 * GazLang's value semantics: truthiness, printing, array keys, operators and indexing
 *
 * Values are plain PHP values: int, string, bool, null and array. Everything here is a
 * pure function of values, so any backend that runs GazLang (the interpreter now, a VM
 * later) gets identical behaviour. Errors are plain Exceptions; the interpreter adds
 * the source location.
 */
final class Values
{
    /**
     * Decide whether a value counts as true in conditions and logical operators
     *
     * Strings and arrays are true unless empty, null is false; everything else is C-like, true unless 0.
     *
     * @param  mixed  $value  The value to test
     */
    public static function isTruthy($value): bool
    {
        return match (true) {
            is_string($value) => $value !== '',
            is_array($value) => $value !== [],
            default => $value !== null && $value != 0,
        };
    }

    /**
     * Convert a value to the text echo prints and + concatenates
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
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif ($value === null) {
            return 'null';
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

        throw new Exception('Cannot convert '.get_debug_type($value).' to string');
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
            throw new Exception('Array keys must be int or string, got '.get_debug_type($key));
        }

        return $key;
    }

    /**
     * Apply a binary operator other than && and ||, which short-circuit and are left to the caller
     *
     * @param  Token  $op  The operator token (its value is used in error messages)
     * @param  mixed  $left  The left operand
     * @param  mixed  $right  The right operand
     * @return int|string|bool The result
     *
     * @throws Exception If the operator can't be applied to these values
     */
    public static function binary(Token $op, $left, $right): int|string|bool
    {
        $type = $op->type;

        // If either operand is a string, plus performs string concatenation
        if ($type === Token::PLUS && (is_string($left) || is_string($right))) {
            return self::toString($left).self::toString($right);
        }

        // Strict equality compares type and value as they are, before any conversion
        if ($type === Token::STRICT_EQUALS) {
            return $left === $right;
        } elseif ($type === Token::STRICT_NOT_EQUALS) {
            return $left !== $right;
        }

        // null only equals null; arithmetic and ordering on it are errors
        if ($left === null || $right === null) {
            return match ($type) {
                Token::EQUALS => $left === $right,
                Token::NOT_EQUALS => $left !== $right,
                default => throw new Exception("Cannot use {$op->value} on null"),
            };
        }

        // Arrays are equal when they have the same keys, in the same order, with identical values
        if (is_array($left) || is_array($right)) {
            return match ($type) {
                Token::EQUALS => $left === $right,
                Token::NOT_EQUALS => $left !== $right,
                default => throw new Exception("Cannot use {$op->value} on array"),
            };
        }

        // Everywhere else booleans act as 1/0, so true + 1 is 2 and true == 1
        $left = is_bool($left) ? (int) $left : $left;
        $right = is_bool($right) ? (int) $right : $right;

        if (in_array($type, [Token::PLUS, Token::MINUS, Token::MULTIPLY, Token::DIVIDE, Token::MODULO], true)) {
            return self::arithmetic($op, $left, $right);
        }

        // A string and an int only compare when the string holds an integer ("5" == 5);
        // any other string never equals an int, and ordering them is an error
        if (is_string($left) !== is_string($right)) {
            $int = Lexer::parse_integer(is_string($left) ? $left : $right);
            if ($int === null) {
                return match ($type) {
                    Token::EQUALS => false,
                    Token::NOT_EQUALS => true,
                    default => throw new Exception("Cannot use {$op->value} on string and int"),
                };
            }
            [$left, $right] = is_string($left) ? [$int, $right] : [$left, $int];
        }

        // Two strings compare byte by byte, so "1" != "01" and "10" < "9"; two ints numerically
        $cmp = is_string($left) ? strcmp($left, $right) : $left <=> $right;

        return match ($type) {
            Token::EQUALS => $cmp === 0,
            Token::NOT_EQUALS => $cmp !== 0,
            Token::LESS_THAN => $cmp < 0,
            Token::LESS_EQUALS => $cmp <= 0,
            Token::GREATER_THAN => $cmp > 0,
            Token::GREATER_EQUALS => $cmp >= 0,
            default => throw new Exception("Unknown operator: {$type}"),
        };
    }

    /**
     * Negate a value (unary -)
     *
     * @param  mixed  $value  The operand
     *
     * @throws Exception If the value isn't an int or bool, or negating it overflows
     */
    public static function negate($value): int
    {
        if (! is_int($value) && ! is_bool($value)) {
            throw new Exception('Cannot use - on '.get_debug_type($value));
        }
        if ($value === PHP_INT_MIN) {
            throw new Exception('Integer overflow');
        }

        return -(int) $value;
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
                throw new Exception('String positions must be int, got '.get_debug_type($index));
            }

            return $index >= 0 && $index < strlen($target) ? $target[$index] : null;
        }

        throw new Exception('Cannot use [] on '.get_debug_type($target));
    }

    /**
     * Apply + - * / % to two ints (booleans already converted)
     *
     * @param  Token  $op  The operator token
     * @param  mixed  $left  The left operand
     * @param  mixed  $right  The right operand
     *
     * @throws Exception On strings, division by zero or overflow
     */
    private static function arithmetic(Token $op, $left, $right): int
    {
        if (is_string($left) || is_string($right)) {
            throw new Exception("Cannot use {$op->value} on string");
        }
        if ($op->type === Token::DIVIDE && $right === 0) {
            throw new Exception('Division by zero');
        }
        if ($op->type === Token::MODULO && $right === 0) {
            throw new Exception('Modulo by zero');
        }

        $result = match ($op->type) {
            Token::PLUS => $left + $right,
            Token::MINUS => $left - $right,
            Token::MULTIPLY => $left * $right,
            // intdiv(PHP_INT_MIN, -1) throws ArithmeticError; its result wouldn't fit either
            Token::DIVIDE => $left === PHP_INT_MIN && $right === -1 ? PHP_INT_MAX + 1 : intdiv($left, $right),
            // The sign follows the left operand, as in PHP and C: -7 % 3 is -1
            Token::MODULO => $left % $right,
            default => throw new Exception("Unknown operator: {$op->type}"),
        };

        // PHP turns an int that overflows into a float, which GazLang has no type for
        if (! is_int($result)) {
            throw new Exception('Integer overflow');
        }

        return $result;
    }
}
