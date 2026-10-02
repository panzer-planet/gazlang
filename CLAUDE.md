# GazLang Development Guidelines

GazLang is self-hosting: the lexer, parser and code generator are written in GazLang
(`compiler/`), compiled to bytecode (`compiler/gazlang.gzb`, checked in) and built into a VM in C
(`vm/`); together they are `bin/gaz`. The tests are PHP (PHPUnit classes, run in parallel by
Pest), which runs `bin/gaz`.
`README.md` is the invitation, `docs/language.md` the language reference, `docs/internals.md`
the contributor guide, `docs/bytecode.md` the bytecode spec. This file holds the rules and the
reasons behind them; history is in git.

## Build & Test Commands
```bash
# Build gaz: the VM in C with the self-hosted compiler built in, as bin/gaz
# (not checked in; the tests build it themselves), with profile-guided optimisation where the
# C compiler can do it; PGO=0 builds plain -O2, a few seconds quicker while editing the C
make -C vm
make -C vm PGO=0

# Run a file, print its bytecode, run bytecode, or print its tokens or tree
bin/gaz tests/programs/functions.gaz
bin/gaz -c tests/programs/functions.gaz > /tmp/f.gzb && bin/gaz /tmp/f.gzb
bin/gaz --tokens tests/programs/functions.gaz
bin/gaz --ast tests/programs/functions.gaz

# After changing compiler/, rebuild the compiler gaz has built in, with gaz alone
# (a test fails until then; see "Changing the compiler")
make -C vm compiler

# The test dependencies, then all tests: a process per core (under 2 minutes), or one test at a
# time (about 4). Two suites, the language's (`core`) and the games' (`games`, under a minute),
# which a plain run does both of. --shard=N/M runs the Mth part, as each CI job does, split by
# the class timings in tests/.pest/shards.json; after adding a test class, refresh them with
# vendor/bin/pest --parallel --update-shards (a class it doesn't know still runs, in the last part)
composer install
vendor/bin/pest --parallel
vendor/bin/pest --parallel --shard=1/2
vendor/bin/phpunit
vendor/bin/phpunit --testsuite core
vendor/bin/phpunit --testsuite games

# Play the football manager (needs a terminal); a seed makes the same clubs again
bin/gaz games/football/main.gaz 1     # or: -- load [FILE]

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

# After a change to what the front end or the command line prints: record it, review the diff.
# Recording (this, vm/snippets.php, progress.php --update) runs one test at a time, never in parallel
GAZLANG_RECORD=1 vendor/bin/phpunit --filter 'SelfHosted|CliTest'

# The self-hosted front end from its source; without a file it reads piped source
bin/gaz compiler/gazlang.gaz code tests/programs/functions.gaz
bin/gaz compiler/gazlang.gaz ast < tests/programs/errors.gaz

# The C VM's coverage by the harness, its speed, and a build that collects cycles at every chance
# (over an hour: collecting is quadratic, and the entries that compile the compiler take longest)
php vm/coverage.php [file.c]
php vm/bench.php
make -C vm stress && GAZVM=vm/build/gazvm-stress php vm/progress.php

vendor/bin/phpstan analyse          # must be clean
vendor/bin/pint                     # formatting
composer ci                         # what CI runs, cold: phpstan with no result cache at 1G, pint --test, pest --parallel
```

## Code Style Guidelines
- **GazLang** (`compiler/`, `lib/`): functions, variables, fields and methods snake_case, kinds
  PascalCase, constants UPPERCASE. The lexer's and parser's methods are named after the grammar
  rule or step they read (`get_next_token()`, `function_call()`, `binary()`).
  A comment of more than one line is a `/* */` block (` * ` down the side), not stacked `//` lines;
  `//` is for one line, or a note after code.
  **Clean code over dense code**: a kind for each concept rather than a list read by position
  (`Task("Write the VM", 3, 10)`, not `[name, done, total]` and `$t[1]`), small methods named for
  what they do (`select_next()`, `advance()`), named constants for layout and other magic
  numbers, drawing and input handling apart from the state they show, and one statement or idea
  per line: no `if` body on its condition's line, no ternaries nested into arguments, no long
  chains packed onto one line. A longer file is the accepted price. `examples/dashboard.gaz` is
  the model. New code and rewrites follow it; existing code changes when it is worked on, not in
  a sweep.
- **C** (`vm/`): plain C11 plus POSIX (`-D_DEFAULT_SOURCE`, which glibc needs for
  `open_memstream` and `realpath`; `builtins.c` defines `_GNU_SOURCE` too, for `memmem` before glibc 2.38), libc, libm and pthreads, and OpenSSL in `net.c`
  only (on by default, `make TLS=0` without, `GAZ_TLS` saying which), libsqlite3 in `sqlite.c` and
  libpq in `pg.c` only (on when found, `make SQLITE=0`/`PG=0` without), built warning-free by
  clang and gcc, commented where the C isn't obvious (a
  flexible array member, a `goto` into shared code), for readers who know a little C.
- **PHP** (the tests and `vm/*.php`): 8.5 or later, PSR-4 under `GazLang\Tests`, methods
  camelCase, PHPDoc on classes and methods. The pipe operator (`$x |> trim(...)`) where a chain
  of single-argument calls reads better.
- `ponytail:` comments mark known ceilings, with what would lift them.

## Layout

- `compiler/`: `lexer.gaz`, `parser.gaz` and `nodes.gaz`, `template.gaz` (`.gazml` templates into
  GazLang), `codegen.gaz`, and `gazlang.gaz`, the
  driver: `gazlang.gaz -- code|tokens|ast [FILE]`, reading standard input without a FILE, a
  usage message and exit 2 otherwise. `gazlang.gzb` is its bytecode.
- `vm/`: the VM in C. `gazvm.h` says which file does what: `value.c` and `ops.c` are what values
  mean (operators, truthiness, printing, keys, indexing, write paths), `builtins.c` the builtins
  and their arities (`builtin_info[]`), `load.c` reading and checking bytecode, `vm.c` running it
  and the CLI, `gc.c` the cycle collector, `net.c` sockets and TLS, `db.c` with `sqlite.c` and `pg.c` databases, `term.c` raw mode and keys, `workers.c` `workers()`, `watch.c` `gaz --watch`, `crypto.c` random bytes, hashes and password hashes, `siphash.c` the hash behind every map.
- `lib/`: the standard library in GazLang. `examples/`: sample programs that nothing tests
  (see "Programs are tests or examples"). `tests/programs/`: programs the tests do run.
  `games/`: programs built on the language, each with tests of its own (see "A game is neither").
  `apps/`: web apps built to find what hurts, in the repository for the same reason as the games
  (`apps/todo`: registration, login and todos on PostgreSQL, with `FRICTION.md`, the evidence for
  what the library and language lack, in the order a stranger would meet it). Its tests need a
  PostgreSQL database and run from the app's directory, so CI doesn't: `cd apps/todo &&
  ../../bin/gaz test tests`.
- `editors/`: TextMate grammars, `gaz/gaz.tmLanguage` for source, `gzb/gzb.tmLanguage` for
  bytecode and `gazml/gazml.tmLanguage` for templates (HTML with GazLang embedded: `{{ }}`,
  `{!! !!}` and the directive lines, also inside tags and attribute values; VS Code, Sublime and
  most editors read them). `EditorGrammarTest` fails when the
  first misses a builtin or keyword, the template grammar's directives differ from `directive()`'s in
  `compiler/template.gaz`, or the second doesn't name exactly `INFO`'s instructions in
  `vm/load.c`, so a new instruction needs a word in the grammar; the bytecode grammar marks what
  doesn't fit a line's shape as invalid, and accepts all the loader reads (comments,
  single-quoted strings and hex in a `PUSH`), not only what the compiler writes.
- `site/`: the website, a GazLang program (see "The website"): `build.gaz` the driver,
  `pages.gaz` and `templates/*.gazml` the pages, `markdown.gaz`, `highlight.gaz` (on the
  compiler's lexer), `library.gaz` (the library's pages from its source), `documents.gaz` (links
  and the repository), `verify.gaz` (the checks every page passes), `style.css`. Its tests are
  `tests/gaz/site/` and `SiteTest`.
- `tests/`: PHPUnit, `tests/gaz/` (GazLang programs, the standard library's in `tests/gaz/lib/`),
  `tests/expected/` (what every program prints), and `tests/corpora/`, the corpora: `lexer/`,
  `parser/`, `codegen/`, `vm/`, `bytecode/`, `cli/`, `json/`, `csv/`.

## How it is held together

Everything is checked against recorded output, byte for byte, and the recordings are the spec:
a change to what something prints is recorded (`progress.php --update`, `GAZLANG_RECORD=1`) and
its diff reviewed like code. **Break a checker on purpose before believing a run that finds
nothing**: several first versions of a harness or corpus passed everything and caught nothing.

- **The tests run `bin/gaz`**: every `GazLangTestCase` helper (`executeCode()`, `parse()`,
  `lex()`, `generateCode()`, `runProgram()`, `cli()`) runs the optimised build as a process from
  the project root, a snippet piped in, and a failure is a `ProgramError` holding what it printed
  after `Error: `. `vm/snippets.php` collects the snippets, as JSON, for `CVMTest`. Order matters
  as much as results: `KEY_CHECK` exists so a bad key fails before later keys and the value run.
- **The suite runs in parallel**, a whole class to a process (`pest --parallel`), so a class's
  static caches are computed once, and nothing may assume another class isn't running:
  `CVM::build()` runs make under a lock (`CVM::exclusively()`), so a clean tree builds once; a
  file one class writes and another runs is renamed into place (`vm/build/driver.gzb`); and the
  `tests/.tmp` paths that recorded snippets name are used only holding `CVM::lock('scratch')`,
  by `CVMTest` running those snippets and by the `StdlibTest` tests they came from. Anything
  else a test writes is its own class's, or named by its process. `CVM::$jobs` stays 24 under
  `--parallel`: fewer measured the same.
- **Programs print what `tests/expected/` records** (`CVMTest`, `tests/CVM.php`): each entry of
  `vm/passing.txt` (every program and corpus file, the snippets in `tests/vm_snippets.txt`, and
  the hand-written broken `.gzb` files) runs on the C VM built with ASan and UBSan and must give
  the recorded stdout, stderr and exit code (`.stdout` always, `.stderr` and `.exit` when there
  is one; the checkout's path as `<root>`). A source entry runs from source, so the built-in
  compiler compiles each one under the sanitizers; a snippet is piped in from the project root,
  as it has no file. `progress.php` adds a candidate the compiler accepts and that doesn't leak;
  the list only grows, except when a snippet or fixture itself goes, which `--update` drops
  (`CVM::gone()`; `CVMTest` fails naming them until then).
- **The front end prints what its corpora record**: each `X.gaz` in `tests/corpora/lexer`,
  `tests/corpora/parser` and `tests/corpora/codegen` has what `--tokens`, `--ast` or `-c` must
  print next to it (`X.tokens`, `X.ast` and `X.piped.ast`, `X.code` and `X.piped.code`; the
  runs from other working directories in `tests/corpora/parser/places/`), exit code 1 when a line
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
  also runs in the sanitized builds and catches plain allocations the count can't see (a `main()`
  that returns early without freeing what an option collected: on a Mac,
  `leaks --atExit -- bin/gaz ARGS` finds the same);
  `__lsan_default_suppressions()` in `vm.c` exempts only the loader, which gives up on a broken
  file without freeing what it built.
- **The command line prints what `tests/corpora/cli/expected/` records** (`CliTest`): a table of
  invocations of `tests/corpora/cli/` programs (arguments, what is piped in, working directory). A change
  to its options or how it reads input needs a row.
- **The compiler compiles itself to itself**: `compiler/gazlang.gzb`, run under the sanitizers,
  compiles `compiler/gazlang.gaz` to exactly `gazlang.gzb`
  (`test_the_self_hosted_compiler_compiles_itself_to_itself`). This checks the front end on the
  largest program there is.
- **GazLang code is tested with GazLang programs**: every `tests/gaz/**/*_test.gaz` must print
  exactly its `*_test.expected` (`GazProgramTest`) and pass under `bin/gaz test tests/gaz games`
  (CI runs it). They include `std/test.gaz` `use expect, throws`: `expect($label, $actual,
  $expected)` prints `ok <label>` or a FAIL line, and `throws($label, $thunk, [$kind,] $message)`
  checks an error rather than a `try` block written out, except where the test is of `try` and
  `catch` themselves, or of more than the message (`#line`, `#trace`, state after the error).
  `lib/json.gaz` is checked against PHP's
  `json_decode` on `tests/corpora/json/y_*`/`n_*` (the prefix says whether it must parse), `lib/csv.gaz`
  against `fgetcsv` on `tests/corpora/csv/`, `lib/chars.gaz` against the lexer's classes for all 256
  bytes.
- **Programs are tests or examples, not both**: a program in `tests/programs/` is checked: run
  under the sanitizers and leak check with its output recorded (`CVMTest`), given arguments and
  input by its own test (`FootballTest`, `CsvTest`, `HttpTest`), compiled by `BytecodeTest` and
  mutated by the fuzzer. A program in `examples/` is none of these, so it can be written for a
  reader (it may need a terminal, the network or a long run) without the cost of a test, and it may
  stop working without anything saying so. Only the PGO training runs it, and that ignores
  failures. When an example needs to keep working, or is worth running under the sanitizers as a
  big program, move it to `tests/programs/` and give it a test; `functions.gaz` and `errors.gaz`
  are there because CI and these docs use them as inputs.
- **A game is neither a test subject nor an example**: `games/NAME/` is a program being built on
  the language. It is in this repository so that a change to `lib/` or the VM goes in the same
  commit as the game code that needed it: there is no module search path (an `include` is relative
  to the file), so a game in a repository of its own would need a checkout of gazlang at a known
  path, and every language change would be two commits. Its tests are GazLang programs in
  `games/NAME/tests/`, run by their own PHPUnit suite (`--testsuite games`; `--testsuite core` for
  the language alone; a plain run does both), and they assert what stays true however the game is
  tuned (a season plays every match, a table adds up), not what a seeded run prints: the odds are
  meant to change, and recordings would be re-recorded with every tweak. What stays byte-exact is
  `tests/programs/football.gaz`, the frozen simulator the game started from, the VM's biggest test
  program and the benchmark workload; it is not tuned for the game. The duplication will drift, on purpose. A game reaches the standard library by `include "std/..."`,
  which the VM carries, so its only outward dependency is a `gaz` binary and moving it to a
  repository of its own later is cheap.
- **The README's examples are tests**: `ReadmeTest` runs every ```` ```gaz ```` block followed
  by an output block and requires exactly that output.

**Difficulty is never a reason to refuse a feature.** The pursuit of excellence needs no
justification: if a feature is right for the language, build it properly, however much work it
is. Reject one because it is wrong (it breaks a rule of the language, duplicates something, or
makes the language worse), never because it is hard. "Not worth the cost" is not an argument
unless the cost is a defect the feature would bring, not the effort of building it.

Judge new features by how they fit **in C**: value semantics suit reference counting, and
anything that leans on a platform's behaviour (hashing, string conversion, float formatting,
rounding) must be a rule GazLang defines and writes out step by step. Grow the language by
writing real GazLang and fixing what hurts, and when a workaround in the repository's GazLang is
the evidence for a gap, check with `git log` when it was written: code older than a feature
can't have used it. Re-measure before trusting a recorded number.

## Changing the compiler

`make -C vm compiler` rebuilds `compiler/gazlang.gzb` with `bin/gaz` alone, in three stages:
the current compiler compiles the new source (stage 1), which compiles itself (stage 2), which
compiles itself again (stage 3). **Stage 2 must equal stage 3**: stage 1 was written by the old
code generator, so it differs by design when code generation changes, but a compiler whose
output depends on how it was itself compiled has a bug. Only then is stage 2 copied over
`gazlang.gzb` and `bin/gaz` rebuilt, so a broken edit fails at stage 1 or 2 and leaves a
binary that can compile its fix. Nothing changed means nothing rebuilt.
`test_make_compiler_rebuilds_the_compiler_without_php` runs it on a copy, including an edit that
changes the code generated (the only way to tell stage 1 checked in from stage 2) and a second run
that must find nothing to do.

- **`compiler/gazlang.gzb` is checked in because it is the seed**: a fresh clone has nothing else that can
  compile `compiler/`, and the bootstrap should need only a C compiler. It is marked generated in
  `.gitattributes` (no line diff, and no text merge): on a conflict take either side and run
  `make -C vm compiler`, which rebuilds it from the sources; the self-compile test fails while it is stale.
- **An explicit target, not a dependency**: plain `make` always builds from the checked-in
  bytecode, since a fresh clone's timestamps are arbitrary and a dependency would have make
  regenerate the compiler with a binary that needs it to be built.
- **A language feature lands in two steps**: the compiler's own source can't use a feature
  until a compiler that understands it has been built. Add it to `compiler/`, run
  `make compiler`, and only then use it in `compiler/`. A new instruction goes into the C VM
  before any bytecode using it runs.
- The bytecode is built in with `od` into `vm/build/compiler.c`, and the standard library the same
  way into `vm/build/std.c` (see "Builtins and the standard library"): numbers only, so nothing to
  escape, and no trigraphs, which `-std=c11` turns on and the `??=` in it would be.

## Status and what is next

- **The goal**: GazLang is the PHP Werner always wanted, for **web servers and CLI tools**, and
  the project succeeds when **one person besides him chooses to use it**. So work is ranked by
  what makes real web apps and CLI tools pleasant, then by what one stranger needs to find it,
  install it, get a first program working and trust it; not by what would win many users
  (Windows, a registry and a playground wait for someone to ask).
- **The roadmap**, in build order (optional types, `gaz --watch`, the pipe, cryptography, cookies
  and signed sessions, `gaz test`, the list helpers in `lib/lists.gaz` and tagged literals, with
  `db::sql"..."` and `web::html"..."` on them, are done and described below):
  1. **Rest patterns** in destructuring, `[$first, ...$rest] = $list`, when JSON handling asks.
  2. **HTTP keep-alive** in `http::serve` is done (see "Serving HTTP"); server-side TLS and HTTP/2,
     which it was the prerequisite for, remain open.
  3. **Files for command line tools**: `file_open` is read only, and there is no append, rename, copy,
     modification time or size, `mkdir -p`, or glob; `trim` takes no character set. Found by comparing
     with PHP's file functions, not yet by a program. A builtin takes its name from every program, so
     decide the set before adding any (a mode on `file_open`, `file_write`, `rename_file`,
     `file_info`).
  4. **Dates from a clock**: `date.gaz` counts days and `time()` gives seconds, but nothing formats
     a moment, parses ISO 8601 or knows a time zone (`apps/todo` shows timestamps as text). The rules
     are GazLang's, written out as `round()` is, never the platform's.
  5. **Regex shorthands and groups in a replacement**: `\d`, `\w`, `\s` and `$1` in `regex::replace`,
     the two things every reader expects first. Still a Thompson NFA, so no backreferences or
     lookaround.
  6. **A cause on `Error`**, so code that catches a database error and throws its own keeps the
     original (`#cause`, printed under the trace).
  7. **Interfaces**: `interface` and `implements` (reserved now), a parse-time check that a kind
     has every method an interface names, with matching arities and types as an override's, and
     `is_a($x, Shape)` true for an implementer. See "Decided, not built"; `final` follows with it
     or after it.
  8. **Enums**: a closed set of named values for a status or a kind of token, in place of string
     constants that nothing checks. Not designed: whether a case is a value or an object, whether
     it can carry data or methods, how `match` and `json::encode` see one, and what `type_of` says.
     Typed fields and parameters (`Status $status`) should be the point, so a misspelt case is an
     error when the program is read.
  - **On demand**: dumping the raw bytes of a request that got a 500, to replay it (the small
    version of record and replay); `parallel($thunks, $max)` over forked processes, giving plain
    data only, its child raising `vm_process` as a worker does so the handles it inherited are
    refused and abandoned (see "Serving HTTP"); `std/money`, amounts as integer cents; shape
    patterns in `match`, only with a syntax that can't be read as today's `==` arms (a map there
    already means "equals this map"); `db::join($fragments, $separator)`, not designed yet, for a
    bulk insert of many rows as one statement; lazy iteration (a
    generator or an iterator protocol, so a large file or a result set needn't be a list first);
    `multipart/form-data` for uploads (`http::form()` refuses anything but urlencoded); a child
    process with pipes (`run()` waits for the end and returns everything); Unicode case mapping and
    slicing by characters (`upper`/`lower` are ASCII, `len` counts bytes).
  - **Not building**: taint mode (a mark on strings leaks, since one-byte strings are shared and
    `url_decode()` rebuilds text with `chr()`; Ruby removed taint as useless; tagged literals
    prevent the bug instead), contracts (types and a guard line cover them),
    `sh"..."` (`run()` already takes an argv list, which is safe), native decimals and full record
    and replay (for now).
- **`gaz test [path...] [--update] [-v]`**, so a user of gaz doesn't need PHP to test gaz code: it
  finds every `*_test.gaz` file under each path (the current directory by default), recursively,
  and runs each in its own `gaz` process, reinvoked with `program_path()`. `std/test.gaz` gives
  `test::expect($label, $actual, $expected)` (`ok <label>`, or a FAIL line naming both values and,
  for two lists or maps, the first index or key where they differ), `test::throws($label, $thunk,
  [$kind,] $message)`, `test::snapshot($label, $actual)` (compared against a file recorded next to
  the calling test, `--update` (re)writing it instead) and `test::done()` (exit 1 if a check
  failed). Every check is one line, `ok ` or `FAIL `, which is what the runner counts, so a value
  on a FAIL line is cut past 200 bytes and its newlines escaped. The runner prints each file's
  FAIL lines, its stderr and `path: N checks, M failed`; `-v` prints everything. A file fails if a
  check failed, it exits non-zero, or it checked nothing (a test that tests nothing passes by
  mistake); `gaz test` exits 1 if any file did.
  - **`throws` matches a kind exactly** (`kind_of($e) == $kind`), not by `is_a`: under `is_a`,
    naming `Error` would accept every error, so a test couldn't say that a specific kind was not
    what came. A runtime error and `throw "text"` are `Error`; a thrown value that isn't an
    `Error` is compared with the expected one as `expect` compares.
  - **`done()`'s count is a static field** (`Tally::failures` in the namespace, so no global a
    test could collide with), raised by every failing check.
  - **A subprocess per file, not `include`-by-computed-path**: `include` only ever takes a
    string literal, resolved at parse time, on purpose, so a runner has no dynamic way to splice
    a discovered path into one program. Running each as `gaz <file> <file> [--update]` needs no
    such thing and gives free isolation, one file's crash or infinite loop can't corrupt
    another's run, at the cost of a process start per file.
  - **`program_path()`** gives `argv[0]` exactly as `gaz` was invoked (a bare name found on
    `PATH`, a relative path, or an absolute one; not resolved to a canonical path, which nothing
    yet needs). `test::main()` passes it to `run()` as the reinvoked program, so `gaz test` works
    the same way whether it was started as `gaz`, `./bin/gaz` or an absolute path, without
    needing `gaz` on `PATH` — the concrete problem a self-reinvoking program has; a
    `/proc/self/exe`/`_NSGetExecutablePath`-based canonical path is a different, bigger feature
    nobody has asked for yet.
  - **`gaz test` is a bareword subcommand**, dispatched in `vm.c`'s `main()` before any of the
    usual `-`-prefixed option parsing, since `test` names a shape (`cargo test`, `go test`), not a
    flag; a file literally named `test` needs `-f test` to run instead, an accepted, negligible
    edge case. It runs a small fixed bootstrap program (`include "std/test.gaz"; test::main();`)
    with everything after `test` as that program's own `args()`.
  - **A test file learns its own path as its first argument**, not through a builtin: `args()`
    is only what follows the file on the command line, and `program_path()` names the
    interpreter, not the script it is running, so neither gives a test file its own path. The
    runner passes the file twice, once to say what to run and again as `args()[0]`, which
    `test::snapshot()` reads to find where "next to it" is.
  - Fixtures are `tests/fixtures/gaz_test` (`TestCommandTest`), which all pass since `gaz test
    tests` runs over them too; the failing files are written into a temporary copy.
    `tests/gaz/lib/test_test.gaz` checks what passes directly and what fails by running
    `tests/fixtures/test_library/failures.gaz` (and `gaz -e` snippets) with `run()`, comparing their
    lines and exit status, so it prints no FAIL line of its own.
- **A language server** (`lsp/server.gaz`, `bin/gaz lsp/server.gaz`), so an editor gets errors and
  eventually more without a stranger installing anything but gaz. Diagnostics
  (`textDocument/didOpen`/`didChange` reparses the whole document, full sync, and
  `publishDiagnostics` the first syntax error), hover (a builtin's arity from `builtins()`, or a
  declared function's parameters found by scanning the document's own text for `fn name(...)`),
  go-to-definition (the same textual search, followed across the document's own `include`
  chain — resolved the way the real compiler resolves them, from the document's own directory,
  and only into files that exist on disk, so a `std/` include isn't chased — cycles ended by a
  set of real paths already visited on that branch), and completion (every keyword worth
  completing: `Lexer::KEYWORDS` in its order, skipping the ones the parser only refuses,
  `Parser::RESERVED`, so a new keyword is offered by itself, which `LspTest` checks against
  the lexer's table; every builtin with its arity; every function the
  document can reach by name, itself and what it includes, each once even if declared reachably
  more than once; no filtering by what is typed, which editors do themselves) are done; textual,
  not from the parsed tree, since the tree doesn't exist while the document has an unrelated
  syntax error, which is the common case mid-edit. Nothing else is planned yet; add what a real
  session of using it shows is missing. It is `namespace gazlang`, not its own, reusing the
  compiler's own `Lexer` and `Parser` as a test of the internals does (see "Namespaces" and
  `tests/LspTest.php`), rather than making them `pub` for one caller. Framing a message needs an
  exact byte count (`Content-Length`), which needed a builtin of its own: `read_stdin_bytes($n)`,
  since `read_stdin()` reads to the end and blocks a server that stays open between messages. A
  message shaped other than a handler expects is an error response, or a dropped notification,
  never the end of the process: one bad message shouldn't cost the whole session.
- **Releases**: `VERSION` holds the version, which the Makefile compiles in and
  `gaz --version` prints. Pushing a tag `vX.Y.Z` matching it runs `.github/workflows/release.yml`,
  which builds and tries gaz on Linux (the latest Ubuntu, x86_64) and macOS (Apple silicon and
  Intel), and publishes the three `.tar.gz` and their `SHA256SUMS` as a GitHub release
  with notes from the commits; `gh workflow run release.yml` is a dry run that publishes nothing.
  To release: change `VERSION`, re-record the CLI's `version` row (`GAZLANG_RECORD=1 vendor/bin/phpunit
  --filter CliTest`), commit, then tag and push the tag. The binaries have TLS and SQLite but
  not PostgreSQL, since libpq is rarely installed and Homebrew's can't travel. On macOS OpenSSL
  is linked in, so they need nothing but the system, and `net.c` trusts the system's
  `/etc/ssl/cert.pem` too (unless `SSL_CERT_FILE` says otherwise), since a linked-in OpenSSL looks
  for certificates where Homebrew keeps them; without it every https request failed on a Mac
  without Homebrew. No promise about what changes between releases yet.
- **CI** (`.github/workflows/ci.yml`) runs on Ubuntu (the latest) and on macOS, Apple silicon and
  Intel, for every push: it builds gaz without TLS or databases (the bootstrap needs only a C
  compiler), then with them, and rebuilds its compiler before PHP is even installed, then the
  suite, in two jobs per platform (`pest --parallel --shard=N/2`, each building gaz for itself),
  with `gaz test` and the minute of fuzzing on the second Linux one only. phpstan and
  pint are a job of their own on Ubuntu, which needs no build and so reports first; phpstan runs
  cold there (no result cache) at a 1G limit, as `composer ci` does locally, since a warm local
  cache once hid a table that needed a gigabyte. LeakSanitizer runs in the sanitized build on
  Linux only: Apple's clang has none, and Homebrew's LLVM gave only system-library noise.
  Development is on an Intel Mac.
- **The website** is built by gaz: `bin/gaz site/build.gaz` (from the root; into `site/dist`,
  gitignored; open `site/dist/index.html`, or `php -S localhost:8000 -t site/dist`) makes the home
  page from the README, a reference page per `##` section of `docs/language.md`, a page per
  `lib/*.gaz` from its `pub` names and the comments above them (read from the source, so it can't
  drift and a new file appears by itself), and `docs/bytecode.md` and `docs/internals.md`. Pages
  are `.gazml` templates (`site/templates/`), so escaping is the templates' and not remembered
  at each concatenation; a link's scheme must be http, https or mailto, anything else leaves its
  text. **The library's comments are Markdown as the docs are**, through the same converter and
  links, except that a backslash is always itself (`"\"` in a regex comment means a backslash),
  and an example indented under a blank line is GazLang code. `.github/workflows/pages.yml`
  publishes it.
  **GazLang is highlighted by the compiler's own lexer** (`Lexer.span()` says where each token
  lies), so a colour can't disagree with the language, and every piece is cut from the source
  rather than printed from a token, so whitespace, comments and escapes come out as written:
  `round_trip_test.gaz` requires the pieces, and the HTML read back, to give every docs block and
  every file of `lib/` and `examples/` byte for byte. Source the lexer refuses is shown plain, not
  fatal. A fence names its language (`gaz`, `gzb`, `gazml`, `bash`); a plain fence right after
  code is shown as its output. No JavaScript, fonts or anything fetched from elsewhere.
  **Every page is checked before anything is written** (`site/verify.gaz`): internal links and
  anchors, ids given once, tags closed in order, no block inside a `<p>`, and no Markdown the
  converter failed to read: each block's text outside code is read again, and if that finds a
  code span or emphasis, the converter got it wrong (a lone `` ` `` or `**` it left as text finds
  nothing, as on GitHub). A failure leaves the last good site alone, writes the pages to
  `DIRECTORY.failed/` and names the page and line there.
  **Where the repository is** comes from `--repository owner/name`, else git's remote origin
  (any GitHub URL shape), else `$GITHUB_REPOSITORY`; without one the build still succeeds, with a
  notice, and leaves out the links to the repository's files and releases, since a local clone
  or a mirror has no GitHub origin. **Download links name `--release TAG`**, which the workflow
  sets to the latest published release, never `VERSION`: `VERSION` is committed before its tag is
  pushed and the release published after its builds, so the workflow builds again when the
  Release workflow finishes (`workflow_run`, since a release published with the workflow's own
  token starts no `release` workflow). **Publishing is release-only**: `pages.yml` has no `push`
  trigger, so a docs or library edit on `master` waits for the next release rather than showing on
  the live site ahead of what a stranger can install; `workflow_dispatch` still runs it by hand.
  The build is a tool, not a test program: what it writes
  changes with every docs edit, so `SiteTest` checks it succeeds, has every page, fails on a link
  planted in a copy of the docs (`--root`), and builds without a repository; `tests/gaz/site/`
  tests its parts.
- **Speed** is measured by `php vm/bench.php` (CPU time, interleaved, best of several; the README's
  table is its output on the default PGO build, so a `PGO=0` build runs a little slower). gaz is
  around PHP's speed and faster than Python. The arithmetic loop is still about nine dispatches an
  iteration after fusion (a register form would need about six), which only a register bytecode
  or a JIT would close; lists, maps, strings and objects spend theirs in malloc/free (about 17% of
  a football run) and the collector. A list keeps its first four items in its own header (see
  "Values"), which took 28% of the allocation calls out of a football run and 18% out of compiling
  it; what is left is mostly lists past four items, map headers and index arrays, closures'
  captured arrays and strings, so small maps come before a pool; measure with a sampling profile
  first.
- **Bytecode has no compatibility promise yet**: stable so far, but free to change; a change
  old files can't load under bumps the version.
- **The fuzzer** (`php vm/fuzz.php`, a minute; CI runs one on each push, seeded by the run's
  id) needs no oracle: generated programs, mutated corpus programs and mutated bytecode
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
    instruction is read, which the corpus already covers. One try in five still lands anywhere.
  - **Everything follows from the seed**, so `--seed N --runs M` replays a run. A failure is
    saved in `vm/build/fuzz/` and shrunk, a minute in a run and to the end with
    `--shrink FILE`. A try costs about 0.1s of the sanitized build's start-up, whatever the
    program, which is why shrinking takes the time, not the run.
  - **Nothing opens a socket, starts a program, exits, waits or writes a file**: a program naming
    `run`, `exit`, `workers`, `worker_recycle`, `write_file`, `read_stdin` (or `read_stdin_bytes`),
    `read_line`, `sleep`, `getenv`, a directory builtin, a `term_` builtin, a `file_` builtin
    (`/dev/stdin` waits and `/dev/zero` never ends) or a `socket_` builtin is skipped (`getenv`
    since what it gives isn't the seed's; `worker_recycle` since it ends the process by an unhandled
    signal, which prints no `GAZVM_STATS` line and would fail the harness for a reason that isn't a
    bug — found the hard way, by CI actually failing on it, the day it was added), an included
    file's text included, which is sound because a builtin is reached only by its name.
- **Known limits**:
  - The self-hosted parser runs out of call depth on source nested past about 9000 levels
    (recursive descent is about eleven calls a level), as an internal error. Its tree walks use
    an explicit stack for that reason.
  - A `make compiler` stage's own runtime errors name `vm/build/bootstrap/` as the source
    directory, since bytecode paths resolve against the bytecode file; the lines are right.
  - A main file given by an absolute path through a symlinked directory (macOS's `/var`) gives
    include locations that climb to the root and back through the real path.
  - The keyword hint misses `IF (1) { }`, where the error lands at the `{`, past the name.
  - Naming a private INSTANCE method through a kind's name (`Tally::m()`, where `m` is a method
    of `Counter` and not a static one) says `Counter::m is not pub`, when the real mistake is that
    `m` is not a static member.
- **Language gaps**, closed in the order real code shows what shape each needs:
  - Appending to a list parameter changes only the call's copy. **A parameter whose every use
    in the body is a write through an index is a parse error** (`fn add_to($l) { $l[] = 1; }`,
    `check_lost_writes()` in `parser.gaz`, at the first such write), in functions, methods and
    lambdas. Conservative on purpose: any other use (`return $l`, a read, a reassignment) lets it
    pass, and a path with a field in it (`$bag.items[] = 1`, `$rows[0].n = 1`) is never noted,
    since it could reach an object. `ponytail:` `$l[] = len($l);` with no other use still passes.
    Mutable state belongs in an object, or, for closures, in a `shared` variable. Explicit
    by-reference parameters stay refused ("values, not references": a parameter would alias the
    caller's variable).
  - A private field can't be set from outside its kind, so restoring saved state (a played match's
    score, a league's fixtures) takes a static factory written in the kind (`Fixture::played()`,
    `League::from_json()`); there is no way to construct an object with some fields already set.
  - `$obj.$name` (dynamic member access; `lib/sorting.gaz` can sort maps but not objects).
  - `kind_of` is strict, so a pass over a tree with absent children needs a `type_of` check
    first; if that recurs, make it lenient.
  - Scanning bytes: `$s[$i]` makes a one-byte string (shared in C) and there is no `byte_at` or
    "index of the first byte in this set". `chars::span($s, $i, $predicate)` is "consume while
    this holds" (the length of the run at `$i`, a GazLang loop calling the predicate) and
    `starts_with($s, $prefix, $offset)` asks what is at a position without a slice. The
    self-hosted lexer still spells out comparisons and walks local indexes; measure on the C VM
    whether that still pays.
  - Including a file also runs its top level code. `Error`'s members are reserved across its
    children, so a domain error can't declare its own `#line` or `#message`.
  - No copy-with-change for objects, no `catch (A | B $e)`.
  - `match ($x)` is a linear chain of `EQUALS`; no jump table.
  - No enum (roadmap item 8); the lexer's token types stay strings on purpose, being the
    `--tokens` format.
- **HTTP is HTTP/1.1 in GazLang (`lib/http.gaz`) on socket builtins, TLS through OpenSSL**,
  linked by default and optional (`make TLS=0`), so the bootstrap still needs only a C compiler.
  Not curl through `run()`: a process per request, the headers visible in `ps`, and curl as a
  runtime dependency; not TLS of our own, which would be thousands of lines of crypto whose bugs
  no output shows. The client makes one connection per request; keep-alive, proxies, compression
  and HTTP/2 wait for a program that needs them.
- **Serving HTTP is `http::serve()` in the same file, in `workers()` processes**, the PHP-FPM
  model rather than Node's: a server is `socket_listen()`, `workers($n)`, then a loop of
  `socket_accept()` and the requests on each connection. **Prefork, not an event loop**: share-nothing
  processes suit value semantics and refcounting, need no locks, and a crash takes one request, where
  an event loop needs non-blocking sockets and callbacks or coroutines the language doesn't have.
  - `workers($n)` is one builtin, not `fork()`/`wait()`/`kill()`, so no program can leave zombies or
    orphans: it returns 1 to n in each worker, and the master stays in C (`workers.c`) for good,
    starting a worker again when one dies of an error or a signal, stopping all of them on
    SIGINT/SIGTERM/SIGHUP and then dying of that signal, unless the program started with it ignored
    (`nohup`), which stays so. A worker that dies within a second of starting *without having
    accepted a connection* stops the lot with its code, as a startup bug would otherwise respawn for
    ever; one that accepted first died of a request and is started again. Workers say so in a page
    of shared memory (`mmap`, a byte each), not a pipe, since the master only reads it when one dies.
  - **A handle made before `workers()` is the process's that made it**: a fork copies a `db`,
    `socket` or `file` along with the connection or file offset under it, shared with every other
    process. Two workers on one PostgreSQL connection garble each other's replies and hang, and
    SQLite forbids a connection crossing a fork. So every `Db`, `Socket` and `File` records
    `vm_process` (`workers.c`: 0 at start, raised in each worker's child by `fork_worker()`; a
    counter, since `getpid()` would be a system call on every `file_read_line()`) as its owner, and
    using another process's is a catchable error (`db_run(): this db was opened before workers(), and
    workers can't share one: open one after workers()`). **Listeners are exempt**, sharing one being
    the point of prefork. **Releasing one is an abandon, not a close**, in the paths every release
    takes (`db_close()`, `net_close()`, `file_close()`: decref, the collector, the end of the
    program and an explicit close, which is silent): a close says goodbye on the shared connection
    and ends it for its owner too, which even a worker that never touched it did, just by ending.
    PostgreSQL's socket is pointed at `/dev/null` (`abandon_fd()`) before `PQfinish()`, so its
    Terminate goes nowhere; TLS skips `SSL_shutdown()`'s close_notify; a file's descriptor is pointed
    at `/dev/null` before `fclose()`, whose seek back to its own position would move the shared
    offset (nothing can read it after the fork today, but a `parallel()` parent would); SQLite's is
    never closed in a worker (a close can roll back the owner's journal), only kept reachable, a
    `ponytail:` in `sqlite.c`. **On macOS `open_sqlite()` sets `OS_ACTIVITY_MODE=disable`**
    first: Apple's libsqlite3 makes an `os_signpost` on every open, and libtrace's state doesn't
    survive a fork, so a worker opening a database after a master that had opened one (migrations
    at start-up) crashed in `os_signpost_enabled` about one run in six on Apple silicon (found by
    CI; 0 of 80 with it, 7 of 40 without); a worker with no earlier open in the master never did. The per-driver part is `DbDriver.abandon`. The master never returns
    from `workers()` and ends with `exit()`, so its copy of a PostgreSQL connection stays open and
    idle until the server stops. Tested by `tests/gaz/workers/inherited_test.gaz` (under the
    sanitizers, each worker's `GAZVM_STATS` line checked by `CVM::leaks()`) and `DbPgTest`.
  - **`worker_recycle()` is a third case**, neither the graceful "clean exit(0), shrink the pool" nor
    the generic "died, log a failure, restart": a worker retiring itself on purpose (PHP-FPM's
    `pm.max_requests`, see `http::serve`'s `"max_requests"` below), which must be replaced like a
    crash (the pool stays `$n` wide, and whatever state the worker built up over its life goes with
    it) but treated as neither a crash nor a graceful stop. It flushes output, then raises SIGUSR2 on
    itself with no handler installed, so the default disposition (terminate) applies; a signal, not a
    reserved exit code, because `exit($code)` already lets a program choose any code 0 to 255 freely,
    and a reserved one could collide with an unrelated `exit()` somewhere and be misread as a happy
    recycle, where a signal-terminated exit can't collide with anything `exit()` produces. The master
    tells `WIFSIGNALED(status) && WTERMSIG(status) == SIGUSR2` apart before the generic failure path:
    it restarts the worker at once, skipping the "died within a second of starting" check (a low
    `max_requests` recycling fast on purpose is not a startup failure) and logging a plainly different
    line (`worker N recycled; starting another`) rather than the failure phrasing.
  - **Stopping is graceful**: the master sends SIGTERM, which a worker catches (not `SA_RESTART`,
    so a waiting `accept()` wakes) and turns into `null` from its next `socket_accept()`, so
    `http::serve()` returns after the request in hand; the master kills what is left after 10
    seconds (`STOP_GRACE`). `socket_accept()` waits in `poll()` a second at a time, so a stop that
    lands between its check and the wait is still seen. Ctrl-C reaches the workers directly and
    ends them at once, which is what it is for at a terminal.
  - **The program runs on its own thread** (see "The C VM"), so a fork is that thread alone, with
    no `main()` to end the process: `run()` in `vm.c` exits a worker itself. And a signal to the
    master may land on `main()`'s thread, which is why the master polls every 50ms instead of
    sleeping until one; the stop signals are blocked across each `fork()`, or one sent before a new
    worker has put its own handlers back would only set its copy of the master's flag.
  - `http::serve` refuses what isn't well formed before the handler sees it (the statuses are in
    `docs/language.md`), both `Content-Length` and `Transfer-Encoding` included, as the way requests
    are smuggled past a proxy, and a chunk size line or trailer with a lone CR or LF in it (a line
    end to some proxies, so the same request would be two; found by the review of keep-alive, where
    a connection carries on with what follows); handler errors are a 500 and a line on stderr. Request and response
    are maps, like the client's response; the request carries `"remote_address"` (from `socket_peer()`, `null`
    if the client is already gone, the proxy's behind one: reading `X-Forwarded-For` is the program's decision). `HttpServerTest` runs `tests/programs/web_server.gaz` and
    speaks to it over raw sockets, so requests no client would send can be sent.
  - **A request has a deadline** (`"request_timeout"`, 30s, a 408) as well as the per-read
    `"timeout"`, which alone let a client trickling a byte every few seconds hold a worker for hours.
    `Reader` checks it before each read, so it can overrun by one read's timeout.
  - **`"max_requests"`** (unset, no limit) calls `worker_recycle()` once the worker has answered that
    many requests, however many connections they came on, instead of looping back to
    `socket_accept()`: an opt-in policy, since forcing it by default would be gaz second-guessing an
    app that has no accumulating state to worry about. The last one says `Connection: close`, and the
    connection closes before the worker recycles.
  - **Keep-alive is on by default**, since every browser and proxy expects it and a TCP handshake
    per request is the cost it saves. `"idle_timeout"` (5s) is the wait for a connection's next
    request, `"timeout"` still the wait for its first; `"requests_per_connection"` (100) bounds one
    connection, 1 being the old one-request-per-connection server, so there is no on/off flag.
    `"request_timeout"` restarts with each request.
    - **What closes it**: an HTTP/1.0 request (no HTTP/1.0 keep-alive, the rarer the path the fewer
      its bugs: `ab -k` gets a connection per request); a request whose `Connection` header's options
      include `close`; any refusal and any read error, since the bytes after a request that couldn't
      be read can't be trusted to start the next (the smuggling the strict checks are for); a
      handler's 500; a handler's own `"Connection" => "close"` (the one value of that header it may
      give, any case; anything else is still the error); `requests_per_connection`; the worker's last
      request before `max_requests`. A closing response says `Connection: close`, written by
      `write_response()` alone; one kept open says nothing, HTTP/1.1's default. Every response is
      framed by `Content-Length`, but a 204, a 304 and a HEAD's, which have no body by rule.
    - **A quiet connection is closed without a word**: one that sends nothing before its first
      request's `"timeout"`, or between requests within `"idle_timeout"`, gets no 400 or 408, which
      nobody would read (`Reader.heard_anything()`); one that sent part of a request still does.
    - **The idle wait is `socket_wait()`**, which sees a stop within a second, so a worker holding an
      idle connection still stops promptly; a request already read in part (pipelined) is answered
      without waiting, in order. `ponytail:` such a request is answered even after a stop (bounded by
      `requests_per_connection` and the master's grace), and the response written after a stop can't
      say `Connection: close`; a `worker_stopping()` builtin would let it.
    - **An idle connection yields to a waiting client**, which is what makes keep-alive safe on by
      default in a prefork pool, where each idle connection would otherwise hold a whole worker for
      `idle_timeout` (a browser opens up to six). Between requests (never before a connection's
      first) a worker waits on its socket and its listener together; when the listener is ready it
      gives its own client between one and two times `YIELD_GRACE` (10ms), then looks at the
      listener again: a client still queued means no worker is free, so it closes the idle
      connection quietly and returns to `socket_accept()`; one gone means a free worker took it,
      and it waits out the rest of its idle time (a deadline, not a fresh wait each time round).
      The grace is drawn by each worker because idle workers that look again together all see the
      client still queued and all give up their connections (with four idle workers and one new
      client, 2.4 on average did, where one is enough; with the draw 1.25). Free capacity makes
      keep-alive cost nothing; without it the worst case is a connection per request plus up to
      20ms.
      `http::handle()` has no listener and waits on its socket alone.
    - **Up to four empty lines before a request line are skipped** (`MAX_EMPTY_LINES`), as RFC 9112
      asks of a server, bounded so a client can't hold a worker with them; a fifth is a 400.
    - Tested by `tests/gaz/lib/http_connection_test.gaz` (`http::handle()` over a socket pair,
      responses compared byte for byte without the `Date` line) and `HttpServerTest` (idle close,
      stop while idle, `max_requests` on one connection, a 500 closing, the yield both ways).
  - **`"max_requests"` jitter, not built: a small edge PHP-FPM doesn't have out of the box.** Every
    worker in an FPM pool shares one exact `pm.max_requests`, so under steady traffic the workers
    that started closest together drift toward recycling close together too — a real, documented
    complaint, and FPM itself has no config option to randomise it (verified: nothing in the official
    `php.net` config reference, and the only "jitter" proposal found is an open, unimplemented issue
    on `php-fpm-ng`, an unrelated third-party reimplementation, not upstream FPM). Gaz's version is
    pure library code, no VM change: let `"max_requests"` take `[$min, $max]` as well as a plain int,
    and have each worker call `rand_int($min, $max)` once at startup to pick its own personal
    threshold instead of sharing one. **The one real gotcha**: `workers($n)` forks the running
    program, and a fork duplicates the PRNG's state along with everything else — a worker that
    doesn't call `rand_seed()` again after `workers()` returns its number would inherit the exact
    same xoshiro256** state its siblings did, and `rand_int()` would hand every worker the identical
    "random" threshold, silently defeating the whole point. `lib/http.gaz` reseeding from fresh OS
    entropy right after `workers()` returns, before touching `"max_requests"`, is what actually makes
    the jitter real; `rand_seed()`'s own doc already says every program starts freshly seeded, which
    is true of the master before its first fork but not automatically true of each child after.
  - **`workers($n)` is a fixed pool, not built: dynamic sizing (PHP-FPM's `pm = dynamic`/`ondemand`)**,
    growing the pool under load and letting idle workers exit, the one real capability gap against
    FPM's process manager (`worker_recycle()` already covers the other one, `pm.max_requests`). The
    blocker isn't the idea, it's that the master currently knows only whether a worker is alive
    (`workers.c`'s one byte per worker in shared memory) — it has no idea which workers are idle in
    `socket_accept()` versus busy in a handler, because workers race to accept on the shared listening
    socket independently with no coordination through the master at all. Scaling needs that byte to
    become a state (idle/busy, with a last-transition time), workers to write it on each transition,
    and the master's poll loop to read it: spawn more when nothing's been idle for a stretch, and send
    one specific worker (not the whole group) the same graceful SIGTERM that shutdown already uses when
    one's been idle past a timeout with others to spare — which needs the master to track a *target*
    pool size separate from the live count, so a deliberate scale-down isn't misread as a crash and
    respawned. `workers($min, $max)` in place of `workers($n)` is the likely shape; `ponytail:` no
    jitter on synchronized scale-down either, matching FPM's own lack of one.
  - **Decoding a request is asked for, not done for every request**: `http::query($request)`
    and `http::form($request)` give maps of strings, a key given twice keeping its last value, so
    a handler never checks a value's type; `query_all()`/`form_all()` give every value as a list
    (Go's `Get` against the full list, not PHP's `tag[]` convention, where a key's type would
    depend on what the client sent). `+` is a space only there, as HTML forms send it:
    `url_decode()` is RFC 3986's, where `+` is itself. A bad escape (`%zz`, a `%` at the end) is
    an error naming it, not passed through as PHP and browsers do, so a mangled value can't
    arrive looking valid; a handler that doesn't catch it answers 500. `form()` refuses a body
    whose Content-Type isn't `application/x-www-form-urlencoded` rather than giving `{}`.
    Pieces split as the WHATWG parser splits them (empty ones skipped, no `=` is a value of
    `""`). Tested by `tests/gaz/lib/http_decode_test.gaz`, and end to end by `HttpServerTest`.
  - **Routing is `http::Router()`, in `http.gaz` itself**, not a file of its own: a separate
    `router.gaz` read as `router::Router()`, one namespace naming the other redundantly, for a
    program that was already reaching for `http::serve` to run what it dispatches. `get`, `post`,
    `put`, `patch`, `delete` and `route($method, ...)`, and `$app.handler()` is an ordinary
    handler for `http::serve`, so the server learns nothing. Patterns are segments, literal or
    `:name` (no regex); the path is split before it is percent-decoded, so a `%2F` is a `/` in a
    value and never a separator, and a `:name` never takes an empty segment. Params go into
    `$request["params"]`, so a handler keeps one argument and tests with a plain map. The first
    route added that matches wins (Express's rule: nothing to rank). A path matched by other
    methods is a 405 with `Allow` (a GET route answers HEAD); a path only its other spelling
    (trailing slash added or taken away) matches is a 308 to that spelling, query kept, so a link
    works either way and a page has one URL; a bad escape is a 400; the rest a 404 or what
    `not_found()` was given. Middleware is `($request, $next) -> response` and wraps the whole
    dispatch, 404s included, the first added outermost. Tested by `tests/gaz/lib/router_test.gaz`.
  - **`http::redirect($to, $status = 303)`** is the response that sends a client elsewhere, public
    because every handler that answers a form needs one (the router's own 308 to a path's other spelling
    is a separate private function). 303 by default, since a browser follows it with a GET after a POST;
    301, 302, 307 and 308 are the others. A `$to` is written as given, so one taken from a request
    must be checked by the caller (an open redirect), and one with a line break or NUL byte is an error
    at the call, not a 500 when the response is written. Tested by `tests/gaz/lib/http_redirect_test.gaz`.
  - **Static files are `http::serve_static($dir)`, in `http.gaz` too**: a handler, for
    `http::serve()` directly or a `Router`'s `not_found()`, answering from files under `$dir`.
    The path is percent-decoded and split into segments, as a `Router`'s is; a segment of `..`
    is refused as a 404, the same answer a missing file gets, rather than resolved and hoped to
    stay inside `$dir`, and so is a segment that decodes to hold a `/` (`..%2f` is a `..` the
    segment check never sees; `%2F` is never a separator here). The regression tests aim at a file
    that exists above the served directory, since a 404 alone can't tell a refusal from a miss.
    A path ending in `/`, or one naming a directory, looks for `index.html`
    inside it; Content-Type comes from a small extension table, `application/octet-stream`
    otherwise. `gaz -S host:port [--docroot DIR]` (`std/devserver.gaz`, run by `vm.c`'s `-S`)
    is a zero-config preview server built on it, `php -S`'s equivalent: no router script
    argument the way `php -S` can take one, since `include` takes a string literal resolved at
    parse time, never a runtime-named file (the same reason there is no type-tag
    deserialization); a program that wants routing or anything dynamic writes its own few lines
    on `serve_static()` instead. Tested by `tests/gaz/lib/http_serve_static_test.gaz` and,
    end to end, `DevServerTest`.
  - **Cookies and signed sessions**, in `http.gaz` too, on the cryptography (see "Cryptography"):
    `http::cookies($request)` (the `Cookie` header as a map), `http::set_cookie($response, $name,
    $value, $options = {})` (`"path"`, `"http_only"` true by default, `"secure"`, `"same_site"`
    `"Lax"` by default, `"max_age"`), `http::session($request, $secret)` and
    `http::session_cookie($response, $session, $secret, $options = {})`, and
    `http::csrf_token($session)`/`http::verify_csrf($session, $submitted)`. A session is a map,
    `json::encode`d and signed as one value with `crypto::sign`/`crypto::unsign` (HMAC-SHA256,
    checked with `crypto::equals`, never `==`); a missing, forged or malformed session cookie is
    `{}`, never an error a handler must catch, since a client showing up with no session or an old
    one is routine. A `Set-Cookie` header's value can be a list, one line per cookie, since
    RFC 6265 forbids joining several with a comma (an `Expires` attribute has one of its own);
    `write_response()` writes any header's list value that way, not only `Set-Cookie`'s. Tested by
    `tests/gaz/lib/http_session_test.gaz` and end to end by `HttpServerTest`.
  - **Middleware for a web app**, in `http.gaz` too, the glue the todo app proved (`apps/todo`,
    whose tests passed unchanged on it): `http::security_headers($options)`, `http::sessions($secret,
    $options)`, `http::csrf($options)`, added in that order (headers outermost, so a 403 and a
    redirect get them too; sessions before csrf, which reads the token), then the app's own
    `authentication`. Library middleware because the glue is where the security bugs live, and each
    app writing its own gets them its own way. Tested by `tests/gaz/lib/http_sessions_test.gaz`.
    - **A handler asks for a session change by adding `"session"` to its response**
      (`http::with_session()`), which the middleware takes out, since `write_response()` refuses
      any other key: the response is the one thing a handler returns, so no global, mutable request
      or second return value is needed. `$request["session"]` always has a `"csrf_token"`;
      `$request["flash"]` is the last request's message, kept out of the session so a handler that
      passes the session on doesn't show it again (`http::flash()`).
    - **The cookie is written only when the session differs from the one the cookie held**, so a
      page view sends no `Set-Cookie` and a flash is cleared by the same rule. The comparison is
      against the cookie as it came, *before* the token is added: compared with the session after,
      a new visitor's token is never written and every form of theirs is a 403. A test plants
      exactly that mutation.
    - **`http::csrf()` checks the `Origin` as well as the token** (when one is sent; `null` is
      foreign), a second, independent line that holds if a token leaks; a body that isn't a form
      carries no token, so a hostile non-form POST is a 403, not a 500. Without `http::sessions()`
      before it, every request is an error, since a wiring mistake that answered 403 for ever would
      look like a user's problem.
    - **The secret must be 32 bytes or more**, an error when the middleware is made: a short signing
      secret is the program's mistake, and one that can be guessed forges every session.
    - **Who the user is stays the app's** (`authentication`, and the login that builds a *new*
      session with a fresh `crypto::token()` so nothing planted before it survives, the
      session-fixation defence): the library doesn't know what a user is, and a login that kept the
      old map would undo the defence however good the middleware.
  - `ponytail:` writing a response has only the per-write timeout; the stop grace
    is fixed; while workers drain, new connections queue in the listener's backlog (the master
    holds it too) and are reset when the program ends, where closing the listeners first would
    need `workers()` to know which sockets are listeners; a master killed with SIGKILL leaves its
    workers running (Linux's `PR_SET_PDEATHSIG` would end them, macOS has nothing like it). No TLS
    on the server side: a proxy in front does it.
  - **Next, when a program asks** (the order they would be built in):
    - Handing the client's address to a handler (`$request["remote_address"]`, from `socket_peer()`,
      which exists), for logs and rate limits; behind a proxy `X-Forwarded-For` is the one that matters,
      and whether to trust it is the program's.
    - `quote($value)` as a builtin (`value.c` has the function): `text::quote()` in `lib/text.gaz`
      is the idiom `slice(to_string([$x]), 1, -1)` with a name, used by the library; the compiler
      still spells it out and `lib/test.gaz` keeps a helper of its own, both of which can use it now
      that nothing loads the library by path. A builtin takes its name from every program, so it
      waits for a program that needs the speed or the bare name.
    - Measured once already, against PHP's built-in server across no-opcache/opcache/opcache+JIT
      (`ab`, `workers(1)` each side): gaz matches PHP with every performance feature on for an
      ordinary handler (build data, encode it), and only loses on a tight arithmetic loop inside
      the handler, which is what JIT is actually for. Not yet measured against `php-fpm` behind
      nginx, or with a real worker pool on either side; re-measure rather than trust this once
      the pool-sizing or JIT-adjacent work above lands.
    - An access log line per request
      (`http::http_date(time())`, method, path, status, bytes, `monotonic_time()` for how long).
- **Decided, not built** (roadmap item 7): `interface`/`implements` (a parse-time check
  that the methods exist, plus `is_a`), and `final`. The keywords are reserved.
- **Namespaces** are resolved by the parser, so the VM never learns the word and bytecode only
  sees longer names: functions and kinds carry `::`, while a method block stays
  `Kind.method`, which is what lets the loader tell the two apart. Resolution is one pass
  before anything else is checked, so nothing below it knows namespaces exist.
  - `namespace json;` first in a file, at most one, optional: a file without one declares its
    names globally, as every file did before, and a program never needs one.
  - **A name is private to its namespace unless `pub`**: a file is implementation, and only
    what it says is public escapes it. Privacy is per namespace, not per file, so `compiler/`'s
    files declare `namespace gazlang;` and go on seeing each other's everything, and a
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
  - `namespace`, `use`, `pub`, `kin` and `shared` are reserved, so `fn use()` no longer parses.
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
    `Counter::rows[] = $r`, `#rows[] = $r`): a `pub static` from anywhere, one that says nothing
    only from inside the kind, so a static needs no rule of its own to protect it. Both
    spellings compile to the same instruction, since `::` resolves when it is parsed (a path
    written with a `#` takes the static as its root, `static_start()` in `codegen.gaz`, not `#`,
    which a static method hasn't got); the cost was a fifth root for `store_path()` next to a local,
    a global, a capture and `#`, and the check refusing `Kind::NAME = ...` that mirrors the one
    refusing `#NAME[0] = 1`.
  - **In a static method `#name` is the kind's**, so `#count` is the static field and
    `#helper()` another static method; naming an instance member is a parse error, as is `#`
    on its own, since there is no object and members are declared.
  - `#next` without calling it is an error that says to write `Counter::next`, which is the
    function. A bound static would be the same value by another spelling, so it waits for a
    program that wants it.
- **Not yet designed**, each built when real code shows what it needs: traits, late static
  binding, operator overloading, `log`/`exp`/fractional powers (each wants an algorithm GazLang
  defines, as `round` has), variadic parameters and spread in calls (pass a
  list), `foreach` over a string (`split($s, "")`). A REPL is possible and wanted: see "A real
  REPL" under the C VM.
- **Regular expressions**: `lib/regex.gaz` (`regex::matches`, `regex::search`,
  `regex::find`, `regex::groups`, `regex::replace`), a Thompson NFA (Pike's VM) so there is no
  backtracking and no ReDoS. Literals, `.`, `*` `+` `?`, `|`, `(...)` groups (capturing),
  `[...]`/`[^...]` classes with `a-z` ranges, `^`/`$` anchors, `\` escapes. **Perl's match**:
  threads run in priority order carrying their group slots (`save` instructions), and one
  reaching `match` drops the threads after it while those before run on, so repetitions are
  greedy and the first alternative wins, as every engine a reader knows does; a new start is
  seeded each step only until something matched, which is what makes it leftmost.
  `groups` gives a group that took no part as null (Python's None; PHP's `""` can't be told from
  an empty capture), a repeated one's last. `replace` takes `$with` as it is and moves on a byte
  after an empty match (Perl's, Python's and JavaScript's `"-a-b-c-"`). `ponytail:` an empty
  iteration of a starred group dies at the loop, so `(a*)*` reports its group as null where
  Perl says `""`; no `$1` in replacements or a function for `$with`, no backreferences, no
  `\d`/`\w`/`\s` shorthands (`lib/chars.gaz` has those as named functions) — add them if a
  program needs one.

# The language

Rules, with the reason where the choice isn't obvious. `docs/language.md` is the reader's
version.

## Operators, truthiness and equality

- **Precedence**, loosest first: assignment (right associative) → `?:` and `throw` (right) → `??` (right)
  → `||` → `&&` → equality (`==` `!=` `<=>`) → relational → `|>` → `..` → `|` → `^` → `&` → shifts →
  `+ -` → `* / %` → unary → `**` (right) → postfix (`[index]`, `(args)`, `.name`, `?.name`, `::name`) → primary. Bitwise precedence
  is Rust's and Python's, not C's, so `$flags & MASK == 0` is `($flags & MASK) == 0`. `..` sits
  looser than the bitwise operators and tighter than comparison, so `"x = " .. $f & MASK` and
  `$f & MASK .. "!"` both do the obvious thing (between the bitwise levels, every unparenthesised
  mix would be an error); shifts stay tighter than `..`, unlike Lua, so `"n = " .. $x << 2` works.
  `**` is Python's: tighter than a unary on its left, looser than one on its right (`power()` in
  `parser.gaz` reads a postfix, then `**` and a unary), so `-2 ** 2` is -4 and `2 ** -1` parses.
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
- **Lists order element by element** (`order()` in `ops.c`): the first pair that differs
  decides, and a list that the other starts with comes first (`[1] < [1, 0]`), as in Python and
  Rust; each pair follows the rules above, so `[1] < ["a"]` is an error, but only once that pair
  is reached. Maps can't be ordered. What it is for is sorting by several keys in one
  comparison, swapping `$a` and `$b` in an element to turn its order round:
  `[$b.points, $a.name] <=> [$a.points, $b.name]` is most points first, then by name.
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
- **`$x |> f(a)` is `f($x, a)`**: the value on the left is the call's first argument, since the
  builtins take their subject first (`$title |> trim |> lower |> replace(" ", "-")`). `$x |> f` is
  `f($x)`, `$x |> $g(a)` is `$g($x, a)`, a qualified name or a kind as its call, and a
  parenthesised expression is called with the value (`|> ($v -> $v * 2)`, `|> ($h["k"])`); left
  associative. **Parser sugar only** (`pipe()` in `parser.gaz`): the right side is read at the next
  tighter level, as any operand is, and becomes an ordinary `FunctionCallAST` or `CallValueAST`,
  so its checks, errors, traces and order of evaluation (a called value before its arguments) are
  the call's, and nothing below the parser learns of it. `#grouped` (the last expression
  `parenthesised()` gave) is how a parenthesised expression is told from anything else that
  starts with `(`.
  - **Looser than `..` and arithmetic, tighter than comparison**, so `"Hello " .. $name |> upper`
    pipes the whole greeting and `$items |> len > 3` compares the length. The price is that
    `$x |> f .. "!"` pipes into `f .. "!"`, which is refused saying `|>` binds looser than `..`.
  - **Refused**: a lambda right after `|>` (its body would swallow the rest of the chain: write
    `|> ($v -> ...)`); a method (`|> $obj.m()`, `|> $obj.m`, `|> #m()`), the restrictive choice,
    loosenable later; anything else that isn't a name, a variable or a parenthesised expression,
    with or without arguments (`|> 5`, `|> $h["k"]`). `ponytail:` `|> ($a, $b) -> ...` gets the
    plain `Unexpected ','`.

## Numbers

64-bit ints and floats, always finite (no INF or NAN).

- Literals: `42`, `1.5`, `1e10`, `2.5E-3`; digits on both sides of a dot (`1.` and `.5` are
  errors). `0xFF` is an int (hex has no exponent: `0x1e5` is 485); strings are never read as
  hex. `parse_number()` in `value.c` reads the same syntax for `to_float()`, and JSON numbers are
  valid.
- **Nothing overflows silently**: an int that doesn't fit is `Integer overflow` (PHP would
  switch to a float), an infinite float literal is a lexer error, an infinite result is `Float
  overflow`. Division by zero is an error.
- **`**` is square-and-multiply** (`power()` in `ops.c`), never libm's `pow`, whose rounding
  differs between platforms and would break recordings: int to a non-negative int is an exact
  int or `Integer overflow` (squaring the base overflows only when the result would), anything
  else a float multiplied step by step, a negative exponent one divided by the power (so a power
  too small to hold is 0.0, 0 to one is `Division by zero`). Only a whole exponent (a whole float
  too, within the int range): `log`, `exp` and fractional powers stay open until GazLang defines
  an algorithm for them, since libm's differ in the last bit. `sqrt` is libm's, as IEEE 754
  requires a square root to be correctly rounded.
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
  `round` and `sqrt` give floats (`sqrt` of a negative number is an error); `abs` keeps the type; `min`/`max` take two numbers or two strings, or a
  list or map whose values are all numbers or all strings (empty is an error), a tie giving the
  first. `sum` adds a list's or map's values from 0 with `+` (`binary_op(OP_ADD)`), so its
  errors, overflow and int-or-float are `+`'s and `sum([])` is 0. `to_int` truncates a float and
  errors outside the int range; `to_float(true)` is 1.0. **`to_int($x, $default)` and
  `to_float($x, $default)`** give the default for a string, or a float too large for an int,
  that can't be converted (`to_int($arg, null) ?? fail(...)`), so bad input needs no `try`,
  which would also catch running out of call depth; any other type is still an error, being a
  mistake rather than bad input (`fall_back()` in `builtins.c`).

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
- **Tagged strings**: a name touching a double quote (`sql"..."`, `db::sql"..."`) is `sql($parts,
  $values)`: the text parts with escapes read, always one more than the values, empty ones kept,
  and the values as they are, never made text. **Parser sugar only**: the lexer gives a `TAG`
  token (the name) instead of `IDENTIFIER` when a non-keyword word touches `"`, the string's tokens
  following as usual, and `tagged_literal()` in `parser.gaz` makes an ordinary `FunctionCallAST`
  of two list literals (the parts all constants, so pushed as one value), marked `#tagged` only so
  an arity error can say what was passed; name resolution, `pub`, `use`, arity and types are a
  call's, and nothing below the parser learns of it.
  - **Names only**, since a tag is looked up when the program is read: `$f"..."` stays a syntax
    error. **No raw text list**: nothing needs one yet, and `name'...'` is refused by the lexer so
    that spelling stays free for a raw tag.
  - **A keyword is never a tag** (`echo"x"`, `return"x"` keep their meaning), so a keyword can't
    become one later without breaking programs; a capitalised keyword is a name, so `ECHO"x"` is a
    call (with the lowercase hint when undefined).
  - **Refused with a message**: a name, a space, then a string (`A tag touches its string`, which
    no valid program has: a name is never followed by a string, and a miscapitalised keyword keeps
    its own hint), and a tagged string after `|>` (it is already a call; `pipe()` would otherwise
    add a third argument).
  - **The library's tags** are `db::sql"..."` (see "Databases") and `web::html"..."` (see
    "Templates"), each in its namespace, never a global name; `lib/` can use a tag only through a
    `bin/gaz` whose built-in compiler knows them, since the library is compiled by it.

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
- `...$x` spreads a list into a **list literal** (`[$first, ...$rest]`, `[...$a, ...$b]`) and a
  map into a **map literal** (`{...$defaults, ...$options, "k" => 1}`), each only its own
  kind: `Cannot spread map: only a list can be` and `Cannot spread list: only a map can be`,
  raised before the entries after it run (a list's indexes as keys would be a silent
  surprise). In a map, later entries win, as a duplicate key does, and a key already there
  keeps its place (`MAP_EXTEND`, `map_set()`), as in JavaScript and PHP. `f(...$args)`, a bare
  `...$a` and a rest pattern are parse errors that say so; each could be added later without
  breaking anything. `...` is one token
  (longest match, so `.....` is `...` then `..`).
- `echo` prints them as literals; arithmetic and unary `-` on them throw, and so does ordering
  a map (lists order element by element, see above).

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
  default is never shared; the arity is then `[required, total]`. `function` is an ordinary
  name (and a type's), not a keyword.
- **`$x` is always local** (to the running call or the top level), **`@x` always global**; a
  function can't read top-level `$x`. Parameters are `$` only, or a list pattern of `$`
  variables (below). `return` outside a function is a
  parse error; no return gives `null`. Variables holding `null` are still defined.
- Calls are capped at 100000 deep (`MAX_CALL_DEPTH` in `gazvm.h`), a catchable GazLang error.
  The cap is bounded by the C stack, not the value stack: a callback (`map`, `sort`, `to_string()`)
  nests on the 1GB thread stack, about 5KB a level in a release build and 36KB or more in a
  sanitized one, where every local of `call_builtin()`'s `switch` gets a slot of its own. So **no
  stack buffer belongs in `call_builtin()`**: a big one goes in a `noinline` function (`read_stream()`),
  and `-Wframe-larger-than=4096` on a sanitized build names any that crept back.
- `include "path.gaz";` is top level only, takes a string literal relative to the including
  file (the working directory for piped source), and is resolved at parse time by splicing the
  file's statements in; each file is included once (the main file counts), by real path, which also breaks cycles.

## Builtins and the standard library

Builtins are `builtin_info[]` in `builtins.c` (name to an arity, or `[fewest, most]`), can't
be redeclared, compile to `CALL_BUILTIN name argc`, and check argument types by their
`type_of()` names.

- Strings: `len`, `slice($x, $start, $length)` (strings and lists, PHP's rules including
  negatives), `lower`, `upper`, `trim` (the lexer's whitespace only), `split` (empty separator:
  bytes, one string each; a third argument, an int of 1 or more or null, caps the parts, the last holding the
  rest), `join` (elements converted like echo), `replace` (every occurrence; empty search
  is an error), `contains`, `ends_with`, `starts_with($s, $prefix, $offset = 0)` (whether the
  prefix is there at the offset, so a scanner asks without slicing off what it has read),
  `index_of($s, $needle, $offset = 0)` (null when absent), both with the same offset rule: negative
  from the end, and one outside the string is an error (the end itself is fine to
  `starts_with`, which finds only an empty prefix there), `repeat`, `chr` (0 to 255), `ord` (one
  byte), `to_int` (ints, bools, decimal strings with an optional `-`), `to_float`, `to_string`.
- **UTF-8**: `utf8_valid($s)`, `utf8_length($s)` and `utf8_chars($s)`. **Strings stay bytes**:
  `len`, `slice`, `reverse` and `split($s, "")` count and cut bytes and can cut a character in half,
  and nothing converts or checks a string by itself; a text type would split every API in two.
  `utf8_valid` is RFC 3629 written out in C (`utf8_character()` in `builtins.c`, no locale): a lead
  byte says how many continuation bytes follow, and a narrower range for the first of them after
  E0, ED, F0 and F4 leaves out overlong forms, surrogates and anything above U+10FFFF; C0, C1 and
  F5 to FF never lead. **Builtins, not a library**: a check belongs on every request path, over
  every byte of a form field, which is C's work, and all three share the one decoder so they can't
  disagree. **`utf8_length` and `utf8_chars` are errors on text that isn't well formed**, naming
  the byte where the first bad character starts, since a count of something that isn't characters
  is a wrong answer; a caller asks `utf8_valid` first or catches. `tests/Utf8Test.php` checks all
  three against PCRE's UTF-8, which shares no code with them, on random and hand-made bytes.
  **JSON is strict at both ends** (`lib/json.gaz`): RFC 8259 requires UTF-8, so `json::encode`
  refuses a string or key that isn't well formed (it would write invalid JSON), and `json::decode`
  refuses a document that isn't (one `utf8_valid` before parsing) and an escape naming half a
  surrogate pair, so it never hands back text the rest of a program can't trust.
- Lists and maps: `in_array` (`==`), `has_key`, `keys`, `values`, `last` (an empty list is an
  error), `reverse` (lists, strings by byte, and maps, which keep their keys), and `map`, `filter`
  (truthiness, as `if`), `reduce($x, $f, $initial)` and `sort` (stable; the comparator must return
  an int; without one, or null, it is `<=>` in C, `binary_op(OP_CMP)`, with its errors), which call back into GazLang through `call_value()` in `vm.c`, checked as a call
  written in the program is. `sort` is a defined merge sort, since a comparator can see which
  comparisons are made: split in the middle, merge asking `$compare(right, left)` (`merge_sort()`
  in `builtins.c`). Types are checked before anything is called.
  **`map`/`filter` also pass the element's index or key, and `reduce` its third argument, to a
  function the program defines that needs two (three) arguments** (`callable_min_args()` in `vm.c`: its
  fewest, or -1 for a builtin, a kind or a non-function): what used to be an arity error is the index
  now, and nothing that ran before changes. Not to a function with a default for the second parameter
  (it asked for one), and never to a builtin, whose extra parameters are options (`to_int($x, $default)`),
  or a kind. It is decided by what a function *needs*, not by what it accepts, so a callback's meaning
  never depends on a default someone adds.
- Types: `type_of` (`int float string bool null list map function kind object socket db file`), `is_a`,
  `kind_of`, `kind_name` (a kind's name as declared, namespace included, `tui::Rect`: the bare
  name is `last(split(..., "::"))` and the other way would be impossible; of a kind or an object,
  whose kind is the only thing it could mean), `fields` (see "Objects"), `object_id` (an int no other object of the program has or
  had, counted from 1 in `object_new()` and reset by `run_program()`, so it is the same
  however the program runs: a set of objects or a side table is a map keyed by it; a counter,
  not the address, since an address is reused and differs from run to run).
- I/O: `print`/`print_error` (echo without the newline, to stdout or stderr), `read_file`,
  `write_file`, `read_stdin` (empty when the program itself was piped in), `args()` (after the
  gaz options or `--`; the CLI rejects options it doesn't know, since `getopt` would drop
  them silently), `cwd()`, `real_path()` (as `realpath(3)`; `""`, a NUL byte, `file/` and
  `file/..` are nothing, where platforms disagree), `file_exists()`.
- `file_open($path)`, `file_read_line($file)`, `file_close($file)`: a file read a line at a time, so
  a large file or a log needn't be in memory whole. A `file` is a handle like a `socket` or a `db`
  (`T_FILE`, refcounted, closed when the last reference goes, closing twice fine), and the line rule
  is `read_line()`'s (`"\n"` or `"\r\n"` stripped, a last line needing neither, `null` at the end). **Three
  builtins, not `read_line($f)`**: an optional handle would make `read_line`'s meaning depend on an
  argument's type, and a handle that could later take a mode (`"w"`, `"a"`) has room. Only a
  directory is refused (`EISDIR`, written out as `delete_file` does): a pipe or `/dev/stdin` is
  what streaming is for, where `read_file()` insists on a regular file. Close-on-exec, as a socket is; one opened before `workers()` can't be
  read in a worker (see "Serving HTTP"). `ponytail:` read only, no
  seek, no bytes; a file being appended to stops at the first `null` (C's end-of-file flag sticks); a `foreach` can't be lazy, so a program loops on `file_read_line()`.
- Directories: `list_dir()` (sorted with `str_cmp`, byte by byte, since `readdir()`'s order is
  the file system's), `is_dir()` (through symlinks, as `file_exists`), `make_dir()` (one level),
  `delete_dir()` (empty only), `delete_file()` (not a directory: its reason is written out as
  `EISDIR`, since `unlink()` says EPERM on macOS). A failure is `Cannot VERB "path": strerror`,
  the path quoted so a NUL byte shows; a NUL byte in a path is `ENOENT`, as no name holds one.
  `StdlibTest::test_directories` runs one snippet that makes, lists and clears
  `tests/.tmp/dirs`, clearing a failed run's leftovers first so it can be recorded; the names in
  it avoid differing only in case, which macOS's file system can't hold.
- `read_line()`: `getline()` on `stdin`, the line without `"\n"` or `"\r\n"`, or null at the end;
  through the stdio buffer `read_stdin()` reads too, so the two share the input without losing a
  byte, and a piped program's input was read by `main()` already, so both find nothing. It
  flushes output first (a prompt). Tested through `CliTest` rows with `tests/corpora/cli/lines.txt`.
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
- Sockets (`net.c`): `socket_listen($host, $port, $backlog = 128)` (port 0 the system's pick,
  `socket_port()` says which), `socket_accept($listener, $timeout = 30)` (waits for good; the timeout
  `socket_peer($socket)` gives `{"address", "port"}` of a connection's other end (an error for a
  listener, a closed socket and an inherited one). **The address text is `ipaddr.c`'s, not
  `inet_ntop()`'s**, whose IPv6 spelling differs between systems: IPv4 dotted, IPv6 lowercase groups
  with the longest run of two or more zero groups as `::` (RFC 5952), and IPv4-mapped IPv6 as plain IPv4 so a
  client has one spelling however it came; checked against Python's `ipaddress` on 295 addresses
  (`tests/IpTextTest.php`, on its own so no network is needed) and by `tests/gaz/sockets/peer_test.gaz`.
  is the connection's), both plain TCP. `workers($n)` (`workers.c`) forks the program for a server;
  see "Serving HTTP", and for why a connection made before it can't be used in a worker while a
  listener can. The fuzzer skips `workers` with the socket builtins.
  `socket_open($host, $port, $tls = false, $timeout = 30)` gives a `socket`,
  a reference-counted handle closed when the last reference goes (so a forgotten close leaks no
  descriptor), `socket_read()` up to 64KB or `""` at the end, `socket_write()` all of it,
  `socket_close()` twice is fine. TLS verifies the chain against the system's store
  (`SSL_CERT_FILE` overrides, which is how `HttpTest` trusts `tests/fixtures/tls/`) and the host
  name, or the address for an IP (no SNI then). SIGPIPE is ignored around each call and put back
  after, as curl does: per socket only macOS can turn it off, and ignoring it for good would
  change what a program writing to a closed pipe does. OpenSSL reports a socket timeout as
  wanting to read; `net.c` says `timed out`.
  `socket_wait($sockets, $seconds)` gives the index of the first socket in the list with something
  to read (data or the end on a connection, a queued connection on a listener; a hang-up or error
  counts, since `socket_read()` won't wait on either, and bytes OpenSSL already decrypted count
  without asking `poll()`), or `null` once `$seconds` pass or at once in a worker asked to stop. It
  exists for kept-alive connections: a worker idle on one must see a stop (`socket_read()` retries
  on `EINTR`, so a stop never interrupts it) and a client waiting on the shared listener, and both
  are one `poll()`, waited a second at a time as `socket_accept()` does. Closed and inherited
  sockets are refused as `socket_read()` refuses them, listeners exempt. Tested by
  `tests/gaz/sockets/wait_test.gaz`, `inherited_test.gaz` and `HttpServerTest` (a SIGTERM during a
  60-second wait ends it). `ponytail:` at most 16 sockets, a fixed array; a TLS record only partly
  arrived reads as ready and `socket_read()` then waits for the rest.
- Databases (`db.c`, drivers `sqlite.c` and `pg.c`): `db_open($url)` gives a `db` handle (refcounted
  like a socket, closed when the last reference goes), `db_run($db, $sql, $params = [])` gives
  `{"rows", "changes"}`, `db_close($db)`. **Three builtins whatever the drivers**, since a builtin takes
  its name from every program: the URL's scheme (`sqlite:`, `postgres:`) picks a `DbDriver` (open, run,
  close) in C, so a new database is a file and a table entry, and `lib/db.gaz` (`db::open`, the `Db`
  kind with `run`, `query`, `row`, `value`, `exec`, `transaction`) is what programs use. **The
  builtin takes the database's own placeholders** (`?`, `$1`) and a string, not rewritten: rewriting
  means reading string literals in C. No last-insert id (`returning` says it in both). Parameters are
  always bound, never spliced; with them the SQL is one statement, without, a script whose last
  result is given.
  - **Programs write `db::sql"..."`** (a tagged string, the kind `db::Sql`), and `Db`'s methods take
    exactly one and **refuse anything else** (`Db.query() takes db::sql"...", not a string: ...`),
    so a plain string can't reach the database by accident. **`Sql` isn't `pub`**, so
    `db::Sql([$concatenated], [])` is `db::Sql is not pub, so only namespace db can use it`, and
    `sql()`, `raw()` and `Db` (one namespace) are what make and take one; a pub function may still
    declare `: Sql`. What remains is deliberate: a constructor is always reachable, so
    `kind_of($fragment)([...], [])` builds one on purpose, as visibly as `db::raw()`, and its
    constructor still checks the parts and values. `Db` picks the placeholder from the URL's scheme
    (`sqlite:` `?`, `postgres:`/`postgresql:` `$n`) and `Sql.render($placeholder)` gives `[$text,
    $params]`, walking the parts with one counter: a nested `Sql` is spliced in and renumbered, a
    list is `(?, ?)` for `in (...)` or a `values` row (scalars only; **an empty list is an error**,
    since rendering it `(null)` makes `not in` quietly match nothing), a `db::ident($name)` is
    `"name"` with `"` doubled (empty and NUL refused), and any other scalar one parameter, its type
    kept. Values are checked when the `Sql` is made, so the error is at the line that wrote it.
  - **The tag refuses a numbered placeholder in its text**, `$` or `?` and a digit: with PostgreSQL
    `db::sql"select $1, {$x}"` would render `select $1, $1` and bind `$x` twice, and SQLite reads
    `?1` as the first value however many `?` there are. A false alarm inside an SQL string literal
    or a `$$...$$` body is accepted, loud being the safe side, with `db::raw()` the way round. A
    bare `?` and a named `:name`, `@name` or `$name` need no rule: the driver's parameter count
    refuses them (`sqlite: the SQL takes 2 parameters, got 1`).
  - **The tag refuses what looks like a call in braces** (`{name(`, or a qualified `{a::b`):
    interpolation starts only at `{$`, `{@` or `{#`, so `{db::ident($c)}` would be text and give a
    baffling driver error. A linear scan (`check_no_call()`); any other brace stays text, so
    PostgreSQL's `'{1,2}'` and JSON's `'{"k": 1}'` work.
  - **`db::raw($text)`** is the visible way round (a migration from a file), no values and exempt from
    the `$1` rule; `transaction()` uses the builtin for its own fixed statements. `to_string()` of an
    `Sql` is its text with `?` placeholders, never the values, which may be secrets.
  - `tests/gaz/lib/sql_test.gaz` renders both styles with no database; `db_test.gaz` and
    `pg_check.gaz` run them. `ponytail:` no helper joins a list of fragments (a bulk insert of many
    rows is one `values {$row}` per row, or a loop in a transaction).
  - Both drivers are on when their library is found (`make SQLITE=1`/`PG=1` make that an error,
    which CI asks for). SQLite is tested by `tests/gaz/lib/db_test.gaz` on `:memory:` (recorded,
    sanitized, leak-checked); PostgreSQL by `DbPgTest` running `tests/db/pg_check.gaz` against the
    server `GAZLANG_TEST_PG` names (skipped without), on temporary tables. `ponytail:` a bool
    parameter is 0/1 in SQLite, no blobs going in, and PostgreSQL's numeric, timestamps and json
    come back as text.
- `monotonic_time()`: seconds as a float on `CLOCK_MONOTONIC`, from an undefined point, so only a
  difference means anything; a program that prints it can't be recorded, so tests check its type and
  that it never goes back, and its uses (`tui::Metronome`) take the time as an argument.
- `time()`: the wall clock, whole seconds since 1970 as an int, added for a server's `Date` header
  and logs. A program that prints it can't be recorded, so it is tested by type and range and by
  `HttpServerTest` against PHP's clock; `date.gaz` still keeps no clock (`intdiv(time(), 86400)` is
  today in UTC), so a game keeps its own date.
- `sleep($seconds)`: an int or float of 0 or more, `nanosleep()` a day at a time (any finite float
  fits) and carrying on after a signal; it flushes output first, as `term_read` does, since a
  program that sleeps is showing progress. `getenv($name)`: a string or null; a NUL byte in the
  name is an error rather than null, being a mistake. Both are tested by shape (`time_test.gaz`)
  and `getenv`'s value by `StdlibTest` setting one, since neither can be recorded.
- The terminal (`term.c`): `term_raw($on)`, `term_read($timeout = null)`, `term_size()`,
  `term_is_tty($stream)`, `term_is_virtual()` (see `gaz --tty`), only what GazLang can't do itself; drawing is escape sequences through
  `print` and turning bytes into keys is GazLang's (`lib/term.gaz`), so the rules are written out
  and testable from a pipe. `term_read` gives raw bytes (`null` on a timeout, `""` at the end) and
  works on a pipe, which is how the key decoder is tested; it flushes output first, since `stdout`
  is buffered in 64KB. **Raw mode outlives the program unless something puts it back**, and
  `exit()` and an uncaught error skip `finally`, so `term.c` restores it in an `atexit` handler
  and in handlers for SIGINT, SIGTERM, SIGHUP and SIGQUIT (which then end the program as they
  would have, so its exit status is still the signal's). Always `TCSANOW`: `TCSADRAIN` waits for
  the terminal to take the output, which never ends once the terminal is gone. Raw mode turns off
  `ISIG`, so Ctrl-C is the byte 3 for the program to decide about; a program in a loop that never
  reads can't be interrupted from the keyboard then. Resize is polled with `term_size()`, not
  signalled, so nothing runs asynchronously. `tests/fixtures/pty_run.py` runs a program on a pty,
  since raw mode can't be seen from a pipe; `TermTest` skips those tests without python3.
  `ponytail:` `run()` hands a child the terminal as raw mode left it.
- Random numbers, not cryptographically secure (the docs say so): `rand_int($min, $max)` (both
  included), `rand_float()` (0.0 up to 1.0, the top 53 bits), `rand_seed($seed = null)`
  (without a seed, from OS entropy; every program starts that way). xoshiro256** seeded
  through SplitMix64, as PHP's `Xoshiro256StarStar` does. Mapping outputs onto a range (mask
  and reject, `random_between()`) and onto a float is GazLang's rule, pinned for several seeds
  by `StdlibTest` against an independent port. The state is per program, reseeded by
  `run_program()`, since the compiler runs first. **Anything that prints random values calls
  `rand_seed()` first**, or what it prints can't be recorded as expected (snippets and corpus
  files included); unseeded behaviour is tested by type and range only.
- **Cryptography** (`crypto.c`): `random_bytes($length)`, `sha256($data)`, `hmac_sha256($data,
  $key)`, and one builtin per password hash, `pbkdf2_sha256`, `scrypt` and `argon2id`, each with
  its specification's parameters in order (`argon2id` adds its optional secret and associated
  data, which RFC 9106's test vector needs); raw bytes in and out, hex and base64 being
  `lib/crypto.gaz`'s. Six names, since a builtin takes its name from every program: one per
  algorithm rather than a `kdf($name, $params)` map, so each argument is type-checked where it
  is and a misspelt parameter can't be a key nobody reads; HMAC in C because PBKDF2 needs it
  there anyway, and at 600000 iterations only C is fast enough.
  - **Our own C, not OpenSSL**: from FIPS 180-4, RFC 2104, 8018, 7914, 9106 and 7693 (BLAKE2b),
    so a `TLS=0` build has all of it, the bootstrap still needs only a C compiler, and every
    platform gives the same bytes, as the rest of the language's rules do. Bytes are loaded into
    words of a stated order one at a time, so the host's order never matters. The defaults take
    roughly 0.15s (Argon2id), 0.25s (scrypt) and 0.3s (PBKDF2) on the development machine.
  - **Checked against the specifications and against code that shares none with it**:
    `tests/gaz/lib/crypto_test.gaz` holds every vector FIPS 180-4, RFC 4231, RFC 7914 (PBKDF2 and
    scrypt) and RFC 9106 give (scrypt's fourth, 1 GiB, runs unsanitized in `CryptoTest`), plus
    lengths either side of each block and padding edge; `CryptoTest` compares with PHP's hash
    extension, libsodium, libargon2 (`password_hash()` in both directions), Python's
    `hashlib.scrypt` and `openssl kdf` (lanes, secret and data, which libsodium lacks), skipping
    what the machine doesn't have. Its inputs come from a small LCG written on both sides, so the
    programs print only digests and are recorded and sanitized like any snippet. Planting a wrong
    constant, round count, padding edge, BLAKE2b counter, Argon2 index rule or lane XOR each failed
    both; the one mutant nothing sees, Integerify's high word, can't matter while scrypt's memory
    cap keeps N below 2^32.
  - **The builtins' limits are what C can do safely, not what a login should cost**: memory at
    most 4 GiB (RFC 9106's first recommendation is 2 GiB), so Argon2id has at most 524288 lanes
    (8 KiB each; RFC 9106 allows 2^24 - 1, which no memory under the cap could satisfy), outputs
    and `random_bytes` at most 1 MiB, iterations and passes at most 2^32 - 1, each refused before
    anything is allocated, as a catchable error; so is a size a 32-bit `size_t` can't hold, and a
    failed `malloc` of the big buffers, not `xmalloc`'s exit. A call within them can still take
    4 GiB and hours; bounding what a stored string may ask for is `verify_password`'s job. Memory is on the heap (the
    program's thread has a big stack, but 4 GiB is past it), and secrets are wiped by `wipe()`, a
    `memset` through a volatile pointer the compiler can't prove is one. Argon2's lanes are
    computed one after another: the result is a lane-parallel implementation's, and threads would
    only divide the wall time.
  - **`random_bytes` is `getentropy()`**, 256 bytes a call, as `rand_seed()` without a seed is.
    `vm/fuzz.php` skips programs naming it (what they print wouldn't follow from the seed) and the
    three password hashes (their cost is their arguments, so a slow one isn't a bug).
  - **`lib/crypto.gaz`** is what programs use. `hash_password` writes the PHC string format
    (`$argon2id$v=19$m=65536,t=3,p=4$<salt>$<hash>`, base64 without padding), which PHP,
    libsodium and libargon2 write too, so Argon2id hashes move between them; scrypt is
    `$scrypt$ln=17,r=8,p=1$...` and PBKDF2 `$pbkdf2-sha256$i=600000$...`, options named as the
    string names them. Defaults: Argon2id with 64 MiB, 3 passes and 4 lanes (RFC 9106's second
    recommendation, for when 2 GiB is too much), scrypt's and PBKDF2's OWASP's, a 16-byte salt and
    a 32-byte hash.
  - **`verify_password` gives false for any stored string it won't read**, as for a wrong
    password: malformed, not canonical (each parameter a plain decimal, in order), a salt under 8
    bytes, a hash outside 16 to 64 bytes (a 1-byte hash accepted wrong passwords one time in 256),
    or asking for more than `LIMITS`: about four times each default (Argon2id `m=262144,t=12,p=16`,
    scrypt `ln=18,r=16,p=4`, PBKDF2 `i=2400000`), so the worst a planted string costs is 512 MiB
    and some sixteen times a default login; a `$limits` argument changes them per algorithm.
    Wrong argument types, and limits naming what doesn't exist, still raise, being the program's
    mistakes. It derives as many bytes as the stored hash has. `needs_rehash` is true for a string
    it can't read and otherwise compares the header `hash_password` would write, ignoring the
    limits, which are about verifying.
  - **`crypto::equals` compares HMACs of both strings under a key drawn for the call**, not bytes
    in a loop: a loop would index one-byte strings, each made the first time its byte value is
    seen, which is a timing difference that depends on the secret, while the MACs' first
    difference is at a place nobody can predict or steer. It costs two HMACs and a `getentropy()`,
    and its time still grows with the lengths (a `ponytail:` there).
  - Hex and base64 are GazLang (a digest is 32 bytes; speed would matter only for bulk data,
    which nothing encodes yet), and decoding refuses all but the canonical spelling (padding,
    alphabet, leftover bits), so a value has one. They go through one-byte strings and
    `index_of`, which leak timing about the bytes; `ponytail:` comments mark each, and an encoder
    and decoder in C would lift them.
- `std_source($name)`: one file of the built-in standard library as text, or null (see below).
- `exit($code = 0)` stops with that code, 0 to 255, printing nothing and running no `finally`.
  Raising is the keyword `throw` (see "Errors").
- `builtins()` is that table as a map, in no promised order: the builtins of the runtime running
  the program, which the self-hosted parser checks calls against. That is right because the
  compiler always runs on the runtime that will run its output, and a loader refuses bytecode
  naming a builtin it lacks.
- **The standard library is built into the VM** (`vm/build/std.c`, made from `lib/*.gaz` by the
  Makefile with `od`, as the compiler's bytecode is), so a program in another repository reaches it
  by name and needs no path to this one: `include "std/json.gaz";`. The parser resolves it (a path
  starting with `std/`, or a plain name inside a library file, whose "directory" is `<std>`) through
  the `std_source($name)` builtin, which gives a file's text or null; a name is one file's, never a
  path. The VM, the bytecode and the collector learn nothing: the file is spliced in as any other, its
  location is `<std>/json.gaz`, and a name in angle brackets is what bytecode never rewrites, so
  bytecode runs from anywhere. **Embedding, not a search path** (Python's `sys.path`, Lua's
  `package.path`): one file, `bin/gaz`, works from any directory with no install layout to get
  wrong and no skew between a binary and the library it runs; the cost, a rebuild after editing `lib/`
  (a few seconds; the tests build it themselves), is met by `GAZLIB=lib`, a directory `std/` reads
  instead of the built-in copy, for working on the library. `std/` is reserved as a first path
  component; `./std/x.gaz` is a directory of your own. Everything in the repository loads the
  library by `std/`, as any other program would: the tests (`tests/gaz/lib` tests the built-in copy,
  which the tests build from `lib/`), the games, the website, the language server and `compiler/`,
  whose `std/chars.gaz` is the built-in copy of the `bin/gaz` that compiles it. Loading a library
  file by path as well would declare its names twice. `StdLibraryTest` checks that what is built in
  equals `lib/`.
- In GazLang instead, each its own namespace, so only what a file marks `pub` escapes it:
  `chars.gaz` (character classes), `sorting.gaz` (`sorting::values`, `sorting::by`, on `sort`),
  `lists.gaz` (plain functions over plain lists, not a wrapping object: a `Collection` kind would have to be a
  handle, since every `kind` is, reintroducing the "did I get a copy?" ambiguity `..` and value semantics
  exist to remove; the list is always first so each reads naturally after `|>`, and each key or predicate
  function is called once per element. `flatten`, `unique`, `max_by`/`min_by` (the first on a tie, so
  they replace a stable `sort(...)[0]` exactly), `group_by`/`count_by` (a map of key to group or count,
  insertion order kept, keys ints or strings as a map's are), `partition` (`[$matching, $rest]`, pairs with
  a list pattern), `chunk`, `zip` (stops at the shorter list, Python's rule, said in its own doc comment),
  `take`/`drop` (named wrappers over `slice()`, for how they read mid-chain, and unlike it an error for a
  negative count), `pluck` (a list of maps only: `$obj.$name` doesn't exist yet, so a list of objects isn't
  reachable until it does), `sum_by`/`avg`/`avg_by` (`avg` is always a float and an error on empty),
  `first` (an error on empty, mirroring `last()`), `find` (`null` on no match, since not-found is an ordinary
  outcome, not a bug) and `contains_by` (`in_array`'s predicate-based sibling). A list helper goes here
  rather than into the builtins, since a builtin takes its name from every program and a namespace only
  from those that include it),
  `text.gaz` (`text::quote`: a value as a literal for a message; `text::lines`: what `read_line()` reads, as a list, so `text::lines(read_stdin())` is a
  `gaz -e` one-liner's input: `split($s, "\n")` leaves a trailing `""` after a final newline, and "\r\n" is
  stripped only where a "\n" follows, as `read_line()` and `file_read_line()` do; `text::indentation`
  and `text::unindented`, a line's leading spaces and tabs, which the website's Markdown and highlighter
  read),
  `format.gaz` (`format::number`, `format::pad_left`/`pad_right`
  convert like echo: display helpers take any value, string functions stay strict;
  `format::sprintf($template, $args)`, a list since there are no variadic calls: `%s` echo's
  text, `%d` and `%x` ints only (`%x` of a negative is an error, not two's complement), `%f`
  through `format::number()` so it rounds as `round()` does and never by the platform's printf,
  which caps it at an int's worth of digits and 18 decimals (`ponytail:`); `-` beats `0`, as in C;
  every placeholder is read and the count checked before anything is formatted),
  `json.gaz`, `csv.gaz` (RFC 4180), `db.gaz` (see Databases above), `web.gaz` (`web::html"..."`,
  see "Templates"), `http.gaz` (method and header names checked
  as HTTP tokens and URLs for spaces and control characters, so nothing can end a line of the
  request; credentials dropped on a redirect to another origin; `HttpTest` runs it against
  `tests/fixtures/http_server.php`, over TCP and TLS, which writes framing out by hand so it can
  get it wrong on purpose; ports vary, so what it prints is checked by shape, not recorded; the
  server, `http::serve`; and `http::Router`, routing; see "Serving HTTP" for both),
  `date.gaz` (`date::days`, `date::civil`, `date::format`: a date is a number of days from 1 January
  1970, with no clock, since a program that asked one what day it is could not be recorded, so a game
  keeps its own date), `cli.gaz`
  (`cli::Command`, see "Command line arguments"), `test.gaz` (see `gaz test`),
  `random.gaz` (`random::shuffle`, `random::pick`, `random::key`, `random::chance`,
  `random::weighted`), `crypto.gaz` (see "Cryptography" above), `term.gaz` (`term::style`, the cursor and screen sequences,
  `term::decode`, `term::Input`, `term::fullscreen`, on the terminal builtins; drawing functions
  return their sequence, so a program prints them and a test compares them), `tui.gaz`
  (`tui::Screen`, `tui::Rect`, `tui::box`, `tui::label`, `tui::progress`, `tui::table`, `tui::Table`, `tui::Menu`,
  `tui::TextField`, `tui::choose`, `tui::ask`, `tui::interact`; everything draws into a `Screen`, a grid
  that `render()` diffs against what it last drew, so a program redraws it all every frame and a test
  reads `lines()` without a terminal). Scan long strings with `index_of`, not a character at a time.

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
- **`shared $x = value;` makes a variable that closures share** with the function they are
  written in, instead of each owning a copy: `shared $events = []; $hear = $e -> { $events[] = $e; };`
  fills the list the outside reads. **Parser sugar only** (`shared_declaration()` and
  `variable()` in `parser.gaz`): the statement assigns a `Shared` box, a builtin kind holding one
  `value` and compiled only into programs that use it, to a hidden `$#shared_x`, and every later use
  of `$x`, in the function and in the lambdas written in it, is `$#shared_x.value`. That is an
  ordinary field, so write paths, `++`, `??=` and `delete` need nothing, a lambda captures the box (a
  handle) by value like any variable, and the VM, the bytecode and the collector learn nothing.
  - **A declaration and a keyword, not a mark on each use or on the closure**: a `$$x` sigil is
    wanted for variable variables; PHP's `use (&$x)` on the closure makes every use of the variable
    in the scope around it quietly a shared one, and a closure that forgets it reads a snapshot;
    and it is the declaration, which makes a new box each time it runs, that gives each pass of a
    loop and each call of a function its own variable.
  - **Refused when the file is read**: a name already shared, or already used or a parameter (or a
    second variable of one name would appear), a lambda parameter with a shared name, and a shared
    variable as a `foreach` or `catch` variable; a value is required. A named function starts with no
    shared names, a lambda keeps those around it and may declare its own. A shared variable stays a
    value: a list is copied when passed on.
  - **`variable()` counts each plain use**, so a `shared $x` after one is refused; a lambda's
    parameters are read as expressions before the `->` shows what they are, so `lambda()` takes
    them back (`forget_uses()`), which a test with `$x -> ...` before `shared $x` found.
  - `Shared` is a builtin kind name like `Error`, and `shared` a reserved word.

## Objects

Declared fields and single inheritance give every kind a fixed layout, so fields are slots
and methods a table in C.

```
abstract kind Shape {
    #name;
    fn _($name) { #name = $name; }
    pub abstract fn area();
    pub fn to_string() { return "{#name} with area " .. #area(); }
}

kind Circle extends Shape {
    pub #radius;
    #history = [];                            // evaluated for each new object
    fn _($radius) {
        ##_("circle");                        // the parent's constructor
        #radius = $radius;
    }
    pub fn area() { return 3.14159 * #radius * #radius; }
    pub fn to_string() { return ##to_string() .. " (r = {#radius})"; }
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
  Point` prints `kind Point`. A call by name is checked like a function call. `class` is an
  ordinary name; a kind is a kind of thing, and `"kind"` is what `type_of` gives.
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
  kind. One keyword for the whole language.
  - **The ladder is unmarked (mine) → `kin` (mine and my children's) → `pub` (anyone's)**, and
    it reads the same on every sort of member: `kin #energy = 100;`, `pub static #tally = 0;`.
    `kin` is for what a kind declares on its children's behalf, which is why `protected` earns
    a rename where `extends` does not: it protects less than the default does, and a level is
    better named after who can see it. `kin` and `kind` are one root (kin, kind, kindred).
    `pub` and `kin` are the whole vocabulary of markers: `public`, `protected` and `private`
    are ordinary names, so writing one is the plain error any stray name gets, and a reader
    learns one spelling. Only `interface`, `implements` and `final` are reserved (decided, not
    built), and say so wherever they land: where a statement, a member (after a marker too) or
    an expression starts (`refuse_reserved_member()`).
  - **The asking kind is where the code is written**, not what the object is: `#name`,
    `##name` and a constant or static reached by name (`Kind::NAME`, `Kind::name`, whichever
    way it is used: read, written, called, taken as a value, piped into) are checked at parse
    time, `$obj.name` when it runs, and a lambda's and a static method's asking kind is the kind
    they sit in. Code outside every kind (the top level, a function, a template) has none, so
    reaches only what is `pub` (`check_member_escapes()`, from the kind each use in `#uses` was
    written in): `Limits::MAX is not pub, so only Limits can use it`, `Limits::MAX is kin, so
    only Limits and what extends it can use it`, the runtime's words for `.name`. A kind's own
    private member named through a child (`Tally::n` in `Counter`) is its own, though the child
    doesn't carry it (`as_asked()`). A block's header carries it (`in Kind`, or
    the dot in a method's name), so the VM pays nothing until a member is looked up.
  - **A parent's private member is the parent's own.** A child can't name it, and may declare a
    method, constant or static of its own by that name: both entries live on and each kind's
    code reaches the one it can see, which is why a method table can hold two of one name (the
    child's declared by the child, not an override, so it takes any marker and its own children
    override it; `claim()` in `parser.gaz`). A
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
- **`?.name` is `.name` unless the object is null**, when the whole postfix chain it is in is
  null and nothing after it runs, arguments included (JavaScript's, C#'s and PHP 8's rule, not one
  step at a time). Only null is skipped, so a missing member or an unset field is still the error
  it is after `.`, which is what `?.` adds over `$x.name ?? null`. **Parser and code generator
  only**: the lexer glues `?.name` into one `NULLSAFE_PROPERTY` token as it does `.name`, the parser
  makes a `NullsafePropertyAST` (a `PropertyAST`, so everything that asks `is_a` treats it as a
  member) and wraps the whole chain in a `NullsafeChainAST`, which no write path accepts, and each
  `?.` compiles to `JNN go; PUSH null; JMP chain_end` with the chain's end label on a stack
  (`nullsafe_ends`), so the VM and the loader learn nothing. On the left of `??` the chain is read
  quietly, as `.` is there.
- `is_a($x, Kind)` tests the kind and its parents; `kind_of($x)` is the object's own kind
  (strict: anything else is an error), so `match (kind_of($n)) { NumAST => ... }` dispatches
  a pass written outside the node kinds. `fields($object)` is a map of the set fields, in
  print order, without `#`, so a pass can walk a tree without knowing its kinds.
- **`to_string()` is the one protocol method**, used by echo, `..`, interpolation and `join`,
  also inside lists and maps; it must return a string and take no arguments. Without one an
  object prints as `Account {#owner => "Werner", #balance => 75}`, and one already being printed
  as `Account {...}`.
- **`json::encode` writes an object as what its `pub fn to_json()` returns** (a value to encode,
  not JSON text), recursively, and `json::decode` only ever gives maps and lists: a kind that can be
  read back has a `static fn from_json($data)` by convention, which nothing calls for it. A
  protocol, like `to_string()`, rather than a callback at each call (every caller would have to
  remember it) or writing `fields()` (which shows private fields on purpose, so an API would leak
  a password hash the day someone returns the object, and would duplicate shared references).
  Decoding into kinds by type tags is refused for good: a document choosing which kinds are built
  is PHP's `unserialize` object injection. A missing `to_json()` is the runtime's own `Team has no
  member to_json`; an object inside its own is an error by `object_id()`, not a loop. Kinds and
  functions are still refused. GazLang only (`lib/json.gaz`). A `to_json()` whose data must also
  compare with `==` or round-trip without JSON (the football game's tests do both) returns plain
  data all the way down rather than leaving nested objects to the encoder.

## Command line arguments

`lib/cli.gaz`: a program declares what it takes and `cli` parses `args()`, writes `--help`, and
turns a mistake into a message and an exit.

- **Builder methods** (`$cli.flag(...)`, `option`, `argument`, `optional`, `rest`), not a spec map,
  so a misspelt method is caught when the program is read and each piece of help sits by what it
  describes. A declaration that can't work (a second `--verbose`, `-h`, an argument after
  `rest()`, a required one after an optional one) is an ordinary error: the program's mistake,
  not its user's.
- **It exits for the program**: `--help`/`-h` print the help on standard output and exit 0, a
  mistake prints what is wrong and `Run 'tool --help' ...` on standard error and exits 2, the
  Unix code for usage. Every tool wants that, so none repeats it; `try_parse()`/`try_run()` raise
  a `cli::Stop` (its `#code` and the text) instead, for tests.
- **Subcommands have handlers**: `$cli.command("add", "...", $handler)` is a `Command` of its own,
  and `$cli.run(args())` parses and calls the handler named with everything parsed, its parents'
  flags and options included (which are accepted after the command word too, and shown in its
  help). A command with subcommands takes no arguments of its own.
- **GNU's rules**: `--file x`, `--file=x`, `-f x`, `-fx`, `-vq`; options and arguments in any order;
  `--` ends the options; `-` alone is an argument; an option given twice keeps its last value,
  as a query string does. Values are strings; a flag is `true`/`false`, an option not given its
  default (null unless one was given).
- `ponytail:` a negative number as an argument needs `--` first (`-5` is read as an option); no
  "did you mean" for an unknown option; values aren't typed (`to_int($args["n"], null)`).
- Tested by `tests/gaz/lib/cli_test.gaz` and by `CliTest` running `tests/corpora/cli/todo.gaz` for the
  exits and which stream each goes to.

## Templates

```
@template user_page($user, $posts)             // views/user.gazml: the function it becomes
<h1>{{ $user.name }}</h1>                      // escaped, unless it is Html
@foreach ($posts as $post)
  <li>{{ $post.title }}</li>
@endforeach
{!! $trusted !!}                               // as it is, whatever it is
```

- **A `.gazml` file is compiled by the compiler when it is included**, into a function returning
  `Html`, so any GazLang expression works in it, a mistake is an error when the program is read,
  and nothing is parsed at run time. `compiler/template.gaz` translates it into GazLang source,
  **one line of source for each line of the template**, which is then lexed and parsed like any
  file: every error, the parser's or the running program's, is at the template's own line, and
  the code generator, the VM and the bytecode learn nothing. Not a library: GazLang has no eval,
  so a library could only offer logic-less templates.
- **The first line declares it**: `@template name($params)`, so a template has real parameters
  (defaults included), a wrong call is caught when the program is read, and its file name is free.
  - **A template may start with `namespace name;`** (`is_namespace_line()` in `template.gaz`), passed on
    as the first line of the generated source so the parser checks the name and the rest of the
    file is read in that namespace: the function is `name::template`, its parameters' types resolve
    there, and it is private to it unless written `@template pub name(...)`. A template can't
    `include`, so before this it could name no kind of a namespaced program (`?User $user` was
    `Undefined type`, and `?todo::User` was `Namespace todo is not included here`) and every template
    was a global function; both were found by `apps/todo`. Still one line of source per line of
    template, the namespace line included. `ponytail:` a template still can't `include` or `use`, so it
    names no other namespace (`format::number` in one is `Namespace format is not included here`, even
    if the program includes it): a function of its own namespace that calls the library is the way
    round, and an `@include` directive the lift. Tested by `tests/gaz/templates/namespaced_test.gaz` and the
    `template_namespace` and `error_template_*` corpora.
- **Blade's syntax**: `{{ }}` escapes, `{!! !!}` doesn't, `@if`/`@elseif`/`@else`/`@endif` and
  `@foreach`/`@endforeach` alone on their lines (a line holding one writes nothing, and a line
  holding only a `{{-- comment --}}` nothing either), `@{{` for a literal `{{`. Another `@word`
  is text, so CSS's `@media` and an email address need nothing. The translator checks the
  blocks nest, so a mismatch is a sentence about the template, not a parse error in source the
  user never wrote.
- **A template's result is safe HTML, the builtin kind `Html`**, and `{{ }}` leaves an `Html` as
  it is (`Html::escape()`), so one template includes another (`{{ header($title) }}`, a layout
  given a page) with no marker. `{!! !!}` is then rare, which is the point: each one stands out
  in review, where if every include needed one, a `{!! $comment !!}` would hide among them. It is
  how Rails, Django and Jinja stay safe. `Html($text)` marks text as trusted; `http::serve` takes
  an `Html` body, as `text/html; charset=utf-8` unless a Content-Type is given.
- **Concatenating an `Html` is an error** (`Cannot concatenate Html: build it with
  web::html"...", or use .text for its markup as a plain string`), since the plain string it
  gave was escaped again by `{{ }}`: `..`, `..=`, `join()` and so interpolation, either side,
  a kind extending `Html` too, and never its contents in the message. The loader notes the kind
  named `Html` (`html_kind`, as `error_kind`) and `append_joined()` in `value.c` refuses it on
  the conversion path, one tag compare ahead of echo's conversion (the `strings` and `objects`
  benchmarks didn't move). `echo`, `print`,
  `to_string()`, `.text` and `format`'s helpers (which call `to_string()`) make no `..` and still
  give the markup; `{!! !!}` writes `to_string((...))` for the same reason. Any other object with
  a `to_string()` concatenates as before.
- **`web::html"..."`** (`lib/web.gaz`, `namespace web`; not the `Html` kind and not a global
  `html`, so it takes no name from a program) is an `Html` from a tagged string, for markup built
  in code: the text parts as they are, since the program wrote them, and each value written for the
  place it lands. **The tag gets the text and the values apart, so it reads the text as a browser
  would** (`Scanner`, in the same file, over the tokenizer's states: text, tags, attribute names and
  quoted, unquoted values, comments, declarations, raw-text elements, `<textarea>` and `<title>`,
  `<svg>` and `<math>` as foreign content) to find where each value falls. No VM or parser change.
  - **Text and `<textarea>`/`<title>`**: an `Html` as it is (so fragments nest) and anything else
    echo's text escaped (`& < > " '`); a list is its elements joined with nothing between, each by
    the same rule; a map and a list inside a list are errors (`web::html can't put a map in markup:
    ...`), having no one obvious text. **A quoted attribute and a comment**: escaped, never markup,
    so an `Html` there is an error (an `Html` holding a `"` would end the attribute), and so is a
    list or a map.
  - **A URL attribute** (`href`, `src`, `action`, `formaction`, `poster`, `cite`, `data` and the
    like): at its start the value is escaped and must be relative or `http`, `https`, `mailto` or
    `tel`, **else it is `about:invalid#blocked`**, not an error, since a bad link typed into a
    profile field shouldn't 500 the page and the result is still safe (Go's `html/template`, Angular
    and templ all neutralise too). The scheme is read as a browser reads it: tabs and line breaks
    removed, leading spaces and control characters skipped (`"java\tscript:"` is `javascript:`). After
    the start (the program's text before the value holds a `/`, `?`, `#` or `:`) it is
    **percent-encoded** byte by byte, an int as it is. **Whether a value is at the start is read
    from the program's text, not from earlier values**, so the scan doesn't depend on what a value
    holds and can be kept: with other text before it and no delimiter (`href="java{$x}"`) or two
    values together at the start it is refused, since the value could finish a scheme. **A value at
    the start of a URL is refused too when the text right after it begins with a `:` or an `&`** (a
    character reference can be a colon): `href="{$a}:{$b}"` with `javascript` and `alert(1)` is two
    harmless values that make a script URL once a browser percent-decodes the second, which the
    scheme check on the first can't see (`after_start`, checked as the next text is read).
  - **Refused**, each saying why: a value in an unquoted attribute, in `<script>`, `<style>`,
    `<xmp>`, `<iframe>`, `<noembed>`, `<noframes>` or `<plaintext>`, in an event handler (`on...`) or
    a `style`, `srcdoc`, `srcset` or `ping` attribute, in a tag or attribute name or between
    attributes, in an end tag or a declaration, right after a `<`, `</`, `<!` or `<!-` or inside the
    end tag of a `<textarea>` or `<title>` (it could finish one), in a URL that loads code or a
    document (`script src`, an svg `script`'s `href` or `xlink:href`, `iframe src`, `frame src`, `embed
    src`, `object data`, `link href`, `base href`, where a URL isn't enough to trust), or in a URL whose program text already starts with
    another scheme (`href="javascript:{$x}"`), and, without a value at all, **a `<script>` holding both
    `<!--` and `<script`**, which a browser doesn't end at the first `</script>` and the end tag search
    here would. A comment ends at `--!>` as well as `-->`, and an `=` where an attribute name should
    start is part of the name, as the tokenizer reads them. **Each tagged string is read from the start as text
    and must end as text**; an `Html` value is taken to be whole markup. Errors are the call's, at
    run time, as `db::sql`'s checks are; the way round is deliberate: write the text in the program
    or build an `Html` and pass it in.
  - **Scanned once for each tagged string** (`Scans::of`, a static map keyed by the parts as a
    literal, which tells `["a\0b", "c"]` from `["a", "b\0c"]`, emptied at 2000), since the parts are
    literals in the source: a tag site costs one scan, and a call costs about a microsecond more
    than plain escaping (a loop of links measured +4%, text-heavy +15%). Percent-encoding was the
    slow part (a call per byte); `contains()` against the whole allowed string and an int fast path
    brought it level.
  - **Checked against an HTML5 parser that shares no code with it** (`tests/HtmlContextTest.php`,
    PHP's `Dom\HTMLDocument`): each template with ~45 hostile values must give a page of the same
    shape as with a harmless value (same elements, attribute names and comments), with no URL that
    runs script because of a value, and a text value read back exactly; the refused templates must
    be refused for every value; and **templates stitched together at random from pieces of markup
    must be refused or written safely**. That test only sees unsafe *acceptance* (refusing more is
    always safe), so the hand-written cases cover over-refusal; each rule was broken on purpose and
    caught, and the first version of the random one missed seven of twelve such breaks until its
    pieces and count grew. **Its templates use one value for every placeholder, so it can't see two
    harmless values make something together**: `test_two_values_cannot_make_a_script_url_between_them`
    fills two-value URL templates with every pair of 22 scheme fragments. URLs are compared with the
    harmless page's where the template writes a `javascript:` link itself.
    `tests/gaz/lib/web_context_test.gaz` has the rules and every message written out, and
    `tests/gaz/lib/web_test.gaz` and `tests/gaz/templates/web_html_test.gaz` the rest.
  - `ponytail:` no `<script>` or `<style>` data (a `web::json` helper is the way, when a program
    needs one); a `<meta http-equiv="refresh" content="0;url={$u}">` isn't read as a URL; text
    split across a value (`</scr{$x}ipt>` in a part that a browser would read as one tag) is
    only refused where a value could finish it; `<noscript>` is read as markup.
- **`Html` is a builtin kind in `BUILTIN_SOURCE`**, like `Error` and `Shared`, compiled into a
  program that includes a template or names `Html` itself (not because its own code does). It is
  the first builtin kind with a static method, which `program()` places itself, since only
  `top_level()` puts a kind's static methods after it.
- **The output is gathered in `$#html`**, a name no program can write: the lexer reads it only in
  source translated from a template (`Lexer($text, true)`).
- `ponytail:` an expression ends at the first `}}` or `!!}`, so it can't hold one, and none spans
  lines; escaping is for HTML text and quoted attributes, not JavaScript or URLs inside a page.
- **Templates are frozen for now**: no new template features until a real web app has used them
  (the repository's own use is the website's seven, about 130 lines, and `.gazml` costs
  `compiler/template.gaz`, include handling, a docs section and some thirty corpus files). They are
  the right shape for whole pages, with loops, conditions and layouts that `web::html` would make
  nested `map` calls, and their errors are at the template's own line, so they stay; but they don't
  read the markup around a value, so **a URL in one isn't checked** (`web::html` does). If the app
  keeps them, the compile-time version of the same scan (the text is known when the program is read,
  so each `{{ }}` can pick its escaper at no run-time cost, and a mistake can be an error then) is
  the next piece, sharing the scanner with `web.gaz`; if it never reaches for them, delete them.

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
- **Kind constants** are `#NAME` inside and `Kind::NAME` outside (which needs `pub`, or `kin`
  in a kind that extends it: a constant is a member like any other), inherited, and **can't be
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
  compound operators or append targets. **An empty slot skips an element**: `[, $b] = $pair`,
  `[$a, , $c] = $row`, in a `foreach` too. The element is there and counts (`[, $b] = [1, 2, 3]` is the
  usual error), and nothing is done with it; `[$a, $b,] = ...` is still two elements, as a trailing comma
  is in a list, so a slot at the end is `[$a, $b, ,]`. The parser reads a slot as an empty entry in the
  list literal (`list_literal()`) and a pattern takes it as a `null` target (`list_pattern()`, the code
  generator skips it); a literal that keeps one without becoming a pattern is an error once the program is
  read, and a pattern of empty slots alone has nothing to take apart.
- **A parameter can be a list pattern** of `$variables`, in a lambda, a function or a method
  (`([$name, $ties]) -> ...`, `fn distance([$x1, $y1], [$x2, $y2])`): one argument, taken
  apart as `[$a, $b] = $arg;` would, with destructuring's errors, and a default allowed. It is
  parser sugar, like a promoted parameter (`pattern_parameter()` in `parser.gaz`): a hidden
  `$#pattern_N` parameter in its place and the destructuring prepended to the body, an
  expression body becoming a block that returns it, so the variables are the call's own and a
  lambda never captures them. Nothing below the parser learns of it. **A lambda's one list pattern
  needs no parentheses**: `[$a, $b] -> $a + $b`, since the brackets already say it is a parameter
  (`ternary()` sees a list literal followed by `->`); two parameters, or a default, still need them.
- `..=` appends in place (`concat_assign()` in `ops.c`), on a variable, a field or an element
  alike, converting what is appended as `..` does, so building a string with it is linear
  rather than a copy per append.

## Types

```
kind Account {
    pub float #balance = 0;                   // a field's type before the #
    static int #made = 0;
    fn _(pub string #owner, int|float $start = 0) { #balance = $start; }
    pub fn deposit(int|float $amount): float { #balance += $amount; return #balance; }
    pub fn close(): null { }                  // returns nothing: null is the value's type
}
fn total(int $n, ?string $label = null): int { return $n * 2; }
$double = (int $x) -> $x * 2;
```

- **Optional, and checked at run time**: leaving a type out means anything, as before, so
  untyped code compiles exactly as it did and pays nothing (the benchmarks are unchanged). A
  typed parameter or return is an instruction in the function (`CHECK_PARAM` after the
  defaults have run, `CHECK_RETURN` before every `RET`, the implicit `null` at the end
  included, and after the function's own try blocks are left, their finally blocks run, so its
  own catch can't swallow the error meant for its caller), a typed field or static field a word on its record in the bytecode, checked in
  the two places a field write goes through (`SET_FIELD` and `write_path()` in `ops.c`) and in
  `STORE_STATIC`. Records rather than instructions for fields, since a field is written by many
  instructions and from outside its kind; instructions rather than records for parameters and
  returns, since `CALL` and `RET` are the hot path and a record would put a test on every call.
- **PHP's syntax**: the type before what it types, the return after the `)`, `?T` for `T|null`,
  `A|B` for a union. Fields, static fields, promoted constructor parameters (`fn _(pub string
  #owner)`, the field's type too) and lambda parameters take one. Not a constructor's return
  (constructing gives the object) and not a lambda's: after `(...)` a `:` is a ternary's else
  (`$c ? ($a) : $b`), and telling the two apart would take lookahead past the type. Not a list
  pattern parameter: it is already a list, and its variables are assigned by taking it apart.
  A lambda's typed parameters are read as an expression first (`int|null` is two bare names
  and a `|`) and taken for a type when a `$parameter` follows, which no expression can be
  followed by (`lambda_item()` in `parser.gaz`); a type before a pattern parses as an index
  and gives the `]` error, which is the price of no lookahead.
- **The names are `type_of()`'s and the kinds'**, resolved through namespaces like any name
  (`TypeAST`, resolved in `resolve_names()`, checked to be kinds in `check_types()`). A kind
  can't be named after a builtin type, so `int` in a type always means the type. `kind` and
  `null` are keywords, so `type_name()` takes those tokens too. No `mixed`: leave
  the type out. **No generics yet**: `list<int>` is refused with a message, since checking an
  element type at run time would walk the list on every call; generics come later as a static
  check only.
- **Strict, never converting, with one exception: an int where `float` is asked arrives as a
  float** (`type_admits()` in `ops.c` widens it in the slot, the stack or the field), in a
  parameter, a return and a field alike, because every int is a float and a program that
  computes an area shouldn't have to write `2.0`. Nothing else converts: `"5"` is not an int and
  `2.0` is not an int, which is the language's rule everywhere else (`==`, `+`), and a type that
  converted would be one more place a string quietly became a number.
- **`: null`, not `void`**, for a function that returns nothing: a call that returns nothing gives
  `null`, so the type is the value's type, with no rule of its own. A bare `return;` in a
  function whose return type excludes null is a parse error, since it could never pass.
- **Errors are catchable runtime errors** located at the check, the trace showing the caller:
  `total() expects $n to be int, got string`, `Account.deposit() expects ...`, `Account()
  expects ...` for a constructor (named as the call that makes the object), `-> at f.gaz:3
  expects ...` for a lambda (as traces name one), `total() should return int, got string`,
  `Account #balance must be float, got string`, `Account::made must be int, got string`. An
  object is named by its kind (`got Circle`), since `object` would leave out what the check
  was about (`describe_type()`).
- **An override keeps the parent's types** where the parent declares them (the same type on
  each such parameter and on the return, `check_override_types()`), the restrictive choice:
  loosening it to covariance later breaks nothing. Where the parent says nothing the child may
  say what it likes, so a typed kind can extend an untyped one. Constructors are exempt, as
  they are from the argument-count rule.
- **The tree dump shows types only where they are**: `param_types`, `return_type`,
  `field_types` and `static_types` are fields set only when a declaration has a type, since
  `fields()` leaves out what was never set, so an untyped program's `--ast` is what it was.
- Templates get types for free: `@template page(User $user): Html` is copied into the `fn`
  the template becomes.

## Errors and try/catch

```
kind NotFound extends Error {
    #key;
    fn _($key) { ##_("Not found: {$key}"); #key = $key; }
}

try {
    throw NotFound("id");                     // or any runtime error, or throw "text"
} catch (NotFound $e) {
    echo "{$e.message} ({$e.key}) at line {$e.line}";
} catch (Error $e) {
    echo $e.message;                          // division by zero, a missing key...
} catch ($e) {
    echo "something else was thrown: {$e}";   // throw 5, throw [1, 2]
} finally {
    echo "always";
}
```

- **Catchable**: every runtime error (including running out of call depth) and anything thrown
  with `throw`. Syntax and include errors happen before the program runs. `return`, `break`,
  `continue` and `exit()` are not errors.
- **`Error` is a builtin kind** written in GazLang (`BUILTIN_SOURCE` in `parser.gaz`, located
  as `<builtin>`): `#message`, `#file` (null for piped input), `#line`, `#trace`, `_($message)`,
  `to_string()`. Programs extend it; runtime errors and `throw "text"` are caught as `Error`.
  It is compiled only into programs that can catch, name or extend it: a program that throws
  but never catches doesn't need it, since only a catch turns an error into an object.
- **`throw $value` raises any value**: a string becomes an `Error`'s message, anything else is
  caught as it is. An `Error` gets its location and trace where it is first thrown, so
  `throw $e;` rethrows keeping them. Uncaught, gaz prints `Error: ` and the value as echo
  would, with no location (runtime errors keep theirs), and the text is made only then, so
  throwing never runs `to_string()`. It compiles to `THROW`, which ends its path as `RET` does.
- **A keyword, not a builtin**: raising is control flow, like `return`, and a reader (and the
  loader's stack walk) should see that nothing after it runs; a builtin also takes its name from
  every program, and `error` is a name programs want. **Replacing `error()`, not joining it**:
  two spellings of one thing would be the first thing a style guide had to settle. A call to an
  undeclared `error()` says to write `throw` (`undefined_hint()` in `parser.gaz`); a program may
  declare its own `error`.
- **An expression, not only a statement**, because raising is often the fallback of a value:
  `$m[$k] ?? throw NotFound($k)`, `default => throw "Unknown cell"` in a `match`, a ternary's
  branch, a lambda's body. It is parsed where a lambda is, at the start of `coalesce()`, so it
  can begin any expression down to `??`'s right side, and its operand is a whole expression
  that extends as far right as it can (`throw $a ?? $b` throws whichever is there), as a
  lambda's body does. As the operand of anything tighter (`$ok || throw ...`) it is refused
  with a message saying to parenthesise it, since `1 + throw $e` reads as a mistake. `throw;`
  is an error: a rethrow names what it throws.
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
  the innermost node that has one. A thrown string's message is printed as it is, without a
  location, which `catch` still sees. Include paths show relative to the working directory,
  the main file as given.
- **Code generation**: calling convention is arguments pushed left to right then `CALL name
  argc`, the callee's frame holding them in slots 0..argc-1, `RET` pushing the result. `..=`
  appends in place, to a plain variable with `CONCAT_ASSIGN` and to anything else with a
  `SET_PATH` whose path ends in `..=` (`store_path()`); only a value printing through
  `to_string()` joins first and writes again from the start, since running it could move what
  the walk points into. Other compound assignment, `++`/`--`, list patterns and `??=` are
  lowered to plain instructions with hidden variables (`$#update_*_n`, `$#destructure_n`,
  `$#match_n`, `$#finally_error_n`) that no program can name. `foreach` keeps what it iterates
  and an int position in two such variables (`$#foreach_*_n`) and steps with `FOREACH_NEXT`
  (`_KEY` when it wants the key), which reads the element in place: lowering it to a loop over
  `keys()` made a list of keys per loop and two `INDEX_GET`s per element. The hidden
  variable's reference is the snapshot, since a write elsewhere copies a shared list or map first;
  when the loop runs out the instruction nulls it, so the next write to the original needn't copy
  (a loop left by `break`, `return` or `throw` keeps it until the frame ends, as before). Lists and maps
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
  Each block's header ends with a `locals` line naming its slots.
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
  error, `CALL_METHOD` whether it has a method entry over an object, `CALL_CONSTRUCTOR` whether the kind has a constructor (a method table isn't built when the instructions are walked, and visibility decides what an entry is), and `ARRAY_PUSH`,
  `ARRAY_EXTEND` and `MAP_SET` whether they are building a list or a map, as `ADD` asks what
  it is adding, because a finally block stores the error in a local and loads it back. Peephole rewrites belong to loaders, not the format.
- **A stack machine**, not registers: the compiler is the part written in GazLang, a stack
  machine's is much simpler, and a loader can add superinstructions without touching the format.

## The self-hosted front end

- **Its shape, and why**: the lexer's scanner is an object, because `include` needs two lexers
  alive at once; its operators are one table matched longest first. The parser's twelve binary
  levels are one table and a loop (precedence climbing) rather than a method each, 28% faster.
  `lambda_head` is one field, since nothing is read between marking a `(` and asking. A member
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
  (`throw $e.message`), so `--tokens` and `--ast` print only the message; a bug in a port is a
  different error and still arrives with its trace. `LexError` carries `#reason` and
  `#source_line`, since `#line` is where in `lexer.gaz` it was raised. The one `try` in the lexer
  (`hex_value()`) holds only the arithmetic it is about, since `catch (Error)` also catches
  running out of call depth.
- **What it leans on instead of writing out**: `slice(to_string([$v]), 1, -1)` is a value as a
  literal (a string quoted, which is the inverse of reading one), `..` on a float formats it,
  `to_int($text, null)` is the overflow check.
- **Paths** resolve with `cwd()`, `real_path()` and `file_exists()`; the parser harness also
  runs from other working directories and with an absolute include.

## The C VM

- **The CLI** parses options as PHP's `getopt` does, plus the check for unknown ones: options
  end at `--` or the first non-option; `-f` takes the next argument whatever it is. Then, unless
  `--` ended them or `-f` gave a file, **the first argument is the file** (`gaz main.gaz a b`, as
  Python, PHP and Node take it) or `-` for the program on standard input, and the rest are the
  program's. Standard input is the program's whenever a file is given; piped source with bare
  arguments is `gaz - a b` or `gaz -- a b`. The lexer skips a `#!` first line, so
  `#!/usr/bin/env gaz` scripts run. Bytecode is recognised by its first line or a `.gzb` name. `-c`, `-t` and `--ast` run the
  built-in front end in that mode; running source runs it in `code` mode first. With no file and
  a terminal on stdin it prints the help to stderr and exits 1: there is no REPL (running each
  line as its own program wouldn't be one).
  - **`gaz -e CODE`** (`--eval`, `-eCODE`; more than once, each a line joined by `"\n"`) runs text
    given on the command line: the same path `-S` and `gaz test` take (`job.text` set, no path, so
    the front end reads it as piped source, which it consumes before the program starts), and
    **`main()` never reads standard input for it**, which is the whole point: `read_stdin()` and
    `read_line()` find the data that was piped in. Nothing in `compiler/` knows about it. Every
    argument left after the options is the program's `args()` (never a file), refused with a file,
    `-`-as-file (`-f`), `--watch`, `-S` and `--tty`, and `-e` with no code. Errors are "on line N",
    as piped source's are, not a made-up file name, which would break resolving includes from the
    working directory. **Plain program semantics, on purpose**: no implicit `echo` of a last
    expression (a rule that changes what a statement does by where it sits), no `-n`/`-p` line loop
    (C would wrap the text, shifting line numbers and inventing `$line`), and no prelude of
    libraries (implicit, and about 70ms a run): `include "std/lists.gaz";` is the price, and a
    `lists::x` without it says so (`library_hint()` in `parser.gaz`). `ponytail:` code shows in
    `ps`; no columns in an error.
- **`gaz --watch app.gaz ARGS`** (`watch.c`) runs the program and runs it again whenever a file it
  is made of changes. A supervisor in C that runs no GazLang: it starts `argv[0]` again (spawnp,
  so a bare `gaz` is found on the PATH as the shell found it; portable, where `/proc/self/exe` and
  `_NSGetExecutablePath` are one system each) as `gaz -f FILE -- ARGS`, in a process group of its
  own so a `workers()` master and its workers stop together. A file, never piped source, and not
  with `-c`, `--tokens` or `--ast`.
  - **What is watched is the files the program is made of**, not a directory: the main file and
    the distinct paths of the `@` lines of a `gaz -c` of it (`source_files()` in `load.c`,
    resolved and shown as the loader shows them, `<builtin>` and `<std>` left out), so templates
    are watched and the built-in library isn't. Worked out again before each start, so a new
    include is watched from its first run; a compile that fails keeps the last list. Compiling
    twice costs the restart one compile, but needs no change to the front end. `ponytail:` a file
    that leaves no instruction (only constants) isn't watched.
  - **Polled every 0.25s**, modification time (nanoseconds too), size and inode, portable where
    inotify and kqueue are one system each. A change or a file gone restarts: SIGTERM to the
    group (graceful for `workers()`), SIGKILL after 1s, where production's grace is 10s, so a
    save feels instant. A compile error (the child prints it) or a program that ends by itself
    waits for the next change.
  - **Messages on stderr**: `gaz: watching app.gaz and 6 files it includes` (again whenever the
    list changes), `gaz: views/page.gazml changed, restarting`, `gaz: waiting for a change`.
  - **SIGINT, SIGTERM, SIGHUP stop the group the same way, then end the supervisor as the signal
    would**; one it was started ignoring stays ignored (so `sh -c '... &'` background jobs, which
    ignore SIGINT, need SIGTERM), as in `workers()`. Ctrl-C reaches only the supervisor, since the
    child's group isn't the terminal's. `ponytail:` so the program can't read the terminal or turn
    on raw mode (SIGTTIN, SIGTTOU); the supervisor says so and ends it rather than leave it stopped.
    Handing the child's group the terminal (`tcsetpgrp`) would lift that, with Ctrl-C then the
    program's. `WatchTest` edits files under a running supervisor.
- **`gaz --tty[=COLSxROWS] app.gaz`** runs a program on a pretend terminal (120x40 by default), so
  a TUI can be developed with no terminal: by an AI agent above all, which is who it is for, out of
  the box, and by scripts and tests. Standard input is its keys, as a terminal's keyboard is its
  program's stdin, which is why there is no `--keys`: `gaz --tty app.gaz <<< "2 j snap enter"`, or
  `< keys.txt`. Stateless on purpose: the same keys always print the same screens, so an agent's
  shell calls are its session and a sequence showing a bug is already a test case.
  - **The VM pretends, the library shows** (`tty_cols`, `tty_rows`, `tty_clock` in `term.c`):
    `term_is_tty()` is true for 0 and 1, `term_size()` the pretend size, `term_raw()` a no-op,
    `term_is_virtual()` true, and the clock virtual: `monotonic_time()` starts at 0.0 and only
    `sleep()` moves it, at once, so a screen run by a beat is deterministic. Reading the script
    is GazLang's (`term::Script`, shared by every `term::Input`, parsed from `read_stdin()` on
    first use), as turning bytes into keys is, and `term_read()` is an error there, since the
    script isn't bytes. The screen is printed by `tui::interact()` as `Screen.snapshot()`, from
    the grid it already has, rather than by a VT emulator in C decoding the escape bytes back:
    the grid is the truth. `ponytail:` so a bug in `render()`'s bytes doesn't show, and a
    program drawing its own escapes gets keys and clock but no screens.
  - **The script's words are `term::name()`'s** (`j`, `enter`, `ctrl+c`, `page_up`), one
    vocabulary; `"text"` types characters; `wait N` moves the clock; `snap` prints the screen. A
    snap reaches `interact()` as the key `"snap"` through `Input.read_or_snap()`, never through
    `read()`, which passes it over, since only `interact()` has a screen to show; a snap that
    follows a `wait` inside one read is why it can't be peeked for before reading instead.
    An unknown word is the user's mistake, not the program's: one line and exit 2, no trace.
  - **The dead end points the way**: `term::fullscreen()` refuses a program without a terminal on
    stdin and stdout, naming `gaz --tty`, and `term_raw()` does too, so programs don't carry
    checks of their own and an agent's first `gaz app.gaz` tells it what to do next.
- **A real REPL is possible, not built**, and nothing decided rules it out; what stands in the way
  is that everything assumes a whole program. It would take: a session mode in the compiler (the
  parser keeps its function, kind, constant and namespace tables between entries, the code
  generator keeps the top level's slot map and the global slots, and each entry compiles as a
  continuation of the top block plus any new blocks); a loader that appends blocks to a running
  program and grows its globals, statics and top frame instead of `run_program()` starting afresh;
  and a loop that reads with `term.c`/`lib/term.gaz`, asks for more when the parser runs out of
  input mid-construct, and prints a bare expression's value as a literal. Decisions it forces:
  whether a function or kind can be redefined (a kind can't be safely, since objects keep its
  layout), that an entry failing halfway keeps its side effects (as Python's does), and that a
  call to a function not yet defined is an error for that entry.
- **Packages: git only to begin, not built.** A package is a directory of GazLang source (no C,
  so one binary and a C compiler stay the whole install; no bytecode, which has no compatibility
  promise), a git repository with version tags. A project has `gaz.json` (its name and
  `"requires": {"router": "github.com/someone/gaz-router@1.2.0"}`), `gaz.lock` (the exact commit
  of every package, direct or not) and `packages/` inside it, never a global install, for the
  reason the standard library is embedded. `include "pkg/router/router.gaz"` reserves `pkg/` as
  `std/` is, found by walking up from the including file to the nearest `gaz.json`. One version
  of a package per project, since namespaces are program-wide; versions by minimal version
  selection (Go's: the highest of the minimums asked for, deterministic, no solver). **Git URLs,
  not a registry**: nothing to run, names unique by construction, the commit hash as integrity; a
  registry can come later as an index of git repositories (Packagist's shape) without changing a
  package. The tool would be GazLang built into the binary, as the compiler is (`run()` for git,
  the HTTP client, JSON, and the file builtins).
  - **The blocker is stability**: a package written today breaks with the next language change,
    and with no promise about what changes between releases it can't say which gaz it needs.
    Versioned releases exist; some promise about what changes between them comes first, and
    `gaz.json` then says `"gazlang": ">=0.3"`.
  - **The binary is `gaz`**, built by `make` with `bin/gazlang` a link to it for old scripts. The
    language stays GazLang, and so do the compiler's own names (`namespace gazlang`,
    `compiler/gazlang.gaz`, `gazlang.gzb`).
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
  pointers; one-byte strings are 256 shared values. A list's first `LIST_INLINE` (4) items
  live in its header, and move to an array of their own when it outgrows that, never back, so
  most lists are one malloc: 4 is what the first push allocated anyway, so a list of one to four
  costs the memory it did, and 6 or 8 saved a point or two more allocations for 32 or 64 more
  bytes on every list. A `List` is never copied as a struct, since `items` points into it.
  - **Keys are hashed under a key drawn for each process** (`siphash.c`, SipHash-1-3 for strings,
    splitmix64's finaliser after an xor with the key for ints), since a map's keys are often
    someone else's (a JSON object, a query string, a form) and an unkeyed hash lets them choose
    ones that all share a bucket: 20000 such keys took nine times as long to decode as ordinary
    ones, and the cost grows with the square. Nothing a program can see depends on it (a map keeps
    insertion order, so output is the same whatever the key), which is why it may differ between
    runs where the rest of the language must not. **Drawn once and kept through a `fork()`**:
    a string or map made before one holds hashes worked out under the key, so `workers()` must
    not draw again, as it does reseed `rand_*`. `tests/SipHashTest.php` checks the function
    against CPython's, which is SipHash-1-3 too; it costs about 6% on a loop that does nothing
    but make and look up new string keys and nothing measurable elsewhere. `ponytail:` not
    constant-time and not for secrets; a program that must cap what a client can send still
    wants `max_body`.
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
  so errors and their lines are the sequence's own. `tests/corpora/vm/superinstructions.gaz`
  takes every fallback; `tests/corpora/bytecode/superinstruction_lookalikes.gzb` holds the shapes only
  hand-written bytecode has.
- **Speed**: what paid was an int fast path for `%`, the `STORE; LOAD; POP` peephole, shared
  one-byte strings, not interning names on the hot path, inline caches on member instructions,
  and the superinstructions. What didn't: computed-goto dispatch (the CPU predicts the switch
  well), a fast path for `==`, and fusing a comparison with `JZ` on its own. A call into another
  file on the hot path costs twice, so `arity_fits()` is `static inline` in the header. On the
  development machine moving code a few bytes swings a hot loop by 5%, so judge a change with the
  same binary both ways, or on two builds, and keep what wins on both. What is left in a profile
  is the dispatch loop, malloc/free and the collector.
- **PGO is the default where the toolchain has it** (gcc, or clang with `llvm-profdata`), and
  plain `-O2` where it doesn't, so the bootstrap still needs only a C compiler: it makes every
  benchmark faster for about 5s more per build. It trains on the compiler, `examples/` and `tests/programs/`, never `vm/bench`, so the benchmarks stay an
  honest test; `bench.php` times whichever build `bin/gaz` is, so compare a change with
  both builds PGO (or both `PGO=0`). `-O3` was a wash and `-flto` slower.
- **Why C**: over Rust, Zig and Go, since the heap (refcounts plus a cycle collector) is unsafe
  code in every one of them, Go has no refcounts for cheap copy-on-write, and Zig moves under a
  pinned toolchain; C bootstraps with nothing but a C compiler (`TLS=0`), and the differential harness
  under ASan and UBSan is the safety net C usually lacks. A separate program rather than PHP
  FFI, since converting values per call costs more than an instruction.
- `ponytail:` in the C: float printing tries up to 34 `printf`/`strtod` pairs per float.
