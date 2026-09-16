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
php bin/gazlang -f examples/example.gaz
 
# Generate code instead of interpreting
php bin/gazlang -f examples/example.gaz -c
```
 
## Code Style Guidelines
- **Namespaces**: Use `GazLang\` namespace root with PSR-4 autoloading
- **Classes**: PascalCase (e.g., `Parser`)
- **Methods/Functions**: camelCase (e.g., `getNextToken()`)
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
   → `additive` → `multiplicative` → `unary` → `primary`. Binary levels share
   `left_associative()`; add a new level by adding a one-line method there.
2. ~~**Add comparison and logical operators.**~~ Done. `<`, `>`, `<=`, `>=`,
   `!=`, `==`, `===`, `!==`, `&&`, `||` (short-circuiting in both backends), `!`, and unary
   `-` via `UnaryOpAST`. `!=`, `===` and `!==` sit at the equality level, C-style. There is a
   real boolean type (`BooleanAST`, `true`/`false` keywords):
   - Comparisons, `!`, `&&` and `||` return booleans; `echo 1 < 2` prints `true`.
   - Truthiness lives in `Interpreter::isTruthy()`, shared by the interpreter's
     `if`, `while`, `!`, `&&`, `||`. The code generator's `JZ`/`NOT` have no VM
     defined behind them yet; a VM must reuse the same rules. Numbers and booleans are C-like (`if (5)` is true,
     `if (0)` is false); strings are true unless empty, so `"0"` is true.
   - Booleans act as 1/0 in arithmetic and comparisons: `true + 1` is `2`,
     `true == 1` is true.
   - Concatenation uses the same spelling as `echo`: `"x" + true` is `"xtrue"`.
   - Two strings compare byte by byte (`"1" != "01"`, `"10" < "9"`). Mixed
     string/number comparisons still follow PHP (`"5" == 5`).
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
   - Gotcha: with the pcov (or Xdebug) extension enabled, every PHP call uses the
     C stack and deep GazLang recursion segfaults (exit 139) well before the limit.
     Run with `-d pcov.enabled=0` when that matters.
   - Reserved for later: `#` for object properties (`@` is taken by globals).
5. **Add arrays (and maybe maps).** At minimum arrays/lists with indexing;
   a hashmap/dict type will matter once GazLang needs to represent its own
   symbol tables and ASTs.
6. **Design a minimal standard library.** File reading, string manipulation
   builtins, basic I/O — the plumbing self-hosting quietly depends on.
7. **Begin porting the lexer/parser to GazLang itself.** Only once functions,
   arrays, and a stdlib exist does porting `Lexer.php`, `Parser.php`, etc. into
   GazLang source become plausible. Everything before this step is groundwork.

**Note:** any new AST node type (e.g. new BinOp/UnaryOp variants)
needs visitor support in *both* `Interpreter/Interpreter.php` and
`CodeGenerator/CodeGenerator.php` — don't patch one backend and leave the
other stale.
