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
        'if' => 'IF',
        'else' => 'ELSE',
        'true' => 'BOOLEAN',
        'false' => 'BOOLEAN',
    ];

    /**
     * Constructor
     *
     * @param  string  $text  The source code to tokenize
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

        throw new Exception('Invalid character at position '.$this->pos.': '.$this->current_char);
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
     */
    public function integer(): int
    {
        $result = '';
        while ($this->current_char !== null && ctype_digit($this->current_char)) {
            $result .= $this->current_char;
            $this->advance();
        }

        return (int) $result;
    }

    /**
     * Parse a string literal enclosed in double quotes
     * Handles escape sequences like \n, \t, \", etc.
     *
     * @throws Exception
     */
    public function string(): string
    {
        // Skip the opening quote
        $this->advance();

        $result = '';
        $escape = false;

        while ($this->current_char !== null && ($this->current_char !== '"' || $escape)) {
            if ($escape) {
                // Handle escape sequences
                switch ($this->current_char) {
                    case 'n':
                        $result .= "\n";
                        break;
                    case 't':
                        $result .= "\t";
                        break;
                    case '"':
                        $result .= '"';
                        break;
                    case '\\':
                        $result .= '\\';
                        break;
                    default:
                        // For any other character, just add the character itself
                        $result .= $this->current_char;
                }
                $escape = false;
            } elseif ($this->current_char === '\\') {
                $escape = true;
            } else {
                $result .= $this->current_char;
            }

            $this->advance();
        }

        if ($this->current_char === null) {
            throw new Exception('Unterminated string literal');
        }

        // Skip the closing quote
        $this->advance();

        return $result;
    }

    /**
     * Return an identifier or reserved keyword
     */
    public function identifier(): Token
    {
        $result = '';
        while ($this->current_char !== null && (ctype_alnum($this->current_char) || $this->current_char === '_')) {
            $result .= $this->current_char;
            $this->advance();
        }

        // Check if the identifier is a reserved keyword
        $type = $this->reserved_keywords[strtolower($result)] ?? null;

        if ($type) {
            return new Token($type, $result);
        } else {
            throw new Exception("Unknown identifier: $result");
        }
    }

    /**
     * Return a variable identifier (starting with $)
     */
    public function var_identifier(): Token
    {
        $result = '';
        $result .= $this->current_char; // Add the $ sign
        $this->advance();

        // Variable names must start with a letter or underscore after the $
        if ($this->current_char === null || (! ctype_alpha($this->current_char) && $this->current_char !== '_')) {
            throw new Exception("Invalid variable name: $result");
        }

        while ($this->current_char !== null && (ctype_alnum($this->current_char) || $this->current_char === '_')) {
            $result .= $this->current_char;
            $this->advance();
        }

        return new Token(Token::VAR_IDENTIFIER, $result);
    }

    /**
     * Lexical analyzer (tokenizer)
     *
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

            if ($this->current_char === '"') {
                return new Token(Token::STRING, $this->string());
            }

            if ($this->current_char === '$') {
                return $this->var_identifier();
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

            // Logical operators
            if ($this->current_char === '!') {
                $this->advance();
                if ($this->current_char === '=') {
                    $this->advance();

                    return new Token(Token::NOT_EQUALS, '!=');
                }
                return new Token(Token::NOT, '!');
            }

            if ($this->current_char === '&' && $this->peek() === '&') {
                $this->advance(); // Skip first '&'
                $this->advance(); // Skip second '&'

                return new Token(Token::AND, '&&');
            }

            if ($this->current_char === '|' && $this->peek() === '|') {
                $this->advance(); // Skip first '|'
                $this->advance(); // Skip second '|'

                return new Token(Token::OR, '||');
            }

            if ($this->current_char === '=') {
                $this->advance();
                // Check for equality operator (==)
                if ($this->current_char === '=') {
                    $this->advance();

                    return new Token(Token::EQUALS, '==');
                }

                return new Token(Token::ASSIGN, '=');
            }

            if ($this->current_char === ';') {
                $this->advance();

                return new Token(Token::SEMICOLON, ';');
            }

            if ($this->current_char === '(') {
                $this->advance();

                return new Token(Token::LEFT_PAREN, '(');
            }

            if ($this->current_char === ')') {
                $this->advance();

                return new Token(Token::RIGHT_PAREN, ')');
            }

            if ($this->current_char === '{') {
                $this->advance();

                return new Token(Token::LEFT_BRACE, '{');
            }

            if ($this->current_char === '}') {
                $this->advance();

                return new Token(Token::RIGHT_BRACE, '}');
            }

            $this->error();
        }

        return new Token(Token::EOF, null);
    }

    /**
     * Peek at the next character without advancing
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
