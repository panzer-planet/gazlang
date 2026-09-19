# GazLang Development Guidelines

GazLang is self-hosting: the lexer, parser and code generator are written in GazLang
(`selfhost/`), compiled to bytecode (`selfhost/gazlang.gzb`, checked in) and built into a VM in C
(`vm/`); together they are `bin/gazlang`. The first implementation, in PHP (`src/`,
`bin/gazlang-php`), stays as the reference every harness compares against. `README.md` is the
invitation, `docs/language.md` the language reference, `docs/internals.md` the contributor
guide, `docs/bytecode.md` the bytecode spec. This file holds the rules and the reasons behind
them; history is in git.

## Build & Test Commands
```bash
# Install the PHP dependencies (the tests and the reference need them)
composer install

# Build gazlang: the VM in C with the self-hosted compiler built in, as bin/gazlang
# (not checked in; the tests build it themselves)
make -C vm

# Run a file, print its bytecode, run bytecode, or print its tokens or tree
bin/gazlang -f examples/functions.gaz
bin/gazlang -c -f examples/functions.gaz > /tmp/f.gzb && bin/gazlang -f /tmp/f.gzb
bin/gazlang --tokens -f examples/functions.gaz
bin/gazlang --ast -f examples/functions.gaz

# The PHP reference, with the same options; only it has the tree-walking interpreter
bin/gazlang-php -f examples/functions.gaz
bin/gazlang-php --interpreter -f examples/functions.gaz

# After changing selfhost/, rebuild the compiler gazlang has built in, with gazlang alone
# (a test fails until then; see "Changing the compiler")
make -C vm compiler

# Run all tests (about 75s)
vendor/bin/phpunit

# The self-hosted drivers on big inputs on both VMs, which the default run leaves out;
# run it before merging anything that touches a port or either VM
php -d pcov.enabled=0 vendor/bin/phpunit --group whole-repository

# Run a specific test file, or method
vendor/bin/phpunit tests/SpecificTest.php
vendor/bin/phpunit --filter=testMethodName tests/SpecificTest.php

# The self-hosted front end from its source; without a file it reads piped source
bin/gazlang -f selfhost/gazlang.gaz -- code examples/functions.gaz
bin/gazlang -f selfhost/gazlang.gaz -- ast < examples/errors.gaz

# Which programs the C VM matches the PHP VM on (CVMTest checks the ones in vm/passing.txt);
# --update adds the new ones
php -d pcov.enabled=0 vm/progress.php [FILTER] [--update]

# Differential fuzzers, not part of the suite: run the one for whatever you changed
php tests/fuzz_lexers.php [RUNS] [SEED]                        # the two lexers
php tests/fuzz_parsers.php [RUNS] [SEED] [code]                # the two parsers, or compilers
php -d pcov.enabled=0 tests/fuzz_vms.php [RUNS] [SEED] [values|programs]   # the two VMs

# The C VM's coverage by the harness, its speed, and a build that collects cycles at every chance
# (over an hour: collecting is quadratic, and the entries that compile the compiler take longest)
php vm/coverage.php [file.c]
php vm/bench.php
make -C vm stress && GAZVM=vm/build/gazvm-stress php vm/progress.php

vendor/bin/phpstan analyse          # must be clean
vendor/bin/pint                     # formatting
```

## Code Style Guidelines
- **PHP version**: 8.5 or later, so the pipe operator (`$x |> trim(...) |> strtolower(...)`)
  is available. Use it where a chain of single-argument calls reads better; don't force it on
  multi-argument calls.
- **Namespaces**: `GazLang\` root, PSR-4 autoloading.
- **Classes**: PascalCase (`Parser`). **Methods/Functions**: camelCase (`visitBinOp()`,
  `isTruthy()`), except Lexer and Parser methods, which are snake_case and named after the
  grammar rule or step they read (`get_next_token()`, `function_call()`, `left_associative()`).
  The GazLang ports keep the PHP names so the two read side by side.
- **Properties**: snake_case (`$current_char`). **Constants**: UPPERCASE (`TOKEN::INTEGER`).
- **Documentation**: PHPDoc for classes and methods with parameter/return types.
- **Error Handling**: throw exceptions with descriptive messages.
- **Class Structure**: properties at top, constructor next, public methods first.
- **C** (`vm/`): plain C11 plus POSIX (`-D_DEFAULT_SOURCE`, which glibc needs for
  `open_memstream`, `realpath` and `memmem`), libc, libm and pthreads only, built warning-free by
  clang and gcc, commented where the C isn't obvious (a
  flexible array member, a `goto` into shared code), for readers who know a little C.
- `ponytail:` comments mark known ceilings, with what would lift them.

## Layout

- `src/Lexer`, `src/Parser`, `src/AST`: source text to a tree. `Lexer::quote()` and
  `Lexer::parse_integer()` are the one definition of string and integer literals.
  `AST\Dumper` prints a tree for `--ast` by reading each node's fields.
- `src/Runtime`: what values mean, shared by both PHP backends. `Values` holds the operators,
  truthiness, printing, keys, indexing and write paths as static pure functions; `Builtins`
  holds the builtins and their arities (`Builtins::ARITIES`).
- `src/Interpreter`: walks the tree. It does not decide what values mean.
- `src/CodeGenerator`: compiles the tree to a `Program` (one block per function, each
  instruction with its file and line); `Program` writes the bytecode and `BytecodeReader` reads it.
- `src/VM`: runs a `Program`; `bin/gazlang-php`'s default backend.
- `selfhost/`: `lexer.gaz`, `parser.gaz` and `nodes.gaz`, `codegen.gaz` (ports of the above) and
  `gazlang.gaz`, the driver: `gazlang.gaz -- code|tokens|ast [FILE]`, reading standard input
  without a FILE, a usage message and exit 2 otherwise. `gazlang.gzb` is its bytecode.
- `vm/`: the VM in C. `gazvm.h` says which file does what; the files follow the PHP classes
  (`value.c` and `ops.c` are `Runtime\Values`, `builtins.c` is `Runtime\Builtins`, `load.c` is
  `BytecodeReader`, `vm.c` is `VM\VM` and the CLI, `gc.c` the cycle collector).
- `lib/`: the standard library in GazLang. `examples/`: sample programs.
- `tests/`: PHPUnit, `tests/gaz/` (GazLang programs), and the corpora: `lexer_corpus/`,
  `parser_corpus/`, `codegen_corpus/`, `vm_corpus/`, `bytecode_corpus/`, `cli/`, `json/`, `csv/`.

## How it is held together

Everything is checked differentially: one side is the spec, the other must match it byte for
byte. **Break a checker on purpose before believing a run that finds nothing**: several first
versions of a fuzzer or corpus passed everything and caught nothing.

- **The two PHP backends agree.** `GazLangTestCase::executeCode()` runs every snippet on the
  interpreter and on the VM and fails if the output, or the error's class, message, file or
  line, differs; `GazProgramTest`, `JsonTest` and `VMTest` do the same for whole programs. So a
  new AST node needs a visitor in both `Interpreter/Interpreter.php` and
  `CodeGenerator/CodeGenerator.php`. Order matters as much as results: the VM's `KEY_CHECK`
  exists so a bad key fails before later keys and the value run, as in the interpreter.
- **The ports match the PHP front end** on every `.gaz` file in the repository and their own
  corpus: `SelfHostedLexerTest` against `--tokens`, `SelfHostedParserTest` against `--ast`,
  `SelfHostedCompilerTest` against `-c`, output and exit code. They run the driver compiled from
  the current `selfhost/` source (not the built-in one, which is stale until `make compiler`),
  on the C VM, 24 at once (`CVM::driver()`), also piped and from other working directories. So
  a change to the lexer, parser, a node or the code generator is made in `src/` and `selfhost/`
  together. The dump is read off the nodes rather than written per node type, so a field added
  to a node fails the harness until the port has it; a harness failure reports the first line
  that differs (`assertSameText()`), since phpunit's diff is quadratic.
- **Corpora**: add a file whenever a port or VM reveals an untested case. In the lexer, parser
  and bytecode corpora the files named `error_*` must be exactly the ones that fail. Put a
  node's tokens on different lines when its location matters: a one-line case can't tell one
  token's line from another's.
- **The C VM matches the PHP VM** (`CVMTest`, `tests/CVM.php`): each entry of `vm/passing.txt`
  (every program and corpus file, the `executeCode()` snippets in `tests/vm_snippets.txt`,
  recollected by `php vm/snippets.php`, and the hand-written broken `.gzb` files) runs on both,
  the C one built with ASan and UBSan, and must give the same stdout, stderr and exit code. A
  source entry runs from source on both, so the built-in compiler compiles each one under the
  sanitizers. The list only grows. So a change to what a value means, a builtin or an error
  message is made in `src/Runtime` and `vm/` together. When PHP itself changes behaviour, the
  rule is GazLang's to define: write it out in `Runtime`, as `Builtins::round()` does, rather
  than calling PHP's.
- **The C VM doesn't leak**: output can't show a forgotten `decref`, and ASan's leak detector
  doesn't run on macOS. With `GAZVM_STATS` set, the end of a run drops the globals and the top
  frame, collects cycles and prints `gazvm: N values leaked`, N being what is left beyond the
  constants loaded with the code; the harness, `progress.php` and `fuzz_vms.php` fail an entry
  that leaks or prints no line. `exit()` and a refused file say `leaks not checked`. On Linux
  LeakSanitizer also runs in the sanitized builds and catches plain allocations the count
  can't see; `__lsan_default_suppressions()` in `vm.c` exempts only the loader, which gives up
  on a broken file without freeing what it built.
- **The two CLIs match** (`CliParityTest`): a table of invocations of `tests/cli/` programs
  (arguments, what is piped in, working directory) through both, which must give the same
  stdout, stderr and exit code. A change to either CLI's options or how they read input needs
  a row. The one intended difference is `--interpreter`, which C refuses, naming
  `bin/gazlang-php --interpreter`.
- **The compiler compiles itself**: the PHP compiler's bytecode for `selfhost/gazlang.gaz`, run
  on the C VM, gives the same bytecode, which gives the same again, and `gazlang.gzb` must equal
  it (`test_the_self_hosted_compiler_compiles_itself_on_the_c_vm`).
- **GazLang code is tested with GazLang programs**: every `tests/gaz/**/*_test.gaz` must print
  exactly its `*_test.expected` (`GazProgramTest`); `check.gaz`'s `check($label, $actual,
  $expected)` prints `ok <label>` or a FAIL line. `lib/json.gaz` is checked against PHP's
  `json_decode` on `tests/json/y_*`/`n_*` (the prefix says whether it must parse), `lib/csv.gaz`
  against `fgetcsv` on `tests/csv/`, `lib/chars.gaz` against `Lexer::is_*` for all 256 bytes.
- **The README's examples are tests**: `ReadmeTest` runs every ```` ```gaz ```` block followed
  by an output block and requires exactly that output.
- **Under pcov**, deep PHP recursion segfaults (exit 139, no test named) before the call depth
  limit, so tests of deep recursion on the interpreter go through the CLI, and
  `tests/vm_corpus/depth.gaz` runs its PHP side in its own process (`CVM::DEEP`). Run the suite
  with pcov on at least sometimes: a segfault it causes stays invisible without it.

Judge new features by what they cost **in C**, not only in PHP: value semantics suit
reference counting, and anything that leans on PHP behaviour (hashing, string conversion, float
formatting) must be a rule GazLang defines and both runtimes implement. Grow the language by
writing real GazLang and fixing what hurts, and when a workaround in the repository's GazLang is
the evidence for a gap, check with `git log` when it was written: code older than a feature
can't have used it. Re-measure before trusting a recorded number.

## Changing the compiler

`make -C vm compiler` rebuilds `selfhost/gazlang.gzb` with `bin/gazlang` alone, in three stages:
the current compiler compiles the new source (stage 1), which compiles itself (stage 2), which
compiles itself again (stage 3). **Stage 2 must equal stage 3**: stage 1 was written by the old
code generator, so it differs by design when code generation changes, but a compiler whose
output depends on how it was itself compiled has a bug. Only then is stage 2 copied over
`gazlang.gzb` and `bin/gazlang` rebuilt, so a broken edit fails at stage 1 or 2 and leaves a
binary that can compile its fix. Nothing changed means nothing rebuilt.
`test_make_compiler_rebuilds_the_compiler_without_php` runs it on a copy.

- **An explicit target, not a dependency**: plain `make` always builds from the checked-in
  bytecode, since a fresh clone's timestamps are arbitrary and a dependency would have make
  regenerate the compiler with a binary that needs it to be built.
- **A language feature lands in two steps**: the compiler's own source can't use a feature
  until a compiler that understands it has been built. Add it to `selfhost/`, run
  `make compiler`, and only then use it in `selfhost/`. A new instruction goes into the C VM
  before any bytecode using it runs.
- While PHP is the reference, `gazlang.gzb` must also equal what `bin/gazlang-php -c` writes.
- The bytecode is built in with `od` into `vm/build/compiler.c`: numbers only, so nothing to
  escape, and no trigraphs, which `-std=c11` turns on and the `??=` in it would be.

## Status and what is next

- **CI** (`.github/workflows/ci.yml`) runs on Ubuntu and on macOS, Apple silicon and Intel,
  for every push: it builds gazlang and rebuilds its compiler before PHP is even installed (the
  bootstrap needs only a C compiler), then the suite and the whole-repository group; phpstan and
  pint run on Ubuntu only. Development is on an Intel Mac.
- **Speed**: the same program takes gazlang 0.4 to 1.6 times what it takes PHP (JIT or not),
  and Python 3.9 1.4 to 4 times what it takes gazlang, except where Python's builtins do the
  work in C (compiling from source included on all sides; gazlang's compile is about 10ms). If
  speed is next, measure with `php vm/bench.php`; a Python column there (ports of
  `vm/bench/*.php`, with a Python 3.11 or later) would make the Python figures checkable. The
  arithmetic loop (1.6x) is still about a dozen dispatches an iteration against PHP's JIT, which
  only a register bytecode or a JIT would close; lists, maps, strings and objects (1.2 to 1.4x)
  spend theirs in malloc/free and the collector, so profile those before trying an allocator.
- **Decisions waiting for Werner**: whether bytecode version 1 now carries a compatibility
  promise (`docs/bytecode.md` says none "until the compiler is self-hosted", which it is).
- **Known limits**, none worth fixing yet:
  - The self-hosted parser runs out of call depth on source nested past about 1100 levels
    (recursive descent is about nine calls a level), as an internal error. Its tree walks use
    an explicit stack for that reason.
  - `fuzz_vms.php`'s programs mode reports a mismatch when a program exhausts memory (PHP dies
    at its limit, the C VM is killed at the time limit).
  - A `make compiler` stage's own runtime errors name `vm/build/bootstrap/` as the source
    directory, since bytecode paths resolve against the bytecode file; the lines are right.
  - A main file given by an absolute path through a symlinked directory (macOS's `/var`) gives
    include locations that climb to the root and back through the real path. Both compilers
    agree.
  - The keyword hint misses `IF (1) { }`, where the error lands at the `{`, past the name.
  - Under Xdebug, deep recursion in the interpreter segfaults as under pcov; not handled.
  - `CVMTest` doesn't catch `make compiler` checking in stage 1 instead of stage 2: that only
    shows when an edit changes code generation, which would cost the suite a second rebuild.
- **Language gaps**, each waiting for real code to ask:
  - Appending to a list parameter silently does nothing (`fn add_to($l) { $l[] = 1; }`), and
    the parser can't tell it from a function that returns the list. Mutable state belongs in
    an object; by-reference parameters aren't worth their cost against refcounting.
  - `$obj.$name` (dynamic member access; `lib/sort.gaz` can sort maps but not objects),
    `json_encode` of an object (`fields()` lists what it would write; `to_string()` and cycles
    to settle), and `class_name($class)` (the bare name; today `slice(to_string(class_of($x)),
    6)`).
  - `class_of` is strict, so a pass over a tree with absent children needs a `type_of` check
    first; if that recurs, make it lenient.
  - Scanning bytes: `$s[$i]` makes a one-byte string (shared in C) and there is no `byte_at` or
    "index of the first byte in this set". The self-hosted lexer spells out comparisons and
    walks local indexes for speed on the PHP VM; measure on the C VM before keeping that.
  - `..=` on a field or element (`#buf ..= $c`) still lowers to `#buf = #buf .. $c` in generated
    code, which copies the string: only a plain variable appends in place.
  - Included files share one namespace, so modules prefix their privates (`json_*`), and
    including a file runs its top level code. `Error`'s members are reserved across its
    subclasses, so a domain error can't declare its own `#line` or `#message`.
  - No identity key for an object (a side table keyed by node), no `to_int`/
    `to_float` that returns null instead of throwing, no copy-with-change for objects, no
    `catch (A | B $e)`, no bare rethrow, no `_` to skip an element in a list pattern.
  - `match ($x)` is a linear chain of `EQUALS`; no jump table.
  - No enum: token types are strings on purpose (they are the `--tokens` format).
- **Decided, not built**: `interface`/`implements` (a parse-time check that the methods exist,
  plus `is_a`), `final`, and `private`/`protected` with public implicit (`#` and `##` checked at
  parse time, `$obj.name` when it runs, against the running method's class; a parent's private
  field is invisible to children, which may then declare their own). The keywords are reserved.
- **Not planned** until real code asks: traits, late static binding, static members, operator
  overloading, `**` and `sqrt`/`pow`/`log`, variadic parameters and spread in calls (pass a
  list), `time()` (time it from outside), `foreach` over a string (`split($s, "")`), regular
  expressions (character classes are explicit on purpose), a REPL, a C `--interpreter`.

# The language

Rules, with the reason where the choice isn't obvious. `docs/language.md` is the reader's
version.

## Operators, truthiness and equality

- **Precedence**, loosest first: assignment (right associative) → `?:` (right) → `??` (right)
  → `||` → `&&` → equality (`==` `!=` `<=>`) → relational → `..` → `|` → `^` → `&` → shifts →
  `+ -` → `* / %` → unary → postfix (`[index]`, `(args)`, `.name`) → primary. Bitwise precedence
  is Rust's and Python's, not C's, so `$flags & MASK == 0` is `($flags & MASK) == 0`. `..` sits
  looser than the bitwise operators and tighter than comparison, so `"x = " .. $f & MASK` and
  `$f & MASK .. "!"` both do the obvious thing (between the bitwise levels, every unparenthesised
  mix would be an error); shifts stay tighter than `..`, unlike Lua, so `"n = " .. $x << 2` works.
- **A real bool**: comparisons, `!`, `&&` and `||` give `true`/`false`, `&&`/`||` short-circuit.
  A bool is not a number: `true == 1` is false and `true + 1` is `Cannot use + on bool`;
  `to_int(true)` is 1. Truthiness (`Values::isTruthy()`) is the one place a non-bool is read as
  a bool: numbers C-like, strings true unless empty (`"0"` is true), empty lists and maps false,
  `null` false, functions and objects always true.
- **`..` concatenates**, converting both sides as `echo` does (`1 .. 2` is `"12"`); `+` is
  numeric only. It sits below `+`, so `"n = " .. $a + $b` concatenates the sum.
- **`==` never converts between strings and numbers** (`Values::equals()`, shared with
  `in_array` and `match`): strings compare byte by byte (`"1" != "01"`, `"10" < "9"`), a string
  never equals a number or bool, and ordering a string against a number is an error. Numbers
  compare by value, exactly (`1 == 1.0`, but `9007199254740993 != 9007199254740992.0`, unlike
  PHP). A bool equals only itself, `null` only `null`. Lists compare in order, maps by keys and
  values in any order, and a list never equals a map, even `[] == {}`. Functions, classes and
  objects compare by identity (bound methods: the same object, class and method). No `===` (it lexes as `==` then `=`, a syntax error). `<=>` gives
  -1, 0 or 1 by the ordering rules.
- **Bitwise** `& | ^ << >> ~` and their compound forms are ints only, as `%` is. A shift count
  must be 0 to 63 (PHP quietly gives 0 above). `>>` keeps the sign, `~$x` is `-$x - 1`, and
  bits shifted off the top of `<<` are gone (`1 << 63` is the smallest int), as in C, Java and
  Rust: a shift moves bits rather than scaling a quantity. No `>>>`, no `&`/`|` on bools.
- **`$a ?? $b`** is `$a` unless null or missing: on its left an undefined variable, a missing
  key, or indexing something missing is null (a bad key type or indexing an int still errors).
  `0`, `false`, `""` are kept; the right side runs only when needed. `$a ??= $b` evaluates keys
  once and, like `=`, creates a missing variable or last key but not keys along the way.
- **`$c ? $a : $b`** evaluates only the taken branch, is right associative as in C, and sits
  between `??` and assignment, so `$x ?? $y ? 1 : 2` tests the coalesced value.
- `%` takes the left operand's sign. `null`: arithmetic, ordering and unary `-` on it throw;
  `echo null` prints `null`.

## Numbers

64-bit ints and floats, always finite (no INF or NAN).

- Literals: `42`, `1.5`, `1e10`, `2.5E-3`; digits on both sides of a dot (`1.` and `.5` are
  errors). `0xFF` is an int (hex has no exponent: `0x1e5` is 485); strings are never read as
  hex. `Lexer::parse_number()` reads the same syntax for `to_float()`, and JSON numbers are valid.
- **Nothing overflows silently**: an int that doesn't fit is `Integer overflow` (PHP would
  switch to a float), an infinite float literal is a lexer error, an infinite result is `Float
  overflow`. Division by zero is an error.
- Int with int gives an int, a float on either side a float, a bool on either side an error.
  **`/` always gives a float** (`6 / 2` is `3.0`, as in Python 3 and Lua 5.3), converting ints
  first, so it loses precision above 2^53; `intdiv()` truncates.
- **Printing is exact**: `Lexer::format_float()` gives the shortest digits that read back as the
  same float, always with a dot or exponent (`1.0`, `0.30000000000000004`, `1.0E+25`, `-0.0`).
  echo, `to_string`, interpolation, `--tokens` and bytecode all use it. The C version tries both
  neighbours at each length, since next to a power of two the correctly rounded candidate can
  fail to read back.
- Floats can't be keys, indexes or string positions. `0.0` and `-0.0` are false.
- `round($x, $precision = 0)` is PHP's (halves away from zero, with its pre-rounding, so
  `round(1.005, 2)` is `1.01`; negative precision rounds to tens), written out step by step in
  `Builtins::round()` because PHP's own changed between 8.5 releases. `floor`, `ceil`, `round`
  give floats; `abs` keeps the type; `min`/`max` take two numbers or two strings, a tie giving
  the first. `to_int` truncates a float and errors outside the int range; `to_float(true)` is 1.0.

## Strings

Byte strings (`len("é")` is 2; `upper` and character classes are ASCII; UTF-8 passes through).
Names are ASCII.

- Single-quoted literals are raw: only `\'` and `\\` are escapes. Double-quoted ones support
  `\n \t \r \v \f \e \0 \\ \" \$ \{`, `\xHH` and `\u{H}` (1 to 6 hex digits, up to 10FFFF, no
  surrogates, written as UTF-8). Any other escape is a lexer error, and so is `\0` before a
  digit (octal in PHP and C).
- Double-quoted strings interpolate: `"$name"` with an optional PHP-style index (`[0]`, `[-1]`,
  `[key]` as `"key"`, `[$i]`; `[01]` is the string key), and `"{$expr}"` / `"{@expr}"` / `"{#expr}"`
  up to the matching `}`. `#` and property paths interpolate only inside braces, so `"#fff"`
  and `"$file.txt"` stay text, but `{#` starts one anywhere, so outside a method write `\{#` or
  use single quotes. Anything else is literal (`$5`, `me@example.com`, `{ $x}`). The
  lexer emits `STRING_START`, the tokens, `STRING_MIDDLE`, `STRING_END`, with a stack so strings
  nest; the parser desugars to `..`, so the backends need nothing. Include paths can't interpolate.
- `Lexer::quote()` is the exact inverse of a literal, and everything that shows a string as
  source uses it.

## Lists and maps

- A list `[1, 2]` holds values at 0, 1, 2...; a map `{"k" => 1, 5 => 2}` holds values by key
  in insertion order (duplicate keys keep the last). `[k => v]` is a parse error pointing at
  `{}`. Keys are int or string, and `"1"` and `1` are different keys (`MapValue::key()`). **Two
  types, not PHP's one array**, so a list's indexes are always 0 to len - 1.
- **Values, not references**: assigning or passing one copies it (copy on write in both
  runtimes). Writing one in place is only ever through a variable's path.
- Reading: a list index must be an int in range (`Index out of range: 5`, no negatives), a map
  key must exist (`Undefined key: "k"`, strings quoted so `"1"` and `1` differ), except on the
  left of `??`. Strings index the same way, read only, to a one-byte string.
- Writing: `$a[k] = v`, `$a[k1][k2] = v`, `$a[] = v` (append, only as a target). Keys are
  evaluated left to right, then the value, then the variable is read, so side effects are kept.
  A list index must exist (append with `[]`), a map may gain its last key, missing keys along
  the way are errors, appending to a map is an error.
- `delete $a[k];` removes an element, with a target written like an assignment's that must end
  at an index; a list's later elements move down, a map keeps its order, removing what isn't
  there is an error. `delete` can't take a field (`Cannot delete a field`), a variable, `$a[]`, a
  call's result or a string; a method may still be named `delete`. There is no `pop`: a function can't change its argument, so take `last($l)`
  and delete it, which is how a list is a stack (and cheap: `array_pop()` in PHP).
- `...$x` spreads a list into a **list literal only** (`[$first, ...$rest]`, `[...$a, ...$b]`);
  anything but a list is `Cannot spread map: only a list can be`, raised before the elements
  after it run. `{...$m}`, `f(...$args)`, a bare `...$a` and a rest pattern are parse errors
  that say so; each could be added later without breaking anything. `...` is one token
  (longest match, so `.....` is `...` then `..`).
- `echo` prints them as literals; arithmetic, ordering and unary `-` on them throw.

## Statements, functions and scope

- `for (init; cond; step)` needs all three clauses and is desugared in the parser into a
  `while` whose step runs after the body and on `continue`. `break;`/`continue;` affect the
  innermost loop and are parse errors outside one.
- `foreach ($x as [$key =>] $value)` iterates a list or map as it was when the loop started;
  the loop variables (`$` or `@`, or a list pattern of variables for the value) keep their last
  values. Anything else is `foreach expects a list or map`.
- `fn name($a, $b = $a * 2) { }` is top level only (so `break` can't reach a caller's loop) and
  callable before its declaration. The parser checks every call's name and argument count once
  the whole program is read, so the backends trust calls. A default is evaluated on each call
  that leaves the argument out, inside the function, so it sees earlier parameters and a `[]`
  default is never shared; the arity is then `[required, total]`. `function` is reserved and
  says to write `fn`.
- **`$x` is always local** (to the running call or the top level), **`@x` always global**; a
  function can't read top-level `$x`. Parameters are `$` only. `return` outside a function is a
  parse error; no return gives `null`. Variables holding `null` are still defined.
- Calls are capped at 10000 deep (`Values::MAX_CALL_DEPTH`), a catchable GazLang error.
- `include "path.gaz";` is top level only, takes a string literal relative to the including
  file (the working directory for piped source), and is resolved at parse time by splicing the
  file's statements in; each file is included once (the main file counts), by real path, which also breaks cycles.

## Builtins and the standard library

Builtins are `Builtins::ARITIES` (name to an arity, or `[fewest, most]`), can't be redeclared,
compile to `CALL_BUILTIN name argc`, and check argument types by their `type_of()` names.

- Strings: `len`, `slice($x, $start, $length)` (strings and lists, PHP's rules including
  negatives), `lower`, `upper`, `trim` (the lexer's whitespace only), `split` (empty separator:
  characters), `join` (elements converted like echo), `replace` (every occurrence; empty search
  is an error), `contains`, `starts_with`, `ends_with`, `index_of($s, $needle, $offset = 0)`
  (null when absent; negative offset from the end; an empty needle or an offset outside the
  string is an error), `repeat`, `chr` (0 to 255), `ord` (one
  byte), `to_int` (ints, bools, decimal strings with an optional `-`), `to_float`, `to_string`.
- Lists and maps: `in_array` (`==`), `has_key`, `keys`, `values`, `last` (an empty list is an
  error), `reverse` (lists, strings by byte, and maps, which keep their keys), and `map`,
  `filter` (truthiness, as `if`), `reduce($x, $f, $initial)` and `sort` (stable; the
  comparator must return an int), which call back into GazLang through `Values::$call_value`
  (the interpreter's `callValue()`, the PHP VM's `valueCaller()`, the C VM's `call_value()`),
  checked as a call written in the program is. `sort` is a defined merge sort, since a
  comparator can see which comparisons are made: split in the middle, merge asking
  `$compare(right, left)`; `Builtins::sort()` and C's `merge_sort()` must stay the same
  algorithm. Types are checked before anything is called.
- Types: `type_of` (`int float string bool null list map function class object`), `is_a`,
  `class_of`, `fields` (see "Objects").
- I/O: `print`/`print_error` (echo without the newline, to stdout or stderr), `read_file`,
  `write_file`, `read_stdin` (empty when the program itself was piped in), `args()` (after the
  gazlang options or `--`; both CLIs reject options they don't know, since `getopt` would drop
  them silently), `cwd()`, `real_path()` (as `realpath(3)`; `""`, a NUL byte, `file/` and
  `file/..` are nothing, where PHP and macOS disagree), `file_exists()`.
- `error($value)` raises (see "Errors"); `exit($code = 0)` stops with that code, 0 to 255,
  printing nothing and running no `finally` (`Runtime\ExitSignal`).
- `builtins()` is `ARITIES` as a map, in no promised order: the builtins of the runtime running the program, which the
  self-hosted parser checks calls against. That is right because the compiler always runs on
  the runtime that will run its output, and a loader refuses bytecode naming a builtin it lacks.
- In GazLang instead: `lib/chars.gaz` (character classes), `lib/sort.gaz` (by key, on `sort`),
  `lib/format.gaz` (`pad_left`/`pad_right`
  convert like echo: display helpers take any value, string functions stay strict),
  `lib/json.gaz`, `lib/csv.gaz` (RFC 4180). Scan long strings with `index_of`, not a character
  at a time.

## Function values and closures

- A bare function or builtin name is a value (`$f = add;`), checked once the program is read
  (`Undefined function or constant: x`); a bare word is unambiguous because variables have
  sigils. Named functions are interned, so `add == add`; closures compare by identity of
  creation.
- Any postfix expression can be called (`$f(1)`, `$h["k"]($x)`, `pick()(1)`). Only `name(...)`
  is checked at parse time; a call on a value evaluates the callee, then the arguments, then
  checks callability and arity (the parser's wording), then calls.
- `echo add` prints `function add`, a closure `function -> at file.gaz:12`; errors name a
  closure by where it was made, then where it was called.
- **Lambdas**: `$x -> $x * 2`, `($a, $b = 1) -> $a + $b`, `() -> { return 1; }`. An expression
  body extends as far right as it can, so a lambda sits at the ternary's level. A block body
  returns only through `return`, and `break`/`continue` can't leave it. A lambda returning a map
  writes `$x -> ({"v" => $x})`, since `{` after `->` is a block. The parser needs no lookahead:
  `ternary()` marks a `(` as a possible head and checks the elements only if `->` follows.
- **Closures own their captured variables**: every `$` variable the body uses that isn't a
  parameter and that no plain `=`, `foreach` or `catch` in it assigns is copied in when the
  lambda is evaluated (if it exists) and stays there, persisting between calls and shared by
  recursive calls, while the enclosing scope never sees the changes. A variable a plain `=`
  assigns is local to each call, so temporaries can't leak between recursive calls.
  `$f = <lambda>` gives the closure its own `$f`, so lambdas recurse. A closure is one value
  (`$g = $f` shares its variables), and a captured name that didn't exist stays undefined until
  the closure sets it with `??=`. `@globals` are never captured. A closure made in a method keeps its object.

## Objects

Declared fields and single inheritance give every class a fixed layout, so fields are slots
and methods a table in C.

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

- **Classes** are top level, usable before their declaration, and share the namespace of
  functions, builtins and constants. `abstract class` can't be constructed; `abstract fn` must
  be defined by a concrete subclass. A class body holds only fields, methods and constants.
- **Classes are values and constructing is a call**: `Point(1, 2)`, `$make = Point`. `echo
  Point` prints `class Point`. A call by name is checked like a function call.
- **Constructing** sets the field defaults (the parent's first, in order), then runs `_` with
  the arguments. A class without `_` inherits its parent's. A child calls the parent's with
  `##_(...)`, only in a constructor; nothing calls it automatically. `return value;` in `_` and
  `_` as a member are errors.
- **Fields are declared** (`#x;` or `#x = default;`); a default is evaluated per object, may use
  `#` but not `$` variables. Reading a field never set is an error; `??` reads it as null.
  Objects print only the fields that are set.
- **`#`** is the object, `#name` its member, checked at parse time against the class and its
  parents (in methods, field defaults and lambdas in them; elsewhere `Cannot use #name outside a
  method`). **`##name`** is the parent's version of a method, decided at parse time from the
  class it is written in; only methods, not abstract ones. Bare `##` is a parse error, kept free
  for the parent class as a value; `#.name` says to write `#name`. Member names can be any word,
  keywords included.
- **Members** share one namespace across the hierarchy: a child can't redeclare a field or
  constant or give a field a method's name (the error suggests a new name). An override must
  accept every argument count the parent's does (constructors exempt), and an abstract method
  can't replace a concrete one.
- **Objects are handles**: `$b = $a; $b.x = 1` changes `$a`; lists and maps inside stay values.
  `==` is identity, objects are always true, and operators, keys, indexes and `foreach` on them
  are errors.
- **`.` is member access**, checked when it runs (`Account has no member foo`). `.name` is one
  token glued to its name, but may start a line so chains continue. `$obj.name(args)` evaluates
  the object, looks the member up, then the arguments, then calls. `$obj.method` is a bound
  method, `==` another when object, class and method match.
- **Write paths**: a variable or `#`, then any index or property steps (`$rows[0].total = 5`,
  `#count++`); a path can't start at a call. `Values::store()` is the one definition.
- `is_a($x, Class)` tests the class and its parents; `class_of($x)` is the object's own class
  (strict: anything else is an error), so `match (class_of($n)) { NumAST => ... }` dispatches
  a pass written outside the node classes. `fields($object)` is a map of the set fields, in
  print order, without `#`, so a pass can walk a tree without knowing its classes.
- **`to_string()` is the one protocol method**, used by echo, `..`, interpolation and `join`,
  also inside lists and maps; it must return a string and take no arguments. Without one an
  object prints as `Account {#owner => "Werner", #balance => 75}`, and one already being printed
  as `Account {...}`. `json_encode` refuses classes, objects and functions.

## match

```
$kind = match ($type) {                       // an expression: every arm is an expression
    "int", "float" => "number",               // several values to an arm
    default => "other",                       // a trailing comma is allowed
};

match ($c) {                                  // a statement: an arm may be a block
    "\"" => { read_string(); }                // no comma needed after a block arm
    default => fail("bad character")          // and no ; after the closing }
}

match {                                       // no subject: the arms are conditions
    is_digit($c) => { number(); }
    default => operator()
}
```

- With a subject, it is evaluated once and each arm's values in order, compared with `==`; the
  values after a match never run. Without one, each arm is tested for truth as `if` does,
  which is why it exists: `match (true)` silently misses a condition returning a truthy
  non-bool. Keeping the two forms apart keeps `match ($x)` all-`==`, so a jump table stays
  possible.
- **No ranges** as arms: `"a".."z"` is already concatenation.
- No fallthrough; `break`, `continue` and `return` in an arm belong to what is around it.
- Nothing matching and no `default` is `No arm matches "x"` (a string quoted, anything not a
  scalar named by its type, since printing it could run `to_string()`, which must never happen
  while raising) or `No arm matched`. `default` must be last; no arms is a parse error.
- Only a statement `match` may have block arms, and it ends at its `}` (a `;` after it is an
  error); in a statement a map after `=>` is written `({...})`.

## Constants

```
const WIDTH = 3;
const AREA = WIDTH * HEIGHT;                  // in terms of others, in any order
const HEIGHT = WIDTH + 1;

class Token {
    const EOF = "EOF";
    const ENDS = [#EOF, Token.EOF .. "!"];
    fn is_eof($type) { return $type == #EOF; }
}
```

- **The value is a constant expression the parser works out**: literals, every operator, `?:`,
  lists and maps, other constants; no variables, calls or indexing. What may appear is checked
  on the source first (`check_constant_expression()`), then `Parser::fold()` evaluates it with
  `Runtime\Values`, so errors are the runtime's, located at the operator, and short-circuited
  sides aren't evaluated. It uses `Values` directly rather than the interpreter, so the parser
  depends on no backend. Every constant is folded once the program is read, used or not; a
  cycle is `Constant A depends on itself: A uses B uses A`. Not any expression: one evaluated
  once at run time (`Point(0, 0)`) would bring initialisation order, mutation through a handle,
  and a slot and instruction in both VMs.
- **A use is its value**: the parser stamps each use and the backends push the value, so there
  is no constant in the tree below the parser, the bytecode or the VMs.
- A top level constant is a bare name, sharing the namespace of functions and classes, so a
  typo is a parse error. Constants are immutable because no write path can start at one; the
  one that could, `#NAME[0] = 1`, is refused (`Cannot change constant #NAME`).
- **Class constants** are `#NAME` inside and `Class.NAME` outside, inherited, and **can't be
  redeclared by a child**, since `#NAME` is resolved from the class it is written in. They are
  **reached by name only**: `$class.NAME` and `$object.NAME` are not constants. Both rules are
  the restrictive choice on purpose: loosening them later breaks nothing.
- `ConstTest::expressions()` requires a constant to give what a running program gives, for
  every operator and kind of value, on both backends and in the port.

## Assignment

- `= += -= *= /= %= ..= ??=` and the bitwise compound forms are right associative
  expressions whose value is the new value. `++`/`--` are numbers only; prefix gives the new
  value, postfix the old. Appending (`$a[] = v`) is plain `=` only.
- Keys are evaluated once, left to right, then the right side, then the target is read and
  written, so `$k += $k *= 2` sees the updated `$k`. A compound update needs the variable and
  every key to exist (reading a missing key as null would make `$a["n"] ..= "x"` quietly give
  `"nullx"`), and nothing is written if it fails.
- **List patterns**: `[$a, $b] = $pair;` needs exactly as many elements as targets, checked
  before anything is written; the right side runs first, then each target left to right, so
  `[$a, $b] = [$b, $a]` swaps. Targets are anything `=` can assign. No nesting, map patterns,
  compound operators or append targets.
- `..=` on a plain variable appends in place (`Values::concatAssign()`), converting what is
  appended as `..` does, so building a string with it is linear: loading the string onto the
  stack first made PHP copy all of it on every append.

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

- **Catchable**: every runtime error (including running out of call depth) and anything thrown
  with `error()`. Syntax and include errors happen before the program runs. `return`, `break`,
  `continue` and `exit()` are not errors.
- **`Error` is a builtin class** written in GazLang (`Parser::BUILTIN_CLASSES`, located as
  `<builtin>`): `#message`, `#file` (null for piped input), `#line`, `#trace`, `_($message)`,
  `to_string()`. Programs extend it; runtime errors and `error("text")` are caught as `Error`.
  It is compiled only into programs that use it.
- **`error($value)` throws any value**: a string becomes an `Error`'s message, anything else is
  caught as it is. An `Error` gets its location and trace where it is first thrown, so
  `error($e)` rethrows keeping them. Uncaught, gazlang prints `Error: ` and the value as echo
  would, with no location (runtime errors keep theirs), and the text is made only then, so
  throwing never runs `to_string()`.
- **`#trace`** lists the calls running when the error was raised, innermost first, each where it
  was running (`["inner at fib.gaz:3", "top level at fib.gaz:7"]`): a function by name, a method
  `Class.name`, a constructor `Class._`, a lambda `->`. Deep traces keep the innermost and
  outermost 10 around `... N more`. Uncaught, the trace is printed under the message unless it
  is a single call. A method run from inside an expression (`to_string()` by echo, `..` or a
  builtin) is called from where the expression is running, and its trace carries on through
  the calls outside it; once the program has ended (printing an uncaught error) it has none.
  The interpreter records each call as it makes it; the VMs read their frames when an error
  happens, which costs nothing until then. The VMs' nested loops learn where they were called
  from through the instructions that can run program code (`PRINT`, `CONCAT`, `CONCAT_ASSIGN*`,
  `CALL_BUILTIN` and `CALL_VALUE` of a builtin), which say where they are; `trace_test.gaz` has
  a case for each, so one that forgets fails there.
- Catch clauses are tried in order; a typed one matches the class or a subclass; an untyped one
  (`catch ($e)`) must be last. An unmatched error carries on unchanged.
- **`finally`** runs however the block is left: normally, when an error passes, and on
  `return`/`break`/`continue` (a return's value is worked out first). An error in it replaces
  what was in flight; `return`, `break` and `continue` can't leave it; `exit()` skips it. A
  `try` needs a catch or a finally.

## Comments and names

- `//` and `/* */`, skipped by the lexer. **Block comments nest** (as in Rust and Swift), so a
  region already holding a comment can be commented out; an unterminated one is an error at
  the line the outermost opened on. `editors/gaz.tmLanguage` nests them too.
- **Keywords are lowercase and exact**, so `class If`, `fn Return()` and `class Match` are
  ordinary names, which a self-hosted AST wants. PHP matches keywords *and* names
  case-insensitively; matching only keywords that way was its wart without its rule. A
  miscapitalised keyword gets a hint (`keywords are lowercase: write 'return', not 'Return'`)
  from `Parser::keyword_hint()`, built only while an error is, as does a statement that starts
  with PHP's `elseif` (`write 'else if'`), unless a function of that name is declared. Sigils
  and member names have their own namespaces, so `$If` and `fn match()` were always fine.

# Implementation notes

## The PHP front end and backends

- **Errors**: every error a program can hit is a `GazLangError` whose message ends in its
  location (` at path/file.gaz:12`, or ` on line 12` for piped source). Tokens carry their line
  and the parser stamps `line` and `file` on every node (`Parser::at()`). The interpreter turns
  a plain `Exception` thrown while running a node into a `GazLangError` there, so runtime code
  can throw plain exceptions and the innermost located node wins; `try` catches only
  `GazLangError`, so PHP bugs aren't swallowed. `error()`'s messages have `show_location` false.
  Include paths show relative to the working directory, the main file as given.
- **The interpreter** unwinds `return`, `break` and `continue` with one preallocated signal each
  (a new exception per return recorded a trace and made recursion quadratic) and writes lists in
  place through PHP references. A `MapValue` is shared until written: only `Values::store()` and
  `remove()` change one, cloning each map on the path first.
- **Code generation**: calling convention is arguments pushed left to right then `CALL name
  argc`, the callee's frame holding them in slots 0..argc-1, `RET` pushing the result. Compound
  assignment (except `..=` on a plain variable), `++`/`--`, `foreach`, list patterns and `??=`
  are lowered to plain
  instructions with hidden variables (`$#update_*_n`, `$#foreach_*_n`, `$#destructure_n`,
  `$#match_n`, `$#finally_error_n`) that no program can name. Lists and maps made only of
  constants are built once and pushed as one value. `match` emits the tests first and the bodies
  after, so every arm leaves exactly one value and the stack depth agrees on every path. `try`
  emits `TRY`/`END_TRY` handlers, with `break`/`continue`/`return` leaving them itself
  (`leaveTries()`) and copying each `finally` after its own code. A postfix `++` used as a
  statement compiles as prefix. `docs/bytecode.md` has every instruction.
- **The PHP VM**: `VM::link()` collapses `STORE x; LOAD x; POP`, resolves labels, and splits the
  code into opcode and argument arrays; `execute()` is one dispatch loop with frames in an array,
  so deep recursion doesn't use PHP's stack. It re-enters itself for a method called from inside
  an instruction (echo calling `to_string()`, through `Values::$call_method`) and for a builtin's
  callback (`Values::$call_value`, checked by `callTarget()`, which `CALL_VALUE` doesn't call:
  that cost it 25%). Its fast paths
  cover only the commonest cases whose result is obvious (ints that don't overflow, `==` on two
  ints or strings, `JZ`/`NOT` on bools, list and map indexing, `len`/`ord`/`chr`/`in_array`,
  `LOAD_FIELD` on a set field); everything else, errors included, falls through to `Values`.
  Keep them that way. `SET_PATH` unsets its temporaries first, since one still holding the list
  made PHP copy it on every write.
- **`bin/gazlang-php` restarts itself once** (`GAZLANG_RESTARTED`, since a `-d` isn't in `$argv`)
  with `pcov.enabled=0`, because with pcov every PHP call uses the C stack and deep recursion in
  the interpreter segfaults, and with `opcache.jit=1235` (about 25% faster; the default tracing
  JIT made the VM twice as slow, 1205 cost 0.2s per run; skipped under Xdebug). It restarts
  through `proc_open` with its own streams, so stdout and stderr stay in order.

## The bytecode format

`docs/bytecode.md` is the spec. The choices behind it:

- **Text, one instruction per line**, by name, not number: the self-hosted compiler writes it
  with `..` and `join`, a loader needs only a line reader and a literal parser, and two
  compilers' output diffs readably. A binary cache can come later if loading measures slow.
- **A block per function** (code objects, as in Lua and Python), labels scoped to their block
  and hidden variables numbered within it, so a change in one block renumbers nothing else.
  Each block ends with a `locals` line naming its slots.
- **Locations as `@ "file" line` lines**, paths relative to the main source's directory and
  resolved against the bytecode file's, so bytecode saved next to its source reports exactly
  what running the source does. `<builtin>` is never rewritten.
- **`PUSH` takes a GazLang literal** as the rest of the line. The smallest int has no literal,
  so a loader must read a sign and digits as one number (`strtoll`).
- **Deterministic**: the same source gives byte-identical bytecode. **A version** in the header
  that loaders refuse to mismatch.
- **The loader checks everything**, including a walk of each block's stack through every jump,
  so a file that loads is one the VM can run (Lua and CPython crash on bad bytecode); the walk
  also gives each frame's size. Peephole rewrites belong to loaders, not the format.
- **A stack machine**, not registers: the compiler is the part written in GazLang, a stack
  machine's is much simpler, and a loader can add superinstructions without touching the format.

## The self-hosted ports

- **Where they differ from the PHP, and why**: the lexer's scanner is an object, because
  `include` needs two lexers alive at once; its operators are one table matched longest first.
  The parser's eleven binary levels are one table and a loop (precedence climbing) rather than a
  method each, 28% faster. `lambda_heads` (a set by `spl_object_id`) is one field, since nothing
  is read between marking a `(` and asking. A member use's record is found by an index the node
  holds, not a reference, so a class's tree isn't a cycle for the collector. Trees are walked
  with an explicit stack, since a chain of 5000 operators is 5000 deep. The lexer's operator
  table is matched longest first, which assumes every prefix of an operator is an operator too
  (except `..`'s; a lone `.` isn't one): a new operator that breaks that needs handling. The code generator
  dispatches with one `match (class_of($node))`, since GazLang can't build a method name, and
  copies a rebuilt node's location by hand (easy to forget; the corpus checks it). The writer's
  paths stay textual, since a path written into bytecode needn't exist.
- **The tree dump** skips fields that are derived or the code generator's: `AST\Dumper::SKIPPED`
  and the driver's `SKIPPED` must agree, or the parser harness fails. On an error there is no
  partial tree, since the whole-program checks write into nodes parsed long before; nothing
  catches a `ParseError` and carries on, so neither parser restores state.
- **Files with no top level code** (`lexer.gaz`, `parser.gaz`, `codegen.gaz`), since including a
  file runs it; the driver is separate.
- **The driver raises `LexError` and `ParseError` messages again from the top level**
  (`error($e.message)`), so `--tokens` and `--ast` print only the message; a bug in a port is a
  different error and still arrives with its trace. `LexError` carries `#reason` and
  `#source_line`, since `#line` is where in `lexer.gaz` it was raised. A `try` in the lexer holds
  only the `to_int()`/`to_float()` it is about, since `catch (Error)` also catches running out
  of call depth.
- **What they lean on instead of porting**: `slice(to_string([$v]), 1, -1)` is `Lexer::quote()`
  and `Program::value()`, `..` on a float is `format_float()`, `to_int()` in a `try` is the
  overflow check.
- **Paths** resolve as `Parser.php` does, with `cwd()`, `real_path()` and `file_exists()`; the
  parser harness also runs from other working directories and with an absolute include.

## The C VM

`bin/gazlang` must behave exactly as `bin/gazlang-php` does, the PHP VM being its spec. It runs
0.4 to 1.6 times the time of the same program written in PHP, and 6 to 40 times faster than the
PHP VM (`php vm/bench.php`: CPU time, interleaved, best of several).

- **The CLI** parses options as PHP's `getopt` does, plus the check for unknown ones: options
  end at `--`, `-` or the first non-option; `-f` takes the next argument whatever it is.
  Bytecode is recognised by its first line or a `.gzb` name. `-c`, `-t` and `--ast` run the
  built-in front end in that mode; running source runs it in `code` mode first. With no file and
  a terminal on stdin it prints the help to stderr and exits 1: there is no REPL (the PHP one
  runs each line as its own program, which isn't one).
- **Running source**: the compiler runs as a program of its own with its output captured in an
  `open_memstream()` buffer, which is then loaded as bytecode saved next to the source. Each run
  starts with fresh stacks and globals and the compile's leftovers are dropped first, so the leak
  check sees only the program. It costs about 10ms before the first instruction.
- **Errors are return values**: a function that can fail returns `bool` with the error in
  `vm_error`, passed up to the dispatch loop, which locates it and unwinds. No
  `setjmp`/`longjmp`, so reference counts stay right on the way out. The loader is the
  exception: it gives up at the first problem.
- **Values** are a 16-byte tag and payload. Strings, lists, maps, functions, objects and raised
  errors are reference counted, and lists and maps copied on write, which is PHP's value
  semantics exactly. A map is PHP's design: entries in insertion order with holes, and an
  open-addressed index rebuilt as it grows. Names are interned, so member lookups compare
  pointers; one-byte strings are 256 shared values.
- **Frames live on one value stack**: a call's pushed arguments become the callee's first
  locals, and its stack is sized by the loader's walk. The one use of the C stack is a method
  or a builtin's callback run from inside an instruction (`call_method()`, `call_value()`), which
  can nest as deep as the call limit, so the program runs on a thread with a 1GB stack (address
  space, backed only as used). `call_value()` checks its callee as `CALL_VALUE` does, with its
  own copy of the checks (`enter_value()`): sharing them cost lambda calls 8%.
- **The cycle collector** (`gc.c`) is CPython's trial deletion over every list, map, object and
  function, needing no roots, run at a backward jump or call once as many containers have been
  made as were alive after the last collection. There are no destructors, so freeing runs no
  program code. The tested build collects every 64 new containers so the harness exercises it.
- **Superinstructions** (`fuse()` in `load.c`): the loader puts one in place of the first
  instruction of a common sequence (`LOAD; PUSH; LT; JZ`, `LOAD x; INC; STORE x`, seven in all,
  after `OP_COUNT` so no file can name one) and leaves the sequence where it was, so a jump into
  it still lands on real instructions. A superinstruction's quick path must be one that can't
  fail or run program code; otherwise it runs its first instruction alone and the rest follow,
  so errors and their lines are the sequence's own. `tests/vm_corpus/superinstructions.gaz`
  takes every fallback; `bytecode_corpus/superinstruction_lookalikes.gzb` holds the shapes only
  hand-written bytecode has.
- **Speed**: what paid was an int fast path for `%`, the `STORE; LOAD; POP` peephole, shared
  one-byte strings, not interning names on the hot path, inline caches on member instructions,
  and the superinstructions (fib 25% faster, the arithmetic loop and lists 15 to 20%, the
  self-hosted compiler 2 to 7%). A call into another file on the hot path costs twice: a
  one-line `arity_fits()` in `builtins.c` made `CALL_VALUE` 6% slower once code before it moved,
  so it is `static inline` in the header. Computed-goto dispatch didn't (the CPU predicts the switch
  well), nor did a fast path for `==`, nor fusing a comparison or `==` with `JZ` on its own once
  `LOAD; PUSH; comparison; JZ` existed (a string comparison missed the quick path and paid for
  the detour). On the development machine (an i7-8700) moving code a few bytes swings a hot
  loop by 5%, so judge a change with the same binary both ways, or on two builds (adding
  `-mbranches-within-32B-boundaries` moves everything), and keep what wins on both. What is
  left in a profile is the dispatch loop, malloc/free and the collector.
- **Why C**: over Rust, Zig and Go, since the heap (refcounts plus a cycle collector) is unsafe
  code in every one of them, Go has no refcounts for cheap copy-on-write, and Zig moves under a
  pinned toolchain; C bootstraps with nothing but a C compiler, and the differential harness
  under ASan and UBSan is the safety net C usually lacks. A separate program rather than PHP
  FFI, since converting values per call costs more than an instruction.
- `ponytail:` in the C: float printing tries up to 34 `printf`/`strtod` pairs per float.
