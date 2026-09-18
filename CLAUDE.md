# GazLang Development Guidelines
 
## Build & Test Commands
```bash
# Install dependencies
composer install
 
# Run all tests
vendor/bin/phpunit
 
# Run the self-hosted drivers on big inputs on both VMs, which the default run leaves out
# (see "The way out" under roadmap step 7); do it before merging port or VM changes
php -d pcov.enabled=0 vendor/bin/phpunit --group whole-repository

# The self-hosted front end, which must print what -c, --tokens and --ast print; without a
# file it reads piped source, as gazlang does
php bin/gazlang -f selfhost/gazlang.gaz -- code examples/functions.gaz
php bin/gazlang -f selfhost/gazlang.gaz -- ast < examples/functions.gaz

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

# The VM in C (roadmap step 7, stage 2): build it, and run source (with the self-hosted
# compiler built into it) or bytecode on it
make -C vm
# vm/gazvm takes bin/gazlang's options: -f FILE, -c, -t, --ast, piped source, -- ARGS
vm/gazvm -f examples/functions.gaz
php bin/gazlang -c -f examples/functions.gaz > /tmp/f.gzb && vm/gazvm -f /tmp/f.gzb

# After changing selfhost/, rebuild the compiler the C VM has built in (a test fails until then)
php bin/gazlang -c -f selfhost/gazlang.gaz > selfhost/gazlang.gzb

# Which programs the C VM matches the PHP VM on (CVMTest checks the ones in vm/passing.txt);
# --update adds the new ones. After changing either VM, also fuzz them against each other
php -d pcov.enabled=0 vm/progress.php [FILTER] [--update]
php -d pcov.enabled=0 tests/fuzz_vms.php [RUNS] [SEED] [values|programs]

# The C VM's coverage by the harness, and its speed against the PHP VM and against PHP
php vm/coverage.php [file.c]
php vm/bench.php
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
   → `concat` (`..`) → `bit_or` (`|`) → `bit_xor` (`^`) → `bit_and` (`&`) → `shift` (`<<`, `>>`)
   → `additive` → `multiplicative` → `unary` → `postfix` (`[index]`, `(args)`) → `primary`. Binary levels share
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
   - `& | ^ << >> ~` and `&= |= ^= <<= >>=` are ints only, as `%` is: a float, bool, string
     or anything else on either side is `Cannot use & on float` (`Values::bitwise()` and
     `Values::bitwiseNot()`). A shift count must be 0 to 63, else `Shift count must be
     between 0 and 63, got 64`, where PHP quietly gives 0 above that. `>>` keeps the sign
     and `~` is two's complement (`~$x` is `-$x - 1`, which never overflows). Bits shifted
     off the top of a `<<` are gone and the result wraps (`1 << 63` is the smallest int,
     `-1 << 1` is `-2`), as in C, Java and Rust: a shift moves a bit pattern rather than
     scaling a quantity, so there is nothing to report as overflow, and every rule for what
     counts as "lost" treats `1 << 63` and `-1 << 63` differently though both give the same
     bits. No `>>>`, and no `&`/`|` on bools. Precedence is Rust's and Python's, not C's, so
     `$flags & MASK == 0` is `($flags & MASK) == 0`; see the tower in step 1. `..` is looser
     than `&`, `^` and `|` and tighter than the comparisons, so `"x = " .. $f & MASK`
     concatenates the masked value and `$f & MASK .. "!"` appends to it. Putting `..` between
     the bitwise levels instead (where the tower in the original decision had it) makes every
     unparenthesised mix of the two an error, in both directions, since one grouping hands a
     string to `&` and the other hands `..` nothing to do. `<<` and `>>` stay tighter than
     `..`, so `"n = " .. $x << 2` concatenates the shifted value; Lua puts `..` tighter than
     the shifts instead, which would break that. Code generation emits `BIT_AND`, `BIT_OR`, `BIT_XOR`, `SHL`, `SHR` and `BIT_NOT`, with
     no VM fast path: nothing measured uses them yet.
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
   indexes), the value can be a list pattern of variables (`foreach ($rows as $i => [$name,
   $age])`, see "Assignment"), and anything else is "foreach expects a list or map". The interpreter runs it directly; the code
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
     `bin/gazlang` restarts itself with `-d pcov.enabled=0`. It restarts at most once
     (`GAZLANG_RESTARTED`), since a `-d` on the command line isn't in `$argv`, and through
     `proc_open` with its own streams, so a program's standard output and standard error stay
     in the order it wrote them; diagnostics go to standard error. In-process code
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
   - `delete $a[k];` removes an element (`DeleteStatementAST`, decided and built 2026-09-17).
     The target is written like an assignment's, a variable or `#` followed by steps
     (`delete $rows[0]["total"];`, `delete #items[$i];`), and must end at an index: a field is
     "Cannot delete a field", `delete $a[];` and `delete f()[0];` are parse errors, and so is
     deleting a variable. A list's later elements move down, since its indexes are 0 to
     len - 1; a map keeps the order of the rest. Removing what isn't there is an error, as
     reading it is (`Undefined key: "k"`, `Index out of range: 5`), and so is a string or
     anything else that isn't a list or map. Keys are evaluated left to right, then the
     variable is read, as in an assignment. `Values::remove()` is the one definition, walking
     the path as `store()` does and cloning maps on the way; the code generator emits the keys
     then `DELETE_PATH path slot` (`_GLOBAL`, `_CAPTURED`, `_THIS`). `delete` is a keyword,
     though a method may still be called `delete`. There is no `pop`: take the last element,
     then delete it, which is how a list is a stack (`Values::remove()` takes the last element
     with `array_pop()`; `array_splice()` rebuilt the list on every pop, quadratically).
   - `...$x` in a list literal puts the elements of the list `$x` there: `[$first, ...$rest]`
     prepends, `[...$a, ...$b]` joins (`SpreadAST`, decided and built 2026-09-18, after the
     parser and code generator ports met it seven times between them). Elements are worked
     out left to right, and anything but a list is an error at the `...`, "Cannot spread
     map: only a list can be" (`Values::spread()`, the one definition), before the elements
     after it run. The result is a new list, as every list is a value. **Only in list
     literals**: `{...$m}`, `f(...$args)` and a bare `...$a` are parse errors that say so,
     and a pattern can't take the rest (`[$a, ...$b] = $l` says to use `slice()`); each can be
     added later without breaking anything. A constant can spread constants, since a constant
     is any operator on constants. `...` is one token, and the lexer takes the longest match,
     so `.....` is `...` then `..`; no valid program had three dots in a row before. Code
     generation: `ARRAY_EXTEND` appends a list to the list below, and a literal with a spread
     in it is never folded into one `PUSH`.
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
     `has_key($x, $key)` (a map's key, or a list's index), `keys($x)` (a list's indexes),
     `values($x)` (a map's values in insertion order; a list is already its values).
   - Other: `type_of($x)` (`int`, `float`, `string`, `bool`, `null`, `list`, `map`, `function`,
     `class`, `object`), `is_a($x, Class)`, `class_of($x)` and `fields($object)` (see "Objects"),
     `print($value)` and `print_error($value)` (write the value to standard output or standard
     error as `echo` does, without the newline, and give null: a tool writes its result to one
     and its diagnostics to the other), `error($value)` (raises an error that try/catch can catch, see "Errors and try/catch";
     uncaught it stops with `Error: ` and the value as echo prints it, exit 1, with no
     location), `exit($code = 0)`
     (stops the program with that exit code, 0 to 255, printing nothing; not an error, so
     `try/catch` doesn't see it: both backends unwind with `Runtime\ExitSignal`, which
     `bin/gazlang` and the tests' `runProgram()` turn into the exit code), `read_file($path)` and `write_file($path, $string)`
     (relative to the working directory; write creates or overwrites and returns
     null), `cwd()`, `real_path($path)` (absolute, with symlinks, `.` and `..` resolved, as
     `realpath(3)`; an error when nothing is there, and `""` and a NUL byte are nothing, where
     PHP's `realpath()` differs) and `file_exists($path)` (whether `real_path` would succeed;
     built 2026-09-18 for the parser port's includes, see the friction log #9),
     `read_stdin()` (all remaining standard input; empty when the program
     itself was piped in), `args()` (the command line arguments
     after the gazlang options, or after `--`). `bin/gazlang` rejects options it
     doesn't know, since `getopt` would silently drop them, so a program's own
     flags must come after `--`. `builtins()` (built 2026-09-18) is `Builtins::ARITIES` as a map,
     name to arity, in no promised order: the builtins of the runtime running the program,
     which is what the self-hosted parser checks calls against. That is only right while the
     compiler runs on the runtime that will run its output, which the bootstrap keeps true (the
     C VM plus the compiler's bytecode), and a loader refuses bytecode naming a builtin it lacks.
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

   1. ~~**Pin down the bytecode as a file format.**~~ Done 2026-09-17, see
      `docs/bytecode.md`, which specifies the file and every instruction (arguments, stack
      effect, errors) and is what the C VM will be built from. `gazlang -c -f x.gaz > x.gzb`
      writes a file and `gazlang -f x.gzb` runs it (recognised by its first line or a name
      ending in `.gzb`, so there is no flag, and a broken `.gzb` gets the loader's error rather
      than a syntax error); a normal run still compiles in memory and never goes through the text, while
      every test's VM side goes through write-then-read, so the suite tests the format too.
      - **Text, one instruction per line**, not binary or JSON: the self-hosted compiler
        writes it with `..` and `join` (GazLang can't pack bytes), a C loader needs only a
        line reader, a tokenizer and a literal parser, and step 3's "same bytecode as the
        PHP compiler" gets readable diffs. A binary cache can come later if loading measures slow.
      - **Instructions by name** (`LOAD 0`), never numbers: each loader converts names once,
        so dispatch speed doesn't depend on the file, and a mismatch is a load error.
        `Program::INSTRUCTIONS` is the one table of every instruction's arguments and stack
        effect; `BytecodeTest` keeps it, `docs/bytecode.md` and the VM's cases in step, and
        checks every instruction the compiler emits for the repository's programs is in it.
      - **A block of code per function** ("code objects", as in Lua and Python): `top`, `fn
        name fewest most` (a method is a function named `Class.name`, which no function name
        can be), `class Name extends Parent` with its `field` and `method` records followed by
        the code that makes an object, and `lambda n fewest most` with a `capture` line per
        captured variable and a `self` line. Each block ends with a `locals` line naming its
        slots, the parameters first. The loader concatenates them, the top level first, which
        ends in a HALT it adds; `CALL` names the function.
      - **Labels, scoped to their block**, resolved by the loader, not offsets, and labels and
        hidden variables are numbered within a block, so an instruction added in one block
        doesn't renumber the others. Peephole rewrites (`STORE; LOAD; POP`) belong to each
        VM's loader, not the format.
      - **Locations as `@ "file" line` lines** (`@ line` for piped or inline source) that apply
        to the instructions after them, and start afresh in each block. Paths are written
        relative to the main source file's directory and resolved against the bytecode file's,
        so bytecode saved next to its source reports exactly the paths running the source does;
        a name in angle brackets, like `<builtin>`, is not a path and is never rewritten.
      - **`PUSH` takes a GazLang literal** as the rest of the line (`PUSH {"a" => [1, 2.5]}`),
        which is why a value argument always comes last; `PUSH_STR` is gone. The stack also
        holds two values a program can't write, which the format names: the method entry
        `GET_METHOD` pushes, and a raised error.
      - **A version in the header**, and a loader that refuses any other; no compatibility
        promise until the bootstrap. Output is deterministic: the same source gives
        byte-identical bytecode.
      - **The loader checks everything**, so a file that loads is one the VM can run: unknown
        instructions, wrong argument counts, undefined labels (in that block), undefined
        functions, builtins, classes and lambdas, a class record naming a parent, field
        declarer or method block that isn't there, a slot the block, the globals or the closure
        doesn't have, a block other than the top level that runs off its end into the next
        one's code, and each block's stack, walked from the top following every jump: the same
        depth on every path into an instruction, nothing popped from an empty stack, a handler
        one deeper holding the error. That walk also gives the greatest depth a block reaches,
        which a C VM needs to size a frame. Lua and CPython both crash on malformed bytecode;
        this doesn't.
      - **The VM stays a stack machine** (decided 2026-09-17 while reviewing the format
        against Lua, CPython, the JVM, .NET and WebAssembly). Lua 5.0 moved to registers and
        got fewer instructions, but the compiler is the part being written in GazLang next, a
        stack machine's is much simpler, and JVM, CPython and .NET are all fast enough on one;
        a loader can merge common sequences into superinstructions without touching the format.
        Changing this after the bootstrap would mean a new format and a rewritten compiler.
   2. ~~**Write a standalone VM in C.**~~ Done 2026-09-18, see "The C VM" below. A separate
      program, not called from PHP through FFI: every FFI call converts its values, which costs
      more than the work of one instruction. The PHP implementation stays the reference. **C, decided
      2026-09-18** over Rust, Zig and Go: the heap (reference counting for copy-on-write
      values, plus a cycle collector for objects and closures) is unsafe code in every one of
      them, Rust VMs included; Go has no reference counts, so values would need persistent data
      structures and appends would stop being cheap; Zig is pre-1.0 and changes under a pinned
      toolchain. C gives computed-goto dispatch, a bootstrap that needs nothing but a C compiler,
      and Lua and CPython to crib from, and this project's differential tests and fuzzers, run
      under ASan and UBSan, are the safety net C code usually lacks.
      Expected speed: about that of Lua or PHP, 1 to 5 times PHP rather than 60 to 90;
      measured, 0.9 to 2.0 times (computed goto turned out not to pay, see "The C VM").
   3. **Write the compiler in GazLang** (lexer, parser with the parse-time checks,
      code generator). It must produce the same bytecode as the PHP compiler for every file
      the tests cover. The C VM is what makes it fast enough to *use*; it turned out not to be
      needed to *write* it, see the order below.
   4. **Bootstrap.** The GazLang compiler compiles itself, the resulting bytecode is
      checked in, and `gazlang` becomes the C VM plus that compiler; PHP is no longer
      needed to run or build GazLang. The PHP implementation stays as the reference
      (decided 2026-09-18), see "The bootstrap plan" below.

   **The order actually being followed** (decided 2026-09-18, once the lexer and parser were
   ported): stage 3 is running ahead of stage 2, on the PHP VM. The plan put the C VM first
   because a compiler in GazLang on the PHP VM is too slow to use, which is true and beside
   the point: a port is not used, it is checked against the PHP original on a corpus, and
   that loop (a dump from the PHP side, a driver printing the same, every `.gaz` file, a
   differential fuzzer, mutants) found a bug in the spec and needed nothing faster than what
   there is. So, in this order:
   1. **Decide the friction the parser port logged** (`docs/parser-port-friction.md`) before
      writing more GazLang: ~~constants~~ (built, see "Constants"), ~~`cwd()` and `real_path()`~~
      (built with `file_exists()`, see step 6's builtins), ~~`fields($object)`~~ (built, see
      "Objects"), ~~`builtins()`~~ (built, see step 6's builtins): all four done. The
      code generator port would have met all four harder than the parser did, the paths most of all,
      since a bytecode file's `@ "file"` records are relative-path rewrites it must reproduce
      byte for byte. Working around them a second time and then removing the workarounds twice
      is the expensive order.
   2. ~~**Port the code generator to `selfhost/`**, the same way, against `gazlang -c`: the same
      bytecode, byte for byte, for every file, which the format was made deterministic for.~~
      Done 2026-09-18, see "Port the code generator" below.
   3. ~~**The C VM**, stage 2 above, by which time everything above it exists and is tested.~~
      Done 2026-09-18, see "The C VM".
   The way out: each port's harness costs the suite about 35s under pcov (30s to 71s for the
   parser), and a third would be more again. If that becomes unbearable before the code
   generator is done, the C VM goes first after all, since it is what makes these harnesses
   cheap. Short of that, run the suite with `php -d pcov.enabled=0` (46s), or move the
   whole-repository corpora into a phpunit group, keeping the ports' own corpora always on.
   **The group is done** (2026-09-18, a temporary fix until the C VM): each port's check on
   every `.gaz` file outside its own corpus is `@group whole-repository`, which `phpunit.xml`
   excludes. The default suite went from 81s to 43s under pcov (47s with the code generator
   port's own corpus). The group is 110s under pcov with all three ports in it, 56s without
   (`php -d pcov.enabled=0 vendor/bin/phpunit --group whole-repository`, the way to run it).
   Run it before merging anything that touches a port, the lexer, the parser, the tree or the
   code generator.
   **The C VM made it unnecessary** (2026-09-18): the harnesses run their drivers on
   `vm/gazvm`, the optimised build, 24 processes at once (`CVM::driver()`), so every `.gaz` file
   is back in the default suite. It grew from 2679 tests to 3380 and from 65s to 73s under
   pcov, and the group, now only CVMTest's drivers on big inputs, went from 85s to 24s. The
   harnesses check the ports, not the VM, which is why they use the optimised build: CVMTest
   holds it to the PHP VM under the sanitizers, drivers included. A harness failure reports the
   first line that differs (`GazLangTestCase::assertSameText()`), since phpunit's own diff is
   quadratic and ran for minutes on a broken port's tree dumps.

   Throughout: **judge new features by what they cost in C, not only in PHP.** Values
   semantics suit reference counting; anything that leans on PHP behaviour (hashing,
   string conversion, float formatting) must be a rule GazLang defines and both runtimes
   implement. Keep growing the language by writing real GazLang (`lib/`, tools) and
   fixing what hurts; the lexer port waits for the lexical syntax to settle, since a
   second lexer doubles the work of every lexer change. 2026-09-18 added `& | ^ ~ << >>`
   with their compound forms, the `match` and `default` keywords, and block comments
   (see "Comments and names") and made keywords lowercase and exact, which together were the
   last changes the port was waiting on. Nothing known is left that would touch the lexer
   again: port it.

   ~~**Port the lexer to `selfhost/lexer.gaz` against the PHP lexer, which is the
   spec.**~~ Done 2026-09-18, without touching `Lexer.php`. `selfhost/lexer.gaz` (about 620
   lines for the PHP lexer's 1050) holds `Token`, `LexError` and `Lexer`, whose methods keep
   the PHP lexer's names so the two read side by side; `selfhost/gazlang.gaz` is the driver.
   `tests/SelfHostedLexerTest.php` runs `php bin/gazlang -f selfhost/gazlang.gaz -- tokens FILE` on
   every `.gaz` file under `examples/`, `lib/`, `selfhost/` and `tests/` (including
   `tests/lexer_corpus/`, the deliberately tricky cases, and `tests/gaz/`, but not
   `tests/parser_corpus/`, which adds nothing for a lexer), and requires output
   and exit code identical to `php bin/gazlang --tokens -f FILE`: one line per
   token, `LINE TYPE VALUE` as `Token::__toString()` formats it (strings quoted
   with `Lexer::quote()`, integers as digits, other values as source text, EOF
   with no value), then on a lexer error `Error: <message> on line N` and exit code 1. Add
   a corpus file whenever the port reveals an untested case; files named `error_*`
   must be exactly the ones that fail to lex.
   - **The scanner is an object, because `include` needs two lexers alive at once**:
     the parser's include sets the outer lexer aside and makes a new one mid-parse, and the
     parser port will have to as well. With globals that is a hand-written save and restore of
     every one of them, which is a worse price than the 10% hole 1 measured. It also leaves
     three names in the shared namespace instead of thirty `lex_*` functions.
   - **Two files, because including a file runs its top level code**: a lexer ending in
     `read_file(args()[0])` and a print loop could never be included by the parser. So
     `lexer.gaz` has no top level code at all (its tables were field defaults, and are class
     constants since there are constants) and the driver is separate. The lexer itself is not split
     further; nothing would be gained.
   - **What it leans on instead of porting**: `to_string([$s])` minus its brackets is
     `Lexer::quote()`, since a list prints its strings as literals, and `..` on a float is
     `format_float()`; `to_int()` and `to_float()` in a `try` are the overflow checks; hex is
     `$value * 16 + digit`, whose `Integer overflow` is the too-large check. Operators are one
     table matched longest first (3, 2, then 1 characters) in place of the PHP lexer's 150
     lines of hand-written branches, which is the same rule: every operator's prefixes are
     operators too, except `..`'s, and a lone `.` is not in the table.
   - **The driver catches `LexError` and raises its message again from the top level**
     (`error($e.message)`), because an uncaught error prints the calls that were running under
     it, and `--tokens` prints only the message. A bug in the lexer is not a `LexError`, so it
     still arrives with its location and trace. `LexError` carries `#reason` and
     `#source_line` beside the message, for the parser to add the file to: `#line` is `Error`'s
     own (hole 5) and says where in `lexer.gaz` the error was raised, which is never what a
     caller wants. For the same reason a `try` in the lexer holds only the `to_int()` or
     `to_float()` it is about: `catch (Error $e)` also catches running out of call depth, which
     the parser's recursive descent can do, and that must not come out as "Integer literal too
     large".
   - **Speed is hole 4, measured** (PHP VM, pcov on and no JIT as under phpunit, 100k
     iterations each, net of the loop): a comparison is about 0.7µs, a method call 3.5µs, a
     builtin call (`contains`, `slice`) 7µs, constructing a `Token` 10µs, and `lib/chars.gaz`'s
     `is_alpha()` 13µs, being three calls deep. So a call costs what ten to twenty comparisons
     do, and the first version, which advanced through a method and asked `is_alnum()` about
     every character, spent 16s on the corpus. What fixed it, in order of effect: the loops
     that read most characters (names, whitespace, both kinds of comment and string) walk a local index
     and move the scanner once; `//` comments jump with `index_of`; the per-token dispatch
     spells its comparisons out and looks punctuation up with `??`, which is an instruction
     rather than a call. With the JIT it lexes `examples/football.gaz` (22KB, 3800 tokens) in
     about 0.3s, roughly ten times the PHP lexer rather than the usual 60 to 90. `lib/chars.gaz`
     is still used off the hot path. None of this should survive the C VM unexamined: measure
     again there before keeping the spelled-out comparisons.
   - **The harness compiles the driver once and runs it on the VM**, since parsing and
     compiling the lexer again for each of 97 files on the interpreter cost more than lexing
     them. It now runs on the C VM (`CVM::driver()`, see "The way out"), 1.4s for every file. Eight corpus files that between them
     reach every part of the lexer also go through the interpreter. A CLI run would cost ~0.5s
     each. `tests/gaz/selfhost/lexer_test.gaz` tests it as the library the parser will include:
     a token made by hand, a caught `LexError`, two lexers at once.
   - **The corpus passing says the port is right on the corpus**, so two more checks were run
     by hand, and are worth running again after any change to either lexer. Differential
     fuzzing (`php tests/fuzz_lexers.php [RUNS] [SEED]`, not part of the suite: edge-case
     pieces strung together, or windows of corpus files with those pieces spliced in, through
     both lexers and compared; it ends with how often each error was reached, since a fuzzer
     stuck at "Unexpected character" finds nothing, and exits 1 on a difference): 23,000
     inputs reaching every error message, no difference, and a deliberately broken lexer is
     caught. The parser port wants the same script for the two parsers.
     Mutation testing (break one subtle rule in `lexer.gaz`, expect the harness to fail):
     five of fifteen mutants survived, four of them holes in the corpus rather than the port,
     now closed by `error_escaped_newline`, `error_unterminated_after_interpolation`,
     `error_exponent_sign_without_digits` and `error_unicode_surrogate`; `function` was the one
     token type no corpus file produced. The fifth survivor is equivalent: `read_braced_hex()`
     reads up to 7 digits to notice a seventh, but reading 6 finds a digit where the `}` should
     be and fails the same way. Give mutants a timeout: one that makes `skip_whitespace()` and
     its caller disagree about tabs loops forever.

   ~~**Port the parser to `selfhost/parser.gaz` against the PHP parser, which is the spec.**~~
   Done 2026-09-18. `selfhost/nodes.gaz` is the tree (a class per `src/AST` node, same names and
   fields), `selfhost/parser.gaz` holds `ParseError`, `VariableCollector` and `Parser`, whose methods keep the PHP parser's names, and `selfhost/gazlang.gaz` is the driver (it was `ast.gaz` until the bootstrap's step 1).
   `tests/SelfHostedParserTest.php` compiles the driver once, runs it on the C VM on every `.gaz`
   file under `examples/`, `lib/`, `selfhost/` and `tests/`, and requires output and exit code
   identical to `php bin/gazlang --ast -f FILE`: the tree, or `Error: <message> at FILE:N` and
   exit code 1. On an error there is no partial tree, because the checks made once a program is
   read write into nodes parsed long before (`field`, `definer`, a class's layout), so "the tree
   so far" is not a thing that exists.
   - **The dump is what `AST\Dumper` reads off the nodes, not what it knows about them**:
     `get_object_vars()`, 118 lines, no node types. A node is `label: Type line` with its fields
     indented under it in declaration order, a token prints as `--tokens` does, a scalar as a
     GazLang literal, an all-scalar array as a list or map literal on one line, any other array
     a line per element, and `@ "file"` comes before a node from another file. A dumper written
     by hand on both sides shares its author's blind spots: forget `step` on a while loop in
     both and a port that loses it passes. This way a field added to a node fails the harness
     until the port has it, which is the "a second lexer doubles the work of every lexer change"
     rule, enforced. It skips three fields: `existing` (the code generator's), `resolved` and
     `capture_names` (derived). It costs length (10 lines of source is 300 of dump, the corpus
     3.5MB) and ugly tuples (`arms: 0: 0: 0:`), since they are positional in PHP too. JSON was
     the alternative and lost: `json_encode` refuses objects in GazLang, and float and string
     escaping would have been a second format to agree on.
   - **The existing corpus barely tested a parser**: of 98 files 58 parsed, 31 failed in the
     lexer, and 4 were genuine parser errors, against about 80 messages in `Parser.php`. So
     `tests/parser_corpus/` was built alongside each stage: 288 files, 265 of them `error_*`,
     which must be exactly the ones that fail, one for each message and each order two checks
     could run in. `locations.gaz` puts each node's tokens on different lines, since a location
     tested on a one-line program is not tested. The lexer harness skips this directory.
   - **Where the port is not the PHP, and why.** The eleven binary levels are one table and a
     loop (`binary($level)`, precedence climbing) instead of a method each: the tower cost 24
     calls to reach a primary, and the same tree comes out 28% faster with 40 lines fewer.
     `lambda_heads`, a set of `(` tokens by `spl_object_id`, is one field, `#lambda_head`:
     nothing is read between `ternary()` marking a `(` and `parenthesised()` asking, and
     object ids are reused once freed, so the set was the riskier of the two. `member_uses`,
     keyed by node id, is a list whose index the node holds (not the record itself, which
     would make every class's tree a cycle for the C VM's collector to find: node, record,
     class, methods, node). `collect_variables()` filling two
     arrays by reference is a `VariableCollector` object. Every `try`/`finally` that restores
     parser state is gone: nothing catches a `ParseError` and carries on. `left_associative`
     calling `$this->$operand()` by name needed nothing, a bound method (`#unary`) is a value.
   - **Paths were resolved textually** at first, because GazLang could not ask for the working
     directory or a real path, and that was knowingly wrong in four cases: a symlink, or one
     file included by both a relative and an absolute path, was included twice ("already
     declared", a valid program refused), and a main file given by absolute path, or an include
     climbing out of the working directory, was shown differently. `cwd()`, `real_path()` and
     `file_exists()` replaced it the same day, so the port resolves includes as `Parser.php`
     does, and all four cases are tested: `SelfHostedParserTest` also parses from other working
     directories and writes a program with an absolute include where it runs, since a corpus
     file can't spell this machine's paths. A directory or an unreadable file is still told
     apart by reading it.
   - **Trees are walked with a stack, not recursion** (`VariableCollector.collect()`,
     `gazlang.gaz`'s `Dumper.dump()`), since a chain of operators is as deep on its left as it is
     long and two calls a level ran out of call depth at 5000 terms, which generated code can
     reach with `..`; `parser_test.gaz` walks a tree 6000 deep that it builds by hand, since
     parsing one cost the test 8s on the interpreter and printing one is a hundred megabytes,
     each level indenting. It costs the harness 8%. It needed a VM fix:
     taking a list's last element and deleting it, which is the stack there is, copied the
     list on every pop, because `array_splice()` rebuilds a list whatever it removes. 80,000
     pops took 22s; `Values::remove()` now uses `array_pop()` for the last element, 0.44s.
     What is left is parsing itself: recursive descent is about nine calls a nesting level, so
     source nested past about 1100 levels (`((((...))))`) runs out of call depth where the PHP
     parser doesn't, as an internal error rather than a `ParseError`. Not fixed: no real
     program is near it, and the fix is the VM's limit, not the parser.
   - **The port found a bug in the spec.** A name being declared was looked up before anything
     confirmed the token was a name: a bare `fn` at the end of a file was a PHP `TypeError`, not
     a GazLang error, `fn "len"() {}` was "len is a builtin function" and `fn f($a, '$a') {}`
     was "Duplicate parameter $a". `check_new_name()` and the duplicate check now look only at
     a token of the right type, which keeps every other error where it was.
   - **The first fuzzer was useless and said so only when tested**
     (`php tests/fuzz_parsers.php [RUNS] [SEED]`, not part of the suite). It changes programs a
     token at a time, since noise a character at a time dies in the lexer. The first version
     drew its programs from the whole corpus, which is nine tenths error files, so 3% of its
     inputs parsed, it reached 75 kinds of error, reported no difference, and then also reported
     no difference for two deliberately broken ports (`..` a level too tight, captures keeping
     assigned names), because only a tree shows those. Now most inputs start from a program
     that parses and usually swap a piece for another of its kind; a third still parse, and it
     catches both, and `??` made left associative, within 400 runs. 16,000 inputs over four
     seeds, about 95 kinds of error per seed, 5,148 parsed, no difference. **Break the port on
     purpose before believing a fuzzer that finds nothing.**
   - **Mutation testing**: 159 mutants, each one rule broken, each run against the corpus in a
     child process with a time limit (`proc_open` and a clock, since macOS has no `timeout`;
     none needed it). 23 survived and 3 more died only to a file outside the parser's corpus.
     16 were holes, now closed, most of them locations. 7 are equivalent, each because the PHP
     check it breaks is redundant: `self` for a global and a global counted as assigned (a
     global is never captured, so is in neither list), a class name checked before a function
     name (no name is both), a lambda parameter "written as itself" (an expression that starts
     at `$a` and is a variable is `$a`), the collector skipping maps (no node a lambda can reach
     holds a map of nodes), and two checks of `$a[]` that sit behind an earlier error for it.
   - **Speed, measured** (PHP VM, no JIT): `football.gaz` is 0.19s to lex, 0.14s to parse and
     0.15s to dump. The driver costs 8ms a file before it reads anything, 3ms of it linking.
     The harness is 19s without pcov and 36s with it, which took the suite from about 30s to
     71s (46s with `php -d pcov.enabled=0 vendor/bin/phpunit`). Includes are spliced, so
     `lexer.gaz` is parsed and dumped for each of the six files that include it; the six files
     that include `selfhost/` are a third of the time.
   - **What the language forced is in `docs/parser-port-friction.md`**, nine entries with
     options. Ranked by what they would remove from a self-hosted compiler: constants (token
     types are bare strings, where a typo is a branch that silently never runs), `cwd()` and
     `real_path()` (the only workaround that was wrong; both now built), `fields($object)` (a
     `parts()` method on every node class, a fifth of `nodes.gaz`; built, and the 29 methods
     are gone), `builtins()` (a hand-copied table of 44 arities, kept honest by a test; built,
     and the table and test are gone). Three holes the audits listed turned out not to bite:
     calling a method by name, identity keys for objects, and by-reference parameters.

   ~~**Port the code generator to `selfhost/codegen.gaz` against `gazlang -c`, which is the
   spec.**~~ Done 2026-09-18. `selfhost/codegen.gaz` (1077 lines) holds `CodeGenerator`, a port
   of `CodeGenerator.php` (1499) with the PHP method names, and `Program`, the writing half of
   `Program.php`; `selfhost/gazlang.gaz` is the driver, in its `code` mode. `tests/SelfHostedCompilerTest.php`
   requires output and exit code identical to `php bin/gazlang -c -f FILE`, byte for byte, on
   every `.gaz` file (on the C VM; from other working directories on the PHP VM, and four files
   on the interpreter too). The loader,
   `BytecodeReader`, is not ported: loading is the VM's job, so the C VM writes its own.
   - **It matched on every file in the repository at the first run**, which says the four
     friction builtins and the parser port's shape were right, and also that the repository
     can't be trusted to say more. So the corpus was built to reach every branch on its own and
     checked with 49 mutants: 47 die, and the two survivors are equivalent because the PHP
     checks they break are redundant (`existing` on the nodes `??=` builds, which `quietly()`
     never reads; `LOAD_FIELD` testing `existing`, which is only set on nodes whose `field` is
     false). The three holes the mutants found were a constant literal holding `null`, a method
     call with arguments, and a postfix `++` on a different line from its statement.
   - **Where the port is not the PHP**: `dispatch()` is one `match (class_of($node))` with an arm
     per node class, since GazLang can't build a method name and call it; `emit()` takes its
     arguments as a list, having no variadics; the three path walks share a `steps()` that turns
     the path round, lists having no prepend; the postfix `++` PHP clones is built again, with its
     location copied by hand, which a mutant showed is easy to forget. The writer's paths stay
     textual on purpose (`absolute()` over `cwd()`), since a path written into bytecode need not
     exist, and `PUSH`'s literal is `slice(to_string([$v]), 1, -1)`, which is `Program::value()`
     for every value a constant can be.
   - **`php tests/fuzz_parsers.php RUNS SEED code`** compares the two compilers on the parser
     fuzzer's inputs: 9,000 over three seeds, 2,768 compiled, no difference, and it finds a port
     broken on purpose (17 and 10 mismatches in 400 runs).
   - **Speed** (`football.gaz`, CLI with the JIT, best of three): 0.83s against `gazlang -c`'s
     0.29s. Loading the 4251 lines of the port is 0.37s of it, lexing and parsing 0.29s,
     generating code 0.07s and writing the text 0.10s.
   - **The friction is in `docs/codegen-port-friction.md`**. One entry wants deciding: lists
     can't be joined, prepended or spread, which the parser log said to fix "at the third use",
     and this port brought five more; spread in list literals is recommended. Every other entry
     is small.

   With the lexer, parser and code generator ported, everything above the VM exists in GazLang
   and matches the PHP on every file there is, and the VM exists in C and matches the PHP VM on
   every program, snippet and corpus file there is. **What is left is the bootstrap** (stage 4).
   Its first half already holds: the PHP compiler's bytecode for `selfhost/gazlang.gaz`, run on
   the C VM, compiles the compiler to the same bytecode byte for byte, and that compiles it again
   to the same (`test_the_self_hosted_compiler_compiles_itself_on_the_c_vm`, 2s). The C VM
   runs source with that compiler built in (see "The C VM"). What is left is the rest of the
   CLI (`-f`, `--`, `-c`, `--tokens`, `--ast`, piped input) and making it `gazlang`.

   **The bootstrap plan** (decided 2026-09-18). Four decisions:
   - **The C binary becomes `bin/gazlang`; the PHP script becomes `bin/gazlang-php`** and stays
     as the reference every harness compares against. `bin/gazlang` is then built, not checked
     in (gitignored), and the tests that are about PHP's own behaviour (the interpreter, the
     restart with pcov off, the JIT settings) call `bin/gazlang-php`. Deleting the PHP
     implementation was ruled out for now: the harnesses need a spec, and the spec is the PHP
     code, not files of expected output. Keeping `bin/gazlang` as PHP with the C one beside it
     was ruled out too: the default would stay the slow one.
   - **`--tokens` and `--ast` stay, with one driver.** `selfhost/gazlang.gaz` takes a mode
     (`code`, `tokens`, `ast`) and replaces `compile.gaz`, `tokens.gaz` and `ast.gaz`, so the
     binary holds the lexer and parser once rather than three times. The port harnesses then run
     `gazlang --tokens` and the rest directly, which makes the C CLI itself the tested thing.
   - **`--interpreter` is PHP only.** There is no tree-walking interpreter in C; the C CLI says
     so and exits 1, and `bin/gazlang-php --interpreter` keeps working. Porting it to GazLang
     waits for a reason.
   - **No interactive mode in the C CLI.** The PHP one runs each typed line as a program of its
     own, with no state between lines, and no test covers it: not a REPL. With nothing piped
     and no file, the C CLI prints its usage. A real REPL would be its own project.

   The work, in order, each step committed on its own:
   1. ~~**One driver, `selfhost/gazlang.gaz`**~~, done 2026-09-18: `gazlang.gaz -- MODE [FILE]`,
      MODE being `code`, `tokens` or `ast`, reading standard input when there is no FILE (the
      driver calls `read_stdin()` itself, and gives the ports a null path, which they already
      treated as piped source: `@ line` records, includes from the working directory). A bad
      mode or argument count is a usage message and exit 2. `selfhost/gazlang.gzb` is its
      bytecode, the C VM passes it `code FILE`, and the three port harnesses, the fixed-point
      test, `vm/passing.txt`, the fuzzers and `vm/bench.php` use it. The harnesses now also pipe
      each file in (`CVM::driver($mode, $files, piped: true, cwd: ...)`): the lexer's on two
      files, since piped text lexes the same, the parser's and code generator's on their whole
      own corpora and from the four other working directories, against the PHP side run with no
      path. Of twelve mutants of the driver (piped source losing a byte or gaining a name, `write()`
      or the parser losing or gaining the path, errors printed rather than raised or left
      uncaught, the EOF token dropped, a file read in place of standard input) ten die and two
      are equivalent: piped `write("x.gaz")`, whose directory is the working directory as `.`'s
      is (`write("sub/x.gaz")` dies, only to the piped runs from other directories), and
      `error($e)` for `error($e.message)`, since an error raised again from the top level prints
      no trace either way (a trace of one call is never printed; `$e.trace` keeps the original). `CVM::driver()` compiles the
      driver once per run rather than once per call, which took the three harnesses from 18s to
      15s under pcov with the piped runs added.
   2. ~~**The C CLI**~~, done 2026-09-18: `vm/gazvm` parses options as `bin/gazlang` does (PHP's
      `getopt` and the check after it for options it doesn't know, in `main()` in `vm/vm.c`), so
      `vm/gazvm FILE` is gone: it is `vm/gazvm -f FILE -- ARGS`, and every caller moved. Options
      end at `--`, at `-`, or at the first argument that isn't one; `-f` takes the next argument
      whatever it is. `-c`, `-t` and `--ast` run the built-in front end in that mode straight to
      standard output; running source runs it in `code` mode into memory first, as before. Piped
      input is read by `main()`, to see whether it is bytecode, and `read_stdin()` hands it to the
      front end (`piped_input`), so the program itself then reads nothing, as in PHP. `load()`
      takes a NULL path for piped bytecode ("on line N"). `--interpreter` says to use
      `bin/gazlang-php --interpreter` and exits 1, after the bytecode check as in PHP, and the
      help says so on that line, the one place the two CLIs' output differs on purpose. With no
      file and a terminal on standard input it prints the help on standard error and exits 1.
      A table of 54 invocations matched `bin/gazlang` byte for byte (stdout, stderr, exit code)
      on the optimised and the sanitized build, which is step 3's starting point. It found one
      bug in the C (`-f` as the last argument ran past the end of `argv`) and two in
      `bin/gazlang`, fixed there: a repeated `-f`/`--file` was a PHP `TypeError` (getopt gives
      a list), now "Give one file, with -f or --file"; and `-f 0` read standard input, `"0"` being
      falsy, so a given file is now always checked. The port harnesses still run the driver PHP
      compiles from source (`-f gazlang.gaz.gzb -- MODE FILE`) rather than `gazvm --tokens`:
      the built-in one is the checked-in bytecode, which after an edit to `selfhost/` is stale
      until regenerated, and the harness should test the edit. The fixed-point test is what holds
      the built-in one to the source. Also fixed: `CVM::leaks()` missed the `GAZVM_STATS` line
      after a program's standard error that didn't end in a newline (a false leak report in
      `fuzz_vms.php`'s programs mode).
   3. ~~**A CLI parity test**~~, done 2026-09-18: `tests/CliParityTest.php`, 77 invocations
      (each its arguments, the file piped in or `/dev/null`, and the working directory) of the
      programs in `tests/cli/`, run through `php bin/gazlang` and the sanitized `vm/gazvm` at
      once through `CVM::processes()`, which must give the same standard output, standard error
      and exit code. They cover every option and how options end, bad and repeated ones, files
      that aren't there or are directories, piped and file source with arguments, `read_stdin()`
      on both sides of that, runtime, syntax and lexer errors with their locations and traces,
      `exit()`, includes piped and not, from two working directories, the three front end modes
      each on a file, piped and on an error, and bytecode: saved elsewhere so its paths climb
      back with `../../../`, piped, broken, broken without the `.gzb` name, a `.gzb` that is
      source, and the front end modes refusing it. The help is compared with its one line
      about `--interpreter` changed, and a test of its own says what `--interpreter` prints in
      C. About 2.5s. Fourteen mutants of the C CLI (how options end, unknown and repeated ones,
      `-fFILE`, `--file=`, precedence of help, version and modes, a directory read, `-c` on
      bytecode, piped input not reaching the front end, piped bytecode named) all die. Not
      covered: a terminal on standard input, since the runner has none to give.
   4. **Rebuilding without PHP**: `make -C vm compiler` regenerates `selfhost/gazlang.gzb` with
      the C binary alone, as a three-stage bootstrap (GCC's shape, settled 2026-09-18):
      stage 1 is the current `vm/gazvm` compiling `selfhost/gazlang.gaz` (the new compiler,
      compiled by the old one), stage 2 is stage 1 compiling it again (the new compiler compiled
      by itself), stage 3 is stage 2 compiling it again, and **stage 2 must equal stage 3** byte
      for byte. Not stage 1 against stage 2, as this step first said: when a change alters what
      the code generator emits, stage 1 was written by the old code generator and stage 2 by the
      new one, so they differ by design, and the check would fail exactly when it is needed.
      Stage 2 differing from stage 3 means the compiler's output depends on how it was itself
      compiled, which is a real bug. Each stage runs as a program on the existing binary
      (`vm/gazvm -f stageN.gzb -- code selfhost/gazlang.gaz`), so no rebuild is needed between
      them.
      - **The working compiler is never replaced by a broken one**: the stages are written to
        `vm/build/`, and only once the check passes is stage 2 copied over `selfhost/gazlang.gzb`
        and the VM rebuilt. A compiler broken by an edit fails at stage 1 or 2 and leaves the
        current binary able to compile its fix.
      - **An explicit target, not a dependency**: `gazlang.gzb` must not depend on
        `selfhost/*.gaz` in the Makefile. A fresh clone's timestamps are arbitrary, and plain
        `make` would then try to regenerate the compiler with a binary that needs that compiler to
        be built. `make` always builds from the checked-in bytecode; `make compiler` is run after
        editing `selfhost/`.
      - **A language feature lands in two steps**: the compiler's own source can't use a feature
        until a compiler that understands it has been built. Add it to `selfhost/`, run
        `make compiler`, and only then use it there. A new instruction goes into the C VM (plain
        `make`) before any bytecode using it runs.
      - **While PHP is the reference, both ways must give the same file**: the fixed-point test
        still requires `gazlang.gzb` to equal what `php bin/gazlang -c` writes, so
        `make compiler` and the PHP one-liner agree, which this step proves by testing the target
        on a scratch copy. After the swap (step 5) changing the language needs no PHP at all.
   5. **The swap** (decision 1): rename, build `bin/gazlang` from `make -C vm`, point the tests,
      README, `docs/` and this file at `gazlang`, with PHP described as the reference.
   6. **Portability**: the VM has only ever been built on macOS, and "a bootstrap that needs
      nothing but a C compiler" is untested. `open_memstream()` and `realpath()` want
      `_POSIX_C_SOURCE` on Linux. At least one Linux build and suite run (Docker, or CI, whose
      setup is Werner's call) before the bootstrap counts as done. The repository is on GitHub
      as of 2026-09-18 (github.com:panzer-planet/gazlang), so a GitHub Actions job on Ubuntu
      running `make -C vm` and the suite is now the easy way to get that build: not set up yet,
      waiting for Werner to say when.
   Not blocking, left for later: `tests/fuzz_vms.php`'s programs mode reports a mismatch when a
   program exhausts memory (PHP's child process dies at its 2GB limit, the C VM is killed at the
   time limit; also on master before the source work), and the holes list below.

   **The README's examples are tests.** `ReadmeTest` pulls every ```` ```gaz ```` block that is
   followed by an output block out of `README.md` and requires it to print exactly that, on
   both backends. They had gone stale without anything noticing: the flagship program
   interpolated `{round($n, 2)}`, which is literal text, and caught an error as a map long
   after errors became objects. `README.md` is the invitation, `docs/language.md` the reference
   and `docs/internals.md` the contributor guide; this file stays the reasoning behind both.

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
   `lib/format.gaz`'s `pad_left($value, $width, $pad = " ")` and `pad_right` convert the value
   as echo does first (like `join`): display helpers take any value, while string functions
   like `upper` and `trim` stay strict.
   `lib/functional.gaz` has `map($x, $f)` and `filter($x, $keep)` (a list gives a list, a
   map a map with its keys), `reduce($x, $f, $initial)` and `sort($x, $compare)` (a stable
   merge sort giving a list of the values; `$compare` returns negative, zero or positive,
   so write `$a <=> $b`), in GazLang because builtins calling back into GazLang would need
   a re-entrant VM; `lib/sort.gaz`'s `sort_values` and `sort_by` wrap `sort`. Tested by
   `tests/gaz/lib/functional_test.gaz`. Scan
   long strings with `index_of` rather than character by character in GazLang: that
   halved CSV parsing time; the lexer's character classes are ASCII and explicit
   (`Lexer::is_space` is only space, tab, newline and carriage return).

## The C VM

Built 2026-09-18 (roadmap step 7, stage 2): `vm/`, plain C11 with only libc, libm and pthreads,
about 5000 lines with their comments. `vm/gazvm -f program.gzb -- [args]` runs the bytecode `gazlang -c` writes, and must
behave exactly as the PHP VM does: the same output, the same errors word for word, the same
locations, traces and exit codes. `bin/gazlang` stays PHP until the bootstrap. `vm/gazvm.h` says
which file does what; the files follow the PHP classes (`value.c` and `ops.c` are
`Runtime\Values`, `builtins.c` is `Runtime\Builtins`, `load.c` is `BytecodeReader`, `vm.c` is
`VM\VM`).

- **How it is held to the PHP VM** (`tests/CVMTest.php`, `tests/CVM.php`): each entry of
  `vm/passing.txt` is compiled by the PHP compiler, and the bytecode runs on both VMs, the C one
  built with ASan and UBSan, which must give the same stdout, stderr and exit code. The entries
  are every program in the repository and the corpora, the 531 `executeCode()` snippets
  (`tests/vm_snippets.txt`, one GazLang string literal per line, recollected by
  `php vm/snippets.php` after tests are added), `tests/bytecode_corpus/` (a hand-written broken
  file per loader message, since no compiler writes one, and the files named `error_*` must be
  exactly the ones refused), and the self-hosted drivers on big inputs, which cost seconds each on
  the PHP VM and so are `@group whole-repository`. The list only grows: `php vm/progress.php`
  finds what newly passes, `--update` adds it. It is 680 entries, all of what compiles, and adds
  about 10s to the suite, most of it macOS starting 670 sanitized processes (70ms each, which 24
  run at once hide). The C side's processes run while the PHP side runs in-process.
- **It passed nearly everything at the first run, so that proved little**, as with the code
  generator port. What found the bugs, in order of yield: `php vm/coverage.php` (clang's
  source-based coverage over the harness, which showed the whole call-depth family unreached, and
  `tests/vm_corpus/` was written against what it listed: 97% of lines now, the rest out-of-memory
  paths and states no compiler writes); `tests/fuzz_vms.php`, which writes programs throwing
  every kind of value at every operator, builtin, index, member and write path (mostly well typed,
  since random operands die at the first type check and test nothing behind it), or mutates the
  repository's programs; a million random floats printed by both; and 30 mutants of the C VM,
  of which the corpus kills 27 and the other 3 are equivalent for anything a compiler writes.
  **Break it on purpose before believing a run that finds nothing**, as with the ports.
- **What those found in the C VM**: `split_words` cut a `locals` line at 64 names; `slice()`
  negated the smallest int (UBSan); `round()` truncated a huge precision where PHP clamps it to
  a C int; `realpath(3)` on macOS accepts `file/` and `file/..`, PHP doesn't; and float printing
  missed next to powers of two, where the correctly rounded candidate of a length can fail to
  read back and the neighbour on the wide side is the shortest (4776 of a million floats, now
  none: `format_float()` tries both brackets at each length).
- **What they found in the PHP VM, fixed there**: `round()` called PHP's own, which changed
  within 8.5 (see "Numbers"); `..=` on a string whose `to_string()` turned the variable into a
  function hit PHP's `.=` on an object, a PHP `Error` escaping as an internal error (it now joins
  as `..` does, as the C VM did); `VM::link()` printed PHP warnings into a program's output for an
  unreachable jump or call to a name that isn't there, which the reader rightly doesn't check.
- **Errors are return values**: a function that can fail returns `bool`, the error in
  `vm_error`, and the caller passes the `false` up to the dispatch loop, which locates it and
  unwinds to a handler or leaves. No `setjmp`/`longjmp`, so every reference count stays right on
  the way out (the loader is the exception: it gives up at the first problem, and nothing it
  built matters then).
- **Values** are a 16-byte tag and payload. Strings, lists, maps, functions, objects and raised
  errors are reference counted; lists and maps are copied on write, which is PHP's value
  semantics exactly (a write through a variable copies a list or map only when something else
  holds it). A map is PHP's design: entries in insertion order with a removed one left as a hole,
  and an open-addressed index of positions, rebuilt (and the holes squeezed out) as it grows.
  Names are interned, so member lookups compare pointers. One-byte strings are 256 shared values,
  since `$s[$i]` made one per character a lexer reads.
- **Frames live on one value stack**: a call's arguments, already pushed, become the callee's
  first locals, and its stack grows above them, sized by the loader's walk. Calls between
  GazLang functions don't use the C stack; the one exception is a method run from inside an
  instruction (echo calling `to_string()`), a nested `execute()` as in the PHP VM, which can nest
  as deep as the call depth limit, so the program runs on a thread with a 1GB stack (reserved
  address space, backed only as used).
- **The cycle collector** (`vm/gc.c`): lists, maps, objects and functions are linked into one
  list while they live, and collecting is CPython's trial deletion, which needs no list of roots:
  take each reference one of them holds to another off the other's count, and what still has a
  count is held from outside and survives with everything it reaches. It runs only at a backward
  jump or a call, once as many containers have been made as were alive after the last
  collection. GazLang has no destructors, so freeing garbage runs no program code. A loop making
  300,000 self-referencing objects and closures peaks at 6MB rather than 206MB. The tested build
  collects every 64 new containers, so the harness exercises it under ASan; `make -C vm stress`
  builds one that collects at every chance (the corpus and the fuzzer pass on it), and
  `GAZVM_STATS` reports the most containers alive at once, which `test_the_c_vm_collects_cycles`
  checks (and leaks, below).
- **Speed** (`php vm/bench.php`: whole processes, CPU time, interleaved, best of several runs,
  PHP with the JIT settings `bin/gazlang` uses): 0.9 to 2.0 times the time of the same program
  written in PHP, and 6 to 26 times faster than the PHP VM, so the roadmap's expectation
  ("about that of Lua or PHP") is met. The self-hosted compiler compiles `football.gaz` in 0.09s
  against the PHP VM's 0.70s, and the self-hosted parser on `codegen.gaz` takes 0.23s against
  2.4s. What paid, each measured alone: an int fast path for `%` (the arithmetic loop -19%), the
  PHP loader's `STORE x; LOAD x; POP` peephole (-19%), sharing one-byte strings and not interning
  two names on every run of two instructions (the self-hosted parser -12%, objects -18%), and
  inline caches on the member instructions (-2.5%). What didn't, and was dropped: threaded
  dispatch (computed goto; the CPU predicts the switch's one jump well), syncing the stack pointer
  for nested calls only where needed, and a fast path for `==`. What is
  left in a profile of the self-hosted parser is the dispatch loop itself (two thirds), malloc and
  free, and the collector (6%).
- **It runs source** (built 2026-09-18): `gazvm -f FILE -- [args]` compiles a file that isn't
  bytecode with the self-hosted compiler, which is built into it, and runs the result, with the
  same output, errors, paths and exit codes as `php bin/gazlang -f FILE`. The compiler is
  `selfhost/gazlang.gzb`, checked in next to its source so its `@ "file"` records are exact,
  turned into a C array by `od` when the VM is built (`vm/build/compiler.c`: numbers only, so
  nothing to escape, and no trigraphs, which `-std=c11` turns on and the `??=` in it would be),
  and kept equal to what the PHP compiler writes for `selfhost/gazlang.gaz` by the fixed-point
  test; the VM runs it with the arguments `code FILE`. **The compiler's output is captured, not piped** (decided 2026-09-18, over two processes
  and a `load()` builtin): it runs as a program of its own with `output`, where `echo` and
  `print` write, pointed at an `open_memstream()` buffer, and the buffer is then loaded as if it
  were bytecode saved next to the source. Its errors go to standard error as they would anyway.
  Each run starts with fresh stacks, frames and globals, and what the compile left is dropped
  before the program starts, so the leak check still sees only the program. A source run
  costs about 10ms before the program's first instruction, 8ms of it loading the compiler.
  That was 52ms at first: `getcwd()`, which walks the file system on macOS, ran twice for every
  `@` line, and labels, instruction names and interned strings were found by searching or by
  allocating a probe. Now the working directory is read once per load, a block's labels are a
  table, instruction names a binary search, `str_intern()` allocates only for a new name, and the
  last `@` file's path is kept. CVMTest runs every source entry from its source on both VMs, so
  under the sanitizers the built-in compiler compiles each one; that costs the suite 3 to 4s.
- **Leaks are checked on every entry** (built 2026-09-18). The harness compares output, and a
  forgotten `decref` changes none, while ASan's leak detector doesn't run on macOS. So `counted`
  (`vm/value.c`) is every reference-counted value alive: strings, errors, lists, maps, objects and
  functions, but not what lives for the whole run on purpose (interned and one-byte strings, named
  functions and builtins, which are never counted). With `GAZVM_STATS` set, the end of a run drops
  the globals and everything from the top frame's locals up, collects cycles, and prints
  `gazvm: N values leaked`, N being what is left beyond the count right after loading (the
  constants in the code, which the program holds throughout). **Clearing rather than subtracting**
  what a finished program holds: dropping its variables is exact whatever they share (copies on
  write, a string in two globals, cycles), and runs the same free paths the program does, where
  subtracting would need a walk of everything reachable that counts each value once, a second
  collector that could be wrong in its own way. The constants are the exception, since they are
  fixed at load time and never freed, so the count after loading is exactly them. An uncaught
  error is checked too (the unwinding has dropped the stack; the error is dropped after it is
  reported); `exit()` and a file the loader refuses say `leaks not checked`, since the first ends
  mid-instruction holding whatever it holds and the second gives up without cleaning up.
  `CVM::runAll()` sets it on every C run and takes the line out of standard error, and
  `CVMTest`, `vm/progress.php` and `tests/fuzz_vms.php` fail an entry that leaks or prints no
  line, naming it; it costs the suite nothing measurable. The first run found one bug, in 13
  entries: `INDEX_GET` dropped its key with `sp--`, so every read with a string key made at run
  time leaked the key. Error paths were clean. Of five mutants on error paths, three were caught
  (unwinding to a handler without dropping the stack: 32 entries; an error freed without its
  trace: 326; an uncaught error keeping its frames' closures: 1), and two are equivalent
  (`raise_str()` dropping an earlier error, which is never there when one is raised: checked by
  aborting if it is, over the harness and the fuzzer; `RETHROW` leaving its error on the stack,
  which the unwinding right after drops).
- **Under pcov, `tests/vm_corpus/depth.gaz` runs its PHP side in its own process**
  (`CVM::DEEP`): its `to_string()` printing itself nests the PHP VM's `execute()` 10000 deep on
  the C stack, which segfaulted the whole suite (exit 139, no test named) from the day it was
  added until 2026-09-18, unnoticed because the suite was being run with pcov off.
- **Its style**: plain C, commented where the C isn't obvious (a flexible array member, a
  `goto` into shared code), for readers who know a little C. `ponytail:` comments mark known
  ceilings: float printing tries up to 34 `printf`/`strtod` pairs per float, fine until printing
  floats measures slow.

## Holes to fill next

Found 2026-09-18 by three audits, after the previous round (`delete`, `#trace`, `print`/
`print_error`, `values`, bitwise operators, `match`) was finished and described in its own
sections above: every PHP facility `src/Lexer`, `src/Parser`, `src/CodeGenerator` and
`src/Runtime` use, checked one by one against what GazLang can express; an 800 line compiler
for a small language written in GazLang, logging every workaround; and the GazLang already in
`lib/`, `examples/` and `tests/gaz`, read for shapes that recur because something is missing.

They are grouped by cause, since one fix closes several, and ordered by how much each deforms
a self-hosted compiler. Every claim below was reproduced; a struck-through entry has since been
decided and built, and the rest are still open.

When a workaround in this repo's own GazLang is the evidence for a hole, check with `git log`
when that file was written before believing it: hole 1 below was mostly a mirage because
`lib/json.gaz` and `lib/csv.gaz` deformed around an object they could not have used, having
been written the day before classes landed. A comment apologising for a workaround dates from
when it was written, not from now.

1. **Threading mutable state through calls.** Mostly a mirage, corrected 2026-09-18 after
   checking the dates. Multiple return values already work, since `return [$a, $b];` feeds the
   list patterns `[$x, $y] = f();`, and mutable state already crosses calls, since objects are
   handles: a `Scanner` with `#pos` is moved by any function it is passed to. `lib/json.gaz`
   and `lib/csv.gaz`, the evidence that the language was missing something, were both written
   2026-09-16 and classes landed 2026-09-17, so neither could have used the object they wanted;
   json.gaz now does (`JsonReader`), which cost 43 globals and gained about 10% of its running
   time. What is genuinely left is the footgun: appending to a list parameter silently does
   nothing (`fn add_to($l) { $l[] = 1; }`), and there is no sound way to make that loud, since
   the parser cannot tell it from `fn sorted($l) { $l[] = 1; return $l; }` without dataflow
   analysis. By-reference parameters would fix it and are not worth their cost against the C
   VM's refcounting; the answer for now is that mutable state belongs in an object, and the
   footgun stays documented. What this *did* surface belongs to item 4: holding state in an
   object costs about 10% against globals on the PHP VM, spread across method calls and field
   access rather than concentrated anywhere a local can fix. Measure it again once fields are
   slots in C, since the self-hosted lexer will be written in exactly this shape.
2. ~~**Dispatching on an object's type.**~~ The main part is done: `class_of($x)`, see
   "Objects". Still open, and each wants real code asking for it first: `$obj.$name` (dynamic
   member access, which `lib/sort.gaz` needs to sort objects rather than only maps, forcing
   `examples/football.gaz:594` to wrap objects back into maps to sort them), `json_encode` of an
   object (`fields($object)` now lists what it would write, built 2026-09-18; whether to is its
   own decision, with `to_string()` and cycles to settle), and a class's bare name for
   messages, where `to_string(class_of($n))` gives `class Point` and only the prefix is in the
   way. One thing to watch when the self-hosted compiler is written: `class_of` is strict, so a
   pass over a tree whose children can be absent needs `type_of($n) == "object"` before
   dispatching, which is per-node boilerplate of the kind `class_of` was meant to remove. If
   that shows up for real, it is the evidence for making it lenient.
3. ~~**`match` arms that are not `==`.**~~ Decided and built 2026-09-18: `match` takes an
   optional subject, and without one its arms are conditions, tested for truth as `if` does.
   See "match". The evidence was that of the 49 `if` conditions in `lib/json.gaz` only 15 are a
   plain `==`, that `examples/tokenizer.gaz`'s dispatch (the shape the lexer port will have) is
   an eight branch `if`/`else if` chain of which two branches are `==`, and that `match` appears
   in no file under `lib/` or `examples/` at all. What the check found already there was more
   than the hole admitted: `match (true)` gave a condition per arm with no language change. What
   it also found was the reason to build anyway, which the hole had not noticed: `==` is strict,
   so `match (true)` silently misses a condition returning a truthy non-bool, and nothing says
   why. The three questions were answered: **one feature, not two** (no range arms, because
   `"a".."z"` is concatenation today and a range arm would silently change what existing source
   means); **a bare truthy expression**, not Rust's `$c if cond`, since the subject does no work
   in a guard; and **subject-less rather than guards inside a subject-ful match**, which keeps
   `match ($x)` all-`==` so a jump table over it stays reachable, and needs no lexer change, the
   parens being the only tell. Still open, and not asked for by real code yet: nothing here
   gives a jump table, and `match ($x)` is still a linear chain of `EQUALS`.

4. **Scanning bytes.** `$s[$i]` allocates a one byte string and `ord($s[$i])` is two builtin
   calls plus that allocation; there is no `byte_at($s, $i)` and no "index of the first byte in
   this set". A lexer must classify every byte, so it cannot escape into `index_of` the way
   CSV parsing did. Some of this is the C VM's to fix and should be measured again once it exists.
   The lexer port met this head on and has the measurements: see "Speed is hole 4, measured"
   under roadmap step 7. A call per character, not the allocation, is what costs on the PHP VM.

   **The `..=` half is fixed (2026-09-18) and was the bigger of the two.** `$s ..= $x` used to
   lower to `$s = $s .. $x`, which loads the string onto the stack, so PHP holds two references
   and `.` copies all of it on every append. It now appends in place: `Values::concatAssign()`
   is the one definition, the code generator emits `CONCAT_ASSIGN slot` (`_GLOBAL`,
   `_CAPTURED`) for a plain variable instead of lowering, and `Values::store()` uses it for
   every interpreter path, fields and elements included. Measured, 160k appends on the VM:
   0.58s to 0.27s, and 640k: 6.80s to 0.52s, so it is linear rather than quadratic. Building a
   string with `..=` now beats the `$parts[] = ...` then `join` workaround (0.31s at 160k), so
   that workaround is no longer the advice. What is appended does not have to be a string:
   `..` is `toString` on both sides, so a string target converts the right side and appends
   that, which is the same operation. Only a target that is not yet a string falls back to
   the ordinary `..`, and `$out ..= $line_number` (a compiler emitting text) stays linear:
   2.46s to 0.29s at 160k when that was missed. The ceiling left: a field or an element
   (`#buf ..= $c`, `$a[0] ..= $c`) still lowers on the VM, since the path has to be walked to
   reach the string; only the interpreter appends those in place. Lift it if real code needs it.

   **Re-measure before trusting a number here.** Two figures recorded in this section were
   found wrong on 2026-09-18 when they were reproduced: `..=` at 160k was recorded as 6.34s and
   measured 0.58s (the shape was right, 160k was just too small to show the quadratic), and the
   object scanner was recorded as 1.04s against 0.34s hand-inlined and measured 0.51s against
   0.32s net of the 0.15s process startup, a 1.6x gap rather than 3x. The priority order in
   this list was derived from those numbers.
5. **Names and namespaces.** The keyword half is done: keywords are lowercase and matched
   exactly as of 2026-09-18 (see "Comments and names"), so `class If`, `fn Return()` and
   `class Match` all work and the self-hosted AST can call its nodes what they are. Included files share one
   namespace, so two files defining `helper()` is a hard error and every module prefixes its
   own privates (`json_*` is that scar), and including a file runs its top level code.
   `Error`'s members are reserved across the whole hierarchy, so a domain error cannot declare
   its own `#line` or `#message`.
6. **Smaller things, each with real uses behind it.** ~~No list concatenation or prepend.~~
   Built 2026-09-18 as spread in list literals (`[$x, ...$rest]`, see step 5), which replaced
   the merge sort drains in `lib/functional.gaz`, the seven workarounds the ports logged and two
   copy loops they had not. No reverse. ~~No constants.~~ Built 2026-09-18, see "Constants"; `examples/football.gaz`'s UPPERCASE
   zero-argument functions, which re-`split()` a 50 name string on every call, were the evidence. ~~Counting into a map
   needs the key twice, which `+=` creating a missing key from zero would remove.~~ Withdrawn
   2026-09-18: the idiom is already there. `$m[$k] ??= 0; $m[$k]++;` works, because `??=`
   creates a missing last key and evaluates the key once, where the recorded pattern
   (`$m[$k] = ($m[$k] ?? 0) + 1`, 5 places) names the key twice and so evaluates a key with
   side effects twice, which is the real defect in it. Rewrite those 5 places rather than
   changing the language. `+=` creating the key from zero also does not generalise: the
   starting value is 0 for `+=` but 1 for `*=` and `""` for `..=`, and nothing sensible for
   `/=`, so it would either single out `+=` (leaving `$m[$k] *= 2` an error beside it), or give
   every operator an identity nobody can read at a glance, or treat a missing key as null and
   let the operator decide, which is what this language already refused because `$a["n"] ..= "x"`
   would then quietly give `"nullx"`. PHP allows it and warns (`Undefined array key`), Python
   raises `KeyError` as GazLang does and answered the ergonomics with a library
   (`Counter`, `defaultdict`) rather than a language change, and JavaScript gives `NaN`.
   No identity key for an object (PHP's `spl_object_id`, 7 uses in
   `Parser.php` for side tables keyed by AST node), ~~no `cwd()`~~ and ~~no file-existence
   test~~ (built 2026-09-18: `cwd()`, `real_path()`, `file_exists()`), no `to_int`/`to_float` that returns null instead of throwing
   (every parse of untrusted text needs try/catch), no copy-with-change for objects, no
   `catch (A | B $e)` and no bare rethrow.

Two things the audits ruled *out*, both previously assumed: **bitwise operators were not
load-bearing** for the self-hosted lexer, since `lib/json.gaz:286` already encodes UTF-8 with
`intdiv` and `%`, `json_parse_hex4` covers `hexdec` and `json_encode_string`'s nibble table
covers `sprintf('\x%02X')`; they are still clearer and will be faster in C, but the
justification recorded for them was wrong. **Regular expressions are not load-bearing** either:
every use in the PHP lexer is a simple validator that a character loop replaces, and the
`preg_*` calls in `BytecodeReader` belong to the loader, which is C's job.

Deliberately not planned until real code asks for them: `**` and `sqrt`/`pow`/`log`, variadic
parameters and spread (pass a list), `time()` (time it from outside), `foreach` over a string
(`split($s, "")`), and regular expressions (the lexer's character classes are explicit on
purpose). Block comments left this list on 2026-09-18 and were built the same day, see "Comments".

## Decided, not built yet

`docs/design-review.md` (2026-09-17) lists the design questions settled before objects,
with the options considered and a status on each; update it as more are decided. What
it led to and is already built is described in its own section: `..` and strict `==`
(roadmap step 2), `/` always a float ("Numbers"), lists and maps (step 5), function values,
lambdas and closure state ("Function values"), `lib/functional.gaz` (step 7's GazLang
libraries), `fn` (step 4), objects ("Objects") and error objects, typed catch and
`finally` ("Errors and try/catch"), and block comments (see "Comments"). Not built:

- **Later, when real code needs them:** `interface` / `implements` (a parse-time check
  that the methods exist, plus `is_a`), `final`, and visibility with public implicit:
  `private` and `protected` on fields and methods. `#` and `##` are checked at parse
  time, `$obj.name` when it runs against the running method's class; a parent's private
  field is invisible to children, so a child may then declare its own field of that
  name. Not planned: traits, late static binding, static members, operator
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
  too), and share the namespace of functions, builtins and top level constants. The class
  body holds only fields, methods and constants (see "Constants"). Keywords reserved for later: `interface`, `implements`, `final`,
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
- **Members:** fields, methods and constants share one namespace across the hierarchy (a
  child can't redeclare a field or a constant, or give a field a method's name). The errors say so and suggest renaming
  (a cached value can't be `#summary` beside `summary()`: "call the field something else,
  like #summary_value"). A method may override a parent's,
  but must accept every argument count the parent's accepts; constructors are exempt, and
  an abstract method can't replace a concrete one. `to_string` must accept no arguments.
- **Objects are handles:** `$b = $a; $b.x = 1` changes `$a`; lists and maps inside objects
  stay values. `==` on objects is identity; objects are always true; operators, keys,
  indexes and `foreach` on them are errors naming `object`. `type_of` gives `"object"`;
  `is_a($x, Point)` tests the class and its parents (the second argument must be a class),
  and `class_of($x)` gives the object's own class, which is a value like any other: it
  constructs (`class_of($p)(1, 2)`), prints as `class Point` and compares by identity, so
  `match (class_of($n)) { NumAST => ..., AddAST => ... }` dispatches a pass written outside
  the node classes, which is what a compiler with more than one backend needs. The two answer
  different questions: `class_of($circle) == Shape` is false where `is_a($circle, Shape)` is
  true. `class_of` is an accessor rather than a predicate, so it is strict where `is_a`'s
  first argument is lenient: anything but an object is an error.
  `fields($object)` (built 2026-09-18, for the parser port) is a map of the fields that are
  set, by name without the `#`, in the order `echo` prints them (the parent's first, each
  class's in declaration order), so a pass can walk a tree without knowing its node classes.
  A field never set is left out rather than null, since reading one is an error; methods and
  constants are not fields. It is strict like `class_of`, and a copy, since a map is a value.
  In C it walks the class's slot table. `json_encode` refuses classes and objects.
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
with the arguments as locals (their slots are reserved, since a lowered update in a default
uses hidden variables) and the new object as receiver, which sets each default
(`SET_FIELD name`), runs `CALL_CONSTRUCTOR Class` (a frame with only the arguments, whose
call depth error is located at the construction, as in the interpreter) and returns the object (`CALL_VALUE` on a
class does the same after its checks). Methods are at `LABEL METHOD_Class.name` with frames
keyed `Class.name` (`new Class` for initialisers), so no function or lambda name collides.
`LOAD_THIS`, `LOAD_FIELD name` (a plain read of `#name` the parser found is a field:
`PropertyAST::$field`), `GET_PROPERTY name` (`_QUIET`, `_EXISTING`), `GET_METHOD name` then the
arguments then `CALL_METHOD argc name` (a method call without a bound method in between:
`GET_METHOD` pushes the entry `VM::link()` resolved for the object's class,
`ClassValue::$entries`, so a call builds no strings),
`CALL_PARENT Class name argc`, `BIND_PARENT Class name`. `SET_PATH path slot` spells the path:
`[k]` a key from the stack, `.name` a field, `[]` an append (`SET_PATH [k].total 0`);
`SET_PATH_THIS path` starts at `#`. Every VM frame holds its receiver.

## Function values

A bare function name is a value (`$f = add;`, builtins too: `$l = len;`): `FunctionRefAST`,
which the parser records and checks names a function once the whole program is read,
like calls ("Undefined function or constant: missing", since a bare name that isn't called may
as well be a mistyped constant). A bare name is unambiguous because variables
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

## match

Built 2026-09-17; the subject made optional 2026-09-18 (hole 3). A lexer and parser in GazLang
are long `if`/`else if` chains over characters and token types, and of the 49 `if`s in
`lib/json.gaz` nearly all run *statements* per branch, so an expression-only `match` (PHP 8)
would have missed most of it.

```
$kind = match ($type) {                       // an expression: every arm is an expression
    "int", "float" => "number",               // several values to an arm
    "list" => "a list",
    default => "other",                       // a trailing comma is allowed
};

match ($c) {                                  // a statement: an arm may be a block instead
    "\"" => { read_string(); }                // no comma needed after a block arm
    "\\" => { @pos += 2; }
    default => fail("bad character")          // and no ; after the closing }
}

match {                                       // no subject: the arms are conditions
    is_digit($c) => { number(); }
    is_alpha($c) || $c == "_" => { identifier(); }
    default => operator()
}
```

- **Without a subject the arms are conditions**, tested for truth as `if` does rather than
  compared, so `match { $c >= "a" && $c <= "z" => ..., is_digit($c) => ... }` is an
  `if`/`else if` tower with the shape of a `match`, and several values to an arm read as "or".
  The parens are the whole tell: one keyword, two comparison rules. Nothing true and no
  `default` is `No arm matched`, which names no value because nothing was compared. Everything
  else below is the same, including block arms, the order values run in and `default` last.
  `match (true) { ... }` still works and is the trap this replaced: `==` is strict, so a
  condition returning a truthy non-bool (`index_of`, `len`, a map lookup) never matched and
  fell silently to `default`. Write `match { ... }` instead.
- **Ranges are deliberately not arms.** `"a".."z"` is concatenation today, so a range arm
  would silently change what existing source means; any range syntax would have to invent a
  token for it, and `lib/chars.gaz` already wraps the character ranges in named predicates that
  a condition arm calls. Guards being their own construct also leaves the subject-ful form
  all-`==`, so a jump table over it stays reachable.
- **The subject is evaluated once**, then each arm's values are evaluated in order and
  compared with `==` (`Values::equals()`, so `1` matches `1.0` but `"1"` never matches `1`,
  and lists and maps compare by their contents). Only the values before the matching one run,
  so an arm value can have side effects and the ones after it never happen. Arm values are
  full expressions, not just literals.
- **No fallthrough**: the matching arm's body is the value of the whole `match`, and `break`,
  `continue` and `return` in an arm belong to the loop or function around the `match`.
- **Nothing matching and no `default` is an error**, `No arm matches "x"`
  (`Values::noMatch()`: a string is quoted, so `"1"` and `1` differ as they do to `==`, and
  anything that isn't a scalar or null is named by its type, `No arm matches list`, the
  convention `to_int()` and `to_float()` follow. Printing a list, map or object in full would
  run an object's `to_string()`, which raising an error must never do: it can throw, replacing
  the error being raised). Write `default => {}` to ignore the rest.
- **Arms separate with a comma**, optional after the last one and after a block arm, which
  ends in a `}` of its own, as in Rust. `default` must be last, since anything after it is
  dead, the way an untyped `catch` must be last, and a second `default` is the same error.
  A `match` with no arms is a parse error.
- **Only a `match` written as a statement may have block arms**, and it ends at its `}` like
  `if` and `while`, so a `;` after it is a parse error (as `if (1) {};` is). In a statement a
  `{` after `=>` is a block and a map is written `({...})`; in an expression a `{` after `=>`
  is a map literal. That is the rule the language already has for a lambda body.
- `match` and `default` are keywords in lowercase only, so `class Match` and `fn Default()`
  are ordinary names (`examples/football.gaz`'s `class Match` became `Fixture` while keywords
  were still case-insensitive, and could go back). Variables (`$default`) and members
  (`fn match()`) were never affected, since sigils and member names keep their own
  namespaces, and a word merely containing one (`json_match`) is an ordinary name.

Implementation: `MatchAST` holds the subject (null when there is none) and the arms, each
`[values, body, is_block]` with `values` null for the `default` arm. `Parser::match_expression($statement)` is called
from `primary()` (an expression, blocks refused) and from `statement()` (blocks allowed, no
`;` eaten). The interpreter's `visitMatch()` is the obvious loop. Code generation puts the
tests first and the bodies after, so each test knows its body's label: `LOAD`, the value,
`EQUALS`, `NOT`, `JZ MATCH_ARM_n_k` jumps when the two are equal (`JZ` jumps on false, as in
`logicalOp`). Past every test is `LOAD` then `NO_MATCH`, unless a `default` arm's `JMP` got
there first, so no instruction is unreachable and the loader's stack walk stays happy. Every
arm leaves exactly one value, a block arm pushing `null`, so the depth into `MATCH_END_n` is
the same on every path and the statement's `POP` always has something to pop. The subject
lives in a hidden `$#match_n`. Without a subject there is no hidden variable and no `LOAD` or
`EQUALS`: the condition alone, then the same `NOT` and `JZ`, and past every test
`NO_CONDITION`, which pops nothing.

## Constants

Decided and built 2026-09-18, the first of the four things the parser port asked for
(`docs/parser-port-friction.md` #8): `Parser.php` has five class constants and reads two more
from other classes, and in GazLang they were fields, which can be written to, share a
namespace with methods (`#builtin_classes` beside `builtin_classes()` was an error), and need
an instance to reach from outside.

```
const WIDTH = 3;
const AREA = WIDTH * HEIGHT;                  // in terms of others, in any order
const HEIGHT = WIDTH + 1;

class Token {
    const EOF = "EOF";
    const ENDS = [#EOF, Token.EOF .. "!"];
    fn is_eof($type) { return $type == #EOF; }
}
echo AREA .. " " .. Token.EOF;                // 12 EOF
```

- **The value is a constant expression, and the parser works it out**: literals, every
  operator, `?:`, lists and maps, other constants. No variables, no calls, no indexing.
  `Parser::fold()` evaluates it with `Runtime\Values`, the functions the interpreter runs on, so
  `1 / 0` and an overflow are the errors they are when a program runs, located at the
  operator, and the right side of `&&`, `||`, `??` and the untaken branch of `?:` are not
  worked out. Every constant is folded once the program is read, used or not, in the order
  declared; one that depends on itself is `Constant A depends on itself: A uses B uses A`.
- **Literals only was too little and any expression too much.** Literals only covers every
  table the ports have but not `const MAX = LIMIT * 2`. Any expression evaluated once
  (`const ORIGIN = Point(0, 0)`) brings an order of initialisation that include order would
  then change, a constant that can be changed through its handle, and a value that can't be
  inlined, so a slot and an instruction in both VMs. `football.gaz`'s `SURNAMES()` wanted it,
  for a `split`, and is a list literal now.
- **A use is its value, so nothing below the parser knows constants exist.** Once the
  program is read the parser stamps `constant` and `value` on each use: a `FunctionRefAST` for
  `NAME`, a `PropertyAST` for `#NAME` and `Class.NAME`. The interpreter returns the value and
  the code generator emits `PUSH value`; there is no new AST node, no new instruction, nothing
  in the bytecode format, and nothing for the C VM to build. A declaration is not in the tree
  at all (a class keeps its constants' expressions, which `--ast` shows).
- **A bare name was free syntax, and a typo in one was already a parse error.** Variables
  always have a sigil, so a bare word could only be a function or a class, checked once the
  program is read. A constant is a third kind, so a mistyped one fails before the program
  runs, which is the point of naming a string: token types were bare strings, where a typo
  is a branch that silently never runs. A top level constant therefore shares the namespace
  of functions, classes and builtins, and can be used before it is declared, as they can.
- **Immutable without a rule for it**: a write path must start at a variable or `#`, so
  `X = 2`, `X[0] = 2` and `X++` were already syntax errors, and lists and maps are values, so
  `$copy = KINDS; $copy[] = 1;` changes the copy. The one path that does start right is
  `#NAME[0] = 1`, so the parser notes what is written *under* each `#name`
  (`Parser::written()`, for assignments, `++`, patterns and `delete`) and refuses it for a
  constant: "Cannot change constant #NAME". For a field that is allowed, and for a method it is
  left to run, as before.
- **Class constants reverse "constants: not planned"**, which was decided with objects,
  before there was GazLang code to ask. `Class.NAME` was free too: it was a runtime "Cannot
  use . on class". A class's constant shares the namespace of its fields and methods, is
  inherited, and **can't be declared again by a child**: `#NAME` is worked out from the class
  it is written in, like `##name`'s definer, so there is no late binding for an override to
  mean anything by. **It is reached by name, not through a value**: `$class.NAME` and
  `$object.NAME` are not constants ("Cannot use . on class", "Token has no member EOF"), since
  working one out from a value needs a lookup when the program runs, which is a table in each
  class's record and an instruction. Both rules are the restrictive choice on purpose:
  loosening either later breaks no program, tightening would.
- **No enum yet.** Ninety lines of `const LEFT_PAREN = "LEFT_PAREN";` is boilerplate, but the
  token types are strings on purpose (they are the `--tokens` format), and whether it hurts
  is only known once a port's token types are constants. Wanted, later.
- **What it bought on the PHP VM is safety, not speed**: the lexer's four tables and the
  parser's seven are constants now, and `football.gaz` parses in 0.530s against 0.534s, which
  is noise. `Lexer.KEYWORDS` no longer needs a lexer to ask. The ports' token types are still
  bare strings; converting them is the evidence the enum question is waiting for.
- **What may be in a value is checked on the source, then the value is worked out**
  (`check_constant_expression()`, then `fold()`). Folding leaves out the side of `&&`, `||`,
  `??` and `?:` that isn't needed, so on its own it accepted `const A = true ? 1 : $x;` and
  would have refused it the day the condition changed. The code review found that, and four
  smaller things: a constant named `If` got the keyword hint that declared names are spared,
  a constant where a class goes was "Undefined class", a literal made of constants was built
  each time it ran (`isConstant()` now knows a stamped use, so `[Token.PLUS, Token.MINUS]` is
  one `PUSH`), and the port worked out a map entry's value before checking its key.
- **`fold()` is a third place that chooses between `Values` functions**, after the
  interpreter's visitors and the code generator, and the review said so. Running a constant
  through the interpreter instead would make the parser depend on a backend, and there will
  be no interpreter after the bootstrap; `Runtime\Values` is the sharing point and fold's
  share is 25 lines of dispatch. What was missing was a check, so `ConstTest::expressions()`
  is an expression for every operator and kind of value, each required to give a constant
  what it gives a running program on both backends, and `constant_values.gaz`, made from the
  same table, requires the port, which folds with GazLang's own operators, to agree.
- **Constants made the smallest int reachable in bytecode**: it has no literal
  (`-9223372036854775807 - 1`), so only a folded value puts it in a `PUSH`, written
  `-9223372036854775808`, and the loader read the sign and the digits separately, where the
  digits alone don't fit. `docs/bytecode.md` now says a loader must read them as one number,
  which `strtoll` does and a lexer doesn't.
- **The first language change since the ports**, so the first to be made three times: PHP,
  `selfhost/`, corpus. The harnesses did what they were built for: with the PHP side done, 57
  corpus files failed until `nodes.gaz` had the new fields. The port folds with GazLang's own
  operators, which are `Runtime\Values`, so it needs no evaluator, only `apply()`, a `match`
  with an arm per operator, since an operator is not a value. 69 corpus files, most of them
  generated from `ConstTest`'s tables, and 9,000 fuzzed inputs agree.

## Assignment

`=`, `+=`, `-=`, `*=`, `/=`, `%=`, `..=`, `??=` are right associative expressions whose value is the
new value (`AssignAST`, whose token says which). Compound assignment applies the
binary operator, so `..=` concatenates and `+=` on a string is an error. `++`/`--` (`IncrementAST`) work on
numbers only; prefix gives the new value, postfix the old. Targets are a variable
or an element of one (`$a["k"][0]++`), but appending (`$a[] = v`) is plain `=` only.

**List patterns** take a list apart: `[$a, $b] = $pair;` (`AssignAST` with a `ListPatternAST`
on the left, made from the list literal the parser read when `=` follows it). The list must
have exactly as many elements as there are targets (`Values::destructure()`: "Cannot
destructure a list of 3 elements into 2", "Cannot destructure map: only a list can be"),
checked before anything is written. The right side is evaluated first, then each target's
keys are evaluated and it is written, left to right, so `[$a, $b] = [$b, $a]` swaps. Targets
are anything `=` can assign (`[#x, $l[0], @g, $o.y] = ...`); the value of the expression is
the list. Not supported, each a syntax error: nesting (`[[$a, $b], $c]`), map patterns,
compound operators (`+=`), appending targets. `foreach ($x as [$a, $b])` takes each value
apart the same way, with variables only. For closures, pattern targets count as assigned by a
plain `=`. The code generator emits the value, `DESTRUCTURE n` (the check, leaving the list),
stores it in a hidden `$#destructure_n`, and lowers each target to `target = $#destructure_n[i]`.

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

`..=` is the exception: on a plain variable it appends in place rather than lowering, so
`$s ..= "b"` is the value then `CONCAT_ASSIGN slot` (`_GLOBAL`, `_CAPTURED`), which pushes
the new value. `Values::concatAssign()` is the one definition, used by that instruction and
by `Values::store()`, and it grows a string target with PHP's own `.=`, converting whatever
is appended the way `..` already defines it (`toString` on both sides), so appending a number
is as linear as appending a string. Loading the string onto the stack first is what made building one
quadratic: PHP then holds two references and copies all of it on every append, the same
trap `SET_PATH` unsets its temporaries for (see "VM"). A field or an element still lowers on
the VM, since the path has to be walked to reach the string.

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
  input), `#line`, `#trace`, `_($message)`, and `to_string()` giving the message. It can't be
  declared again, and programs extend it. Runtime errors and `error("text")` are caught
  as `Error` objects. It is only compiled into programs that catch, name or extend it.
- **`error($value)` throws any value.** A string is the message of an `Error`; anything
  else is caught as it is (`error(5)` catches `5`). An `Error` or subclass gets `#file` and
  `#line` where it is first thrown, so `error($e)` rethrows it keeping them. Uncaught,
  `bin/gazlang` prints `Error: ` and the value as echo would (through `to_string()`),
  with no location; runtime errors keep theirs. The text is only made once nothing has
  caught the value (`GazLangError::uncaught()`, at the end of `interpret()` and
  `VM::run()`), so throwing never runs `to_string()`.
- **`#trace` is the calls that were running** when the error was raised, innermost first, as
  a list of strings: `["inner at fib.gaz:3", "outer at fib.gaz:4", "top level at fib.gaz:7"]`.
  Each call is shown where it was running, so the innermost is where the error happened and
  the ones around it are at the call they made. A function is its name, a method
  `Class.name`, a constructor `Class._` inside a `new Class` frame, a lambda `->`, and the
  outermost is `top level`. Recursion can be 10000 deep, so a trace keeps the innermost 10
  and the outermost 10 with `... 9981 more` between them (`GazLangError::trace()`). It is
  recorded where the error is first raised and travels with it, like `#file` and `#line`, so
  a rethrow keeps it. Printing runs `to_string()` outside the program's own calls (the VM
  runs it in a loop of its own), so an error inside one starts a trace there. `error(5)`
  carries no trace: a bare value has nowhere to put one. An uncaught error prints its trace
  under the message, indented, unless it is a single call, which the message already names
  (`GazLangError::report()`, used by `bin/gazlang` and the tests' `runProgram()`).
  The interpreter records each call as it makes it (about 3% on a program that does nothing
  but call); the VM reads its frames when an error happens, which costs nothing until then.
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

## Comments and names

`// to the end of the line` and `/* ... */`, both skipped by the lexer, so no token reaches
the parser and `--tokens` never shows one. Built 2026-09-18.

- **Block comments nest**, as in Rust and Swift rather than C and PHP: an inner `/*` opens
  another one and the first `*/` only closes that, so commenting out a region that already
  contains a comment works, which is what a self-hosted compiler wants. It costs a depth
  counter in `Lexer::skip_block_comment()`. This had to be settled before anything was
  written, since changing it later would silently change what existing source means.
- **An unterminated one is a lexer error** at the line the outermost `/*` opened on
  ("Unterminated block comment"), as an unterminated string is: at the end of the input every
  comment still open is open because that one never closed.
- **A `/` is only an opener before a `*`**, so `8 / 2` and `/=` are untouched, and `/*` inside
  a string literal or after a `//` is just text. There is no doc-comment convention.
- `editors/gaz.tmLanguage` highlights them, nesting included: the `block-comment` repository
  rule includes itself, so an inner `/*` starts another begin/end pair and the first `*/`
  closes only that one, which is how Rust's own grammar does it. A plain begin/end rule ends
  at the first `*/` and highlights the rest of the comment as code.

**Keywords are lowercase and matched exactly** (decided and built 2026-09-18), as in every
language designed since C. They used to be case-insensitive, copied from PHP, which reserved
every capitalisation of all thirty of them: `class If`, `While`, `Return`, `Match` and `True`
were syntax errors, which is exactly what a self-hosted AST wants to call its nodes, and is
why `examples/football.gaz`'s `class Match` became `Fixture` when `match` landed.

- **PHP was the wrong model to half-copy.** PHP matches keywords *and* function, class and
  method names case-insensitively, consistently; GazLang matched only keywords that way, so
  `IF (1)` worked while `GREET()` did not find `fn greet()`. That is PHP's wart without PHP's
  rule, and a reader could not derive either.
- **Nothing real depended on it**: the only non-lowercase keywords anywhere in the repo's
  `.gaz` files were in `tests/lexer_corpus/names.gaz` and `objects.gaz`, which existed to test
  the old rule and now test this one.
- **A miscapitalised keyword says so**, the way `function` does ("Declare functions with fn,
  not function"): `Return 1;` is "Expected ';' but found '1' (keywords are lowercase: write
  'return', not 'Return')". `Parser::keyword_hint()` looks at the token the error is at and
  the one before it, which covers a keyword used as a statement (`Return 1;`, `ECHO "x";`),
  and the deferred name check covers a bare one or a call (`True`, `IF(1)`). It does not cover
  `IF (1) { }`, where the failure lands at the `{`, two tokens past the name. The check only
  runs while an error is being built, never on the parsing path.
- Sigils and member names keep their own namespaces, as before, so `$If`, `@while_1` and
  `fn match()` were always fine.

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
  ints only (`Cannot use % on float`), and so are `& | ^ << >> ~` (see step 2).
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
  three return floats, as in PHP; `Builtins::round()` does php-src's steps itself rather than
  calling PHP's, since 8.5.7 gives `round(7e15)` as `7000000000000001.0` and later releases don't,
  and GazLang must not depend on the PHP release running it), `abs` (keeps the type), `intdiv($a, $b)`, `min($a, $b)` and
  `max($a, $b)` (two numbers, or two strings compared as `<` does; anything else is an error;
  a tie gives the first, so `min(1, 1.0)` is `1`; for a list, `reduce($xs, max, $xs[0])`).

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
- `src/CodeGenerator`: compiles the AST to a `Program`: one block of code per function,
  each instruction with the file and line it came from, plus the variable name in each
  slot and the class and lambda records. `Program` also writes and reads the bytecode
  file (`BytecodeReader`), which `docs/bytecode.md` specifies.
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
valid, and `LOAD_FIELD` on a field that is set);
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
