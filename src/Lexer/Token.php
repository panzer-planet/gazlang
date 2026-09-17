<?php

namespace GazLang\Lexer;

/**
 * Token class represents a lexical token in the GazLang programming language
 */
class Token
{
    // Token types
    public const INTEGER = 'INTEGER';

    public const FLOAT = 'FLOAT';  // Float literal: 1.5, 1e10

    public const STRING = 'STRING';  // String literal without interpolation

    public const STRING_START = 'STRING_START';  // Text of an interpolated string before its first interpolation

    public const STRING_MIDDLE = 'STRING_MIDDLE';  // Text between two interpolations

    public const STRING_END = 'STRING_END';  // Text after the last interpolation, up to the closing quote

    public const PLUS = 'PLUS';

    public const MINUS = 'MINUS';

    public const MULTIPLY = 'MULTIPLY';

    public const DIVIDE = 'DIVIDE';

    public const MODULO = 'MODULO';  // Remainder operator (%)

    public const BIT_AND = 'BIT_AND';  // Bitwise and (&)

    public const BIT_OR = 'BIT_OR';  // Bitwise or (|)

    public const BIT_XOR = 'BIT_XOR';  // Bitwise exclusive or (^)

    public const SHIFT_LEFT = 'SHIFT_LEFT';  // Left shift (<<)

    public const SHIFT_RIGHT = 'SHIFT_RIGHT';  // Right shift (>>), keeping the sign

    public const BIT_NOT = 'BIT_NOT';  // Bitwise not (~), two's complement

    public const SEMICOLON = 'SEMICOLON';

    public const ECHO = 'ECHO';  // Echo keyword

    public const LEFT_PAREN = 'LEFT_PAREN';  // Left parenthesis '('

    public const RIGHT_PAREN = 'RIGHT_PAREN';  // Right parenthesis ')'

    public const VAR_IDENTIFIER = 'VAR_IDENTIFIER';  // Local variable identifier (starting with $)

    public const GLOBAL_VAR_IDENTIFIER = 'GLOBAL_VAR_IDENTIFIER';  // Global variable identifier (starting with @)

    public const IDENTIFIER = 'IDENTIFIER';  // Bare name, e.g. a function name

    public const COMMA = 'COMMA';  // Comma ','

    public const LEFT_BRACKET = 'LEFT_BRACKET';  // Left square bracket '['

    public const RIGHT_BRACKET = 'RIGHT_BRACKET';  // Right square bracket ']'

    public const DOUBLE_ARROW = 'DOUBLE_ARROW';  // Key/value separator '=>' in array literals

    public const ASSIGN = 'ASSIGN';  // Assignment operator (=)

    public const PLUS_ASSIGN = 'PLUS_ASSIGN';  // +=

    public const MINUS_ASSIGN = 'MINUS_ASSIGN';  // -=

    public const MULTIPLY_ASSIGN = 'MULTIPLY_ASSIGN';  // *=

    public const DIVIDE_ASSIGN = 'DIVIDE_ASSIGN';  // /=

    public const MODULO_ASSIGN = 'MODULO_ASSIGN';  // %=

    public const BIT_AND_ASSIGN = 'BIT_AND_ASSIGN';  // &=

    public const BIT_OR_ASSIGN = 'BIT_OR_ASSIGN';  // |=

    public const BIT_XOR_ASSIGN = 'BIT_XOR_ASSIGN';  // ^=

    public const SHIFT_LEFT_ASSIGN = 'SHIFT_LEFT_ASSIGN';  // <<=

    public const SHIFT_RIGHT_ASSIGN = 'SHIFT_RIGHT_ASSIGN';  // >>=

    public const CONCAT = 'CONCAT';  // String concatenation (..)

    public const CONCAT_ASSIGN = 'CONCAT_ASSIGN';  // ..=

    public const INCREMENT = 'INCREMENT';  // ++

    public const DECREMENT = 'DECREMENT';  // --

    public const EOF = 'EOF';  // End of file

    public const IF = 'IF';  // If keyword

    public const ELSE = 'ELSE';  // Else keyword

    public const WHILE = 'WHILE';  // While keyword

    public const FOR = 'FOR';  // For keyword

    public const FOREACH = 'FOREACH';  // Foreach keyword

    public const AS = 'AS';  // As keyword, in foreach

    public const BREAK = 'BREAK';  // Break keyword

    public const CONTINUE = 'CONTINUE';  // Continue keyword

    public const FN = 'FN';  // fn keyword, declaring a function

    public const FUNCTION = 'FUNCTION';  // function, reserved: functions are declared with fn

    public const RETURN = 'RETURN';  // Return keyword

    public const NULL = 'NULL';  // Null literal

    public const DELETE = 'DELETE';  // Delete keyword, removing an element of a list or map

    public const INCLUDE = 'INCLUDE';  // Include keyword

    public const TRY = 'TRY';  // Try keyword

    public const CATCH = 'CATCH';  // Catch keyword

    public const FINALLY = 'FINALLY';  // Finally keyword

    public const TRUE = 'TRUE';  // Boolean literal true

    public const FALSE = 'FALSE';  // Boolean literal false

    public const LEFT_BRACE = 'LEFT_BRACE';  // Left curly brace '{'

    public const RIGHT_BRACE = 'RIGHT_BRACE';  // Right curly brace '}'

    public const EQUALS = 'EQUALS';  // Equality operator (==)

    public const NOT_EQUALS = 'NOT_EQUALS';  // Inequality operator (!=)

    public const LESS_THAN = 'LESS_THAN';  // Less than operator (<)

    public const LESS_EQUALS = 'LESS_EQUALS';  // Less than or equal operator (<=)

    public const SPACESHIP = 'SPACESHIP';  // Three-way comparison (<=>), -1, 0 or 1

    public const GREATER_THAN = 'GREATER_THAN';  // Greater than operator (>)

    public const GREATER_EQUALS = 'GREATER_EQUALS';  // Greater than or equal operator (>=)

    public const AND = 'AND';  // Logical and operator (&&)

    public const OR = 'OR';  // Logical or operator (||)

    public const COALESCE = 'COALESCE';  // Null coalescing operator (??)

    public const COALESCE_ASSIGN = 'COALESCE_ASSIGN';  // Null coalescing assignment (??=)

    public const QUESTION = 'QUESTION';  // The ? of a ternary

    public const COLON = 'COLON';  // The : of a ternary

    public const ARROW = 'ARROW';  // The -> of an anonymous function

    public const NOT = 'NOT';  // Logical not operator (!)

    public const HASH = 'HASH';  // # alone, the object a method runs on

    public const HASH_IDENTIFIER = 'HASH_IDENTIFIER';  // #name, a member of the object a method runs on

    public const PARENT = 'PARENT';  // ## alone, not allowed yet

    public const PARENT_IDENTIFIER = 'PARENT_IDENTIFIER';  // ##name, the parent class's version of a method

    public const PROPERTY = 'PROPERTY';  // .name, a member of an object: $user.name

    public const CLASS_KEYWORD = 'CLASS';  // class keyword (a constant can't be named CLASS)

    public const EXTENDS = 'EXTENDS';  // extends keyword

    public const ABSTRACT = 'ABSTRACT';  // abstract keyword

    public const INTERFACE = 'INTERFACE';  // Reserved

    public const IMPLEMENTS = 'IMPLEMENTS';  // Reserved

    public const FINAL = 'FINAL';  // Reserved

    public const PUBLIC = 'PUBLIC';  // Reserved

    public const PRIVATE = 'PRIVATE';  // Reserved

    public const PROTECTED = 'PROTECTED';  // Reserved

    /**
     * @var string The token type
     */
    public $type;

    /**
     * @var mixed The token value
     */
    public $value;

    /**
     * @var int|null The line the token starts on, set by the lexer
     */
    public $line;

    /**
     * Constructor
     *
     * @param  string  $type  The token type
     * @param  mixed  $value  The token value
     */
    public function __construct(string $type, $value)
    {
        $this->type = $type;
        $this->value = $value;
    }

    /**
     * The token as one line of `gazlang --tokens` output: LINE TYPE VALUE
     *
     * String values (including the parts of interpolated strings) are quoted as literals and integers written as digits; every other
     * value is the source text, which never contains spaces. EOF has no value. The
     * self-hosted lexer must print exactly this, so the two can be diffed.
     */
    public function __toString(): string
    {
        $value = match (true) {
            $this->type === self::EOF => '',
            $this->type === self::FLOAT => ' '.Lexer::format_float($this->value),
            in_array($this->type, [self::STRING, self::STRING_START, self::STRING_MIDDLE, self::STRING_END], true) => ' '.Lexer::quote($this->value),
            default => " {$this->value}",
        };

        return "{$this->line} {$this->type}{$value}";
    }
}
