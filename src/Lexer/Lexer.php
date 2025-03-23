<?php

namespace GazLang\Lexer;

use Exception;

/**
 * Lexer class tokenizes input code into a stream of tokens
 */
class Lexer
{
    /**
     * @var string The source text to tokenize
     */
    private $text;
    
    /**
     * @var int Current position in the text
     */
    private $pos;
    
    /**
     * @var string|null Current character being processed
     */
    private $current_char;

    /**
     * @var array Keywords in the language
     */
    private $reserved_keywords = [
        'echo' => 'ECHO',
    ];
    
    /**
     * Constructor
     *
     * @param string $text The source code to tokenize
     */
    public function __construct(string $text)
    {
        $this->text = $text;
        $this->pos = 0;
        $this->current_char = strlen($text) > 0 ? $this->text[$this->pos] : null;
    }
    
    /**
     * Raise an error for invalid characters
     *
     * @throws Exception
     */
    public function error(): void
    {
        throw new Exception('Invalid character at position ' . $this->pos . ': ' . $this->current_char);
    }
    
    /**
     * Advance the position pointer and set the current character
     */
    public function advance(): void
    {
        $this->pos++;
        if ($this->pos > strlen($this->text) - 1) {
            $this->current_char = null;  // End of input
        } else {
            $this->current_char = $this->text[$this->pos];
        }
    }
    
    /**
     * Skip whitespace characters
     */
    public function skip_whitespace(): void
    {
        while ($this->current_char !== null && ctype_space($this->current_char)) {
            $this->advance();
        }
    }
    
    /**
     * Skip comments (// until end of line)
     */
    public function skip_comment(): void
    {
        while ($this->current_char !== null && $this->current_char !== "\n") {
            $this->advance();
        }
        
        if ($this->current_char === "\n") {
            $this->advance();
        }
    }
    
    /**
     * Return a (multidigit) integer from the input
     *
     * @return int
     */
    public function integer(): int
    {
        $result = '';
        while ($this->current_char !== null && ctype_digit($this->current_char)) {
            $result .= $this->current_char;
            $this->advance();
        }
        return (int)$result;
    }

    /**
     * Return an identifier or reserved keyword
     * 
     * @return Token
     */
    public function identifier(): Token
    {
        $result = '';
        while ($this->current_char !== null && (ctype_alnum($this->current_char) || $this->current_char === '_')) {
            $result .= $this->current_char;
            $this->advance();
        }
        
        // Check if the identifier is a reserved keyword
        $type = isset($this->reserved_keywords[strtolower($result)]) 
            ? $this->reserved_keywords[strtolower($result)] 
            : null;
            
        if ($type) {
            return new Token($type, $result);
        } else {
            throw new Exception("Unknown identifier: {$result}");
        }
    }
    
    /**
     * Lexical analyzer (tokenizer)
     *
     * @return Token
     * @throws Exception
     */
    public function get_next_token(): Token
    {
        while ($this->current_char !== null) {
            if (ctype_space($this->current_char)) {
                $this->skip_whitespace();
                continue;
            }
            
            if ($this->current_char === '/' && $this->peek() === '/') {
                $this->advance(); // Skip first '/'
                $this->advance(); // Skip second '/'
                $this->skip_comment();
                continue;
            }
            
            if (ctype_digit($this->current_char)) {
                return new Token(Token::INTEGER, $this->integer());
            }
            
            if (ctype_alpha($this->current_char)) {
                return $this->identifier();
            }
            
            if ($this->current_char === '+') {
                $this->advance();
                return new Token(Token::PLUS, '+');
            }
            
            if ($this->current_char === '-') {
                $this->advance();
                return new Token(Token::MINUS, '-');
            }
            
            if ($this->current_char === '*') {
                $this->advance();
                return new Token(Token::MULTIPLY, '*');
            }
            
            if ($this->current_char === '/') {
                $this->advance();
                return new Token(Token::DIVIDE, '/');
            }
            
            if ($this->current_char === ';') {
                $this->advance();
                return new Token(Token::SEMICOLON, ';');
            }
            
            $this->error();
        }
        
        return new Token(Token::EOF, null);
    }
    
    /**
     * Peek at the next character without advancing
     *
     * @return string|null
     */
    private function peek(): ?string
    {
        $peek_pos = $this->pos + 1;
        if ($peek_pos > strlen($this->text) - 1) {
            return null;
        }
        return $this->text[$peek_pos];
    }
} 