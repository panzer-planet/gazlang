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
git clone https://github.com/panzer-planet/gazlang.git
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
5 + 3;           // Evaluates but no output
echo 10 * 2 - 5;  // Outputs: 15
echo 2 * (3 + 4); // Outputs: 14 (parentheses for grouping)
echo (5 + 3) * 2; // Outputs: 16 (changes operator precedence)
$x = 5;           // Assign value to variable
echo $x + 3;      // Outputs: 8 (using variables in expressions)
$y = $x * 2;      // Variables in assignment expressions
echo $y;          // Outputs: 10
echo $x + $y;     // Outputs: 15
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