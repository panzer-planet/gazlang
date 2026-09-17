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
php bin/gazlang -f examples/functions.gaz

# Run a file on the tree-walking interpreter instead
php bin/gazlang --interpreter -f examples/functions.gaz

# Print the compiled VM code instead of running it
php bin/gazlang -f examples/functions.gaz -c
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
 
The long-term goal is for GazLang to be self-hosting: the lexer, parser and compiler
written *in GazLang itself*, running on a small native VM, with no PHP left in the
toolchain (decided 2026-09-17, see step 7). Work toward that goal in this order;
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
   `foreach ($x as [$key =>] $value) { }` (`ForeachStatementAST`) follows PHP:
   the list or map is evaluated once and iterated as it was then (they are
   values, so changing the variable in the body doesn't change the iteration), the
   loop variables can be `$` or `@` and keep their last values (a list's keys are its
   indexes), and anything else is "foreach expects a list or map". The interpreter runs it directly; the code
   generator lowers it to a while loop over `keys()` with hidden `$#foreach_*_n`
   variables (no program can name them), using the step for `continue`.
4. ~~**Add functions and scoping.**~~ Done. Decided semantics:
   - `fn name($a, $b) { ... }` (renamed from `function` on 2026-09-17; `function` stays
     reserved and says to use `fn`) is only allowed at the top level. Calls may
     come before the declaration (mutual recursion works). The parser checks
     every call's name and argument count once the whole program is read, so
     both backends trust calls. Functions are values and there are anonymous
     functions, see "Function values" below.
   - Parameters can have defaults, `fn f($a, $b = $a * 2)`, after all the
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
   - Objects use `.` for properties, `#` for this object and `##` for its parent: see
     "Objects" below.
5. ~~**Add lists and maps.**~~ Done (one PHP-style array at first, split into two types
   on 2026-09-17, see `docs/design-review.md` #10). Decided semantics:
   - A list `[1, 2]` holds values at indexes 0, 1, 2...; a map `{"k" => 1, 5 => 2}` holds
     values by key in insertion order (trailing comma allowed, duplicate keys keep the
     last value). `[k => v]` is a parse error pointing at `{}`. A `{` that starts a
     statement is a block and one right after `->` is a lambda body, so a lambda
     returning a map writes `$x -> ({"v" => $x})`. Map keys are int or string and
     `"1"` and `1` are different keys (`Runtime\MapValue::key()` encodes the strings
     PHP would convert). `type_of` gives `list` or `map`.
   - Lists and maps are values: assigning or passing one copies it. A list is a plain
     PHP list and the interpreter writes it in place through PHP references (appending is
     linear; PHP drops a reference once nothing else holds it, so later copies don't
     share). A map is a `MapValue` object, so `Values::store()` clones each map on the
     path before writing (cheap: the items array is shared until written) and nothing
     else may change one in place.
   - `$a[i]` reads (also chained, and on any expression). A list index must be an int
     in range (`Index out of range: 5`, no negative indexes) and a map key must exist
     (`Undefined key: "k"`, strings quoted so `"1"` and `1` differ), unless read on the
     left of `??`, which gives null. Strings index the same way, by an int position in
     range, to a one character string, read only.
   - `$a[k] = v`, `$a[k1][k2] = v` and `$a[] = v` (append, only valid as an
     assignment target) write through a variable (`$` or `@`). Keys are
     evaluated left to right, then the value, and only then is the variable's
     value read, so side effects of the keys and value are kept. The variable must
     exist; a list index must already exist (append with `[]`), a map may gain its last
     key, missing keys along the way are an error, and appending to a map is an error.
   - `echo` and `..` print lists and maps as literals (`[1, "a"]`, `{"k" => 1}`). Empty
     ones are false in conditions. `==` on lists compares elements in order, on maps the
     same keys with equal values in any order (`[1] == [1.0]`); a list never equals a
     map, even `[] == {}`. Arithmetic, ordering and unary `-` on them throw.
   - `len($x)` (list or map count, or string length) is the first builtin. Builtins
     live in `Runtime\Builtins::ARITIES` (name → arity), share the call checks with user
     functions, and can't be redeclared; the code generator emits
     `CALL_BUILTIN name argc`.
   - Code generator: `NEW_ARRAY`, `ARRAY_PUSH`, `NEW_MAP`, `MAP_SET`, `INDEX_GET`; an
     indexed assignment pushes keys and value, then `SET_PATH [k][k] slot` (`[]` at the
     end appends; `_GLOBAL` for globals) updates the variable in place
     through `Values::store()` (stack effects are documented on `CodeGenerator`).
   - Not yet: removing elements (build a new list or map instead).
   - A builtin's arity is an int, or `[fewest, most]` when it has optional
     parameters (`index_of`, `slice`); the parser checks calls against the range,
     the same way as for user functions with defaults.
6. ~~**Design a minimal standard library.**~~ Done. Builtins live in
   `Runtime\Builtins::ARITIES` (name → arity, or `[fewest, most]` for optional
   parameters), are implemented in `Runtime\Builtins::call()`, can't be
   redeclared, and compile to `CALL_BUILTIN name argc`. Argument types are
   checked with the `type_of()` names.
   - Strings: `len($s)`, `slice($x, $start, $length = to the end)` (strings and lists, PHP
     `substr`/`array_slice` rules including negatives), `lower($s)`,
     `upper($s)`, `trim($s)` (only the lexer's whitespace), `split($s, $sep)` (an
     empty separator splits into characters), `join($list, $sep)` (elements
     converted like echo), `replace($s, $search, $replacement)` (every
     occurrence; empty search is an error), `contains`, `starts_with`,
     `ends_with`, `index_of($s, $needle, $offset = 0)` (null when not found; a
     negative offset counts from the end, as in strpos; an empty needle or an offset
     outside the string is an error),
     `repeat($s, $count)`, `chr($byte)` (0 to 255) and `ord($char)` (exactly one byte),
     the number builtins listed under "Numbers",
     `to_int($x)` (ints, bools as 1/0, or strings of decimal digits with an optional `-`;
     anything else or overflow is an error), `to_string($x)` (same text as echo).
   - Lists and maps: `len`, `slice` (lists), `in_array($value, $list)` (compares with `==`),
     `has_key($x, $key)` (a map's key, or a list's index), `keys($x)` (a list's indexes).
   - Other: `type_of($x)` (`int`, `float`, `string`, `bool`, `null`, `list`, `map`, `function`,
     `class`, `object`), `is_a($x, Class)` (see "Objects"),
     `error($value)` (raises an error that try/catch can catch, see "Errors and try/catch";
     uncaught it stops with `Error: ` and the value as echo prints it, exit 1, with no
     location), `exit($code = 0)`
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
7. **Finish the language first, then leave PHP behind in four stages.** Decided
   2026-09-17. Measured then, with PHP's JIT on for both: the VM is 25 to 130 times
   slower than the same program in PHP (typically 60 to 90), the interpreter 35 to 430.
   A self-hosted toolchain has to run on something faster, and transpiling to PHP was
   ruled out as the main path because it ties GazLang to PHP for good (it stays a
   fallback). The plan is the Lua/Python shape instead: everything above the VM in
   GazLang, the VM and runtime native. Objects come first, since they change the value
   model the other stages depend on; both phases are built ("Objects", "Errors and try/catch").

   1. **Pin down the bytecode as a file format.** Today's `Program` (stack
      instructions with file and line, the function table with arities, the lambda
      table with capture maps, local and global names) is the design; `-c` already
      prints it as text. Make it a versioned, serialisable format that any compiler can
      write and any VM can load, and have the PHP VM run programs from it.
   2. **Write a standalone VM in C** (a separate program, not called from PHP through
      FFI: every FFI call converts its values, which costs more than the work of one
      instruction). It needs its own values, an ordered hash for maps, byte strings,
      the operator rules of `Runtime\Values` exactly (including shortest float printing,
      PHP's `round`, overflow errors), the builtins, and memory management: reference
      counting suits lists and maps as values, but closures (and later objects) can
      form cycles, so it also needs a cycle collector or a tracing GC. Test it the way
      everything here is tested: the PHP compiler writes bytecode, and the C VM must
      match the PHP VM on every test, `tests/gaz` program and example, output, errors,
      locations and exit codes. The PHP implementation stays the reference. C is the
      default choice of language; Zig or Rust can still be decided when this starts.
      Expected speed: about that of Lua or PHP, 1 to 5 times PHP rather than 60 to 90.
   3. **Write the compiler in GazLang** (lexer, parser with the parse-time checks,
      code generator), running on the C VM, which is what makes it fast enough to use.
      It must produce the same bytecode as the PHP compiler for every file the tests
      cover, starting with the lexer harness below.
   4. **Bootstrap.** The GazLang compiler compiles itself, the resulting bytecode is
      checked in, and `gazlang` becomes the C VM plus that compiler; PHP is no longer
      needed to run or build GazLang. Whether the PHP implementation stays as a
      reference is decided then.

   Until then: **judge new features by what they cost in C, not only in PHP.** Values
   semantics suit reference counting; anything that leans on PHP behaviour (hashing,
   string conversion, float formatting) must be a rule GazLang defines and both runtimes
   implement. Keep growing the language by writing real GazLang (`lib/`, tools) and
   fixing what hurts; the lexer port waits for the lexical syntax to settle, since a
   second lexer doubles the work of every lexer change.

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
   `json_decode` follows PHP's `json_decode($text)`, objects as maps (checked against it by
   `JsonTest` on every `tests/json/y_*.json` and `n_*.json`, where the prefix says
   whether it must parse) and `json_encode` writes compact JSON. `lib/csv.gaz`
   (RFC 4180, checked against PHP's `fgetcsv` by `CsvTest` on `tests/csv/y_*.csv` and
   `n_*.csv`), `lib/functional.gaz` and `lib/format.gaz` back `examples/csv_report.gaz`.
   `lib/functional.gaz` has `map($x, $f)` and `filter($x, $keep)` (a list gives a list, a
   map a map with its keys), `reduce($x, $f, $initial)` and `sort($x, $compare)` (a stable
   merge sort giving a list of the values; `$compare` returns negative, zero or positive,
   so write `$a <=> $b`), in GazLang because builtins calling back into GazLang would need
   a re-entrant VM; `lib/sort.gaz`'s `sort_values` and `sort_by` wrap `sort`. Tested by
   `tests/gaz/lib/functional_test.gaz`. Scan
   long strings with `index_of` rather than character by character in GazLang: that
   halved CSV parsing time; the lexer's character classes are ASCII and explicit
   (`Lexer::is_space` is only space, tab, newline and carriage return).

## Decided, not built yet

`docs/design-review.md` (2026-09-17) lists the design questions settled before objects,
with the options considered and a status on each; update it as more are decided. What
it led to and is already built is described in its own section: `..` and strict `==`
(roadmap step 2), `/` always a float ("Numbers"), lists and maps (step 5), function values,
lambdas and closure state ("Function values"), `lib/functional.gaz` (step 7's GazLang
libraries), `fn` (step 4), objects ("Objects") and error objects, typed catch and
`finally` ("Errors and try/catch"). Not built:

- **Later, when real code needs them:** `interface` / `implements` (a parse-time check
  that the methods exist, plus `is_a`), `final`, and visibility with public implicit:
  `private` and `protected` on fields and methods. `#` and `##` are checked at parse
  time, `$obj.name` when it runs against the running method's class; a parent's private
  field is invisible to children, so a child may then declare its own field of that
  name. Not planned: traits, late static binding, static members, constants, operator
  overloading. The keywords are reserved already ("interface is reserved").

## Objects

The design decided 2026-09-17 (`docs/design-review.md`, "Object syntax"); phase 2, errors
as objects, is under "Errors and try/catch". Judged
by the C VM plan too (roadmap step 7): declared fields and single inheritance give every
class a fixed layout, so fields can be slots and methods a table. `examples/objects.gaz`
and `tests/gaz/objects/` show it working.

```
abstract class Shape {
    #name;
    fn _($name) { #name = $name; }
    abstract fn area();
    fn to_string() { return "{#name} with area " .. #area(); }
}

class Circle extends Shape {
    #radius;
    #history = [];                            // evaluated for each new object
    fn _($radius) {
        ##_("circle");                        // the parent's constructor
        #radius = $radius;
    }
    fn area() { return 3.14159 * #radius * #radius; }
    fn to_string() { return ##to_string() .. " (r = {#radius})"; }
}

$c = Circle(2);                               // constructing is a call; no new
echo $c;                                      // circle with area 12.56636 (r = 2)
echo is_a($c, Shape) .. " " .. $c.radius;     // true 2
$area = $c.area;                              // a bound method
```

- **Classes:** `class Name { ... }`, `class B extends A` (single inheritance), `abstract class`
  (can't be constructed: a parse error by name, a runtime error through a value) and
  `abstract fn name(...);` (only in an abstract class; a subclass must define it, or be
  abstract too). Classes are top level only, can be used before they are declared (parents
  too), and share the namespace of functions and builtins. The class body holds only
  fields and methods. Keywords reserved for later: `interface`, `implements`, `final`,
  `public`, `private`, `protected`.
- **Classes are values and constructing is a call:** `Point(1, 2)`, `$make = Point;
  $make(1, 2)`. `type_of(Point)` is `"class"`, `echo Point` prints `class Point`, and `==` is
  identity. A call by name is checked like a function call ("Class Point expects 2
  arguments, 1 given").
- **Constructing** makes the object, sets the field defaults (the parent's first, in
  declaration order), then runs the constructor `_` with the arguments; it gives the object.
  A class without `_` inherits its parent's, or takes no arguments. A child's `_` calls the
  parent's with `##_(...)`, which is only allowed in a constructor (not in a lambda in one);
  nothing runs it automatically, but the parent's field defaults are always set. `return
  value;` in `_` is a parse error, and `_` can't be used as a member (`#_`, `$obj._`).
- **Fields are declared:** `#x;` or `#x = default;`. A default is evaluated for each new
  object, so a `[]` default is never shared; it can use `#` (so `#b = #a * 2` reads an
  earlier field) but no `$` variables, a parse error. Reading a field that was never set
  is an error ("Property owner of Account is not set"); `??` reads it, or a field of null,
  as null, and `??=` sets it. Objects print only the fields that are set.
- **`#` is the object sigil:** `#` alone is the object (`return #;`), `#name` its field or
  method, checked against the class and its parents at parse time (undeclared, assigning
  to a method, a call's argument count), in methods, field defaults and lambdas in them;
  anywhere else it is "Cannot use #name outside a method". `#.name` says to write `#name`.
  Member names can be any word, keywords included (`#class`, `$o.echo`, `fn if()`).
- **`##name` is the parent's version of a method:** `##to_string()` extends an override,
  `##area` alone is a bound method running the parent's version. Which class's version
  runs is decided at parse time from the class it is written in (`ParentMethodAST::$definer`).
  Only methods, and not abstract ones; `##` alone is a parse error, kept free.
- **Members:** fields and methods share one namespace across the hierarchy (a child can't
  redeclare a field, or give a field a method's name). A method may override a parent's,
  but must accept every argument count the parent's accepts; constructors are exempt, and
  an abstract method can't replace a concrete one. `to_string` must accept no arguments.
- **Objects are handles:** `$b = $a; $b.x = 1` changes `$a`; lists and maps inside objects
  stay values. `==` on objects is identity; objects are always true; operators, keys,
  indexes and `foreach` on them are errors naming `object`. `type_of` gives `"object"`;
  `is_a($x, Point)` tests the class and its parents (the second argument must be a class).
  `json_encode` refuses classes and objects.
- **Properties are `.`:** `$user.name` reads and writes any declared member, checked when
  it runs ("Account has no member foo", "Cannot use . on map", "Cannot assign to method
  Account.deposit"). `.name` is one token, so the name is glued to the dot, but whitespace
  before it is allowed so chains can continue on the next line; `1.x` is an invalid number.
  `$obj.name(args)` calls a method, or a field holding a function: the object, then the
  member lookup (its errors come first), then the arguments, then the call.
  `$obj.method` is a bound method (`FunctionValue::bound()`: `function Account.deposit`
  when printed, named after the class whose version runs); two are `==` when object,
  class and method match.
- **Write paths:** a target is a variable or `#` followed by any steps of two kinds, index
  and property: `#items[] = $x`, `$rows[0].total = 5`, `$o.n += 1`, `#count++`. A path can't
  start at a call (`make().x = 1`). `Values::store()` takes the steps as index keys (null
  appends) or `Runtime\PropertyStep`, writes objects in place, and checks each field is
  declared; a path through `#` passes a table holding the object.
- **In strings, `#`, `##` and property paths interpolate only inside braces:** `"{#name}"`,
  `"{$user.name}"`, `"{##to_string()}"`. Bare `"#fff"` stays literal; `"$file.txt"` is
  `$file` then `.txt`. So `"{#"` in a string outside a method is an error: write `\{#`, or
  use single quotes.
- **A closure created inside a method keeps its object** (`FunctionValue::$receiver`), so
  `() -> #save()` works after the method returns.
- **`to_string()` is the one protocol method:** `echo`, `..`, interpolation, `join` and
  `to_string()` use it, also for objects inside lists and maps; it must return a string.
  Without one an object prints as `Account {#owner => "Werner", #balance => 75}`, and one
  already being printed (handles can form cycles) as `Account {...}`.

Implementation: `ClassDeclarationAST` holds the fields and methods (`FunctionDeclarationAST`
with `$class` and `$abstract`); once the whole program is read the parser resolves each
class (`$layout`: every field and its declaring class, parent's first; `$members`: every
concrete method and the class whose version runs; `$abstract_methods`) and checks `#name`,
`##name` and calls by name against it. `ThisAST`, `PropertyAST` (`#name` is a property of a
`ThisAST`), `MethodCallAST` and `ParentMethodAST` are the expressions. At runtime
`Runtime\ClassValue::build()` makes one value per class per run, with the method table
(name to the class whose version runs), the field defaults and the constructor's arity;
`Runtime\ObjectValue` holds the class and the fields that are set, by name.
`Values::property()`, `propertyExisting()` and `store()` are the one definition of member
access. `echo` reaching `to_string()` goes through `Values::$call_method`, which each backend
sets while a program runs: the interpreter calls the method, and the VM runs it to completion
in a nested `execute()` (see "VM").

Code generation: `PUSH_CLASS name`; `NEW Class argc` pushes a frame at `LABEL NEW_Class`
with the arguments as locals and the new object as receiver, which sets each default
(`SET_FIELD name`), runs `CALL_CONSTRUCTOR Class` and returns the object (`CALL_VALUE` on a
class does the same after its checks). Methods are at `LABEL METHOD_Class.name` with frames
keyed `Class.name` (`new Class` for initialisers), so no function or lambda name collides.
`LOAD_THIS`, `GET_PROPERTY name` (`_QUIET`, `_EXISTING`), `GET_METHOD name` then the
arguments then `CALL_METHOD argc name` (a method call without a bound method in between),
`CALL_PARENT Class name argc`, `BIND_PARENT Class name`. `SET_PATH path slot` spells the path:
`[k]` a key from the stack, `.name` a field, `[]` an append (`SET_PATH [k].total 0`);
`SET_PATH_THIS path` starts at `#`. Every VM frame holds its receiver.

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

`type_of` gives `"function"`, `echo add` prints `function add` (`[function add]` in a
list), functions are true in conditions, `==` is identity, and every other operator,
key or index use is an error naming the type (`Cannot use + on function`). `Values::typeOf()`
replaces `get_debug_type` everywhere, so every existing error message follows.
`json_encode` refuses a function. Code generation: `PUSH_FN add`; a call on a value is
callee, args, `CALL_VALUE argc`, which pops them and either calls the builtin or pushes a
frame as `CALL` does (`VM::link()` resolves each function's entry from its label).
`isConstant()` doesn't know about refs, so `[add, len]` is built at runtime.

### Anonymous functions

`$x -> $x * 2`, `($a, $b = 1) -> $a + $b`, `() -> 42`, and a block body
`($a) -> { ...; return ...; }` (`LambdaAST`). Parameters are `$` only with the same
rules as `fn` (`Parser::check_parameters()`, shared). An expression body's value
is returned and extends as far right as it can (`$x -> $x * 2 == 4` is
`$x -> ($x * 2 == 4)`; `$x -> $y -> $x + $y` nests), so a lambda sits at the ternary's
level: `1 + $x -> 2` is a syntax error, `$c ? $x -> 1 : $y -> 2` and `$f ?? ($x -> $x)`
parse. A block body returns only through `return` (falling off the end gives null), may
use `return` even at top level, and `break`/`continue` inside it can't reach a loop
around the lambda. No lookahead: `ternary()` marks a `(` it starts at as a possible
head, `Parser::parenthesised()` parses a comma list either way, and only if that `(` was
a head and `->` follows are the elements checked to have been written as `$param` or
`$param = default` (so `(($a)) -> 1` is an error, like `fn f(($a))`); `$x ->` is
recognised in `ternary()` after the fact. Errors about a parameter point at it.

Closures own their captured variables (decided 2026-09-17, `docs/design-review.md` #13).
`LambdaAST::$captures` lists every `$` variable the body and the defaults use that isn't
a parameter and that no plain `=`, `foreach` or `catch` in them assigns (a nested lambda
contributes what it captures, not what it assigns); the parser decides it. Evaluating
the lambda copies the ones that exist in the enclosing scope into the closure, where
they stay: the closure's calls read and write them there, so they persist between calls
and recursive calls share them, while the enclosing scope never sees the changes.
```
fn counter() { $n = 0; return () -> ++$n; }        // each counter() counts on its own
$fib = $n -> $memo[$n] ??= ($n < 2 ? $n : $fib($n - 1) + $fib($n - 2));
```
A captured variable that didn't exist is undefined inside ("Undefined variable: $x")
until the closure sets it (only `??=` can). A variable a plain `=` assigns is local to
each call, so a temporary sharing an outer name can't leak between recursive calls, and
`$n = $n + 1` on an outer `$n` is "Undefined variable". `$f = <lambda>` (plain `=` to a
local, when the body uses `$f`) sets the closure's `$f` to the closure itself
(`LambdaAST::$self`), so lambdas recurse; the outer `$f` can change afterwards. A closure
is one value: `$g = $f` shares its variables. `@globals` are never captured and are read
live.

`FunctionValue::closure()` holds the `LambdaAST` and the captured variables (by name in
the interpreter, by index in `LambdaAST::$captures` in the VM, which also keeps its
lambda index on the value); every evaluation makes a fresh value, so `==` is identity of
creation.
`FunctionValue::describe()` names a function in output and messages: `add`, or `-> at
file.gaz:12` / `-> on line 12` for a closure (`GazLangError::location()`, the one
spelling), so `echo $f` prints `function -> at file.gaz:12` and errors read `Function
-> at file.gaz:12 expects 2 arguments, 1 given at file.gaz:20`: where it was made,
then where it was called. Messages are only built when thrown (`Builtins::fitsArity()`
is the hot-path check). The interpreter runs named functions and
closures through one `invoke()`, which also sets the running closure, whose captured
names read and write `$closure->captured` instead of the locals. The code generator
emits `MAKE_CLOSURE n` and compiles each body after the functions under `LABEL LAMBDA_n`
from a worklist (a body can contain more lambdas), with a frame of parameters then
other locals, keyed `->n` in `local_names` so no function name can collide; captured
variables compile to `LOAD_CAPTURED`, `STORE_CAPTURED`, `LOAD_QUIET_CAPTURED` and
`SET_PATH_CAPTURED` on the running closure (kept in each VM frame).
`Program::$lambdas` carries each lambda's capture map (from an enclosing frame slot or
the enclosing closure's variable, to its own index), which the VM applies at
`MAKE_CLOSURE`, copying only what exists, then setting `$self`.

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

```
class NotFound extends Error {
    #key;
    fn _($key) { ##_("Not found: {$key}"); #key = $key; }
}

try {
    error(NotFound("id"));                    // or any runtime error, or error("text")
} catch (NotFound $e) {
    echo "{$e.message} ({$e.key}) at line {$e.line}";
} catch (Error $e) {
    echo $e.message;                          // division by zero, a missing key...
} catch ($e) {
    echo "something else was thrown: {$e}";   // error(5), error([1, 2])
} finally {
    echo "always";
}
```

- **What can be caught:** any runtime error (a failed operator or builtin, an undefined
  variable or key, division by zero, running out of call depth) and anything thrown with
  `error()`. Syntax and include errors happen before the program runs and can't be;
  `return`, `break`, `continue` and `exit()` are not errors and pass through.
- **`Error` is a builtin class** (`Parser::BUILTIN_CLASSES`, GazLang source parsed before
  every program, shown as `<builtin>` in locations): `#message`, `#file` (null for piped
  input), `#line`, `_($message)`, and `to_string()` giving the message. It can't be
  declared again, and programs extend it. Runtime errors and `error("text")` are caught
  as `Error` objects. It is only compiled into programs that catch, name or extend it.
- **`error($value)` throws any value.** A string is the message of an `Error`; anything
  else is caught as it is (`error(5)` catches `5`). An `Error` or subclass gets `#file` and
  `#line` where it is first thrown, so `error($e)` rethrows it keeping them. Uncaught,
  `bin/gazlang` prints `Error: ` and the value as echo would (through `to_string()`),
  with no location; runtime errors keep theirs.
- **Catch clauses** are tried in order; `catch (NotFound $e)` matches an object of that
  class or a subclass, `catch ($e)` (or `@e`) anything and must be the last, and a
  catch's class must be a class (parse time). An error no clause matches carries on
  unchanged.
- **`finally`** runs however the try and catch blocks are left: at their end, when an error
  passes (caught or not; it then carries on), and on `return`, `break` or `continue`, whose
  return value is worked out first. An error in it replaces what was in flight. `return`,
  `break` and `continue` can't leave it (parse error; loops and lambdas inside use them for
  themselves). `exit()` doesn't run it. A `try` has at least one catch or a finally.

Every runtime error is a `GazLangError` by the time it leaves a node with a location
(see "Errors" below), and `Interpreter::visitTryStatement()` catches exactly those, so
PHP bugs (`TypeError` and the like) are not swallowed. `error()` messages have
`show_location` false: uncaught they print exactly as given, but the location is
still recorded for catch. A thrown value rides in `GazLangError::$value`, `located()` keeps
it, and `caught()` works out once what catch sees (an `Error` object, or the value). The
interpreter's finally uses PHP's exception handling (catching the reused `ReturnSignal`
keeps its value); the code generator emits handlers:

```
TRY FINALLY_n                   (with a finally)
TRY CATCH_n
body
END_TRY
JMP ENDTRY_n
LABEL CATCH_n                   the handler pushed the GazLangError
CATCH_MATCH NotFound NEXTCATCH_n_0   a match replaces it with the caught value, else jumps
STORE $e
catch body
JMP ENDTRY_n
LABEL NEXTCATCH_n_0
CATCH_VALUE                     an untyped catch; after typed ones only, RETHROW instead
STORE $e
catch body
LABEL ENDTRY_n
END_TRY
finally body
JMP ENDFINALLY_n
LABEL FINALLY_n
STORE $#finally_error_n
finally body
LOAD $#finally_error_n
RETHROW
LABEL ENDFINALLY_n
```

break, continue and return leave the handlers themselves (`CodeGenerator::leaveTries()`):
`END_TRY` for each, and each finally block's code after its own, compiled as if outside its
try; a return first stores its value in a hidden variable. `RET` drops whatever handlers
its frame still has.

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
  conditions. Floats can't be keys, indexes or string positions.
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
to its matching `}`, so map literals and block bodies can be written inside). Anything else stays literal: `$5`, a lone
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
  through list and map paths). It does not decide what values mean.
- `src/Runtime`: what values mean, shared by every backend. `Values` holds the
  operators, truthiness, printing, keys and indexing as static pure
  functions; `Builtins` holds the builtin functions and their arities. Both backends
  call these rather than reimplementing them.
- `src/CodeGenerator`: compiles the AST to a `Program` of stack VM instructions, each
  with the file and line it came from, plus the variable name in each slot.
- `src/VM`: runs a `Program`. It is the default backend for `php bin/gazlang`;
  `--interpreter` runs the tree-walking interpreter instead. See "VM" below.

## VM

`VM::link()` collapses `STORE x; LOAD x; POP` into `STORE x`, resolves labels to
instruction positions and splits the instructions into parallel opcode and argument
arrays. `VM::run()` then runs `execute()`, one
dispatch loop over a value stack, globals, the current frame's locals, argument count,
closure and receiver, a stack of callers' frames, and a stack of try handlers. The loop is
re-entrant: while it runs, `Values::$call_method` runs a method (`to_string()`) in a nested
`execute()` that starts in the method's frame, shares the globals and the call depth, and
returns when that frame returns; an error it doesn't catch leaves it as an exception, into
the instruction that called the method, where the outer loop's handlers see it. Every operator and
builtin goes through `Runtime\Values` / `Runtime\Builtins`, and assignment through
`Values::store()`, so the VM and the interpreter share their semantics rather than
reimplementing them. The only exceptions are fast paths in the loop for the commonest
cases whose result is obvious (arithmetic and comparisons on two ints that don't
overflow, `==` on two ints or two strings, `JZ`/`NOT` on bools, `INDEX_GET` and `INDEX_GET_QUIET` on a list or on a map with an int or plain name key, `INC`/`DEC` on an int,
and the builtins `len`, `ord`, `chr` and `in_array` when their arguments are plainly
valid);
anything else, errors included, falls through to `Values`. Keep fast paths that way. Calls are frames in an array, not PHP recursion, so deep
recursion doesn't depend on PHP's C stack. Errors get the location of the instruction
that raised it (the innermost node the code generator was compiling, which is the node
the interpreter reports), then unwind to the innermost handler: frames made inside the
try are dropped, the stack is cut back, and the error map is pushed for the catch.
`SET_PATH` first unsets the loop's temporaries (`$first`, `$target`...): one still holding
the list or map being written made PHP copy all of it on every write, quadratically.

**The two backends must agree.** `GazLangTestCase::executeCode()` runs every snippet on
the interpreter and on the VM and fails if the output differs, or the error's class,
message, file or line (what catch sees) differs, and
`GazProgramTest`, `JsonTest` and `VMTest` (examples) do the same for whole programs. So
any new language feature needs both backends, or those tests fail. The code generator
also builds list and map literals made only of constants (with int or string keys) once at
compile time, pushed as one value, and `foreach` takes `len()` of its keys once. Order matters as
much as results: the VM's `KEY_CHECK` exists so a bad key fails before later
keys and the value run, exactly when the interpreter's does. After tuning, the
VM runs fib, arithmetic loops and JSON 2 to 5 times as fast as the interpreter.
`bin/gazlang` runs PHP with `opcache.jit=1235` (JIT for hot functions), restarting itself
with it as it does to turn off pcov: about 25% faster on the VM. PHP's default tracing JIT
made the VM twice as slow, and compiling everything on load (1205) cost 0.2s per run; the
JIT is skipped under Xdebug, which disables it with a warning in the output.

**Note:** any new AST node type (e.g. new BinOp/UnaryOp variants)
needs visitor support in *both* `Interpreter/Interpreter.php` and
`CodeGenerator/CodeGenerator.php` — don't patch one backend and leave the
other stale.
