# Friction log: porting the code generator to GazLang

Every workaround the language forces while `src/CodeGenerator/CodeGenerator.php` and the writing
half of `Program.php` become `selfhost/codegen.gaz`, written down when it is met, with the options
for removing it. Each entry is reproduced, not assumed. The parser port's log,
`docs/parser-port-friction.md`, is the model; its four recommended builtins (`const`, `cwd()` and
`real_path()`, `fields()`, `builtins()`) were all built before this port started.

## Summary

`selfhost/codegen.gaz` is 1077 lines for `CodeGenerator.php`'s 1499 and `Program::write()`
with its helpers, and gives byte-identical bytecode on every `.gaz` file in the repository at
the first run of the harness. Nothing the language lacks got in the way; what it cost:

| # | Friction | Workaround | Recommended |
| --- | --- | --- | --- |
| 1 | ~~Lists can't be joined, prepended or spread~~ | **built**: spread, `[$x, ...$rest]` | done, see below |
| 2 | A list pattern can't skip an element | index the list, or name what isn't used | nothing yet |
| 3 | Copying a node loses nothing in PHP; rebuilding it can | set the location by hand | nothing: one use, and a mutant guards it |
| - | Variadics, dispatch by class | a list; one `match` | nothing, see below |

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

## Met while porting

### 1. Lists can't be joined, prepended or spread (parser log #7, and now past its third use)

The parser log recommended a builtin "at the third use". This port adds five: the three path
walks that PHP writes with `array_unshift` (now one `steps()` method that collects and turns the
list round), and two lines of the writer that PHP writes as `['locals', ...$names]`, for which
the port has a `with($first, $rest)` helper. `[1, ...$x]` is a parse error. Options:

- **Spread in list literals**, `[$first, ...$rest]`: covers prepend, append-a-list and join in
  one syntax, and is what the PHP source already says. A parser and both backends, and a
  `...` token the lexer doesn't have. **Recommended**, since every use met so far is a literal.
- A builtin, `concat($a, $b)`: no syntax, but prepending is `concat([$x], $list)`.
- Leave it: each workaround is a loop of three lines.

**Decided and built 2026-09-18: spread in list literals**, only there, only of lists, and not in
patterns (see CLAUDE.md, roadmap step 5). `steps()` now prepends (`[$node, ...$steps]`), `with()`
is gone, and so are two more copy loops the first pass had missed.

### 2. A list pattern can't skip an element

PHP writes `foreach ($node->arms as $i => [$values])` and `[, $body, $block]`. In GazLang a
pattern must have exactly as many targets as the list has elements ("Cannot destructure a list
of 3 elements into 1"), and `[, $b]` is a parse error, so `visit_match()` indexes the arm
(`$arm[0]`) in one loop and names all three in the other. Two uses; an `_` target that binds
nothing would be the fix if it recurs. Not recommended yet.

### 3. Rebuilding a node instead of cloning it

`visitStatement()` clones a postfix `++` to make it prefix, and the clone keeps its location.
The port builds a new `IncrementAST`, and must copy `line` and `file` by hand, or the `++` is
located at its statement instead. Nothing in the repository noticed until a mutant forgot the
copy: it only shows when the `++` is on a different line from where its statement starts, so
`tests/codegen_corpus/locations.gaz` now has one. Hole 6's "copy-with-change for objects" would
remove the trap; one use is not enough to ask for it.

### Checked and fine

- The 33-arm `match (class_of($node))` in `dispatch()` reads as well as `AbstractNodeVisitor`'s
  name building, and a node class nobody handles is `No arm matches`, which is louder than PHP's.
- `emit($opcode, $args = [])` with a list, 114 times, cost brackets and nothing else.
- No static methods: `Program`'s path helpers are methods of the program being written, which is
  where they are used. `dirname($main)` is `$main .. "/.."` through the textual `absolute()`,
  which drops the file name the same way for any file that can be read.

## Speed, measured

`examples/football.gaz` (22KB, 5121 lines of bytecode), through the CLI with the JIT, best of
three: `gazlang -c` takes 0.29s, `selfhost/compile.gaz` 0.83s. Of that, 0.37s is loading the
port itself (parsing and compiling its 4251 lines of GazLang before anything is read), 0.29s
lexing and parsing, 0.07s generating code and 0.10s writing the text. The code generator is the
cheap part.

The harness costs the default suite 4s (43s to 47s), since only the port's own corpus runs by
default. The whole-repository group went from 38s to 110s under pcov, 56s without it
(`php -d pcov.enabled=0 vendor/bin/phpunit --group whole-repository`), the compiler's share of
that being 30s.

## How it was checked

- `SelfHostedCompilerTest`: `tests/codegen_corpus/` always, from other working directories too,
  and on the interpreter for four files; every other `.gaz` file with `--group whole-repository`.
- 49 mutants, each one rule broken, against the corpus alone: 44 died at first, and the five
  survivors were three holes (a constant literal with `null` in it, a method call with
  arguments, the `++` location above), now closed, and two equivalents, both redundant in the
  PHP too: `existing` on the nodes `??=` builds, which `quietly()` never reads, and `LOAD_FIELD`
  checking `existing`, which is only ever set on nodes whose `field` is false.
- `php tests/fuzz_parsers.php RUNS SEED code` compares the two compilers on the parser fuzzer's
  inputs: 9,000 over three seeds, 2,768 of them compiled, no difference. It finds a broken port:
  17 and 10 mismatches in 400 runs for two deliberately broken ones.
