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
 
# Run a file (compiled and run on the VM)
php bin/gazlang -f examples/functions_example.gaz

# Run a file on the tree-walking interpreter instead
php bin/gazlang --interpreter -f examples/functions_example.gaz

# Print the compiled VM code instead of running it
php bin/gazlang -f examples/functions_example.gaz -c
```
 
## Code Style Guidelines
- **PHP version**: 8.5 or later, so the pipe operator (`$x |> trim(...) |> strtolower(...)`)
  is available. phpstan, pint and nikic/php-parser (5.5+, which phpunit's coverage uses)
  all handle it. Use it where a chain of single-argument calls reads better; don't
  force it on multi-argument calls.
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
   right associative) → `ternary` (`?:`, right associative) → `coalesce` (`??`, right associative) → `logical_or` → `logical_and` → `equality` → `relational`
   → `concat` (`..`) → `additive` → `multiplicative` → `unary` → `postfix` (`[index]`, `(args)`) → `primary`. Binary levels share
   `left_associative()`; add a new level by adding a one-line method there.
2. ~~**Add comparison and logical operators.**~~ Done. `<`, `>`, `<=`, `>=`,
   `!=`, `==`, `<=>` (-1, 0 or 1 by the ordering rules below, at the equality level, for
   comparison functions), `&&`, `||` (short-circuiting in both backends), `!`, `%`
   (sign follows the left operand, at the `*` `/` level), `??` (see below), `+= -= *= /= %=` and
   prefix/postfix `++`/`--` (see "Assignment" below), and unary
   `-` via `UnaryOpAST`. `==` and `!=` sit at the equality level, C-style. There is a
   real boolean type (`BooleanAST`, `true`/`false` keywords):
   - Comparisons, `!`, `&&` and `||` return booleans; `echo 1 < 2` prints `true`.
   - Truthiness lives in `Runtime\Values::isTruthy()`, shared by both backends'
     `if`, `while`, `!`, `&&`, `||` (the VM's `JZ`/`NOT`). Numbers and booleans are C-like (`if (5)` is true,
     `if (0)` is false); strings are true unless empty, so `"0"` is true.
   - A bool is not a number: `true == 1` is false, and `true + 1`, `true < 2` and `-true`
     are `Cannot use + on bool`; `to_int(true)` is `1` and `to_float(false)` is `0.0`.
     Truthiness in conditions is the one place a non-bool is read as a bool.
   - `..` concatenates, converting either side the way `echo` does: `"x" .. true` is
     `"xtrue"`, `1 .. 2` is `"12"`. It sits below `+`/`-` and above comparison (Lua,
     PHP 8), so `"n = " .. $a + $b` concatenates the sum. `..=` is its compound
     assignment. `+` is numeric only: `"1" + 1` is `Cannot use + on string`.
   - `==` never converts between strings and numbers (`Values::equals()`, shared with
     `in_array`): two strings compare byte by byte (`"1" != "01"`, `"10" < "9"`), a
     string never equals a number or bool (`"5" != 5`, `true != "1"`; use `to_float`),
     and ordering a string against a number (`"5" < 6`) is an error. Numbers compare
     by value (`1 == 1.0`); a bool equals only itself. There is no `===`: `===` lexes as `==`
     followed by `=`, a syntax error.
   - Numbers never silently overflow, see "Numbers" below.
   - `$a ?? $b` is `$a` unless it is null or missing, like PHP: on its left an
     undefined variable, a missing key, or indexing something missing is null instead
     of an error (other errors, like a bad key type or indexing an int, still happen).
     `0`, `false` and `""` are kept. The right side only runs when needed. Both
     backends share the rule (`Interpreter::quietly()`, and `LOAD_QUIET`,
     `INDEX_GET_QUIET` and `JNN` in generated code). `$a ??= $b` is `$a ?? ($a = $b)`
     with keys evaluated once, so the right side only runs when needed; like `=`, it
     creates a missing variable or last key but not missing keys along the way
     (PHP would create nested arrays).
   - `$c ? $a : $b` (`TernaryAST`) evaluates only the taken branch. It is right
     associative, as in C and JS (`$a ? 1 : $b ? 2 : 3` is `$a ? 1 : ($b ? 2 : 3)`), and
     sits between `??` and assignment, so `$x ?? $y ? 1 : 2` tests the coalesced value and
     `$v = $c ? 1 : 2` assigns the result. The middle is a full expression. The code
     generator emits `JZ TERNARY_ELSE_n` / `JMP TERNARY_END_n`, like `if`.
   - Code generation pushes `PUSH true` / `PUSH false`.
3. ~~**Add loops.**~~ Done. `while` has its own `WhileStatementAST`; `for` is
   desugared in the parser into `{ init; while (cond) { body } }` with the step
   stored on the while node (it runs after the body and on `continue`), so the
   backends have no separate `for` handling. All three `for` clauses are required.
   `break;` and `continue;` (`LoopControlAST`) affect the innermost loop and are
   a parse error outside one. The interpreter unwinds with `LoopSignal`; the
   code generator jumps to the loop's `CONTINUE_n`/`WHILE_n` or `ENDWHILE_n`
   label.
   `foreach ($array as [$key =>] $value) { }` (`ForeachStatementAST`) follows PHP:
   the array expression is evaluated once and iterated as it was then (arrays are
   values, so changing the variable in the body doesn't change the iteration), the
   loop variables can be `$` or `@` and keep their last values, and a non-array is
   "foreach expects an array". The interpreter runs it directly; the code
   generator lowers it to a while loop over `keys()` with hidden `$#foreach_*_n`
   variables (no program can name them), using the step for `continue`.
4. ~~**Add functions and scoping.**~~ Done. Decided semantics:
   - `function name($a, $b) { ... }` is only allowed at the top level. Calls may
     come before the declaration (mutual recursion works). The parser checks
     every call's name and argument count once the whole program is read, so
     both backends trust calls. Functions are values and there are anonymous
     functions, see "Function values" below.
   - Parameters can have defaults, `function f($a, $b = $a * 2)`, after all the
     required ones. A default is any expression, evaluated inside the function on
     each call that leaves the argument out (so it can use earlier parameters and
     globals, and a default `[]` is never shared between calls). The function's
     arity is then `[required, total]`. The code generator emits an `ARGC` check per
     default at the start of the function.
   - `$x` is always local (to the running call, or to the top level) and `@x`
     is always global, everywhere. A function cannot read top-level `$x`.
     Parameters are `$` only. Because declarations are top level only, they are
     never inside a loop, so `break`/`continue` cannot reach a caller's loop.
   - `return [expr];` is a parse error outside a function. The interpreter
     unwinds with `ReturnSignal`; a missing or bare return gives `null`.
   - `null` is a keyword. `echo null` prints `null`, `"x" .. null` is `"xnull"`,
     null is false in conditions, it only `==` null (`null == 0` and
     `null == false` are false), and arithmetic, ordering and unary `-` on it
     throw. Variables are looked up with `array_key_exists`, not `isset`, so
     one holding null is still defined.
   - Code generator calling convention: push args left to right, `CALL FN_name
     argc`; the callee's frame has the args in local slots 0..argc-1; `RET`
     pushes the return value onto the caller's stack. `LOAD`/`STORE` address
     frame locals, `LOAD_GLOBAL`/`STORE_GLOBAL` globals. Top level code ends in
     `HALT` (only emitted when there are functions), followed by the bodies.
   - Function calls are capped at `Values::MAX_CALL_DEPTH` (10000) in both backends, so runaway
     recursion is a GazLang error rather than a PHP out-of-memory fatal. `return`,
     `break` and `continue` rethrow one preallocated signal each: creating a new
     exception per return records a stack trace and made deep recursion quadratic.
   - With the pcov extension enabled every PHP call uses the C stack and deep
     recursion in the interpreter segfaults (exit 139) before the limit, so
     `bin/gazlang` restarts itself with `-d pcov.enabled=0`. In-process code
     (phpunit) still runs with pcov, so tests of deep recursion on the interpreter
     go through the CLI. The VM doesn't recurse in PHP and is unaffected. Xdebug
     has the same problem and is not handled.
   - Reserved for later: `.` for object properties, and possibly `#` as the sigil for
     the current object (`#title` meaning this object's title), alongside `$` and `@`.
     See "Decided, not built yet" below.
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
   - `echo` and `..` print arrays as literals (`[1, "a"]`,
     `["k" => 1]`). Empty arrays are false in conditions. `==` on arrays needs the
     same keys in the same order with elements equal by `==` (`[1] == [1.0]`);
     arithmetic, ordering and unary `-` on arrays throw.
   - `len($x)` (array count or string length) is the first builtin. Builtins
     live in `Runtime\Builtins::ARITIES` (name → arity), share the call checks with user
     functions, and can't be redeclared; the code generator emits
     `CALL_BUILTIN name argc`.
   - Code generator: `NEW_ARRAY`, `ARRAY_PUSH`, `ARRAY_SET`, `INDEX_GET`; an
     indexed assignment pushes keys and value, then `SET_PATH n slot` /
     `APPEND_PATH n slot` (`_GLOBAL` for globals) updates the variable in place
     through `Values::store()` (stack effects are documented on `CodeGenerator`).
   - Not yet: removing elements (build a new array instead).
   - A builtin's arity is an int, or `[fewest, most]` when it has optional
     parameters (`index_of`, `slice`); the parser checks calls against the range,
     the same way as for user functions with defaults.
6. ~~**Design a minimal standard library.**~~ Done. Builtins live in
   `Runtime\Builtins::ARITIES` (name → arity, or `[fewest, most]` for optional
   parameters), are implemented in `Runtime\Builtins::call()`, can't be
   redeclared, and compile to `CALL_BUILTIN name argc`. Argument types are
   checked with the `type_of()` names.
   - Strings: `len($s)`, `slice($x, $start, $length = to the end)` (strings and arrays, PHP
     `substr`/`array_slice` rules including negatives), `lower($s)`,
     `upper($s)`, `trim($s)` (only the lexer's whitespace), `split($s, $sep)` (an
     empty separator splits into characters), `join($array, $sep)` (elements
     converted like echo), `replace($s, $search, $replacement)` (every
     occurrence; empty search is an error), `contains`, `starts_with`,
     `ends_with`, `index_of($s, $needle, $offset = 0)` (null when not found; a
     negative offset counts from the end, as in strpos; an empty needle or an offset
     outside the string is an error),
     `repeat($s, $count)`, `chr($byte)` (0 to 255) and `ord($char)` (exactly one byte),
     the number builtins listed under "Numbers",
     `to_int($x)` (ints, bools as 1/0, or strings of decimal digits with an optional `-`;
     anything else or overflow is an error), `to_string($x)` (same text as echo).
   - Arrays: `len`, `slice`, `in_array($value, $array)` (compares with `==`),
     `has_key($array, $key)`, `keys($array)`.
   - Other: `type_of($x)` (`int`, `float`, `string`, `bool`, `null`, `array`, `function`),
     `error($message)` (raises an error that try/catch can catch; uncaught it stops
     with `Error: message`, exit 1, printed exactly as given with no location), `exit($code = 0)`
     (stops the program with that exit code, 0 to 255, printing nothing; not an error, so
     `try/catch` doesn't see it: both backends unwind with `Runtime\ExitSignal`, which
     `bin/gazlang` and the tests' `runProgram()` turn into the exit code), `read_file($path)` and `write_file($path, $string)`
     (relative to the working directory; write creates or overwrites and returns
     null), `read_stdin()` (all remaining standard input; empty when the program
     itself was piped in), `args()` (the command line arguments
     after the gazlang options, or after `--`). `bin/gazlang` rejects options it
     doesn't know, since `getopt` would silently drop them, so a program's own
     flags must come after `--`.
   - Deliberately left to GazLang code: character classes (`lib/chars.gaz`:
     `char_at`, `is_char`, `is_digit`, `is_hex_digit`, `is_alpha`, `is_alnum`, `is_space`, which
     `LibCharsTest` checks against `Lexer::is_*` for all 256 bytes) and push/pop
     (`$a[] = $v`, and `slice($a, 0, -1)` to drop the last element).
   - `include "path.gaz";` is top level only and takes a string literal. The
     path is relative to the including file (the working directory for piped
     input). It is resolved at parse time: the included file's statements are
     spliced in where it is included and share the parser's function table, so
     the backends never see it. Each file is included once (the main file
     counts), which also breaks cycles.
7. **Begin porting the lexer/parser to GazLang itself. Deferred** (decided
   2026-09-16): while the syntax is still changing, a second lexer doubles the
   work of every lexer change, and a GazLang toolchain on the tree-walking PHP
   interpreter would be too slow to use. Port once the lexical syntax has been
   stable for a while and something faster exists (the VM, or compiling to PHP).
   Until then, grow the language by writing real GazLang (`lib/`, tools) and
   fixing what hurts. The harness below is ready for when the port starts.

   **Port the lexer to `selfhost/lexer.gaz` against the PHP lexer, which is the
   spec.** `tests/SelfHostedLexerTest.php` runs
   `php bin/gazlang -f selfhost/lexer.gaz -- FILE` on every `.gaz` file under
   `examples/`, `lib/`, `selfhost/` and `tests/` (including `tests/lexer_corpus/`,
   the deliberately tricky cases, and `tests/gaz/`), and requires output
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
   code lives in `lib/`. `lib/json.gaz` is the first real GazLang tool:
   `json_decode` follows PHP's `json_decode($text, true)` (checked against it by
   `JsonTest` on every `tests/json/y_*.json` and `n_*.json`, where the prefix says
   whether it must parse) and `json_encode` writes compact JSON. `lib/csv.gaz`
   (RFC 4180, checked against PHP's `fgetcsv` by `CsvTest` on `tests/csv/y_*.csv` and
   `n_*.csv`), `lib/sort.gaz` and `lib/format.gaz` back `examples/csv_report.gaz`. Scan
   long strings with `index_of` rather than character by character in GazLang: that
   halved CSV parsing time; the lexer's character classes are ASCII and explicit
   (`Lexer::is_space` is only space, tab, newline and carriage return).

## Decided, not built yet

`docs/design-review.md` (2026-09-17) lists the design questions to settle before objects,
ranked, with a status on each; update it as they are decided.

Operator changes decided on 2026-09-17 from that review, each built as its own commit
before function values:
- **Concatenation gets its own operator, `..`** (Lua), which converts non-strings the
  way `echo` does. Interpolation desugars to it. `+` is numeric only: a string on
  either side is an error, so two CSV fields can't quietly join instead of adding.
- **`==` and `!=` stop coercing.** A string never equals a number (`"5" == 5` is
  false; compare with `to_float`); int and float still compare by value (`1 == 1.0`);
  arrays structural, functions and objects by identity. `===` and `!==` are removed.
  Bools are not numbers either (`true == 1` is false, `true + 1` is an error; decided
  2026-09-18).
- **`/` always gives a float** (Python 3, Lua 5.3); `intdiv` is integer division.

Design agreed on 2026-09-17, to build in phases (each committed and reviewed):
1. ~~**Named functions as values.**~~ Done, see "Function values" below.
2. ~~**Anonymous functions with `->`.**~~ Done, see "Function values" below.
3. ~~**Capture by value at creation.**~~ Done, likewise.
4. `lib/functional.gaz`: `map`, `filter`, `reduce`, `sort($array, $compare)`, written
   in GazLang (builtins calling back into GazLang would need a re-entrant VM). The
   CSV report's sorting is the real-world test.

### Objects (decided 2026-09-17, after function values)

- **Classes are values and constructing is a call:** `class Point { ... }` then
  `Point(1, 2)`; no `new`. The parser's bare-name check becomes "names a function or
  class". `type_of` gives `"object"`; `is_a($x, Point)` tests the class.
- **Fields are declared up front** in the class body (a property must be declared to
  be read or written; the parser can check `#x` against the declarations). The
  declaration syntax is still to be chosen; `#x;` is the candidate.
- **The constructor is the method named `_`:** `function _($x, $y) { #x = $x; #y = $y; }`.
- **`#` is the object sigil:** `#name` is exactly `this.name` (same lookup, no
  visibility, no accessor path); `#save()` calls this object's method; `#` alone is
  the object itself (PHP `$this`); `#x` outside a method is a parse error, checked the
  way `return` is. Tentative: `##` for the class of the current object (PHP `self::`),
  for class-level state like `##created`, which is otherwise `Point.created` through
  the class value. No fourth sigil.
- **Objects are handles** (PHP, Python, Ruby, Lua): `$b = $a; $b.x = 1` changes `$a`;
  arrays inside objects stay values. `==` on objects is identity. Objects are always
  true in conditions.
- **Properties are `.`** (`$user.name`, `$rows[0].total`), never spaced around the dot.
  A write path is a list of steps of two kinds, index and property, so `Values::store()`
  and `SET_PATH` need a tagged step. `$obj.method` is a bound method value (Python), so
  `["save" => $doc.save]` works.
- **In strings, `#name` and property paths interpolate only inside braces:**
  `"{#name}"`, `"{$user.name}"`. Bare `"#fff"` and `"#1"` stay literal (Ruby); the
  shorthand `"$name"` stays a variable (plus one `[index]`), so `"Saved $file.txt"` and
  `"Hi $name."` keep meaning what they say.
- **A closure created inside a method binds the receiver automatically** (PHP
  closures, JS arrows), so `() -> #save()` works; locals are still captured by value.
- **`to_string()` is the one protocol method:** `echo`, `..` and interpolation use it.
  No operator overloading.
- **`error()` takes any value,** so `error(ParseError("bad", 3))` works and `catch
  (ParseError $e)` filters by class; a string error keeps today's array shape.
  `finally` arrives with objects.
- **No inheritance in the first cut:** composition and duck typing first; see whether
  the self-hosted parser needs more. A `<=>` operator would suit comparison functions.

## Function values

A bare function name is a value (`$f = add;`, builtins too: `$l = len;`): `FunctionRefAST`,
which the parser records and checks names a function once the whole program is read,
like calls ("Undefined function: missing"). A bare name is unambiguous because variables
always have a sigil; a typo like `retrun 1;` is now "Expected ';'" rather than
"Expected '('". `Runtime\FunctionValue` holds the name; `FunctionValue::named()` interns
one instance per name, so `add == add` and `in_array` work by identity. Closures are
fresh per creation and compare by identity of creation; nothing depends on `named()`.

Any postfix expression can be called: `$f(1)`, `$h["save"]($doc)`, `pick()(1, 2)`,
`(add)(1)` are `CallValueAST` (callee, args), parsed by the same `postfix()` loop as
`[index]` and located at the `(`. Only `add(1)`, a name directly followed by `(`, is a
`FunctionCallAST`, checked at parse time and compiled as before. A call on a value is
checked when it runs, in both backends in this order: the callee is evaluated, then the
arguments, then `Cannot call int` (via `Values::typeOf()`) or the arity
(`Builtins::arityError()`, the parser's wording: "Function add expects 2 arguments, 1
given"), then the call. Both are catchable. `FunctionDeclarationAST::$arity` (a count or
`[fewest, most]`) is set by the parser; the interpreter reads it and `compile()` copies
it into `Program::$functions` for the VM.

`type_of` gives `"function"`, `echo add` prints `function add` (`[function add]` in an
array), functions are true in conditions, `==` is identity, and every other operator,
key or index use is an error naming the type (`Cannot use + on function`). `Values::typeOf()`
replaces `get_debug_type` everywhere, so every existing error message follows.
`json_encode` refuses a function. Code generation: `PUSH_FN add`; a call on a value is
callee, args, `CALL_VALUE argc`, which pops them and either calls the builtin or pushes a
frame as `CALL` does (`VM::link()` resolves each function's entry from its label).
`isConstant()` doesn't know about refs, so `[add, len]` is built at runtime.

### Anonymous functions

`$x -> $x * 2`, `($a, $b = 1) -> $a + $b`, `() -> 42`, and a block body
`($a) -> { ...; return ...; }` (`LambdaAST`). Parameters are `$` only with the same
rules as `function` (`Parser::check_parameters()`, shared). An expression body's value
is returned and extends as far right as it can (`$x -> $x * 2 == 4` is
`$x -> ($x * 2 == 4)`; `$x -> $y -> $x + $y` nests), so a lambda sits at the ternary's
level: `1 + $x -> 2` is a syntax error, `$c ? $x -> 1 : $y -> 2` and `$f ?? ($x -> $x)`
parse. A block body returns only through `return` (falling off the end gives null), may
use `return` even at top level, and `break`/`continue` inside it can't reach a loop
around the lambda. No lookahead: `ternary()` marks a `(` it starts at as a possible
head, `Parser::parenthesised()` parses a comma list either way, and only if that `(` was
a head and `->` follows are the elements checked to have been written as `$param` or
`$param = default` (so `(($a)) -> 1` is an error, like `function f(($a))`); `$x ->` is
recognised in `ternary()` after the fact. Errors about a parameter point at it. A
block body can't be written inside `"{...}"` interpolation, since the first `}` ends
the interpolation.

Capture is by value at creation: `LambdaAST::$free` lists every `$` variable the body
and the defaults use that isn't a parameter (including assignment targets, `foreach`
and `catch` variables, and what nested lambdas capture), and evaluating the lambda
copies the ones that exist in the enclosing scope. One that doesn't exist is simply
undefined inside ("Undefined variable: $x"), and changes to the outer variable after
creation, or to the copy inside, are invisible on the other side. `@globals` are never
captured and are read live, which is where shared state goes. So a closure can't call
itself through the variable it is assigned to (`$fact = $n -> ... $fact(...)`: `$fact`
doesn't exist yet, or holds its old value); use a named function or an `@global`.

`FunctionValue::closure()` holds the `LambdaAST` and the captured values (by name in
the interpreter, by local slot in the VM, which also keeps its lambda index on the
value); every evaluation makes a fresh value, so `==` is identity of creation.
`FunctionValue::describe()` names a function in output and messages: `add`, or `-> at
file.gaz:12` / `-> on line 12` for a closure (`GazLangError::location()`, the one
spelling), so `echo $f` prints `function -> at file.gaz:12` and errors read `Function
-> at file.gaz:12 expects 2 arguments, 1 given at file.gaz:20`: where it was made,
then where it was called. Messages are only built when thrown (`Builtins::fitsArity()`
is the hot-path check). The interpreter runs named functions and
closures through one `invoke()`; the code generator emits `MAKE_CLOSURE n` and compiles
each body after the functions under `LABEL LAMBDA_n` from a worklist (a body can
contain more lambdas), with a frame of parameters, then captured variables, then other
locals, keyed `->n` in `local_names` so no function name can collide; `Program::$lambdas`
carries each lambda's capture map (enclosing slot to its own slot), which the VM
applies at `MAKE_CLOSURE`, copying only slots that exist.
`CALL_VALUE` on a closure starts a frame from the captured values plus the arguments.

## Assignment

`=`, `+=`, `-=`, `*=`, `/=`, `%=`, `..=`, `??=` are right associative expressions whose value is the
new value (`AssignAST`, whose token says which). Compound assignment applies the
binary operator, so `..=` concatenates and `+=` on a string is an error. `++`/`--` (`IncrementAST`) work on
numbers only; prefix gives the new value, postfix the old. Targets are a variable
or an element of one (`$a["k"][0]++`), but appending (`$a[] = v`) is plain `=` only.

The interpreter's `store()` evaluates index keys once, left to right, then the right
side, then reads and writes the target, so `$k += $k *= 2` sees the updated `$k`
(as in PHP). A compound update needs the variable and every key to exist ("Undefined
key: n"; reading a missing key as null would make `$a["n"] += "x"` quietly give
`"nullx"`), so it never creates keys, and nothing is written if the operation fails.
The code generator lowers compound assignment and `++`/`--` to plain assignments in
the same order, holding index keys and a non-constant right side in hidden
`$#update_*_n` variables (a constant right side is used directly), reading the
current value with `INDEX_GET_EXISTING` (`Values::indexExisting()`, the same checks
and messages) and stepping with `INC`/`DEC`. A postfix `++`/`--` used as a statement
compiles as prefix, so `$i++` in a loop is `LOAD`, `INC`, `STORE`.

## Errors and try/catch

`try { ... } catch ($e) { ... }` (`TryStatementAST`) catches any runtime error: a
failed operator or builtin, an undefined variable or key, division by zero, running
out of call depth, or `error($message)`, which is also how programs throw (and
rethrow: `error($e["message"])`). Syntax and include errors happen before the
program runs and can't be caught. `$e` (or `@e`) is
`["message" => ..., "file" => ..., "line" => ...]`, the message without the location;
`file` is null for piped input. `return`, `break`, `continue` and `exit()` are not errors and
pass through. There is no `finally` yet.

Every runtime error is a `GazLangError` by the time it leaves a node with a location
(see "Errors" below), and `Interpreter::visitTryStatement()` catches exactly those, so
PHP bugs (`TypeError` and the like) are not swallowed. `error()` messages have
`show_location` false: uncaught they print exactly as given, but the location is
still recorded for catch. The code generator emits `TRY CATCH_n` ... `END_TRY`, with
the error array pushed at `LABEL CATCH_n`; break and continue emit `END_TRY` for each
try they leave, and `RET` drops the returning frame's handlers.

## Numbers

Ints and floats (64-bit, always finite: GazLang has no INF or NAN).

- Literals: `42`, `1.5`, `1e10`, `2.5E-3`, `3e+2`. A float needs digits on both sides
  of the dot (`1.` and `.5` are errors) and/or an exponent. `Lexer::parse_number()`
  parses the same syntax from strings (with an optional minus), for `to_float()`. JSON numbers are valid GazLang number literals. Hex
  literals `0xFF` / `0XdEaD` are ints (too large for an int is an error, `0x1e5` is
  485: hex has no exponent); strings are never parsed as hex.
- Printing is exact: `Lexer::format_float()` gives the shortest digits that read
  back as the same float, always with a dot or exponent (`1.0`, `0.30000000000000004`,
  `1.0E+25`, `-0.0`). echo, `to_string`, interpolation, `--tokens` and generated
  code all use it.
- Arithmetic (`Runtime\Values::binary()`): int with int gives an int, a float on
  either side gives a float, a bool on either side is an error. `/` always gives a float (`6 / 2`
  is `3.0`), as in Python 3 and Lua 5.3; `intdiv()` divides ints, truncating. `%` is
  ints only (`Cannot use % on float`).
- Nothing overflows silently: an int literal or int result that doesn't fit is
  an error (`Integer overflow`, where PHP would switch to a float), a float
  literal that is infinite is a lexer error, and a float result that is infinite
  is `Float overflow`. Division by zero (`0` or `0.0`) is an error.
- `1 == 1.0` is true. An int and a float compare exactly (`Values::compare()`), not by
  converting the int to a float as PHP does: `9007199254740993 != 9007199254740992.0`. `/`
  does convert, so `9007199254740993 / 1` is `9007199254740992.0`. `0.0` and `-0.0` are false in
  conditions. Floats can't be array keys or string positions.
- Builtins: `to_float($x)` (a bool gives 1.0 or 0.0), `to_int($x)` (truncates a float toward zero; an error
  outside the int range), `floor`, `ceil`, `round($x, $precision = 0)` (PHP's round:
  halves away from zero, correcting for halves stored as slightly less, so
  `round(1.005, 2)` is `1.01`; a negative precision rounds to tens, hundreds...; all
  three return floats, as in PHP), `abs` (keeps the type), `intdiv($a, $b)`.

## Strings

Strings are byte strings (like PHP: `len("é")` is 2, `upper` and the character
classes are ASCII; UTF-8 passes through untouched). Names stay ASCII only.

Single-quoted literals are raw, with PHP's rules: only `\'` and `\\` are escapes
and any other backslash is kept. Double-quoted literals support
`\n \t \r \v \f \e \0 \\ \" \$ \{`, `\xHH` (exactly two hex digits) and `\u{H}` (1 to 6 hex
digits, a code point up to 10FFFF that isn't a surrogate, written as UTF-8). Any
other escape is a lexer error, and so is `\0` followed by a digit, which would be
octal in PHP and C.

Double-quoted strings interpolate, PHP style: `"Hi $name"` (a `$` followed by a
letter or `_`, then the greedy name, optionally followed by one PHP-style index:
`[0]`, `[-1]`, `[key]` as the string `"key"`, or `[$i]`; digits that aren't a
canonical int, like `01`, are a string key, and any other bracket contents are an
error) and `"{$expr}"` / `"{@expr}"` (any expression starting with that sigil, up
to the `}`). Anything else stays literal: `$5`, a lone
`$`, `me@example.com`, `{ $x}`'s brace, `{}`. The lexer emits `STRING_START`, the
expression's tokens, `STRING_MIDDLE` between interpolations and `STRING_END`
(a string without interpolation is one `STRING`), tracking open strings on a
stack so strings nest inside interpolations. The parser desugars to `..`
(`"Hi {$n}!"` is `"Hi " .. $n .. "!"`), so values
convert like `echo` and the backends need nothing. Include paths can't interpolate.

`Lexer::quote()` is the exact inverse of a literal: named escapes, `\xHH` for
other control bytes and NUL, `\$` and `\{` only where they would interpolate,
other bytes as is. Anything that shows a string as source uses it.

## Errors

Every error a program can hit is a `GazLang\GazLangError` whose message ends in
its location: ` at path/file.gaz:12`, or ` on line 12` for piped or inline
source (`$reason`, `$path` and `$line_number` hold the parts). The exception is
`error()`, whose `show_location` is false: its message is exactly what the program
gave, though the location is still recorded. Tokens carry the
line they start on; the parser stamps `line` and `file` on every AST node with
`Parser::at()`, and routes lexer errors through `next_token()` to add the file.
Syntax errors say what was expected and found ("Expected ')' but found ';'").
`Interpreter::visit()` turns any plain `Exception` thrown while running a node
into a `GazLangError` at that node, and gives a `GazLangError` raised without a
location (like `error()`'s) that node's location, so the innermost located node wins
and runtime code can keep throwing plain `Exception`s. Include paths show relative
to the working directory; the main file shows as given on the command line.

## Layout

- `src/Lexer`, `src/Parser`, `src/AST`: source text to a tree. `Lexer::quote()` and
  `Lexer::parse_integer()` are the one definition of string and integer literals.
- `src/Interpreter`: runs the tree (scopes, calls, control flow signals, writes
  through array paths). It does not decide what values mean.
- `src/Runtime`: what values mean, shared by every backend. `Values` holds the
  operators, truthiness, printing, array keys and indexing as static pure
  functions; `Builtins` holds the builtin functions and their arities. Both backends
  call these rather than reimplementing them.
- `src/CodeGenerator`: compiles the AST to a `Program` of stack VM instructions, each
  with the file and line it came from, plus the variable name in each slot.
- `src/VM`: runs a `Program`. It is the default backend for `php bin/gazlang`;
  `--interpreter` runs the tree-walking interpreter instead. See "VM" below.

## VM

`VM::link()` collapses `STORE x; LOAD x; POP` into `STORE x`, resolves labels to
instruction positions and splits the instructions into parallel opcode and argument
arrays. `VM::run()` then runs one
dispatch loop over a value stack, globals, the current frame's locals and argument
count, a stack of callers' frames, and a stack of try handlers. Every operator and
builtin goes through `Runtime\Values` / `Runtime\Builtins`, and assignment through
`Values::store()`, so the VM and the interpreter share their semantics rather than
reimplementing them. The only exceptions are fast paths in the loop for the commonest
cases whose result is obvious (arithmetic and comparisons on two ints that don't
overflow, `==` on two ints or two strings, `JZ`/`NOT` on bools, `INDEX_GET` on an array, `INC`/`DEC` on an int,
and the builtins `len`, `ord`, `chr` and `in_array` when their arguments are plainly
valid);
anything else, errors included, falls through to `Values`. Keep fast paths that way. Calls are frames in an array, not PHP recursion, so deep
recursion doesn't depend on PHP's C stack. Errors get the location of the instruction
that raised it (the innermost node the code generator was compiling, which is the node
the interpreter reports), then unwind to the innermost handler: frames made inside the
try are dropped, the stack is cut back, and the error array is pushed for the catch.

**The two backends must agree.** `GazLangTestCase::executeCode()` runs every snippet on
the interpreter and on the VM and fails if the output differs, or the error's class,
message, file or line (what catch sees) differs, and
`GazProgramTest`, `JsonTest` and `VMTest` (examples) do the same for whole programs. So
any new language feature needs both backends, or those tests fail. The code generator
also builds array literals made only of constants (with int or string keys) once at
compile time, pushed as one value, and `foreach` takes `len()` of its keys once. Order matters as
much as results: the VM's `KEY_CHECK` exists so a bad array key fails before later
keys and the value run, exactly when the interpreter's does. After tuning, the
VM runs fib, arithmetic loops and JSON 2 to 5 times as fast as the interpreter.

**Note:** any new AST node type (e.g. new BinOp/UnaryOp variants)
needs visitor support in *both* `Interpreter/Interpreter.php` and
`CodeGenerator/CodeGenerator.php` — don't patch one backend and leave the
other stale.
