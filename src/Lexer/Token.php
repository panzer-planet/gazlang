<?php

namespace GazLang\Lexer;

/**
 * Token class represents a lexical token in the GazLang programming language
 */
class Token
{
    // Token types
    public const INTEGER = 'INTEGER';

    public const STRING = 'STRING';  // String literal

    public const PLUS = 'PLUS';

    public const MINUS = 'MINUS';

    public const MULTIPLY = 'MULTIPLY';

    public const DIVIDE = 'DIVIDE';

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

    public const EOF = 'EOF';  // End of file

    public const IF = 'IF';  // If keyword

    public const ELSE = 'ELSE';  // Else keyword

    public const WHILE = 'WHILE';  // While keyword

    public const FOR = 'FOR';  // For keyword

    public const BREAK = 'BREAK';  // Break keyword

    public const CONTINUE = 'CONTINUE';  // Continue keyword

    public const FUNCTION = 'FUNCTION';  // Function keyword

    public const RETURN = 'RETURN';  // Return keyword

    public const NULL = 'NULL';  // Null literal

    public const TRUE = 'TRUE';  // Boolean literal true

    public const FALSE = 'FALSE';  // Boolean literal false

    public const LEFT_BRACE = 'LEFT_BRACE';  // Left curly brace '{'

    public const RIGHT_BRACE = 'RIGHT_BRACE';  // Right curly brace '}'

    public const EQUALS = 'EQUALS';  // Equality operator (==)

    public const NOT_EQUALS = 'NOT_EQUALS';  // Inequality operator (!=)

    public const STRICT_EQUALS = 'STRICT_EQUALS';  // Strict equality operator (===), no type conversion

    public const STRICT_NOT_EQUALS = 'STRICT_NOT_EQUALS';  // Strict inequality operator (!==)

    public const LESS_THAN = 'LESS_THAN';  // Less than operator (<)

    public const LESS_EQUALS = 'LESS_EQUALS';  // Less than or equal operator (<=)

    public const GREATER_THAN = 'GREATER_THAN';  // Greater than operator (>)

    public const GREATER_EQUALS = 'GREATER_EQUALS';  // Greater than or equal operator (>=)

    public const AND = 'AND';  // Logical and operator (&&)

    public const OR = 'OR';  // Logical or operator (||)

    public const NOT = 'NOT';  // Logical not operator (!)

    /**
     * @var string The token type
     */
    public $type;

    /**
     * @var mixed The token value
     */
    public $value;

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
     * String representation of the token
     */
    public function __toString(): string
    {
        return "Token({$this->type}, {$this->value})";
    }
}
