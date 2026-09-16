# GazLang Development Guidelines
 
## Build & Test Commands
```bash
# Install dependencies
composer install
 
# Run all tests
vendor/bin/phpunit
 
# Run a specific test file
vendor/bin/phpunit tests/SpecificTest.php
 
# Run a specific test method
vendor/bin/phpunit --filter=testMethodName tests/SpecificTest.php
 
# Run interpreter on a file
php bin/gazlang -f examples/functions_example.gaz
 
# Generate code instead of interpreting
php bin/gazlang -f examples/functions_example.gaz -c
```
 
## Code Style Guidelines
- **Namespaces**: Use `GazLang\` namespace root with PSR-4 autoloading
- **Classes**: PascalCase (e.g., `Parser`)
- **Methods/Functions**: camelCase (e.g., `visitBinOp()`, `isTruthy()`), except Lexer and Parser
  methods, which are snake_case and named after the grammar rule or step they read
  (`get_next_token()`, `function_call()`, `left_associative()`)
- **Properties**: snake_case (e.g., `$current_char`)
- **Constants**: UPPERCASE (e.g., `TOKEN::INTEGER`)
- **Documentation**: PHPDoc for classes and methods with parameter/return types
- **Error Handling**: Throw exceptions with descriptive messages
- **Class Structure**: Properties at top, constructor follows, public methods first
## Roadmap: Path to Self-Hosting
 
The long-term goal is for GazLang to be self-hosting — the lexer/parser/interpreter
eventually rewritten *in GazLang itself*. Work toward that goal in this order;
each step depends on the ones before it.
 
1. ~~**Fix the precedence tower.**~~ Done. `Parser` is now `expr` (assignment,
   right associative) → `logical_or` → `logical_and` → `equality` → `relational`
   → `additive` → `multiplicative` → `unary` → `postfix` (`[index]`) → `primary`. Binary levels share
   `left_associative()`; add a new level by adding a one-line method there.
2. ~~**Add comparison and logical operators.**~~ Done. `<`, `>`, `<=`, `>=`,
   `!=`, `==`, `===`, `!==`, `&&`, `||` (short-circuiting in both backends), `!`, and unary
   `-` via `UnaryOpAST`. `!=`, `===` and `!==` sit at the equality level, C-style. There is a
   real boolean type (`BooleanAST`, `true`/`false` keywords):
   - Comparisons, `!`, `&&` and `||` return booleans; `echo 1 < 2` prints `true`.
   - Truthiness lives in `Runtime\Values::isTruthy()`, shared by the interpreter's
     `if`, `while`, `!`, `&&`, `||`. The code generator's `JZ`/`NOT` have no VM
     defined behind them yet; a VM must reuse the same rules. Numbers and booleans are C-like (`if (5)` is true,
     `if (0)` is false); strings are true unless empty, so `"0"` is true.
   - Booleans act as 1/0 in arithmetic and comparisons: `true + 1` is `2`,
     `true == 1` is true.
   - Concatenation uses the same spelling as `echo`: `"x" + true` is `"xtrue"`.
   - Two strings compare byte by byte (`"1" != "01"`, `"10" < "9"`). A string
     and an int (or a bool, as 1/0) compare as ints only when the string is an
     integer by `Lexer::parse_integer()` (`"5" == 5`, `"007" == 7`, `true == "1"`);
     any other string is never `==` an int (`"1e0" != 1`, `true != "abc"`), and
     ordering it against an int (`"abc" < 1`) is an error.
   - Ints never silently overflow: literals that don't fit are a lexer error,
     and arithmetic or negation that would leave the int range throws
     `Integer overflow` (PHP would produce a float, which GazLang has no type for).
   - `===` / `!==` compare type and value with no conversion: `"5" === 5` and
     `true === 1` are false.
   - Code generation pushes `PUSH true` / `PUSH false`.
3. ~~**Add loops.**~~ Done. `while` has its own `WhileStatementAST`; `for` is
   desugared in the parser into `{ init; while (cond) { body } }` with the step
   stored on the while node (it runs after the body and on `continue`), so the
   backends only know about while. All three `for` clauses are required.
   `break;` and `continue;` (`LoopControlAST`) affect the innermost loop and are
   a parse error outside one. The interpreter unwinds with `LoopSignal`; the
   code generator jumps to the loop's `CONTINUE_n`/`WHILE_n` or `ENDWHILE_n`
   label.
4. ~~**Add functions and scoping.**~~ Done. Decided semantics:
   - `function name($a, $b) { ... }` is only allowed at the top level. Calls may
     come before the declaration (mutual recursion works). The parser checks
     every call's name and argument count once the whole program is read, so
     both backends trust calls. Functions are not values; nested functions
     and closures wait until they are.
   - `$x` is always local (to the running call, or to the top level) and `@x`
     is always global, everywhere. A function cannot read top-level `$x`.
     Parameters are `$` only. Because declarations are top level only, they are
     never inside a loop, so `break`/`continue` cannot reach a caller's loop.
   - `return [expr];` is a parse error outside a function. The interpreter
     unwinds with `ReturnSignal`; a missing or bare return gives `null`.
   - `null` is a keyword. `echo null` prints `null`, `"x" + null` is `"xnull"`,
     null is false in conditions, it only `==` null (`null == 0` and
     `null == false` are false), and arithmetic, ordering and unary `-` on it
     throw. Variables are looked up with `array_key_exists`, not `isset`, so
     one holding null is still defined.
   - Code generator calling convention: push args left to right, `CALL FN_name
     argc`; the callee's frame has the args in local slots 0..argc-1; `RET`
     pushes the return value onto the caller's stack. `LOAD`/`STORE` address
     frame locals, `LOAD_GLOBAL`/`STORE_GLOBAL` globals. Top level code ends in
     `HALT` (only emitted when there are functions), followed by the bodies.
   - Function calls are capped at `Interpreter::MAX_CALL_DEPTH` (10000), so runaway
     recursion is a GazLang error rather than a PHP out-of-memory fatal. `return`,
     `break` and `continue` rethrow one preallocated signal each: creating a new
     exception per return records a stack trace and made deep recursion quadratic.
   - With the pcov extension enabled every PHP call uses the C stack and deep
     GazLang recursion segfaults (exit 139) before the limit, so `bin/gazlang`
     restarts itself with `-d pcov.enabled=0`. In-process code (phpunit) still
     runs with pcov, so tests of deep recursion go through the CLI. Xdebug has
     the same problem and is not handled.
   - Reserved for later: `#` for object properties (`@` is taken by globals).
5. ~~**Add arrays (and maybe maps).**~~ Done. One PHP-style ordered array type
   serves as both list and map. Decided semantics:
   - Literals `[1, 2]` and `["k" => 1, 5 => 2]` (trailing comma allowed,
     duplicate keys keep the last value). Keys are int or string; as in PHP a
     numeric string key like `"1"` is the same key as `1`.
   - Arrays are values, like PHP: assigning or passing one copies it. The
     interpreter writes in place through PHP references for speed (appending is
     linear); PHP drops a reference once nothing else holds it, so later copies
     don't share.
   - `$a[i]` reads (also chained, and on any expression). A missing key, or a
     string position out of range, reads `null`. Strings index by int position
     to a one character string, read only.
   - `$a[k] = v`, `$a[k1][k2] = v` and `$a[] = v` (append, only valid as an
     assignment target) write through a variable (`$` or `@`). Keys are
     evaluated left to right, then the value, and only then is the variable's
     array read, so side effects of the keys and value are kept. The variable must exist and only the last key
     may be new; missing keys along the way are an error, not auto-created.
   - `echo` and `+` concatenation print arrays as literals (`[1, "a"]`,
     `["k" => 1]`). Empty arrays are false in conditions. `==` on arrays is
     strict (same keys, same order, identical values); arithmetic, ordering and
     unary `-` on arrays throw.
   - `len($x)` (array count or string length) is the first builtin. Builtins
     live in `Runtime\Builtins::ARITIES` (name → arity), share the call checks with user
     functions, and can't be redeclared; the code generator emits
     `CALL_BUILTIN name argc`.
   - Code generator: `NEW_ARRAY`, `ARRAY_PUSH`, `ARRAY_SET`, `INDEX_GET`; an
     indexed assignment pushes keys, value, then `LOAD`s the array, and
     `SET_PATH n` / `APPEND_PATH n` then `STORE` the updated array (stack
     effects are documented on `CodeGenerator`).
   - Not yet: removing elements (build a new array instead), `foreach` (loop
     over `keys()`).
   - `Builtins::ARITIES` records a fixed arity; variadic builtins will need that
     to change.
6. ~~**Design a minimal standard library.**~~ Done. Builtins live in
   `Runtime\Builtins::ARITIES` (name → fixed arity; variadic builtins will need that to
   change), are implemented in `Runtime\Builtins::call()`, can't be
   redeclared, and compile to `CALL_BUILTIN name argc`. Argument types are
   checked with the `type_of()` names.
   - Strings: `len($s)`, `slice($x, $start, $length)` (strings and arrays, PHP
     `substr`/`array_slice` rules including negatives), `lower($s)`,
     `upper($s)`, `trim($s)` (only the lexer's whitespace), `split($s, $sep)` (an
     empty separator splits into characters), `join($array, $sep)` (elements
     converted like echo), `replace($s, $search, $replacement)` (every
     occurrence; empty search is an error), `contains`, `starts_with`,
     `ends_with`, `index_of($s, $needle)` (null when not found),
     `repeat($s, $count)`, `chr($byte)` (0 to 255) and `ord($char)` (exactly one byte),
     `to_int($x)` (ints, or strings of decimal digits with an optional `-`;
     anything else or overflow is an error), `to_string($x)` (same text as echo).
   - Arrays: `len`, `slice`, `in_array($value, $array)` (strict, like `===`),
     `has_key($array, $key)`, `keys($array)`.
   - Other: `type_of($x)` (`int`, `string`, `bool`, `null`, `array`),
     `error($message)` (stops with `Error: message`, exit 1, printed exactly as
     given with no location), `read_file($path)` and `write_file($path, $string)`
     (relative to the working directory; write creates or overwrites and returns
     null), `read_stdin()` (all remaining standard input; empty when the program
     itself was piped in), `args()` (the command line arguments
     after the gazlang options, or after `--`). `bin/gazlang` rejects options it
     doesn't know, since `getopt` would silently drop them, so a program's own
     flags must come after `--`.
   - Deliberately left to GazLang code: character classes (`lib/chars.gaz`:
     `char_at`, `is_char`, `is_digit`, `is_alpha`, `is_alnum`, `is_space`, which
     `LibCharsTest` checks against `Lexer::is_*` for all 256 bytes), `join`,
     push/pop.
   - `include "path.gaz";` is top level only and takes a string literal. The
     path is relative to the including file (the working directory for piped
     input). It is resolved at parse time: the included file's statements are
     spliced in where it is included and share the parser's function table, so
     the backends never see it. Each file is included once (the main file
     counts), which also breaks cycles.
7. **Begin porting the lexer/parser to GazLang itself.** Only once functions,
   arrays, and a stdlib exist does porting `Lexer.php`, `Parser.php`, etc. into
   GazLang source become plausible. Everything before this step is groundwork.

   **Port the lexer to `selfhost/lexer.gaz` against the PHP lexer, which is the
   spec.** `tests/SelfHostedLexerTest.php` runs
   `php bin/gazlang -f selfhost/lexer.gaz -- FILE` on every `.gaz` file in
   `examples/`, `tests/fixtures/` and `tests/lexer_corpus/`, and requires output
   and exit code identical to `php bin/gazlang --tokens -f FILE`: one line per
   token, `LINE TYPE VALUE` as `Token::__toString()` formats it (strings quoted
   with `Lexer::quote()`, integers as digits, other values as source text, EOF
   with no value), then on a lexer error `error("<message> on line N")`, which
   prints `Error: ...` and exits 1. The test skips once until the file exists. Add
   a corpus file whenever the port reveals an untested case; files named `error_*`
   must be exactly the ones that fail to lex. The comparison runs in-process
   (`GazLangTestCase::runProgram()`), since a CLI run costs ~0.5s.

   **Test GazLang code with GazLang programs.** Every `tests/gaz/**/*_test.gaz`
   must print exactly its `*_test.expected` (`GazProgramTest`); other `.gaz`
   files there are helpers, like `check.gaz`'s `check($label, $actual, $expected)`,
   which prints `ok <label>` or a FAIL line with both values. Reusable GazLang
   code lives in `lib/`; the lexer's character classes are ASCII and explicit
   (`Lexer::is_space` is only space, tab, newline and carriage return).

## Strings

Strings are byte strings. Single-quoted literals are raw, with PHP's rules: only
`\'` and `\\` are escapes and any other backslash is kept. Double-quoted literals support `\n \t \r \v \f \e \0 \\ \"`, `\xHH` (exactly
two hex digits) and `\u{H}` (1 to 6 hex digits, a code point up to 10FFFF that
isn't a surrogate, written as UTF-8). Any other escape is a lexer error, and so is
`\0` followed by a digit, which would be octal in PHP and C. `Lexer::quote()` is
the exact inverse: named escapes, `\xHH` for other control bytes and NUL, other
bytes as is. Anything that shows a string as source uses it.

## Errors

Every error a program can hit is a `GazLang\GazLangError` whose message ends in
its location: ` at path/file.gaz:12`, or ` on line 12` for piped or inline
source (`$reason`, `$path` and `$line_number` hold the parts). Tokens carry the
line they start on; the parser stamps `line` and `file` on every AST node with
`Parser::at()`, and routes lexer errors through `next_token()` to add the file.
Syntax errors say what was expected and found ("Expected ')' but found ';'").
`Interpreter::visit()` turns any plain `Exception` thrown while running a node
into a `GazLangError` at that node, so the innermost located node wins and
runtime code can keep throwing plain `Exception`s. Include paths show relative
to the working directory; the main file shows as given on the command line.

## Layout

- `src/Lexer`, `src/Parser`, `src/AST`: source text to a tree. `Lexer::quote()` and
  `Lexer::parse_integer()` are the one definition of string and integer literals.
- `src/Interpreter`: runs the tree (scopes, calls, control flow signals, writes
  through array paths). It does not decide what values mean.
- `src/Runtime`: what values mean, shared by every backend. `Values` holds the
  operators, truthiness, printing, array keys and indexing as static pure
  functions; `Builtins` holds the builtin functions and their arities. A future
  VM should call these rather than reimplement them.
- `src/CodeGenerator`: emits stack VM instructions (there is no VM yet).

## Later

- String interpolation, decided but not designed: it will apply to double-quoted
  strings only, so single-quoted strings stay raw and keep their meaning.
- A VM that runs the code generator's output, calling `src/Runtime` for semantics.

**Note:** any new AST node type (e.g. new BinOp/UnaryOp variants)
needs visitor support in *both* `Interpreter/Interpreter.php` and
`CodeGenerator/CodeGenerator.php` — don't patch one backend and leave the
other stale.
