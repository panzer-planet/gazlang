# Parser Improvements

## Future Enhancements

### Parentheses Support

To add support for parentheses, modify the `factor` method in the `Parser` class:

```php
public function factor()
{
    $token = $this->current_token;
    
    if ($token->type === Token::INTEGER) {
        $this->eat(Token::INTEGER);
        return new Num($token);
    } else if ($token->type === Token::LPAREN) {
        $this->eat(Token::LPAREN);
        $node = $this->expr();
        $this->eat(Token::RPAREN);
        return $node;
    }
    
    $this->error();
}
```

And add the token types in the `Token` class:

```php
public const LPAREN = 'LPAREN';    // (
public const RPAREN = 'RPAREN';    // )
```

Then add support for these tokens in the `Lexer` class:

```php
if ($this->current_char === '(') {
    $this->advance();
    return new Token(Token::LPAREN, '(');
}

if ($this->current_char === ')') {
    $this->advance();
    return new Token(Token::RPAREN, ')');
}
```

### Variables

1. Add variable declaration syntax
2. Add assignment operator
3. Implement symbol table
4. Add variable lookup in expressions

### Control Flow

1. Add if/else statements
2. Add while/for loops
3. Add conditionals (>, <, ==, etc.)

### Functions

1. Add function declaration syntax
2. Implement function calls
3. Add parameter passing
4. Add return statement 