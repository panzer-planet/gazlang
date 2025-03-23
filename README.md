# GazLang

A simple programming language compiler that supports mathematical expressions with integers and basic operators.

## Features

- Statements terminated by semicolons (`;`)
- Integer numbers
- Mathematical operators: `+`, `-`, `*`, `/`
- Echo statements for output (`echo <expr>;`)
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
calc> echo 2 * (3 + 4); // Not supported yet (parentheses)
calc> echo 5 + 3; echo 10 * 2;  // Multiple outputs
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