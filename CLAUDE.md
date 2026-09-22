# GazLang Development Guidelines

GazLang is self-hosting: the lexer, parser and code generator are written in GazLang
(`compiler/`), compiled to bytecode (`compiler/gazlang.gzb`, checked in) and built into a VM in C
(`vm/`); together they are `bin/gazlang`. The tests are PHP (PHPUnit), which runs `bin/gazlang`.
`README.md` is the invitation, `docs/language.md` the language reference, `docs/internals.md`
the contributor guide, `docs/bytecode.md` the bytecode spec. This file holds the rules and the
reasons behind them; history is in git.

## Build & Test Commands
```bash
# Build gazlang: the VM in C with the self-hosted compiler built in, as bin/gazlang
# (not checked in; the tests build it themselves)
make -C vm

# Run a file, print its bytecode, run bytecode, or print its tokens or tree
bin/gazlang -f examples/functions.gaz
bin/gazlang -c -f examples/functions.gaz > /tmp/f.gzb && bin/gazlang -f /tmp/f.gzb
bin/gazlang --tokens -f examples/functions.gaz
bin/gazlang --ast -f examples/functions.gaz

# After changing compiler/, rebuild the compiler gazlang has built in, with gazlang alone
# (a test fails until then; see "Changing the compiler")
make -C vm compiler

# bin/gazlang with profile-guided optimisation, trained on the compiler and examples/ (opt-in;
# plain make stays a plain -O2 build, and won't replace this one until a source changes)
make -C vm pgo

# The test dependencies, then all tests (about 55s)
composer install
vendor/bin/phpunit

# Run a specific test file, or method
vendor/bin/phpunit tests/SpecificTest.php
vendor/bin/phpunit --filter=testMethodName tests/SpecificTest.php

# After adding tests, collect their snippets for CVMTest
php vm/snippets.php

# Fuzz the sanitized VM for a minute (--seed N to replay, --seconds S, --shrink FILE)
php vm/fuzz.php

# What differs from tests/expected; --update adds new programs to vm/passing.txt and records
# what every entry prints (review that diff)
php vm/progress.php [FILTER] [--update]

# After a change to what the front end or the command line prints: record it, review the diff
GAZLANG_RECORD=1 vendor/bin/phpunit --filter 'SelfHosted|CliTest'

# The self-hosted front end from its source; without a file it reads piped source
bin/gazlang -f compiler/gazlang.gaz -- code examples/functions.gaz
bin/gazlang -f compiler/gazlang.gaz -- ast < examples/errors.gaz

# The C VM's coverage by the harness, its speed, and a build that collects cycles at every chance
# (over an hour: collecting is quadratic, and the entries that compile the compiler take longest)
php vm/coverage.php [file.c]
php vm/bench.php
make -C vm stress && GAZVM=vm/build/gazvm-stress php vm/progress.php

vendor/bin/phpstan analyse          # must be clean
vendor/bin/pint                     # formatting
```

## Code Style Guidelines
- **GazLang** (`compiler/`, `lib/`): functions, variables, fields and methods snake_case, kinds
  PascalCase, constants UPPERCASE. The lexer's and parser's methods are named after the grammar
  rule or step they read (`get_next_token()`, `function_call()`, `left_associative()`).
- **C** (`vm/`): plain C11 plus POSIX (`-D_DEFAULT_SOURCE`, which glibc needs for
  `open_memstream`, `realpath` and `memmem`), libc, libm and pthreads, and OpenSSL in `net.c`
  only (on by default, `make TLS=0` without, `GAZ_TLS` saying which), built warning-free by
  clang and gcc, commented where the C isn't obvious (a
  flexible array member, a `goto` into shared code), for readers who know a little C.
- **PHP** (the tests and `vm/*.php`): 8.5 or later, PSR-4 under `GazLang\Tests`, methods
  camelCase, PHPDoc on classes and methods. The pipe operator (`$x |> trim(...)`) where a chain
  of single-argument calls reads better.
- `ponytail:` comments mark known ceilings, with what would lift them.

## Layout

- `compiler/`: `lexer.gaz`, `parser.gaz` and `nodes.gaz`, `codegen.gaz`, and `gazlang.gaz`, the
  driver: `gazlang.gaz -- code|tokens|ast [FILE]`, reading standard input without a FILE, a
  usage message and exit 2 otherwise. `gazlang.gzb` is its bytecode.
- `vm/`: the VM in C. `gazvm.h` says which file does what: `value.c` and `ops.c` are what values
  mean (operators, truthiness, printing, keys, indexing, write paths), `builtins.c` the builtins
  and their arities (`builtin_info[]`), `load.c` reading and checking bytecode, `vm.c` running it
  and the CLI, `gc.c` the cycle collector, `net.c` sockets and TLS.
- `lib/`: the standard library in GazLang. `examples/`: sample programs.
- `tests/`: PHPUnit, `tests/gaz/` (GazLang programs), `tests/expected/` (what every program
  prints), and the corpora: `lexer_corpus/`, `parser_corpus/`, `codegen_corpus/`, `vm_corpus/`,
  `bytecode_corpus/`, `cli/`, `json/`, `csv/`.

## How it is held together

Everything is checked against recorded output, byte for byte, and the recordings are the spec:
a change to what something prints is recorded (`progress.php --update`, `GAZLANG_RECORD=1`) and
its diff reviewed like code. **Break a checker on purpose before believing a run that finds
nothing**: several first versions of a harness or corpus passed everything and caught nothing.

- **The tests run `bin/gazlang`**: every `GazLangTestCase` helper (`executeCode()`, `parse()`,
  `lex()`, `generateCode()`, `runProgram()`, `cli()`) runs the optimised build as a process from
  the project root, a snippet piped in, and a failure is a `ProgramError` holding what it printed
  after `Error: `. `vm/snippets.php` collects the snippets, as JSON, for `CVMTest`. Order matters
  as much as results: `KEY_CHECK` exists so a bad key fails before later keys and the value run.
- **Programs print what `tests/expected/` records** (`CVMTest`, `tests/CVM.php`): each entry of
  `vm/passing.txt` (every program and corpus file, the snippets in `tests/vm_snippets.txt`, and
  the hand-written broken `.gzb` files) runs on the C VM built with ASan and UBSan and must give
  the recorded stdout, stderr and exit code (`.stdout` always, `.stderr` and `.exit` when there
  is one; the checkout's path as `<root>`). A source entry runs from source, so the built-in
  compiler compiles each one under the sanitizers; a snippet is piped in from the project root,
  as it has no file. `progress.php` adds a candidate the compiler accepts and that doesn't leak;
  the list only grows, except when a snippet or fixture itself goes, which `--update` drops
  (`CVM::gone()`; `CVMTest` fails naming them until then).
- **The front end prints what its corpora record**: each `X.gaz` in `tests/lexer_corpus`,
  `tests/parser_corpus` and `tests/codegen_corpus` has what `--tokens`, `--ast` or `-c` must
  print next to it (`X.tokens`, `X.ast` and `X.piped.ast`, `X.code` and `X.piped.code`; the
  runs from other working directories in `tests/parser_corpus/places/`), exit code 1 when a line
  starts with `Error: ` (`assertPortPrints()`). `SelfHostedLexerTest`, `SelfHostedParserTest`
  and `SelfHostedCompilerTest` run the driver compiled from the current `compiler/` source (not
  the built-in one, which is stale until `make compiler`), 24 at once (`CVM::driver()`). The
  dump is read off the nodes rather than written per node type, so a field added to a node
  shows in every `.ast`; a failure reports the first line that differs (`assertSameText()`),
  since phpunit's diff is quadratic.
- **Corpora**: add a file whenever something reveals an untested case, and record what it
  prints. In the lexer, parser, code generator and bytecode corpora the files named `error_*`
  must be exactly the ones that fail. Put a node's tokens on different lines when its location
  matters: a one-line case can't tell one token's line from another's.
- **The C VM doesn't leak**: output can't show a forgotten `decref`, and ASan's leak detector
  doesn't run on macOS. With `GAZVM_STATS` set, the end of a run drops the globals and the top
  frame, collects cycles, drops the constants in the code (so an extra reference to one shows)
  and prints `gazvm: N values leaked`, N being what is left beyond what was alive before the
  program loaded; the harness and `progress.php` fail an entry that leaks or
  prints no line. `exit()` and a refused file say `leaks not checked`. On Linux LeakSanitizer
  also runs in the sanitized builds and catches plain allocations the count can't see;
  `__lsan_default_suppressions()` in `vm.c` exempts only the loader, which gives up on a broken
  file without freeing what it built.
- **The command line prints what `tests/cli/expected/` records** (`CliTest`): a table of
  invocations of `tests/cli/` programs (arguments, what is piped in, working directory). A change
  to its options or how it reads input needs a row.
- **The compiler compiles itself to itself**: `compiler/gazlang.gzb`, run under the sanitizers,
  compiles `compiler/gazlang.gaz` to exactly `gazlang.gzb`
  (`test_the_self_hosted_compiler_compiles_itself_to_itself`). This checks the front end on the
  largest program there is.
- **GazLang code is tested with GazLang programs**: every `tests/gaz/**/*_test.gaz` must print
  exactly its `*_test.expected` (`GazProgramTest`); `check.gaz`'s `check($label, $actual,
  $expected)` prints `ok <label>` or a FAIL line. `lib/json.gaz` is checked against PHP's
  `json_decode` on `tests/json/y_*`/`n_*` (the prefix says whether it must parse), `lib/csv.gaz`
  against `fgetcsv` on `tests/csv/`, `lib/chars.gaz` against the lexer's classes for all 256
  bytes.
- **The README's examples are tests**: `ReadmeTest` runs every ```` ```gaz ```` block followed
  by an output block and requires exactly that output.

Judge new features by what they cost **in C**: value semantics suit reference counting, and
anything that leans on a platform's behaviour (hashing, string conversion, float formatting,
rounding) must be a rule GazLang defines and writes out step by step. Grow the language by
writing real GazLang and fixing what hurts, and when a workaround in the repository's GazLang is
the evidence for a gap, check with `git log` when it was written: code older than a feature
can't have used it. Re-measure before trusting a recorded number.

## Changing the compiler

`make -C vm compiler` rebuilds `compiler/gazlang.gzb` with `bin/gazlang` alone, in three stages:
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
  until a compiler that understands it has been built. Add it to `compiler/`, run
  `make compiler`, and only then use it in `compiler/`. A new instruction goes into the C VM
  before any bytecode using it runs.
- The bytecode is built in with `od` into `vm/build/compiler.c`: numbers only, so nothing to
  escape, and no trigraphs, which `-std=c11` turns on and the `??=` in it would be.

## Status and what is next

- **CI** (`.github/workflows/ci.yml`) runs on Ubuntu and on macOS, Apple silicon and Intel,
  for every push: it builds gazlang without TLS (the bootstrap needs only a C compiler), then
  with it, and rebuilds its compiler before PHP is even installed, then the suite; phpstan and
  pint run on Ubuntu only.
  Development is on an Intel Mac.
- **Speed**: the same program takes gazlang 0.4 to 1.5 times what it takes PHP (JIT or not),
  and Python 3.12 1.4 to 2.9 times what it takes gazlang (`php vm/bench.php`, which finds a
  Python 3.11 or later for the `vm/bench/python/` ports; the README's table is its output on a
  `make pgo` build, unlabelled there, so a plain build runs a little slower). The
  arithmetic loop (1.5x) is still about a dozen dispatches an iteration against PHP's JIT, which
  only a register bytecode or a JIT would close; lists, maps, strings and objects (0.8 to 1.3x)
  spend theirs in malloc/free and the collector, so profile those before trying an allocator.
- **Bytecode has no compatibility promise yet**: stable so far, but free to change; a change
  old files can't load under bumps the version.
- **The fuzzer** (`php vm/fuzz.php`, a minute; CI runs one on each push, seeded by the run
  number) needs no oracle: generated programs, mutated corpus programs and mutated bytecode
  run through `CVM::runC()`, and it fails on a sanitizer report, a crash, a leak, a time-out,
  an error raised inside the compiler, or bytecode the compiler wrote that the loader refuses.
  What they print isn't checked, since nothing says what it should be.
  - **Generated programs are made to end**: calls only go down (a function calls the ones
    before it, a method the ones below it), loops run a few times, and a lambda calls nothing
    that calls back, so a time-out in one is a bug. A mutant may just loop, so its time-out is
    only reported.
  - **Mutations aim at instruction lines**, in the file being mutated and in the one a line is
    taken from: a block's header lines (`top`, `locals`, `fn`, `kind`) are a good part of a
    small bytecode file, and damage to one is refused by the header parser before an
    instruction is read, which the corpus already covers. Aiming (one try in five still lands
    anywhere) took mutants that load from 9% to 13% and moved the refusals into the operand
    and stack checks.
  - **Everything follows from the seed**, so `--seed N --runs M` replays a run. A failure is
    saved in `vm/build/fuzz/` and shrunk, a minute in a run and to the end with
    `--shrink FILE`. A try costs about 0.1s of the sanitized build's start-up, whatever the
    program, which is why shrinking takes the time, not the run.
  - **Nothing opens a socket, starts a program, exits or writes a file**: a program naming
    `run`, `exit`, `write_file`, `read_stdin` or a `socket_` builtin is skipped, an included
    file's text included, which is sound because a builtin is reached only by its name.
  - **Break it before believing it**: a missing `decref` in `delete` and a read past a string
    in `reverse`, planted in turn, were both found within 700 programs. The first also showed
    that the leak check couldn't see an extra reference to a constant, which is why a checked
    run now drops the constants too.
- **Known limits**, none worth fixing yet:
  - The self-hosted parser runs out of call depth on source nested past about 1100 levels
    (recursive descent is about nine calls a level), as an internal error. Its tree walks use
    an explicit stack for that reason.
  - A `make compiler` stage's own runtime errors name `vm/build/bootstrap/` as the source
    directory, since bytecode paths resolve against the bytecode file; the lines are right.
  - A main file given by an absolute path through a symlinked directory (macOS's `/var`) gives
    include locations that climb to the root and back through the real path.
  - The keyword hint misses `IF (1) { }`, where the error lands at the `{`, past the name.
  - `CVMTest` doesn't catch `make compiler` checking in stage 1 instead of stage 2: that only
    shows when an edit changes code generation, which would cost the suite a second rebuild.
- **Language gaps**, each waiting for real code to ask:
  - Appending to a list parameter silently does nothing (`fn add_to($l) { $l[] = 1; }`), and
    the parser can't tell it from a function that returns the list. Mutable state belongs in
    an object; by-reference parameters aren't worth their cost against refcounting.
  - `map`/`filter`/`reduce` call their function with the value alone, even over a list, so
    getting the index needs a `foreach ($x as $i => $v)` instead of a `map()`
    (`Scout.team()`'s shirt numbers hit this: `map($formation.positions(), $kind -> ...)`
    became a `foreach` once it needed the index).
  - `split($x, $sep)` has no limit: it always splits on every occurrence, so keeping the
    trailing remainder together (`"a=b=c"` split on `"="` into `["a", "b=c"]`) needs
    `index_of` and two `slice`s instead of a third argument.
  - No `?.`: `$x?.foo` doesn't parse. `??` covers a missing map key or an unset field, but not
    "this might itself be null, so skip the read"; that's an explicit
    `$x == null ? null : $x.foo` every time.
  - `$obj.$name` (dynamic member access; `lib/sorting.gaz` can sort maps but not objects),
    `json_encode` of an object (`fields()` lists what it would write; `to_string()` and cycles
    to settle), and `kind_name($kind)` (the bare name; today `slice(to_string(kind_of($x)),
    5)`).
  - `kind_of` is strict, so a pass over a tree with absent children needs a `type_of` check
    first; if that recurs, make it lenient.
  - Scanning bytes: `$s[$i]` makes a one-byte string (shared in C) and there is no `byte_at` or
    "index of the first byte in this set". The self-hosted lexer spells out comparisons and
    walks local indexes, for speed on the PHP VM it was first measured on; measure on the C VM
    before keeping that.
  - `..=` on a field or element (`#buf ..= $c`) still lowers to `#buf = #buf .. $c` in generated
    code, which copies the string: only a plain variable appends in place.
  - Including a file also runs its top level code. `Error`'s members are reserved across its
    children, so a domain error can't declare its own `#line` or `#message`.
  - No identity key for an object (a side table keyed by node), no `to_int`/
    `to_float` that returns null instead of throwing, no copy-with-change for objects, no
    `catch (A | B $e)`, no bare rethrow, no `_` to skip an element in a list pattern.
  - `match ($x)` is a linear chain of `EQUALS`; no jump table.
  - No enum: token types are strings on purpose (they are the `--tokens` format).
- **HTTP is HTTP/1.1 in GazLang (`lib/http.gaz`) on socket builtins, TLS through OpenSSL**,
  linked by default and optional (`make TLS=0`), so the bootstrap still needs only a C compiler.
  Not curl through `run()`: a process per request, the headers visible in `ps`, and curl as a
  runtime dependency; not TLS of our own, which would be thousands of lines of crypto whose bugs
  no output shows. One connection per request; keep-alive, proxies, compression and HTTP/2 wait
  for a program that needs them.
- **Decided, not built**: `interface`/`implements` (a parse-time check that the methods exist,
  plus `is_a`), and `final`. The keywords are reserved.
- **Namespaces** are resolved by the parser, so the VM never learns the word and bytecode only
  sees longer names: functions and kinds carry `::`, while a method block stays
  `Kind.method`, which is what lets the loader tell the two apart. Resolution is one pass
  before anything else is checked, so nothing below it knows namespaces exist.
  - `namespace json;` first in a file, at most one, optional: a file without one declares its
    names globally, as every file did before, and a program never needs one.
  - **A name is private to its namespace unless `pub`**: a file is implementation, and only
    what it says is public escapes it. Privacy is per namespace, not per file, so `compiler/`'s
    five files declare `namespace gazlang;` and go on seeing each other's everything, and a
    test of the internals joins the namespace rather than making them public. A kind's members
    work the same way, so `pub` means one thing everywhere.
  - **`include "chars.gaz" use is_digit, char_at as at;`** is the only way to bring a name in
    unqualified. Including a file always makes its namespace reachable qualified
    (`chars::is_digit`); the clause only adds aliases, and names in it are bare, since the
    string already said which file. A file already spliced in gives no statements again but
    still answers its `use` clause. There is no standalone `use`, so a file can only name
    what it includes itself, and no `use ns::*`, which is how the flat namespace came back.
  - **Resolution** is the current namespace, then this file's aliases, then global and
    builtins. No fallback into another namespace, which is PHP's wart. Only a name's first
    part is resolved, since a namespace holds no namespace: in `namespace gazlang`,
    `Token::EOF` is `gazlang::Token::EOF` and `json::decode` is already what it means.
  - **A namespace's own name wins over a builtin of that name inside it**, which is what the
    order means: `pub fn values()` in `namespace sorting` makes a bare `values($x)` in that file
    `sorting::values($x)`. The alternative, builtins first, would mean a new builtin could take a
    name a namespace already used.
  - Errors are the parser's: a `use` on a file that declares no namespace, a name that isn't
    `pub`, a name in a `use` clause that is qualified, an alias already taken, a namespace
    only reached through another file's include.
  - `namespace`, `use`, `pub` and `kin` are reserved, so `fn use()` no longer parses.
- **Static members** are reached by name as constants are: `Counter::next()`, `Counter::COUNT`,
  `Counter::count`. Not through a value: `$obj::next()` puts a value on the left of the
  parse-time operator, which is the one place PHP's `::` means something else, and `$obj.count`
  is a member of whatever the object has. `.` on a kind stays an error until a program asks
  for `kind_of($obj).next()`.
  - **`static fn next()` and `static #count = 0;`**, and `#name` inside the kind whichever
    kind it is, since `#name` already means a member of the kind it is written in and
    members share one namespace across the hierarchy. A child shares its parent's static, as
    it shares the rest of the namespace; it can't redeclare one.
  - **A static field is a slot**, addressed like a global: `statics` in the bytecode header
    names each one `Kind::field` after the kind that *declares* it, so a kind and its
    children name the same slot, and `LOAD_STATIC`/`STORE_STATIC`/`SET_PATH_STATIC`/
    `DELETE_PATH_STATIC` are the global instructions again. A **static method is a function**
    named `Kind::name`, so calling one is an ordinary `CALL` and the VM learns nothing: a
    method block keeps its dot, which is still what says "this one needs an object".
  - **A static field's default is a constant expression**, folded by the parser and written
    before the program's own first instruction, which is more restrictive than an instance
    field's (any expression, per object) for the reason constants refuse `Point(0, 0)`:
    running one would bring initialisation order and bytecode before the top level. It is
    required, since a slot with nothing in it would need the quiet load a static never wants.
  - **Assigned from anywhere it escapes to** (`Counter::count = 1`, `#count++`,
    `Counter::rows[] = $r`): a `pub static` from anywhere, one that says nothing only from
    inside the kind, so a static needs no rule of its own to protect it. Both
    spellings compile to the same instruction, since `::` resolves when it is parsed; the cost
    was a fifth root for `store_path()` next to a local, a global, a capture and `#`, and the
    check refusing `Kind::NAME = ...` that mirrors the one refusing `#NAME[0] = 1`.
  - **In a static method `#name` is the kind's**, so `#count` is the static field and
    `#helper()` another static method; naming an instance member is a parse error, as is `#`
    on its own, since there is no object and members are declared.
  - `#next` without calling it is an error that says to write `Counter::next`, which is the
    function. A bound static would be the same value by another spelling, so it waits for a
    program that wants it.
- **Not planned** until real code asks: traits, late static binding, operator
  overloading, `**` and `sqrt`/`pow`/`log`, variadic parameters and spread in calls (pass a
  list), `time()` (time it from outside), `foreach` over a string (`split($s, "")`), a REPL.
- **Regular expressions**: `lib/regex.gaz` (`regex::matches`, `regex::search`,
  `regex::find`), a Thompson NFA (Pike's VM) so there is no backtracking and no ReDoS.
  Literals, `.`, `*` `+` `?`, `|`, `(...)` grouping (not capturing), `[...]`/`[^...]`
  classes with `a-z` ranges, `^`/`$` anchors, `\` escapes. No capture groups, no
  backreferences, no `\d`/`\w`/`\s` shorthands (`lib/chars.gaz` has those as named
  functions) — add them if a program needs one.

# The language

Rules, with the reason where the choice isn't obvious. `docs/language.md` is the reader's
version.

## Operators, truthiness and equality

- **Precedence**, loosest first: assignment (right associative) → `?:` (right) → `??` (right)
  → `||` → `&&` → equality (`==` `!=` `<=>`) → relational → `..` → `|` → `^` → `&` → shifts →
  `+ -` → `* / %` → unary → postfix (`[index]`, `(args)`, `.name`, `::name`) → primary. Bitwise precedence
  is Rust's and Python's, not C's, so `$flags & MASK == 0` is `($flags & MASK) == 0`. `..` sits
  looser than the bitwise operators and tighter than comparison, so `"x = " .. $f & MASK` and
  `$f & MASK .. "!"` both do the obvious thing (between the bitwise levels, every unparenthesised
  mix would be an error); shifts stay tighter than `..`, unlike Lua, so `"n = " .. $x << 2` works.
- **A real bool**: comparisons, `!`, `&&` and `||` give `true`/`false`, `&&`/`||` short-circuit. A
  bool is not a number: `true == 1` is false and `true + 1` is `Cannot use + on bool`;
  `to_int(true)` is 1. Truthiness (`is_truthy()` in `value.c`) is the one place a non-bool is read
  as a bool: numbers C-like, strings true unless empty (`"0"` is true), empty lists and maps
  false, `null` false, functions and objects always true.
- **`..` concatenates**, converting both sides as `echo` does (`1 .. 2` is `"12"`); `+` is
  numeric only. It sits below `+`, so `"n = " .. $a + $b` concatenates the sum.
- **`==` never converts between strings and numbers** (`values_equal()` in `value.c`, shared with
  `in_array` and `match`): strings compare byte by byte (`"1" != "01"`, `"10" < "9"`), a string
  never equals a number or bool, and ordering a string against a number is an error. Numbers
  compare by value, exactly (`1 == 1.0`, but `9007199254740993 != 9007199254740992.0`, unlike
  PHP). A bool equals only itself, `null` only `null`. Lists compare in order, maps by keys and
  values in any order, and a list never equals a map, even `[] == {}`. Functions, kinds and
  objects compare by identity (bound methods: the same object, kind and method). No `===` (it
  lexes as `==` then `=`, a syntax error). `<=>` gives -1, 0 or 1 by the ordering rules.
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
  hex. `parse_number()` in `value.c` reads the same syntax for `to_float()`, and JSON numbers are
  valid.
- **Nothing overflows silently**: an int that doesn't fit is `Integer overflow` (PHP would
  switch to a float), an infinite float literal is a lexer error, an infinite result is `Float
  overflow`. Division by zero is an error.
- Int with int gives an int, a float on either side a float, a bool on either side an error.
  **`/` always gives a float** (`6 / 2` is `3.0`, as in Python 3 and Lua 5.3), converting ints
  first, so it loses precision above 2^53; `intdiv()` truncates.
- **Printing is exact**: `format_float()` in `value.c` gives the shortest digits that read back as
  the same float, always with a dot or exponent (`1.0`, `0.30000000000000004`, `1.0E+25`, `-0.0`).
  echo, `to_string`, interpolation, `--tokens` and bytecode all use it. It tries both neighbours
  at each length, since next to a power of two the correctly rounded candidate can fail to read
  back.
- Floats can't be keys, indexes or string positions. `0.0` and `-0.0` are false.
- `round($x, $precision = 0)` is PHP's (halves away from zero, with its pre-rounding, so
  `round(1.005, 2)` is `1.01`; negative precision rounds to tens), written out step by step in
  `php_round()` in `builtins.c`, since PHP's own changed between 8.5 releases. `floor`, `ceil`,
  `round` give floats; `abs` keeps the type; `min`/`max` take two numbers or two strings, or a
  list or map whose values are all numbers or all strings (empty is an error), a tie giving the
  first. `sum` adds a list's or map's values from 0 with `+` (`binary_op(OP_ADD)`), so its
  errors, overflow and int-or-float are `+`'s and `sum([])` is 0. `to_int` truncates a float and errors outside the int range; `to_float(true)`
  is 1.0.

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
  nest; the parser desugars to `..`, so the VM needs nothing. Include paths can't interpolate.
- `quote()` in `value.c` is the exact inverse of a literal, and everything that shows a string
  as source uses it.

## Lists and maps

- A list `[1, 2]` holds values at 0, 1, 2...; a map `{"k" => 1, 5 => 2}` holds values by key
  in insertion order (duplicate keys keep the last). `[k => v]` is a parse error pointing at
  `{}`. Keys are int or string, and `"1"` and `1` are different keys. **Two
  types, not PHP's one array**, so a list's indexes are always 0 to len - 1.
- **Values, not references**: assigning or passing one copies it (copy on write). Writing one
  in place is only ever through a variable's path.
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
  the whole program is read, so the VM trusts calls. A default is evaluated on each call
  that leaves the argument out, inside the function, so it sees earlier parameters and a `[]`
  default is never shared; the arity is then `[required, total]`. `function` is reserved and
  says to write `fn`.
- **`$x` is always local** (to the running call or the top level), **`@x` always global**; a
  function can't read top-level `$x`. Parameters are `$` only. `return` outside a function is a
  parse error; no return gives `null`. Variables holding `null` are still defined.
- Calls are capped at 10000 deep (`MAX_CALL_DEPTH` in `gazvm.h`), a catchable GazLang error.
- `include "path.gaz";` is top level only, takes a string literal relative to the including
  file (the working directory for piped source), and is resolved at parse time by splicing the
  file's statements in; each file is included once (the main file counts), by real path, which also breaks cycles.

## Builtins and the standard library

Builtins are `builtin_info[]` in `builtins.c` (name to an arity, or `[fewest, most]`), can't
be redeclared, compile to `CALL_BUILTIN name argc`, and check argument types by their
`type_of()` names.

- Strings: `len`, `slice($x, $start, $length)` (strings and lists, PHP's rules including
  negatives), `lower`, `upper`, `trim` (the lexer's whitespace only), `split` (empty separator:
  characters), `join` (elements converted like echo), `replace` (every occurrence; empty search
  is an error), `contains`, `starts_with`, `ends_with`, `index_of($s, $needle, $offset = 0)`
  (null when absent; negative offset from the end; an empty needle or an offset outside the
  string is an error), `repeat`, `chr` (0 to 255), `ord` (one
  byte), `to_int` (ints, bools, decimal strings with an optional `-`), `to_float`, `to_string`.
- Lists and maps: `in_array` (`==`), `has_key`, `keys`, `values`, `last` (an empty list is an
  error), `reverse` (lists, strings by byte, and maps, which keep their keys), and `map`, `filter`
  (truthiness, as `if`), `reduce($x, $f, $initial)` and `sort` (stable; the comparator must return
  an int), which call back into GazLang through `call_value()` in `vm.c`, checked as a call
  written in the program is. `sort` is a defined merge sort, since a comparator can see which
  comparisons are made: split in the middle, merge asking `$compare(right, left)` (`merge_sort()`
  in `builtins.c`). Types are checked before anything is called.
- Types: `type_of` (`int float string bool null list map function kind object socket`), `is_a`,
  `kind_of`, `fields` (see "Objects").
- I/O: `print`/`print_error` (echo without the newline, to stdout or stderr), `read_file`,
  `write_file`, `read_stdin` (empty when the program itself was piped in), `args()` (after the
  gazlang options or `--`; the CLI rejects options it doesn't know, since `getopt` would drop
  them silently), `cwd()`, `real_path()` (as `realpath(3)`; `""`, a NUL byte, `file/` and
  `file/..` are nothing, where platforms disagree), `file_exists()`.
- `run($argv, $input = "")`: `posix_spawnp` of a list of strings, so no shell reads them; the
  environment and working directory inherited. Standard input is `$input` in a temporary file
  unlinked before the program starts (a pipe would need writing while reading two, and SIGPIPE
  when the program stops reading, which Linux can't turn off per pipe), or `/dev/null` when it
  is empty, so a piped program's own input stays its own. Both outputs read together with `poll()` so neither pipe fills and blocks the child.
  Gives `{"status", "stdout", "stderr"}`, the status minus the signal's number when one killed
  it (Python's rule: no exit code is negative, where a shell's 128 + N is ambiguous). What
  can't be started is a catchable error naming it and `strerror()`'s reason, the same words on
  Linux and macOS for the ones tested. A new builtin takes its name from every program:
  `examples/brainfuck.gaz` had a `run()`.
- Sockets (`net.c`): `socket_open($host, $port, $tls = false, $timeout = 30)` gives a `socket`,
  a reference-counted handle closed when the last reference goes (so a forgotten close leaks no
  descriptor), `socket_read()` up to 64KB or `""` at the end, `socket_write()` all of it,
  `socket_close()` twice is fine. TLS verifies the chain against the system's store
  (`SSL_CERT_FILE` overrides, which is how `HttpTest` trusts `tests/fixtures/tls/`) and the host
  name, or the address for an IP (no SNI then). SIGPIPE is ignored around each call and put back
  after, as curl does: per socket only macOS can turn it off, and ignoring it for good would
  change what a program writing to a closed pipe does. OpenSSL reports a socket timeout as
  wanting to read; `net.c` says `timed out`.
- Random numbers, not cryptographically secure (the docs say so): `rand_int($min, $max)` (both
  included), `rand_float()` (0.0 up to 1.0, the top 53 bits), `rand_seed($seed = null)`
  (without a seed, from OS entropy; every program starts that way). xoshiro256** seeded
  through SplitMix64, as PHP's `Xoshiro256StarStar` does. Mapping outputs onto a range (mask
  and reject, `random_between()`) and onto a float is GazLang's rule, pinned for several seeds
  by `StdlibTest` against an independent port. The state is per program, reseeded by
  `run_program()`, since the compiler runs first. **Anything that prints random values calls
  `rand_seed()` first**, or what it prints can't be recorded as expected (snippets and corpus
  files included); unseeded behaviour is tested by type and range only.
- `error($value)` raises (see "Errors"); `exit($code = 0)` stops with that code, 0 to 255,
  printing nothing and running no `finally`.
- `builtins()` is that table as a map, in no promised order: the builtins of the runtime running
  the program, which the self-hosted parser checks calls against. That is right because the
  compiler always runs on the runtime that will run its output, and a loader refuses bytecode
  naming a builtin it lacks.
- In GazLang instead, each its own namespace, so only what a file marks `pub` escapes it:
  `chars.gaz` (character classes), `sorting.gaz` (`sorting::values`, `sorting::by`, on `sort`),
  `format.gaz` (`format::number`, `format::pad_left`/`pad_right`
  convert like echo: display helpers take any value, string functions stay strict),
  `json.gaz`, `csv.gaz` (RFC 4180), `http.gaz` (method and header names checked
  as HTTP tokens and URLs for spaces and control characters, so nothing can end a line of the
  request; credentials dropped on a redirect to another origin; `HttpTest` runs it against
  `tests/fixtures/http_server.php`, over TCP and TLS, which writes framing out by hand so it can
  get it wrong on purpose; ports vary, so what it prints is checked by shape, not recorded),
  `random.gaz` (`random::shuffle`, `random::pick`, `random::key`, `random::chance`,
  `random::weighted`). Scan long strings with `index_of`, not a character at a time.

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

Declared fields and single inheritance give every kind a fixed layout, so fields are slots
and methods a table in C.

```
abstract kind Shape {
    #name;
    fn _($name) { #name = $name; }
    abstract fn area();
    fn to_string() { return "{#name} with area " .. #area(); }
}

kind Circle extends Shape {
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

- **Kinds** are top level, usable before their declaration, and share the namespace of
  functions, builtins and constants. `abstract kind` can't be constructed; `abstract fn` must
  be defined by a concrete child kind. A kind body holds only fields, methods and constants.
- **Kinds are values and constructing is a call**: `Point(1, 2)`, `$make = Point`. `echo
  Point` prints `kind Point`. A call by name is checked like a function call. `class` is
  reserved and says to write `kind`, the way `function` says to write `fn`; a kind is a kind
  of thing, and `"kind"` is what `type_of` gives.
- **Constructing** sets the field defaults (the parent's first, in order), then runs `_` with
  the arguments. A kind without `_` inherits its parent's. A child calls the parent's with
  `##_(...)`, only in a constructor; nothing calls it automatically. `return value;` in `_` and
  `_` as a member are errors.
- **A constructor parameter written `#name` (or `pub #name`, `kin #name`) promotes it**:
  sugar for declaring the field with that visibility and assigning it from the parameter,
  `fn _(pub #x, #y) {}` being `#x;` and `#y;` in the kind plus `#x = $x; #y = $y;` as the first
  lines of `_`'s body — an ordinary `$name` parameter and a promoted `#name` one may mix freely.
  It is parser sugar only (`compiler/parser.gaz`'s `parameters()`/`promoted_assignment()`):
  the assignment is a synthesised `#x = $x;` statement prepended to the body, indistinguishable
  from a hand-written one to the code generator or VM, so it costs nothing beyond parsing. The
  parameter's own default (evaluated per call, may use `$` unlike a field default) is what
  gives the field its value; a promoted field takes no default of its own. A constructor with
  nothing left to write can end with `;` instead of `{}`, the assignments being all there is.
- **Fields are declared** (`#x;` or `#x = default;`); a default is evaluated per object, may use
  `#` but not `$` variables. Reading a field never set is an error; `??` reads it as null.
  Objects print only the fields that are set.
- **`#`** is the object, `#name` its member, checked at parse time against the kind and its
  parents (in methods, field defaults and lambdas in them; elsewhere `Cannot use #name outside a
  method`). **`##name`** is the parent's version of a method, decided at parse time from the
  kind it is written in; only methods, not abstract ones. Bare `##` is a parse error, kept free
  for the parent kind as a value; `#.name` says to write `#name`. Member names can be any word,
  keywords included.
- **Members** share one namespace across the hierarchy: a child can't redeclare a field or
  constant or give a field a method's name (the error suggests a new name). An override must
  accept every argument count the parent's does (constructors exempt), and an abstract method
  can't replace a concrete one.
- **A member is private unless `pub`**, the *same word with the same meaning* as a namespace's:
  `pub` says this name escapes the thing it is written in, whether that thing is a file or a
  kind. One keyword for the whole language, which is why the namespace section no longer has an
  "opposite defaults" paragraph. Measured on `compiler/` and `lib/` after the flip, 120 of 339
  members are marked, so the default was fighting the code.
  - **The ladder is unmarked (mine) → `kin` (mine and my children's) → `pub` (anyone's)**, and
    it reads the same on every sort of member: `kin #energy = 100;`, `pub static #tally = 0;`.
    `kin` is for what a kind declares on its children's behalf, which is why `protected` earns
    a rename where `extends` does not: it protects less than the default does, and a level is
    better named after who can see it. `kin` and `kind` are one root (kin, kind, kindred).
    `public` and `protected` stay reserved and say to write `pub` and `kin`, the way
    `function` says to write `fn`; `private` says a member needs no marker to be its own.
  - **The asking kind is where the code is written**, not what the object is: `#name` and
    `##name` are checked at parse time, `$obj.name` when it runs, and a lambda's and a static
    method's asking kind is the kind they sit in. A block's header carries it (`in Kind`, or
    the dot in a method's name), so the VM pays nothing until a member is looked up.
  - **A parent's private member is the parent's own.** A child can't name it, and may declare a
    method, constant or static of its own by that name: both entries live on and each kind's
    code reaches the one it can see, which is why a method table can hold two of one name. A
    field can't be reused, since a field is a slot and the name is taken across the hierarchy.
    The parent's own methods still reach it on a child's object, and so does the initialiser,
    which sets every slot the kind has (`KIND_INITIALISER` in `ops.c`).
  - **An override escapes as far as what it replaces**, the restrictive choice on purpose:
    loosening it later breaks nothing. The level belongs to the kind that *declared* the
    member, not to whichever version runs, so a `method` line carries a declarer once an
    override makes the two differ (`method area Square kin Shape`) and an ancestor that
    declared a `kin` method can still call it. `to_string()` must be `pub`, since printing
    calls it from outside and a private one would silently print the default form instead; an
    `abstract fn` must be `pub` or `kin`, since a child defines what it can see. A constructor
    takes no marker and always escapes: it is reached by constructing, not by naming.
  - **`fields()` and `echo` are not member access** and show every field that is set, whatever
    it escapes. Reflection exists so a pass can walk an object without knowing its kind
    (`--ast` does), and an `echo` that hid half an object would be a debugging footgun.
- **Objects are handles**: `$b = $a; $b.x = 1` changes `$a`; lists and maps inside stay values.
  `==` is identity, objects are always true, and operators, keys, indexes and `foreach` on them
  are errors.
- **`.` is member access**, checked when it runs (`Account has no member foo`). `.name` is one
  token glued to its name, but may start a line so chains continue. `$obj.name(args)` evaluates
  the object, looks the member up, then the arguments, then calls. `$obj.method` is a bound
  method, `==` another when object, kind and method match.
- **Write paths**: a variable or `#`, then any index or property steps (`$rows[0].total = 5`,
  `#count++`). `store_path()` in `ops.c` is the one definition. A path may also start at a
  call if a field follows it (`$team.keeper().saves++`, `f()[0].x = 1`): the code generator
  holds what the call returned in a hidden `$#root_n`, evaluated once and before the keys
  (`write_root()`), and writes through that, so the VM learns nothing. Without a field
  (`f()[0] = 1`) the write would land in a copy no one sees, so it stays a parse error
  (`writes_through_call()` in `nodes.gaz`); a field on what isn't an object is the runtime's
  error, as on a variable's path. Other expressions (`[$o][0].x = 1`) are still refused.
- `is_a($x, Kind)` tests the kind and its parents; `kind_of($x)` is the object's own kind
  (strict: anything else is an error), so `match (kind_of($n)) { NumAST => ... }` dispatches
  a pass written outside the node kinds. `fields($object)` is a map of the set fields, in
  print order, without `#`, so a pass can walk a tree without knowing its kinds.
- **`to_string()` is the one protocol method**, used by echo, `..`, interpolation and `join`,
  also inside lists and maps; it must return a string and take no arguments. Without one an
  object prints as `Account {#owner => "Werner", #balance => 75}`, and one already being printed
  as `Account {...}`. `json_encode` refuses kinds, objects and functions.

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

kind Token {
    const EOF = "EOF";
    const ENDS = [#EOF, Token::EOF .. "!"];
    fn is_eof($type) { return $type == #EOF; }
}
```

- **The value is a constant expression the parser works out**: literals, every operator, `?:`,
  lists and maps, other constants; no variables, calls or indexing. What may appear is checked on
  the source first (`check_constant_expression()`), then `fold()` in `parser.gaz` evaluates it
  with GazLang's own operators, so errors are the runtime's, located at the operator, and
  short-circuited sides aren't evaluated. Every constant is folded once the program is read, used
  or not; a cycle is `Constant A depends on itself: A uses B uses A`. Not any expression: one
  evaluated once at run time (`Point(0, 0)`) would bring initialisation order, mutation through a
  handle, and a slot and instruction in the VM.
- **A use is its value**: the parser stamps each use and the compiler pushes the value, so there
  is no constant in the tree below the parser, the bytecode or the VM.
- A top level constant is a bare name, sharing the namespace of functions and kinds, so a
  typo is a parse error. Constants are immutable because no write path can start at one; the
  one that could, `#NAME[0] = 1`, is refused (`Cannot change constant #NAME`).
- **Kind constants** are `#NAME` inside and `Kind::NAME` outside, inherited, and **can't be
  redeclared by a child**, since `#NAME` is resolved from the kind it is written in. That
  `::` resolves a name and `.` goes through a value is why `$kind.NAME` and `$object.NAME`
  can't be constants: it is the operator that says so, not a rule of its own. `Kind.NAME`
  says to write `Kind::NAME`, the way a miscapitalised keyword is told to be lowercase. Not
  redeclaring is the restrictive choice on purpose: loosening it later breaks nothing.
- `ConstTest::expressions()` requires a constant to give what a running program gives, for
  every operator and kind of value.

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
- `..=` on a plain variable appends in place (`concat_assign()` in `ops.c`), converting what is
  appended as `..` does, so building a string with it is linear rather than a copy per append.

## Errors and try/catch

```
kind NotFound extends Error {
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
- **`Error` is a builtin kind** written in GazLang (`BUILTIN_SOURCE` in `parser.gaz`, located
  as `<builtin>`): `#message`, `#file` (null for piped input), `#line`, `#trace`, `_($message)`,
  `to_string()`. Programs extend it; runtime errors and `error("text")` are caught as `Error`.
  It is compiled only into programs that use it.
- **`error($value)` throws any value**: a string becomes an `Error`'s message, anything else is
  caught as it is. An `Error` gets its location and trace where it is first thrown, so
  `error($e)` rethrows keeping them. Uncaught, gazlang prints `Error: ` and the value as echo
  would, with no location (runtime errors keep theirs), and the text is made only then, so
  throwing never runs `to_string()`.
- **`#trace`** lists the calls running when the error was raised, innermost first, each where it
  was running (`["inner at fib.gaz:3", "top level at fib.gaz:7"]`): a function by name, a method
  `Kind.name`, a constructor `Kind._`, a lambda `->`. Deep traces keep the innermost and
  outermost 10 around `... N more`. Uncaught, the trace is printed under the message unless it is
  a single call. A method run from inside an expression (`to_string()` by echo, `..` or a builtin)
  is called from where the expression is running, and its trace carries on through the calls
  outside it; once the program has ended (printing an uncaught error) it has none. The VM reads
  its frames when an error happens, which costs nothing until then. Its nested loops learn where
  they were called from through the instructions that can run program code (`PRINT`, `CONCAT`,
  `CONCAT_ASSIGN*`, `CALL_BUILTIN` and `CALL_VALUE` of a builtin), which say where they are;
  `trace_test.gaz` has a case for each, so one that forgets fails there.
- Catch clauses are tried in order; a typed one matches the kind or a child kind; an untyped one
  (`catch ($e)`) must be last. An unmatched error carries on unchanged.
- **`finally`** runs however the block is left: normally, when an error passes, and on
  `return`/`break`/`continue` (a return's value is worked out first). An error in it replaces
  what was in flight; `return`, `break` and `continue` can't leave it; `exit()` skips it. A
  `try` needs a catch or a finally.

## Comments and names

- `//` and `/* */`, skipped by the lexer. **Block comments nest** (as in Rust and Swift), so a
  region already holding a comment can be commented out; an unterminated one is an error at
  the line the outermost opened on. `editors/gaz/gaz.tmLanguage` nests them too
  (`EditorGrammarTest` fails when it misses a builtin or keyword).
- **Keywords are lowercase and exact**, so `kind If`, `fn Return()` and `kind Match` are
  ordinary names, which a self-hosted AST wants. PHP matches keywords *and* names
  case-insensitively; matching only keywords that way was its wart without its rule. A
  miscapitalised keyword gets a hint (`keywords are lowercase: write 'return', not 'Return'`) from
  `keyword_hint()` in `parser.gaz`, built only while an error is, as does a statement that starts
  with PHP's `elseif` (`write 'else if'`), unless a function of that name is declared. Sigils and
  member names have their own namespaces, so `$If` and `fn match()` were always fine.

# Implementation notes

## Errors and code generation

- **Errors**: every error a program can hit ends in its location (` at path/file.gaz:12`, or
  ` on line 12` for piped source). Tokens carry their line and the parser stamps `line` and
  `file` on every node (`at()` in `parser.gaz`); the VM locates a runtime error at the
  instruction that raised it (`locate()` in `vm.c`), whose location the compiler wrote from
  the innermost node that has one. `error()`'s messages are printed as they are, without a
  location, which `catch` still sees. Include paths show relative to the working directory,
  the main file as given.
- **Code generation**: calling convention is arguments pushed left to right then `CALL name
  argc`, the callee's frame holding them in slots 0..argc-1, `RET` pushing the result. Compound
  assignment (except `..=` on a plain variable), `++`/`--`, `foreach`, list patterns and `??=`
  are lowered to plain instructions with hidden variables (`$#update_*_n`, `$#foreach_*_n`,
  `$#destructure_n`, `$#match_n`, `$#finally_error_n`) that no program can name. Lists and maps
  made only of constants are built once and pushed as one value. `match` emits the tests first
  and the bodies after, so every arm leaves exactly one value and the stack depth agrees on
  every path. `try` emits `TRY`/`END_TRY` handlers, with `break`/`continue`/`return` leaving
  them itself (`leave_tries()`) and copying each `finally` after its own code. A postfix `++`
  used as a statement compiles as prefix. `docs/bytecode.md` has every instruction.

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
  also gives each frame's size, and carries the try handlers open, since `END_TRY` closing one
  that no `TRY` opened corrupts the handler stack. `HALT` belongs to the top level (the loader
  puts one there itself), and a file that can catch needs a kind `Error`, which is what a
  caught error is made as. What a value *is* stays the VM's to check
  when it runs: `CATCH_VALUE`, `CATCH_MATCH` and `RETHROW` ask whether the top is a raised
  error, `CALL_METHOD` whether it has a method entry over an object, and `ARRAY_PUSH`,
  `ARRAY_EXTEND` and `MAP_SET` whether they are building a list or a map, as `ADD` asks what
  it is adding, because a finally block stores the error in a local and loads it back. Peephole rewrites belong to loaders, not the format.
- **A stack machine**, not registers: the compiler is the part written in GazLang, a stack
  machine's is much simpler, and a loader can add superinstructions without touching the format.

## The self-hosted front end

- **Its shape, and why**: the lexer's scanner is an object, because `include` needs two lexers
  alive at once; its operators are one table matched longest first. The parser's eleven binary
  levels are one table and a loop (precedence climbing) rather than a method each, 28% faster.
  `lambda_heads` is one field, since nothing is read between marking a `(` and asking. A member
  use's record is found by an index the node holds, not a reference, so a kind's tree isn't a
  cycle for the collector. Trees are walked with an explicit stack, since a chain of 5000
  operators is 5000 deep. The lexer's operator table is matched longest first, which assumes every
  prefix of an operator is an operator too (except `..`'s; a lone `.` isn't one): a new operator
  that breaks that needs handling. The code generator dispatches with one `match
  (kind_of($node))`, since GazLang can't build a method name, and copies a rebuilt node's
  location by hand (easy to forget; the corpus checks it). The writer's paths stay textual, since
  a path written into bytecode needn't exist.
- **The tree dump** (`--ast`) prints each node's fields as `fields()` gives them, skipping those
  that are derived or the code generator's (the driver's `SKIPPED`). On an error there is no
  partial tree, since the whole-program checks write into nodes parsed long before; nothing
  catches a `ParseError` and carries on, so the parser restores no state.
- **Files with no top level code** (`lexer.gaz`, `parser.gaz`, `codegen.gaz`), since including a
  file runs it; the driver is separate.
- **The driver raises `LexError` and `ParseError` messages again from the top level**
  (`error($e.message)`), so `--tokens` and `--ast` print only the message; a bug in a port is a
  different error and still arrives with its trace. `LexError` carries `#reason` and
  `#source_line`, since `#line` is where in `lexer.gaz` it was raised. A `try` in the lexer holds
  only the `to_int()`/`to_float()` it is about, since `catch (Error)` also catches running out
  of call depth.
- **What it leans on instead of writing out**: `slice(to_string([$v]), 1, -1)` is a value as a
  literal (a string quoted, which is the inverse of reading one), `..` on a float formats it,
  `to_int()` in a `try` is the overflow check.
- **Paths** resolve with `cwd()`, `real_path()` and `file_exists()`; the parser harness also
  runs from other working directories and with an absolute include.

## The C VM

`bin/gazlang` runs 0.4 to 1.5 times the time of the same program written in PHP (`php
vm/bench.php`: CPU time, interleaved, best of several).

- **The CLI** parses options as PHP's `getopt` does, plus the check for unknown ones: options
  end at `--`, `-` or the first non-option; `-f` takes the next argument whatever it is.
  Bytecode is recognised by its first line or a `.gzb` name. `-c`, `-t` and `--ast` run the
  built-in front end in that mode; running source runs it in `code` mode first. With no file and
  a terminal on stdin it prints the help to stderr and exits 1: there is no REPL (running each
  line as its own program wouldn't be one).
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
- **PGO is opt-in** (`make pgo`): it makes every benchmark faster, by more than the layout
  noise and on both layouts, but needs `llvm-profdata` or gcc's profile support, which the
  bootstrap mustn't. It trains on the compiler and `examples/`, never `vm/bench`, so the
  benchmarks stay an honest test; `bench.php` times whichever build `bin/gazlang` is, so compare
  a change with both builds plain (or both PGO). `-O3` was a wash and `-flto` slower.
- **Why C**: over Rust, Zig and Go, since the heap (refcounts plus a cycle collector) is unsafe
  code in every one of them, Go has no refcounts for cheap copy-on-write, and Zig moves under a
  pinned toolchain; C bootstraps with nothing but a C compiler (`TLS=0`), and the differential harness
  under ASan and UBSan is the safety net C usually lacks. A separate program rather than PHP
  FFI, since converting values per call costs more than an instruction.
- `ponytail:` in the C: float printing tries up to 34 `printf`/`strtod` pairs per float.
