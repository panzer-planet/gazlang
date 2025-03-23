<?php

namespace GazLang\Lexer;

/**
 * Token class represents a lexical token in the GazLang programming language
 */
class Token
{
    // Token types
    public const INTEGER = 'INTEGER';
    public const PLUS = 'PLUS';
    public const MINUS = 'MINUS';
    public const MULTIPLY = 'MULTIPLY';
    public const DIVIDE = 'DIVIDE';
    public const SEMICOLON = 'SEMICOLON';
    public const ECHO = 'ECHO';  // Echo keyword
    public const EOF = 'EOF';  // End of file
    
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
     * @param string $type  The token type
     * @param mixed  $value The token value
     */
    public function __construct(string $type, $value)
    {
        $this->type = $type;
        $this->value = $value;
    }
    
    /**
     * String representation of the token
     *
     * @return string
     */
    public function __toString(): string
    {
        return "Token({$this->type}, {$this->value})";
    }
} 