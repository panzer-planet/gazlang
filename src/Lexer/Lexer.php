<?php

namespace GazLang\Lexer;

use GazLang\GazLangError;

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
     * @var int The line of the current character, starting at 1
     */
    private $line = 1;

    /**
     * Single character escapes in string literals: the character after the backslash, and what it stands for
     */
    private const ESCAPES = [
        'n' => "\n",
        't' => "\t",
        'r' => "\r",
        'v' => "\v",
        'f' => "\f",
        'e' => "\e",
        '0' => "\0",
        '\\' => '\\',
        '"' => '"',
    ];

    /**
     * @var array Keywords in the language
     */
    private $reserved_keywords = [
        'echo' => 'ECHO',
        'if' => 'IF',
        'else' => 'ELSE',
        'while' => 'WHILE',
        'for' => 'FOR',
        'break' => 'BREAK',
        'continue' => 'CONTINUE',
        'function' => 'FUNCTION',
        'return' => 'RETURN',
        'null' => 'NULL',
        'include' => 'INCLUDE',
        'true' => 'TRUE',
        'false' => 'FALSE',
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
     * @throws GazLangError
     */
    public function error(): never
    {
        throw new GazLangError("Unexpected character '{$this->current_char}'", null, $this->line);
    }

    /**
     * Advance the position pointer and set the current character
     */
    public function advance(): void
    {
        if ($this->current_char === "\n") {
            $this->line++;
        }
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
        while ($this->current_char !== null && self::is_space($this->current_char)) {
            $this->advance();
        }
    }

    /**
     * Skip a comment, from // to the end of the line
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
     * @throws GazLangError If the literal runs into letters (12abc) or doesn't fit in an int
     */
    public function integer(): int
    {
        $result = '';
        while ($this->current_char !== null && self::is_digit($this->current_char)) {
            $result .= $this->current_char;
            $this->advance();
        }

        if ($this->current_char !== null && (self::is_alpha($this->current_char) || $this->current_char === '_')) {
            throw new GazLangError('Invalid integer literal: '.$result.$this->read_word(), null, $this->line);
        }

        return self::parse_integer($result) ?? throw new GazLangError("Integer literal too large: {$result}", null, $this->line);
    }

    /**
     * Read letters, digits and underscores from the current character on
     */
    private function read_word(): string
    {
        $result = '';
        while ($this->current_char !== null && (self::is_alnum($this->current_char) || $this->current_char === '_')) {
            $result .= $this->current_char;
            $this->advance();
        }

        return $result;
    }

    /**
     * Parse a string of decimal digits with an optional leading minus, as GazLang writes integers
     *
     * Shared with the interpreter (to_int, and comparing strings with ints) so every
     * place agrees on what an integer string is.
     *
     * @param  string  $digits  The text to parse
     * @return int|null The integer, or null if the text isn't one or doesn't fit in an int
     */
    public static function parse_integer(string $digits): ?int
    {
        if (! preg_match('/^-?[0-9]+$/', $digits)) {
            return null;
        }

        // (int) saturates on overflow, so the digits only survive a round trip if they fit
        $normalized = preg_replace(['/^(-?)0+(?=[0-9])/', '/^-0$/'], ['$1', '0'], $digits);

        return (string) (int) $digits === $normalized ? (int) $digits : null;
    }

    /**
     * Whether a character is whitespace: space, tab, newline or carriage return
     *
     * The lexer's character classes are ASCII and spelled out rather than ctype_*, so
     * lib/chars.gaz can match them exactly: ctype_space also accepts vertical tab and
     * form feed, which GazLang strings can't express, and ctype_alpha depends on the
     * locale (on macOS it accepts Latin-1 letters such as byte 233).
     *
     * @param  string  $char  One character
     */
    public static function is_space(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
    }

    /**
     * Whether a character is an ASCII digit, 0 to 9
     *
     * @param  string  $char  One character
     */
    public static function is_digit(string $char): bool
    {
        return strlen($char) === 1 && str_contains('0123456789', $char);
    }

    /**
     * Whether a character is an ASCII letter, a to z or A to Z
     *
     * @param  string  $char  One character
     */
    public static function is_alpha(string $char): bool
    {
        return strlen($char) === 1 && str_contains('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $char);
    }

    /**
     * Whether a character is an ASCII letter or digit
     *
     * @param  string  $char  One character
     */
    public static function is_alnum(string $char): bool
    {
        return self::is_alpha($char) || self::is_digit($char);
    }

    /**
     * Write a string as a GazLang string literal, the inverse of string()
     *
     * Used wherever strings are shown as source: printed arrays, syntax errors, generated
     * code and --tokens. Backslash, quote and the named control characters use their
     * escapes, other control bytes (and NUL, so a following digit can't make it look
     * octal) use \xHH, and everything else, including UTF-8, is written as is.
     *
     * @param  string  $value  The string
     */
    public static function quote(string $value): string
    {
        $named = array_flip(array_diff_key(self::ESCAPES, ['0' => true]));

        return '"'.preg_replace_callback(
            '/[\x00-\x1F\x7F"\\\\]/',
            fn ($match) => isset($named[$match[0]]) ? '\\'.$named[$match[0]] : sprintf('\\x%02X', ord($match[0])),
            $value
        ).'"';
    }

    /**
     * Parse a string literal enclosed in double quotes
     *
     * Escapes: \n \t \r \v \f \e \0 \\ \", \xHH for a byte, and \u{H} (1 to 6 hex
     * digits) for a Unicode code point written as UTF-8. Anything else after a
     * backslash is an error, including \0 followed by a digit, which is an octal
     * escape in PHP and C.
     *
     * @throws GazLangError
     */
    public function string(): string
    {
        // Skip the opening quote; an unterminated string is reported where it starts
        $start_line = $this->line;
        $this->advance();

        $result = '';
        while ($this->current_char !== null && $this->current_char !== '"') {
            if ($this->current_char === '\\') {
                $this->advance();
                if ($this->current_char === null) {
                    break;
                }
                $result .= $this->escape();
            } else {
                $result .= $this->current_char;
                $this->advance();
            }
        }

        if ($this->current_char === null) {
            throw new GazLangError('Unterminated string', null, $start_line);
        }

        // Skip the closing quote
        $this->advance();

        return $result;
    }

    /**
     * Read the escape after a backslash in a string literal, and return what it stands for
     *
     * @throws GazLangError If the escape is unknown or malformed
     */
    private function escape(): string
    {
        $char = $this->current_char;
        $this->advance();

        if ($char === 'x') {
            $hex = $this->read_hex(2);
            if (strlen($hex) !== 2) {
                throw new GazLangError("Invalid escape \\x{$hex}: expected two hex digits", null, $this->line);
            }

            return chr(hexdec($hex));
        }

        if ($char === 'u') {
            $hex = $this->current_char === '{' ? $this->read_braced_hex() : null;
            if ($hex === null) {
                throw new GazLangError('Invalid escape \\u: expected \\u{...} with 1 to 6 hex digits', null, $this->line);
            }
            $code_point = hexdec($hex);
            if ($code_point > 0x10FFFF || ($code_point >= 0xD800 && $code_point <= 0xDFFF)) {
                throw new GazLangError("Invalid escape \\u{{$hex}}: not a Unicode code point", null, $this->line);
            }

            return self::utf8($code_point);
        }

        if ($char === '0' && $this->current_char !== null && self::is_digit($this->current_char)) {
            throw new GazLangError("Octal escapes are not supported: \\0{$this->current_char} (use \\x)", null, $this->line);
        }

        return self::ESCAPES[$char] ?? throw new GazLangError("Unknown escape sequence \\{$char} in string", null, $this->line);
    }

    /**
     * Read up to $max hex digits
     *
     * @param  int  $max  The most digits to read
     */
    private function read_hex(int $max): string
    {
        $hex = '';
        while (strlen($hex) < $max && $this->current_char !== null && ctype_xdigit($this->current_char)) {
            $hex .= $this->current_char;
            $this->advance();
        }

        return $hex;
    }

    /**
     * Read {H} with 1 to 6 hex digits, starting at the {
     *
     * @return string|null The hex digits, or null if the braces or digits are malformed
     */
    private function read_braced_hex(): ?string
    {
        $this->advance();
        $hex = $this->read_hex(7);
        if ($this->current_char !== '}' || $hex === '' || strlen($hex) > 6) {
            return null;
        }
        $this->advance();

        return $hex;
    }

    /**
     * Encode a Unicode code point as UTF-8
     *
     * @param  int  $code_point  0 to 0x10FFFF, excluding surrogates
     */
    private static function utf8(int $code_point): string
    {
        if ($code_point < 0x80) {
            return chr($code_point);
        } elseif ($code_point < 0x800) {
            return chr(0xC0 | ($code_point >> 6)).chr(0x80 | ($code_point & 0x3F));
        } elseif ($code_point < 0x10000) {
            return chr(0xE0 | ($code_point >> 12)).chr(0x80 | (($code_point >> 6) & 0x3F)).chr(0x80 | ($code_point & 0x3F));
        }

        return chr(0xF0 | ($code_point >> 18)).chr(0x80 | (($code_point >> 12) & 0x3F))
            .chr(0x80 | (($code_point >> 6) & 0x3F)).chr(0x80 | ($code_point & 0x3F));
    }

    /**
     * Return a reserved keyword, or an IDENTIFIER token for any other bare name
     */
    public function identifier(): Token
    {
        $result = $this->read_word();

        return new Token($this->reserved_keywords[strtolower($result)] ?? Token::IDENTIFIER, $result);
    }

    /**
     * Return a local ($name) or global (@name) variable identifier
     */
    public function var_identifier(): Token
    {
        $type = $this->current_char === '@' ? Token::GLOBAL_VAR_IDENTIFIER : Token::VAR_IDENTIFIER;
        $result = $this->current_char; // Keep the sigil as part of the name
        $this->advance();

        // Variable names must start with a letter or underscore after the $; the whole
        // bad name is shown, so $1abc reads as one mistake rather than a lone $
        if ($this->current_char === null || (! self::is_alpha($this->current_char) && $this->current_char !== '_')) {
            throw new GazLangError('Invalid variable name: '.$result.$this->read_word(), null, $this->line);
        }

        return new Token($type, $result.$this->read_word());
    }

    /**
     * Lexical analyzer (tokenizer): skip whitespace and comments, then read one token
     *
     * @throws GazLangError If the source has an invalid character, name or string
     */
    public function get_next_token(): Token
    {
        while ($this->current_char !== null) {
            if (self::is_space($this->current_char)) {
                $this->skip_whitespace();
            } elseif ($this->current_char === '/' && $this->peek() === '/') {
                $this->skip_comment();
            } else {
                break;
            }
        }

        $line = $this->line;
        $token = $this->scan_token();
        $token->line = $line;

        return $token;
    }

    /**
     * Read the token starting at the current character, which is not whitespace or a comment
     *
     * @throws GazLangError
     */
    private function scan_token(): Token
    {
        if ($this->current_char !== null) {
            if (self::is_digit($this->current_char)) {
                return new Token(Token::INTEGER, $this->integer());
            }

            if ($this->current_char === '"') {
                return new Token(Token::STRING, $this->string());
            }

            if ($this->current_char === '$' || $this->current_char === '@') {
                return $this->var_identifier();
            }

            if (self::is_alpha($this->current_char) || $this->current_char === '_') {
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

            if ($this->current_char === '=') {
                $this->advance();
                if ($this->current_char === '>') {
                    $this->advance();

                    return new Token(Token::DOUBLE_ARROW, '=>');
                }

                // Check for equality operators (== and ===)
                if ($this->current_char === '=') {
                    $this->advance();
                    if ($this->current_char === '=') {
                        $this->advance();

                        return new Token(Token::STRICT_EQUALS, '===');
                    }

                    return new Token(Token::EQUALS, '==');
                }

                return new Token(Token::ASSIGN, '=');
            }

            if ($this->current_char === '!') {
                $this->advance();
                if ($this->current_char === '=') {
                    $this->advance();
                    if ($this->current_char === '=') {
                        $this->advance();

                        return new Token(Token::STRICT_NOT_EQUALS, '!==');
                    }

                    return new Token(Token::NOT_EQUALS, '!=');
                }

                return new Token(Token::NOT, '!');
            }

            if ($this->current_char === '<') {
                $this->advance();
                if ($this->current_char === '=') {
                    $this->advance();

                    return new Token(Token::LESS_EQUALS, '<=');
                }

                return new Token(Token::LESS_THAN, '<');
            }

            if ($this->current_char === '>') {
                $this->advance();
                if ($this->current_char === '=') {
                    $this->advance();

                    return new Token(Token::GREATER_EQUALS, '>=');
                }

                return new Token(Token::GREATER_THAN, '>');
            }

            if ($this->current_char === '&' && $this->peek() === '&') {
                $this->advance();
                $this->advance();

                return new Token(Token::AND, '&&');
            }

            if ($this->current_char === '|' && $this->peek() === '|') {
                $this->advance();
                $this->advance();

                return new Token(Token::OR, '||');
            }

            if ($this->current_char === '[') {
                $this->advance();

                return new Token(Token::LEFT_BRACKET, '[');
            }

            if ($this->current_char === ']') {
                $this->advance();

                return new Token(Token::RIGHT_BRACKET, ']');
            }

            if ($this->current_char === ',') {
                $this->advance();

                return new Token(Token::COMMA, ',');
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
