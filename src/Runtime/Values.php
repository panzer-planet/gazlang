<?php

namespace GazLang\Runtime;

use Exception;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;

/**
 * GazLang's value semantics: truthiness, printing, array keys, operators and indexing
 *
 * Values are plain PHP values: int, float (always finite), string, bool, null and array (a
 * GazLang list, always a PHP list), plus MapValue for a map, FunctionValue for a function,
 * ClassValue for a class and ObjectValue for an object. Everything here is a
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
     * @var (\Closure(ObjectValue, ClassValue, string): mixed)|null How the running backend calls a method, given the
     *                                                              object, the class whose version runs and the name:
     *                                                              toString() calls to_string() with it; set while a program runs
     */
    public static $call_method = null;

    /**
     * @var array<int, true> The objects being printed by toString(), by object id, so one that holds itself prints as Name {...}
     */
    private static $printing = [];

    /**
     * The name of a value's type, as type_of() reports it and errors describe it
     *
     * @param  mixed  $value  The value
     * @return string int, float, string, bool, null, list, map, function, class or object
     */
    public static function typeOf($value): string
    {
        return match (true) {
            is_array($value) => 'list',
            $value instanceof MapValue => 'map',
            $value instanceof FunctionValue => 'function',
            $value instanceof ObjectValue => 'object',
            $value instanceof ClassValue => 'class',
            default => get_debug_type($value),
        };
    }

    /**
     * Decide whether a value counts as true in conditions and logical operators
     *
     * Strings, lists and maps are true unless empty, null is false, functions, classes and objects are true; everything
     * else is C-like, true unless 0.
     *
     * @param  mixed  $value  The value to test
     */
    public static function isTruthy($value): bool
    {
        return match (true) {
            is_string($value) => $value !== '',
            is_array($value) => $value !== [],
            $value instanceof MapValue => $value->items !== [],
            is_object($value) => true,
            default => $value !== null && $value != 0,
        };
    }

    /**
     * Convert a value to the text echo prints and .. concatenates
     *
     * An object with a to_string() method prints what it returns, which must be a string;
     * one without prints its class and fields.
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
            return 'function '.$value->describe();
        } elseif (is_array($value)) {
            // Printed as a literal: [1, "a"]
            return '['.implode(', ', array_map(self::literal(...), $value)).']';
        } elseif ($value instanceof MapValue) {
            // {"key" => 1, 5 => 2}
            $parts = [];
            foreach ($value->items as $key => $item) {
                $parts[] = self::literal(MapValue::unkey($key)).' => '.self::literal($item);
            }

            return '{'.implode(', ', $parts).'}';
        } elseif ($value instanceof ObjectValue) {
            $definer = $value->class->methods['to_string'] ?? null;
            if ($definer === null) {
                return self::describeObject($value);
            }
            $call = self::$call_method ?? throw new Exception('Cannot call to_string: no program is running');
            $text = $call($value, $definer, 'to_string');
            if (! is_string($text)) {
                throw new Exception("{$definer->name}.to_string must return a string, got ".self::typeOf($text));
            }

            return $text;
        } elseif ($value instanceof ClassValue) {
            return "class {$value->name}";
        }

        throw new Exception('Cannot convert '.self::typeOf($value).' to string');
    }

    /**
     * An object printed as its class and the fields that are set: Account {#owner => "Werner", #balance => 75}
     *
     * Objects are handles, so one can hold itself; while it is being printed it prints as Account {...}.
     *
     * @param  ObjectValue  $object  The object
     */
    private static function describeObject(ObjectValue $object): string
    {
        $id = spl_object_id($object);
        if (isset(self::$printing[$id])) {
            return "{$object->class->name} {...}";
        }

        self::$printing[$id] = true;
        try {
            $parts = [];
            foreach ($object->class->fields as $field => $_) {
                if (array_key_exists($field, $object->fields)) {
                    $parts[] = "#{$field} => ".self::literal($object->fields[$field]);
                }
            }
        } finally {
            unset(self::$printing[$id]);
        }

        return "{$object->class->name} {".implode(', ', $parts).'}';
    }

    /**
     * A value as it appears inside a printed list or map: strings quoted, everything else as echo prints it
     *
     * @param  mixed  $value  The element or key
     */
    private static function literal($value): string
    {
        return is_string($value) ? Lexer::quote($value) : self::toString($value);
    }

    /**
     * Check a value can be used as a key or index: an int or string
     *
     * A list then needs an int; a map keeps "1" and 1 apart.
     *
     * @param  mixed  $key  The key value
     * @return int|string The key
     *
     * @throws Exception If the key is not an int or string
     */
    public static function arrayKey($key): int|string
    {
        if (! is_int($key) && ! is_string($key)) {
            throw new Exception('Keys must be int or string, got '.self::typeOf($key));
        }

        return $key;
    }

    /**
     * Apply a binary operator other than && and ||, which short-circuit and are left to the caller
     *
     * @param  Token  $op  The operator token (its value is used in error messages)
     * @param  mixed  $left  The left operand
     * @param  mixed  $right  The right operand
     * @return int|float|string|bool The result (<=> gives -1, 0 or 1)
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

        // null, lists, maps and functions only compare for equality (ints, floats, strings and bools are scalar)
        if (! is_scalar($left) || ! is_scalar($right)) {
            throw new Exception("Cannot use {$op->value} on ".self::typeOf(is_scalar($left) ? $right : $left));
        }

        // A bool is not a number: to_int(true) is the explicit way to get 1
        if (is_bool($left) || is_bool($right)) {
            throw new Exception("Cannot use {$op->value} on bool");
        }

        if (in_array($type, [Token::PLUS, Token::MINUS, Token::MULTIPLY, Token::DIVIDE, Token::MODULO], true)) {
            return self::arithmetic($op, $left, $right);
        }

        // A string never orders against a number: there is no conversion
        if (is_string($left) !== is_string($right)) {
            throw new Exception("Cannot use {$op->value} on string and ".self::typeOf(is_string($left) ? $right : $left));
        }

        // Two strings compare byte by byte, so "10" < "9"; numbers by value
        $cmp = is_string($left) ? strcmp($left, $right) : self::compare($left, $right);

        return match ($type) {
            Token::LESS_THAN => $cmp < 0,
            Token::LESS_EQUALS => $cmp <= 0,
            Token::GREATER_THAN => $cmp > 0,
            Token::GREATER_EQUALS => $cmp >= 0,
            // -1, 0 or 1 (strcmp may give any magnitude), for comparison functions
            Token::SPACESHIP => $cmp <=> 0,
            default => throw new Exception("Unknown operator: {$type}"),
        };
    }

    /**
     * Decide whether two values are equal (==), with no conversion between strings and numbers
     *
     * null only equals null, true only true. Numbers compare by value (1 == 1.0, exactly:
     * see compare()). Strings compare byte by byte, so "1" != "01", and a string never
     * equals a number or a bool. Lists are equal when their elements are equal in order by
     * this rule, maps when they have the same keys (in any order) with equal values. A list
     * never equals a map, even when both are empty. A function, class or object is equal only to
     * itself, except that two bound methods are equal when they bind the same method to the same object.
     *
     * @param  mixed  $left  One value
     * @param  mixed  $right  The other
     */
    public static function equals($left, $right): bool
    {
        // Identical values are always equal (there is no NAN, and named functions are interned)
        if ($left === $right) {
            return true;
        }

        if (is_array($left) && is_array($right)) {
            if (count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $i => $item) {
                if (! self::equals($item, $right[$i])) {
                    return false;
                }
            }

            return true;
        }

        if ($left instanceof MapValue && $right instanceof MapValue) {
            if (count($left->items) !== count($right->items)) {
                return false;
            }
            foreach ($left->items as $key => $item) {
                if (! array_key_exists($key, $right->items) || ! self::equals($item, $right->items[$key])) {
                    return false;
                }
            }

            return true;
        }

        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return self::compare($left, $right) === 0;
        }

        if ($left instanceof FunctionValue && $right instanceof FunctionValue && $left->class !== null) {
            return $left->receiver === $right->receiver && $left->class === $right->class && $left->name === $right->name;
        }

        return false;
    }

    /**
     * Order two numbers by value, exactly, like <=>
     *
     * PHP converts an int to a float to compare them, so 9007199254740993 == 9007199254740992.0
     * there; here an int and a float compare as the numbers they are, as in Python, Ruby and
     * Lua. A float with a fraction is below 2^52 in magnitude, where converting the int to a
     * float keeps their order; an integral float in int range compares as an int; one beyond
     * int range is beyond every int.
     *
     * @param  int|float  $left  One number
     * @param  int|float  $right  The other
     * @return int -1, 0 or 1
     */
    public static function compare(int|float $left, int|float $right): int
    {
        if (is_int($left) === is_int($right)) {
            return $left <=> $right;
        }

        [$int, $float, $sign] = is_int($left) ? [$left, $right, 1] : [$right, $left, -1];
        if (floor($float) !== $float) {
            return $sign * ((float) $int <=> $float);
        }
        // 2^63 as a float, the first value past PHP_INT_MAX; -2^63 is PHP_INT_MIN exactly
        if ($float >= 9223372036854775808.0) {
            return -$sign;
        }
        if ($float < -9223372036854775808.0) {
            return $sign;
        }

        return $sign * ($int <=> (int) $float);
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
        if (! is_int($value)) {
            throw new Exception('Cannot use - on '.self::typeOf($value));
        }
        if ($value === PHP_INT_MIN) {
            throw new Exception('Integer overflow');
        }

        return -$value;
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
     * Read an element of a list or map, or a character of a string
     *
     * A list index or string position must be in range and a map key must exist, unless
     * $quiet (the left side of ??), which reads them as null.
     *
     * @param  mixed  $target  The list, map or string
     * @param  mixed  $index  The index, key or position
     * @param  bool  $quiet  Whether a missing index or key reads as null instead of an error
     * @return mixed The element, a one character string, or null
     *
     * @throws Exception If the target can't be indexed, the index has the wrong type, or it is missing
     */
    public static function index($target, $index, bool $quiet = false)
    {
        if (is_array($target)) {
            if (! is_int($index)) {
                // A float or null is the same error as for a map; only a string is list-specific
                throw new Exception('List indexes must be int, got '.self::typeOf(self::arrayKey($index)));
            }
            if (array_key_exists($index, $target)) {
                return $target[$index];
            }

            return $quiet ? null : throw new Exception("Index out of range: {$index}");
        } elseif ($target instanceof MapValue) {
            // Ints and names are stored as they are; MapValue::key() encodes the rest
            $key = is_int($index) || (is_string($index) && $index !== '' && $index[0] > '9') ? $index : MapValue::key(self::arrayKey($index));
            if (array_key_exists($key, $target->items)) {
                return $target->items[$key];
            }

            return $quiet ? null : throw self::undefinedKey($index);
        } elseif (is_string($target)) {
            if (! is_int($index)) {
                throw new Exception('String positions must be int, got '.self::typeOf($index));
            }

            if ($index >= 0 && $index < strlen($target)) {
                return $target[$index];
            }

            return $quiet ? null : throw new Exception("Index out of range: {$index}");
        }

        throw new Exception('Cannot use [] on '.self::typeOf($target));
    }

    /**
     * Read a member of an object: a field's value, or a method bound to the object
     *
     * A declared field that was never set is an error, unless $quiet (the left side of ??),
     * which reads it as null. A name the class doesn't declare is always an error, and so is
     * the constructor _, which only runs by constructing (or as ##_ in a child's constructor).
     *
     * @param  mixed  $target  The object
     * @param  string  $name  The member name
     * @param  bool  $quiet  Whether a field that isn't set reads as null instead of an error
     *
     * @throws Exception If the target isn't an object, or the member isn't there
     */
    public static function property($target, string $name, bool $quiet = false)
    {
        if (! $target instanceof ObjectValue) {
            throw new Exception('Cannot use . on '.self::typeOf($target));
        }
        if (array_key_exists($name, $target->fields)) {
            return $target->fields[$name];
        }
        $class = $target->class;
        if (isset($class->fields[$name])) {
            return $quiet ? null : throw self::propertyNotSet($target, $name);
        }
        if (isset($class->methods[$name]) && $name !== '_') {
            return FunctionValue::bound($target, $class->methods[$name], $name);
        }

        throw self::undefinedMember($class, $name);
    }

    /**
     * Read a field that must be set, as a compound update ($obj.n += 1) reads the value it combines with
     *
     * Stricter than property(): a method is an error, as assigning to it would be.
     *
     * @param  mixed  $target  The object
     * @param  string  $name  The field name
     *
     * @throws Exception If the target isn't an object, or the name isn't a field that is set
     */
    public static function propertyExisting($target, string $name)
    {
        if (! $target instanceof ObjectValue) {
            throw new Exception('Cannot use . on '.self::typeOf($target));
        }
        if (array_key_exists($name, $target->fields)) {
            return $target->fields[$name];
        }
        self::checkField($target, $name);

        throw self::propertyNotSet($target, $name);
    }

    /**
     * Check an object's class declares a field of that name, which a write path can go through
     *
     * @param  ObjectValue  $object  The object
     * @param  string  $name  The member name
     *
     * @throws Exception If it is a method, the constructor, or not declared
     */
    private static function checkField(ObjectValue $object, string $name): void
    {
        $class = $object->class;
        if (isset($class->fields[$name])) {
            return;
        }
        if ($name !== '_' && isset($class->methods[$name])) {
            throw new Exception("Cannot assign to method {$class->methods[$name]->name}.{$name}");
        }

        throw self::undefinedMember($class, $name);
    }

    /**
     * The error for reading a declared field that was never set
     *
     * @param  ObjectValue  $object  The object
     * @param  string  $name  The field name
     */
    public static function propertyNotSet(ObjectValue $object, string $name): Exception
    {
        return new Exception("Property {$name} of {$object->class->name} is not set");
    }

    /**
     * The error for a member a class doesn't have, or the constructor used as a member
     *
     * @param  ClassValue  $class  The class
     * @param  string  $name  The member name
     */
    public static function undefinedMember(ClassValue $class, string $name): Exception
    {
        return new Exception($name === '_' && isset($class->methods['_'])
            ? "Cannot use the constructor of {$class->name} as a member"
            : "{$class->name} has no member {$name}");
    }

    /**
     * Write to a variable or an element or field of one: =, a compound assignment, or ++/--
     *
     * Shared by the interpreter (variables by name) and the VM (by slot), so both create,
     * check and fail in exactly the same way. The path is a list of steps: a key for an index
     * (null to append), or a PropertyStep for a field. Plain assignment ($op null) may create
     * the variable, a map's last key and a declared field; a list index must already exist, and $l[] = v appends.
     * A compound assignment (a binary operator token, + for +=) or ++/-- (an INCREMENT or
     * DECREMENT token) combines with the current value, so the variable and every key must
     * exist. Missing keys along the way are never created, and nothing is written if
     * computing the new value fails.
     *
     * Lists and maps are values: a list is written in place through a PHP reference, which
     * only changes this variable's copy, and each map on the path is cloned first (cheap,
     * see MapValue), so appending and setting stay linear. Objects are handles, written in
     * place: to write through #, pass a table holding the object.
     *
     * @param  array  $table  The variables, by reference: locals or globals
     * @param  int|string  $slot  The variable's key in $table
     * @param  string  $name  The variable's name, for error messages
     * @param  array  $keys  The steps, outermost first: evaluated index keys, null for an append ([]), or PropertyStep
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
        // Whether $key is in $container; a missing one is only an error once something needs it
        // (building the exception up front would cost a stack trace on every new key)
        $exists = true;
        $missing_key = '';
        // The object whose field $missing_key isn't set, or null when it is a map key
        $missing_object = null;
        foreach ($keys as $next_key) {
            if (! $exists) {
                throw $missing_object === null ? self::undefinedKey($missing_key) : self::propertyNotSet($missing_object, $missing_key);
            }
            $container = &$container[$key];
            if ($next_key instanceof PropertyStep) {
                if (! $container instanceof ObjectValue) {
                    throw new Exception('Cannot use . on '.self::typeOf($container));
                }
                $object = $container;
                self::checkField($object, $next_key->name);
                // A handle: the object's fields are written in place, nothing is copied
                $container = &$object->fields;
                $key = $next_key->name;
                if (! array_key_exists($key, $container)) {
                    [$exists, $missing_key, $missing_object] = [false, $key, $object];
                }

                continue;
            }
            if (is_array($container)) {
                if ($next_key === null) {
                    $container[] = $value;

                    return [null, $value];
                }
                if (! is_int($next_key)) {
                    throw new Exception('List indexes must be int, got '.self::typeOf($next_key));
                }
                if (! array_key_exists($next_key, $container)) {
                    throw new Exception("Index out of range: {$next_key}");
                }
                $key = $next_key;
            } elseif ($container instanceof MapValue) {
                if ($next_key === null) {
                    throw new Exception('Cannot append to a map');
                }
                $container = clone $container;
                $container = &$container->items;
                $key = is_int($next_key) || ($next_key !== '' && $next_key[0] > '9') ? $next_key : MapValue::key($next_key);
                if (! array_key_exists($key, $container)) {
                    [$exists, $missing_key, $missing_object] = [false, $next_key, null];
                }
            } else {
                throw new Exception('Cannot use [] on '.self::typeOf($container));
            }
        }

        if (! $exists && $op !== null) {
            throw $missing_object === null ? self::undefinedKey($missing_key) : self::propertyNotSet($missing_object, $missing_key);
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
     * Stricter than index(): the target must be a list or map, not a string.
     *
     * @param  mixed  $target  The list or map
     * @param  mixed  $index  The index or key
     * @return mixed The element
     *
     * @throws Exception If the target is not a list or map, or the index or key is missing
     */
    public static function indexExisting($target, $index)
    {
        if (! is_array($target) && ! $target instanceof MapValue) {
            throw new Exception('Cannot use [] on '.self::typeOf($target));
        }

        return self::index($target, $index);
    }

    /**
     * The error for a map key that isn't there
     *
     * @param  int|string  $key  The key, shown quoted when it is a string so "1" and 1 differ
     */
    public static function undefinedKey(int|string $key): Exception
    {
        return new Exception('Undefined key: '.(is_string($key) ? Lexer::quote($key) : $key));
    }

    /**
     * Apply + - * / % to two numbers
     *
     * Two ints give an int, and a float on either side gives a float, except that / always
     * gives a float (6 / 2 is 3.0; an int beyond 2^53 loses precision on the way, as in
     * Python). % is for ints only. Nothing else silently overflows: an int result that
     * doesn't fit and a float result that is infinite are both errors.
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
