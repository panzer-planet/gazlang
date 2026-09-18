# Friction log: porting the code generator to GazLang

Every workaround the language forces while `src/CodeGenerator/CodeGenerator.php` and the writing
half of `Program.php` become `selfhost/codegen.gaz`, written down when it is met, with the options
for removing it. Each entry is reproduced, not assumed. The parser port's log,
`docs/parser-port-friction.md`, is the model; its four recommended builtins (`const`, `cwd()` and
`real_path()`, `fields()`, `builtins()`) were all built before this port started.

## Read before writing (2026-09-18)

`CodeGenerator.php` (1499 lines, 33 visitors) and `Program::write()` were read end to end for what
GazLang can't say. Nothing needs deciding before the port starts, which the parser port could not
say. What was found:

- **No variadic parameters.** `emit(string $opcode, ...$args)` is called 114 times, and
  `emitVariable($op, $variable, ...$args)` 19. `fn f(...$a)` is a parse error. The port passes a
  list, `#emit("CALL", [$name, $argc])`, which costs brackets at each call and nothing else.
  Variadics are on the "not planned until real code asks" list; this is real code asking, but
  weakly, since a list is a fine spelling of "some arguments". Not recommended on this evidence.
- **Lists can't be prepended** (parser log #7, "lists can't be joined", which this joins). The
  three path walks (`update()`, `pathAssign()`, `visitDeleteStatement()`) collect a path's steps
  from its end with `array_unshift`. The port collects them in the order met and walks backwards.
- **Dispatching on a node's class**: 33 `visitX` methods, reached in PHP through
  `AbstractNodeVisitor`, which builds the method name from the class name. GazLang can't build a
  name and call it (hole 2, `$obj.$name`), so the port has one `match (class_of($node))` with an
  arm per node class, which is what `class_of` was built for.
- **`clone`**, once: `visitStatement()` copies a postfix `++` to make it prefix. The port
  constructs a new `IncrementAST`, since the node's constructor takes every field. Not friction.
- **Not friction, but worth knowing**: `Program::absolute()` normalises paths *textually* on
  purpose, since a path written into bytecode need not exist when it is written. So the port
  needs a textual normaliser over `cwd()`, the kind of function the parser port just stopped
  using for includes. That one was wrong because includes are files that exist and the system
  knows best; this one is right because the writer's paths are text.
- **The dump format already reaches `existing`**: the code generator writes `IndexAST::$existing`
  and `PropertyAST::$existing` on the nodes it builds. `AST\Dumper` skips the field, so the
  port's nodes gain it and `selfhost/ast.gaz` skips it too.

Checked and fine: slot allocation (`$this->var_addresses[$name] ??= count(...)`) is the same
`??=` with `len()` in GazLang, which evaluates the right side only when the key is missing; and
`slice(to_string([$v]), 1, -1)` writes every constant `PUSH` takes (floats, `-0.0`, strings
needing `\$` and `\x00`, null, bools, nested lists and maps with `"1"` and `1` keys) exactly as
`Program::value()` does.
