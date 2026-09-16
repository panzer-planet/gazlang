# GazLang

A small, PHP-flavoured programming language with an interpreter and a stack-VM code generator, written in PHP on the way to being self-hosting. It has integers, strings, booleans, null and arrays, functions with local and global variables, loops, includes and a small standard library.

## Features

- Statements terminated by semicolons (`;`)
- Dynamic typing with support for integers, floats (`1.5`, `2e-3`), strings, booleans, `null` and arrays
- Arrays that work as lists and maps (`[1, 2]`, `["key" => 1]`), copied on assignment like PHP, with `$a[i]` indexing, `$a[] = v` appending and `len()`
- Boolean literals `true` and `false`; comparisons and logical operators return booleans
- String literals with double quotes (`"Hello, World!"`, with escapes) or single quotes (`'C:\raw\path'`, raw: only `\'` and `\\` are escapes)
- String escape sequences: `\n` `\t` `\r` `\v` `\f` `\e` `\0` `\\` `\"`, `\xHH` for a byte and `\u{1F600}` for a Unicode code point (as UTF-8); anything else, including octal like `\012`, is an error
- String concatenation with the `+` operator
- String interpolation in double-quoted strings: `"Hi $name!"`, `"First: $items[0]"`, `"{$user["name"]} has {@count} items"`
- Mathematical operators: `+`, `-`, `*`, `/` (`7 / 2` is `3.5`, `6 / 2` is `3`), `%`, unary `-`
- Comparison operators: `==`, `!=`, `===`, `!==` (no type conversion), `<`, `<=`, `>`, `>=`
- Logical operators: `&&`, `||` (short-circuiting), `!`
- Parentheses for grouping expressions
- Echo statements for output (`echo <expr>;`)
- Local variables with `$` prefix (`$var = expression;`) and global variables with `@` prefix (`@count = 0;`)
- Control flow with if/else and else if statements
- Loops: `while (cond) { ... }`, `for (init; cond; step) { ... }` and `foreach ($array as $key => $value) { ... }`, with `break` and `continue`
- Functions: `function add($a, $b) { return $a + $b; }`, callable before they are declared, with recursion
- Standard library: `to_float`, `floor`, `ceil`, `round`, `abs`, `intdiv`, `len`, `slice`, `lower`, `upper`, `trim`, `split`, `join`, `replace`, `contains`, `starts_with`, `ends_with`, `index_of`, `repeat`, `chr`, `ord`, `to_int`, `to_string`, `in_array`, `has_key`, `keys`, `type_of`, `error`, `read_file`, `write_file`, `read_stdin`, `args`
- Error messages with file and line (`Error: Expected ')' but found ';' at lib/parser.gaz:12`)
- `include "lib/helpers.gaz";` to split programs across files (each file is included once)
- Single-line comments (`// comment`)
- Ability to interpret expressions
- Code generation for a stack-based virtual machine

## Installation

Requires PHP 8.5 or later.

```bash
git clone https://github.com/panzer-planet/gazlang.git
cd gazlang
composer install
```

## Usage

Run a program from a file (the usual way):

```bash
php bin/gazlang -f program.gaz
```

`php bin/gazlang` on its own starts an interactive prompt, but each line runs as a
separate program, so variables and functions don't carry over between lines.
Piped input runs as one program: `cat program.gaz | php bin/gazlang`.

Example program:

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

// String examples
echo "Hello, World!";     // Outputs: Hello, World!
$greeting = "Hello";      // String assignment
$name = "GazLang";        // Another string assignment
echo $greeting + ", " + $name + "!";  // Outputs: Hello, GazLang!

// String concatenation with numbers
echo "The answer is " + 42;  // Outputs: The answer is 42
echo 2025 + " is the year";  // Outputs: 2025 is the year

// String escape sequences
echo "Line 1\nLine 2";            // Outputs two lines
echo "Tab\tcharacter";            // Outputs with tab
echo "Double \"quotes\" inside";  // Outputs quotes within string

// If/else statements
if (1) {
    echo 42;     // Outputs: 42 (condition is true)
} else {
    echo 0;
}

// Else if statements
if (0) {
    echo 10;
} else if (1) {
    echo 20;     // Outputs: 20 (first condition false, second true)
} else {
    echo 30;
}

// With variables in conditions
$z = 0;
if ($z) {
    echo 100;
} else {
    echo 200;    // Outputs: 200 (variable value is 0, so condition is false)
}

// Nested if statements
if (1) {
    if (0) {
        echo 300;
    } else {
        echo 400; // Outputs: 400
    }
}
```

Run with a file:

```bash
php bin/gazlang -f examples/echo_example.gaz
```

Pass arguments to the program (read them with `args()`):

```bash
php bin/gazlang -f examples/stdlib_example.gaz -- some_file.txt
```

Print the tokens the lexer produces (one `LINE TYPE VALUE` per line):

```bash
php bin/gazlang --tokens -f examples/echo_example.gaz
```

Generate code instead of interpreting:

```bash
php bin/gazlang -f examples/echo_example.gaz -c
```

## Project Structure

- `src/` - Source code
  - `Lexer/` - Tokenizes the input code
  - `Parser/` - Parses tokens into an AST
  - `AST/` - Abstract Syntax Tree nodes and visitor pattern implementation
    - `NodeVisitorInterface.php` - Interface for AST node visitors
    - `AbstractNodeVisitor.php` - Base visitor implementation
    - Various AST node classes for different language constructs
  - `Interpreter/` - Executes the AST
  - `Runtime/` - Value semantics (operators, truthiness, printing) and builtin functions, shared by backends
  - `CodeGenerator/` - Generates stack-based VM code
- `lib/` - Libraries written in GazLang (`chars.gaz`: character classes)
- `bin/` - Executable scripts
- `tests/` - Unit tests; `tests/gaz/` holds GazLang test programs with their expected output

## License

MIT 