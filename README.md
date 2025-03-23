# GazLang

A simple programming language compiler that supports mathematical expressions with integers and basic operators.

## Features

- Statements terminated by semicolons (`;`)
- Integer numbers
- Mathematical operators: `+`, `-`, `*`, `/`
- Parentheses for grouping expressions
- Echo statements for output (`echo <expr>;`)
- Variables with `$` prefix (`$var = expression;`)
- Single-line comments (`// comment`)
- Ability to interpret expressions
- Code generation for a stack-based virtual machine

## Installation

```bash
git clone https://github.com/yourusername/gazlang.git
cd gazlang
composer install
```

## Usage

Run the GazLang interpreter:

```bash
php bin/gazlang
```

Example expressions:

```
calc> 5 + 3;           // Evaluates but no output
calc> echo 10 * 2 - 5;  // Outputs: 15
calc> echo 2 * (3 + 4); // Outputs: 14 (parentheses for grouping)
calc> echo (5 + 3) * 2; // Outputs: 16 (changes operator precedence)
calc> $x = 5;           // Assign value to variable
calc> echo $x + 3;      // Outputs: 8 (using variables in expressions)
calc> $y = $x * 2;      // Variables in assignment expressions
calc> echo $y;          // Outputs: 10
calc> echo $x + $y;     // Outputs: 15
```

Run with a file:

```bash
php bin/gazlang -f examples/echo_example.gaz
```

Generate code instead of interpreting:

```bash
php bin/gazlang -f examples/echo_example.gaz -c
```

## Project Structure

- `src/` - Source code
  - `Lexer/` - Tokenizes the input code
  - `Parser/` - Parses tokens into an AST
  - `AST/` - Abstract Syntax Tree nodes
  - `Interpreter/` - Executes the AST
  - `CodeGenerator/` - Generates stack-based VM code
- `bin/` - Executable scripts
- `tests/` - Unit tests

## License

MIT 