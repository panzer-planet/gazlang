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
   `!=`, `==`, `&&`, `||` (short-circuiting in both backends), `!`, and unary
   `-` via `UnaryOpAST`. `!=` sits at the equality level, C-style. There is a
   real boolean type (`BooleanAST`, `true`/`false` keywords):
   - Comparisons, `!`, `&&` and `||` return booleans; `echo 1 < 2` prints `true`.
   - Truthiness stays C-like ("anything `!= 0`", shared by `if`, `while`, `!`,
     `&&`, `||`): `if (5)` is true, `if (0)` is false.
   - Booleans act as 1/0 in arithmetic and comparisons: `true + 1` is `2`,
     `true == 1` is true.
   - Concatenation uses the same spelling as `echo`: `"x" + true` is `"xtrue"`.
   - Code generation pushes `PUSH true` / `PUSH false`.
3. ~~**Add loops.**~~ Done. `while` has its own `WhileStatementAST`; `for` is
   desugared in the parser into `{ init; while (cond) { body; step; } }`, so the
   backends only know about while. All three `for` clauses are required.
   **Still open:** `break`/`continue`. Adding `continue` means giving `for` its
   own node, since the desugared form would skip `step`.
4. **Add functions and scoping.** Declarations, calls, parameters, `return`,
   and a proper environment/scope chain (variables are currently global-only).
   This is the single biggest unlock for self-hosting — a recursive-descent
   parser can't be written without functions to write it with.
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
