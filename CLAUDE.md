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
# (not checked in; the tests build it themselves), with profile-guided optimisation where the
# C compiler can do it; PGO=0 builds plain -O2, a few seconds quicker while editing the C
make -C vm
make -C vm PGO=0

# Run a file, print its bytecode, run bytecode, or print its tokens or tree
bin/gazlang -f tests/programs/functions.gaz
bin/gazlang -c -f tests/programs/functions.gaz > /tmp/f.gzb && bin/gazlang -f /tmp/f.gzb
bin/gazlang --tokens -f tests/programs/functions.gaz
bin/gazlang --ast -f tests/programs/functions.gaz

# After changing compiler/, rebuild the compiler gazlang has built in, with gazlang alone
# (a test fails until then; see "Changing the compiler")
make -C vm compiler

# The test dependencies, then all tests (about 2 minutes): two suites, the language's (`core`) and
# the games' (`games`, under a minute), which a plain run does both of
composer install
vendor/bin/phpunit
vendor/bin/phpunit --testsuite core
vendor/bin/phpunit --testsuite games

# Play the football manager (needs a terminal); a seed makes the same clubs again
bin/gazlang -f games/football/main.gaz -- 1     # or: -- load [FILE]

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
bin/gazlang -f compiler/gazlang.gaz -- code tests/programs/functions.gaz
bin/gazlang -f compiler/gazlang.gaz -- ast < tests/programs/errors.gaz

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
  `open_memstream`, `realpath` and `memmem`), libc, libm and pthreads, and OpenSSL in `net.c`
  only (on by default, `make TLS=0` without, `GAZ_TLS` saying which), libsqlite3 in `sqlite.c` and
  libpq in `pg.c` only (on when found, `make SQLITE=0`/`PG=0` without), built warning-free by
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
  and the CLI, `gc.c` the cycle collector, `net.c` sockets and TLS, `db.c` with `sqlite.c` and `pg.c` databases, `term.c` raw mode and keys, `workers.c` `workers()`.
- `lib/`: the standard library in GazLang. `examples/`: sample programs that nothing tests
  (see "Programs are tests or examples"). `tests/programs/`: programs the tests do run.
  `games/`: programs built on the language, each with tests of its own (see "A game is neither").
- `editors/`: TextMate grammars, `gaz/gaz.tmLanguage` for source and `gzb/gzb.tmLanguage` for
  bytecode (VS Code, Sublime and most editors read them). `EditorGrammarTest` fails when the
  first misses a builtin or keyword, or the second doesn't name exactly `INFO`'s instructions in
  `vm/load.c`, so a new instruction needs a word in the grammar; the bytecode grammar marks what
  doesn't fit a line's shape as invalid, and accepts all the loader reads (comments,
  single-quoted strings and hex in a `PUSH`), not only what the compiler writes.
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
  program and the benchmark workload; it is not tuned for the game. That costs 1,400 lines of
  duplication that will drift, on purpose. A game reaches the standard library by `include "std/..."`,
  which the VM carries, so its only outward dependency is a `gazlang` binary and moving it to a
  repository of its own later is cheap.
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
  (Windows, a registry, an LSP and a playground wait for someone to ask).
- **Milestone 0.1, the first release**, is what that stranger needs, in this order:
  1. **Web essentials**: request decoding (done), the
     router ("Serving HTTP" has its design), and templates that escape by default, which want
     their own design round first (syntax, how one includes another, whether they compile to
     GazLang functions). Then cookies, static files and uploads as the apps written on it ask.
  2. **CLI essentials**: argument parsing (flags, options with values, subcommands, a generated
     `--help`).
  3. **The `gaz` rename** with `gaz main.gaz` and `-` for standard input (decided, see
     "Packages"), and `#!/usr/bin/env gaz` scripts.
  4. **A release**: a version number `--version` means, a changelog, prebuilt binaries for macOS
     and Linux, and a Homebrew tap; with it, some promise about what may change between
     releases.
  5. **Two tutorials** ("a JSON API in fifteen minutes", "a CLI tool in ten"), a README that
     opens with the niche, and the source grammar published as a VS Code extension.
- **CI** (`.github/workflows/ci.yml`) runs on Ubuntu and on macOS, Apple silicon and Intel,
  for every push: it builds gazlang without TLS (the bootstrap needs only a C compiler), then
  with it, and rebuilds its compiler before PHP is even installed, then the suite; phpstan and
  pint run on Ubuntu only.
  Development is on an Intel Mac.
- **Speed**: the same program takes gazlang 0.4 to 1.5 times what it takes PHP (JIT or not),
  and Python 3.13 1.3 to 2.9 times what it takes gazlang (`php vm/bench.php`, which finds a
  Python 3.11 or later for the `vm/bench/python/` ports; the README's table is its output on the
  default PGO build, so a `PGO=0` build runs a little slower). The
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
  - **Nothing opens a socket, starts a program, exits, waits or writes a file**: a program naming
    `run`, `exit`, `workers`, `write_file`, `read_stdin`, `read_line`, `sleep`, `getenv`, a directory builtin or a
    `socket_` builtin is skipped (`getenv` since what it gives isn't the seed's), an included
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
    an object, or, for closures, in a `shared` variable; by-reference parameters aren't worth
    their cost against refcounting.
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
    self-hosted lexer still spells out comparisons and walks local indexes, for speed on the PHP
    VM it was first measured on; measure on the C VM before keeping that.
  - Including a file also runs its top level code. `Error`'s members are reserved across its
    children, so a domain error can't declare its own `#line` or `#message`.
  - No copy-with-change for objects, no `catch (A | B $e)`, no bare rethrow.
  - `match ($x)` is a linear chain of `EQUALS`; no jump table.
  - No enum: token types are strings on purpose (they are the `--tokens` format).
- **HTTP is HTTP/1.1 in GazLang (`lib/http.gaz`) on socket builtins, TLS through OpenSSL**,
  linked by default and optional (`make TLS=0`), so the bootstrap still needs only a C compiler.
  Not curl through `run()`: a process per request, the headers visible in `ps`, and curl as a
  runtime dependency; not TLS of our own, which would be thousands of lines of crypto whose bugs
  no output shows. One connection per request; keep-alive, proxies, compression and HTTP/2 wait
  for a program that needs them.
- **Serving HTTP is `http::serve()` in the same file, in `workers()` processes**, the PHP-FPM
  model rather than Node's: a server is `socket_listen()`, `workers($n)`, then a loop of
  `socket_accept()` and one request per connection. **Prefork, not an event loop**: share-nothing
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
    are smuggled past a proxy; handler errors are a 500 and a line on stderr. Request and response
    are maps, like the client's response. `HttpServerTest` runs `tests/programs/web_server.gaz` and
    speaks to it over raw sockets, so requests no client would send can be sent.
  - **A request has a deadline** (`"request_timeout"`, 30s, a 408) as well as the per-read
    `"timeout"`, which alone let a client trickling a byte every few seconds hold a worker for hours.
    `Reader` checks it before each read, so it can overrun by one read's timeout.
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
  - `ponytail:` no keep-alive; writing a response has only the per-write timeout; the stop grace
    is fixed; while workers drain, new connections queue in the listener's backlog (the master
    holds it too) and are reset when the program ends, where closing the listeners first would
    need `workers()` to know which sockets are listeners; a master killed with SIGKILL leaves its
    workers running (Linux's `PR_SET_PDEATHSIG` would end them, macOS has nothing like it). No TLS
    on the server side: a proxy in front does it.
  - **Next, when a program asks** (the order they would be built in):
    - A router in GazLang (`lib/router.gaz` or in `http.gaz`): `$app.get("/users/:id", $handler)`,
      a list of `[method, pattern, handler]` matched by splitting paths on `/` (no regex), named
      segments into `$request["params"]`, 404 and 405 (with `Allow`) of its own, and middleware as
      `($request, $next) -> ...` closures wrapped around the handler. Its result is still a handler,
      so `http::serve($listener, $app.handler())` needs nothing new.
    - The client's address (`socket_peer($socket)`, for logs and rate limits), though behind a
      proxy `X-Forwarded-For` is the one that matters.
    - A `quote($value)` builtin, the value as a literal: `value.c` has it, and
      `slice(to_string([$x]), 1, -1)` stands in for it ten times in `http.gaz` and once in each
      compiler file; a builtin takes its name from every program, so the name is the question.
    - Measure it (requests a second against PHP's built-in server and `php-fpm` behind nginx)
      before any tuning; nothing has been timed yet.
    - HTML escaping for writing pages (with the templates of milestone 0.1); static files
      (`read_file()` and a content-type table, refusing `..` in the path); cookies (parse `Cookie`,
      write `Set-Cookie` with `HttpOnly`/`Secure`/`SameSite`); an access log line per request
      (`http::http_date(time())`, method, path, status, bytes, `monotonic_time()` for how long).
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
  overloading, `log`/`exp`/fractional powers (each needs an algorithm GazLang writes out, as
  `round` has), variadic parameters and spread in calls (pass a
  list), `foreach` over a string (`split($s, "")`). A REPL is possible and wanted: see "A real
  REPL" under the C VM.
- **Regular expressions**: `lib/regex.gaz` (`regex::matches`, `regex::search`,
  `regex::find`, `regex::groups`, `regex::replace`), a Thompson NFA (Pike's VM) so there is no
  backtracking and no ReDoS. Literals, `.`, `*` `+` `?`, `|`, `(...)` groups (capturing),
  `[...]`/`[^...]` classes with `a-z` ranges, `^`/`$` anchors, `\` escapes. **Perl's match**:
  threads run in priority order carrying their group slots (`save` instructions), and one
  reaching `match` drops the threads after it while those before run on, so repetitions are
  greedy and the first alternative wins, as every engine a reader knows does; a new start is
  seeded each step only until something matched, which is what makes it leftmost (the first
  version returned the first thread to reach `match`, so `find("abcd", "abcd|c")` was 2).
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

- **Precedence**, loosest first: assignment (right associative) → `?:` (right) → `??` (right)
  → `||` → `&&` → equality (`==` `!=` `<=>`) → relational → `..` → `|` → `^` → `&` → shifts →
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
  default is never shared; the arity is then `[required, total]`. `function` is reserved and
  says to write `fn`.
- **`$x` is always local** (to the running call or the top level), **`@x` always global**; a
  function can't read top-level `$x`. Parameters are `$` only, or a list pattern of `$`
  variables (below). `return` outside a function is a
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
  characters; a third argument, an int of 1 or more or null, caps the parts, the last holding the
  rest), `join` (elements converted like echo), `replace` (every occurrence; empty search
  is an error), `contains`, `ends_with`, `starts_with($s, $prefix, $offset = 0)` (whether the
  prefix is there at the offset, so a scanner asks without slicing off what it has read),
  `index_of($s, $needle, $offset = 0)` (null when absent), both with the same offset rule: negative
  from the end, and one outside the string is an error (the end itself is fine to
  `starts_with`, which finds only an empty prefix there), `repeat`, `chr` (0 to 255), `ord` (one
  byte), `to_int` (ints, bools, decimal strings with an optional `-`), `to_float`, `to_string`.
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
- Types: `type_of` (`int float string bool null list map function kind object socket`), `is_a`,
  `kind_of`, `kind_name` (a kind's name as declared, namespace included, `tui::Rect`: the bare
  name is `last(split(..., "::"))` and the other way would be impossible; of a kind or an object,
  whose kind is the only thing it could mean), `fields` (see "Objects"), `object_id` (an int no other object of the program has or
  had, counted from 1 in `object_new()` and reset by `run_program()`, so it is the same
  however the program runs: a set of objects or a side table is a map keyed by it; a counter,
  not the address, since an address is reused and differs from run to run).
- I/O: `print`/`print_error` (echo without the newline, to stdout or stderr), `read_file`,
  `write_file`, `read_stdin` (empty when the program itself was piped in), `args()` (after the
  gazlang options or `--`; the CLI rejects options it doesn't know, since `getopt` would drop
  them silently), `cwd()`, `real_path()` (as `realpath(3)`; `""`, a NUL byte, `file/` and
  `file/..` are nothing, where platforms disagree), `file_exists()`.
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
  flushes output first (a prompt). Tested through `CliTest` rows with `tests/cli/lines.txt`.
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
  is the connection's), both plain TCP. `workers($n)` (`workers.c`) forks the program for a server;
  see "Serving HTTP". The fuzzer skips `workers` with the socket builtins.
  `socket_open($host, $port, $tls = false, $timeout = 30)` gives a `socket`,
  a reference-counted handle closed when the last reference goes (so a forgotten close leaks no
  descriptor), `socket_read()` up to 64KB or `""` at the end, `socket_write()` all of it,
  `socket_close()` twice is fine. TLS verifies the chain against the system's store
  (`SSL_CERT_FILE` overrides, which is how `HttpTest` trusts `tests/fixtures/tls/`) and the host
  name, or the address for an IP (no SNI then). SIGPIPE is ignored around each call and put back
  after, as curl does: per socket only macOS can turn it off, and ignoring it for good would
  change what a program writing to a closed pipe does. OpenSSL reports a socket timeout as
  wanting to read; `net.c` says `timed out`.
- Databases (`db.c`, drivers `sqlite.c` and `pg.c`): `db_open($url)` gives a `db` handle (refcounted
  like a socket, closed when the last reference goes), `db_run($db, $sql, $params = [])` gives
  `{"rows", "changes"}`, `db_close($db)`. **Three builtins whatever the drivers**, since a builtin takes
  its name from every program: the URL's scheme (`sqlite:`, `postgres:`) picks a `DbDriver` (open, run,
  close) in C, so a new database is a file and a table entry, and `lib/db.gaz` (`db::open`, the `Db`
  kind with `query`, `row`, `value`, `exec`, `transaction`) is what programs use. **Placeholders are
  the database's own** (`?`, `$1`), not rewritten: rewriting means reading string literals in C. No
  last-insert id (`returning` says it in both). Parameters are always bound, never spliced; with them
  the SQL is one statement, without, a script whose last result is given. Both drivers are on when
  their library is found (`make SQLITE=1`/`PG=1` make that an error, which CI asks for). SQLite is
  tested by `tests/gaz/lib/db_test.gaz` on `:memory:` (recorded, sanitized, leak-checked); PostgreSQL
  by `DbPgTest` running `tests/db/pg_check.gaz` against the server `GAZLANG_TEST_PG` names (skipped
  without), on temporary tables. `ponytail:` a bool parameter is 0/1 in SQLite, no blobs going in,
  and PostgreSQL's numeric, timestamps and json come back as text.
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
  `term_is_tty($stream)`, only what GazLang can't do itself; drawing is escape sequences through
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
- `std_source($name)`: one file of the built-in standard library as text, or null (see below).
- `error($value)` raises (see "Errors"); `exit($code = 0)` stops with that code, 0 to 255,
  printing nothing and running no `finally`.
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
  `package.path`): one file, `bin/gazlang`, works from any directory with no install layout to get
  wrong and no skew between a binary and the library it runs; the cost, a rebuild after editing `lib/`
  (a few seconds; the tests build it themselves), is met by `GAZLIB=lib`, a directory `std/` reads
  instead of the built-in copy, for working on the library. `std/` is reserved as a first path
  component; `./std/x.gaz` is a directory of your own. The repository's own `lib/` files are still
  tested by path (`tests/gaz`), and `compiler/` includes `lib/chars.gaz` by path, so the compiler
  builds with nothing but its own sources; the games and examples use `std/`, as any other program
  would. `StdLibraryTest` checks that what is built in equals `lib/`.
- In GazLang instead, each its own namespace, so only what a file marks `pub` escapes it:
  `chars.gaz` (character classes), `sorting.gaz` (`sorting::values`, `sorting::by`, on `sort`),
  `lists.gaz` (`lists::flatten`, `lists::unique`, `lists::max_by`/`min_by`, which call the key once per element
  and keep the first on a tie, so they replace a stable `sort(...)[0]` exactly; a list helper goes here rather than into the builtins, since
  a builtin takes its name from every program and a namespace only from those that include it),
  `format.gaz` (`format::number`, `format::pad_left`/`pad_right`
  convert like echo: display helpers take any value, string functions stay strict;
  `format::sprintf($template, $args)`, a list since there are no variadic calls: `%s` echo's
  text, `%d` and `%x` ints only (`%x` of a negative is an error, not two's complement), `%f`
  through `format::number()` so it rounds as `round()` does and never by the platform's printf,
  which caps it at an int's worth of digits and 18 decimals (`ponytail:`); `-` beats `0`, as in C;
  every placeholder is read and the count checked before anything is formatted),
  `json.gaz`, `csv.gaz` (RFC 4180), `db.gaz` (see Databases above), `http.gaz` (method and header names checked
  as HTTP tokens and URLs for spaces and control characters, so nothing can end a line of the
  request; credentials dropped on a redirect to another origin; `HttpTest` runs it against
  `tests/fixtures/http_server.php`, over TCP and TLS, which writes framing out by hand so it can
  get it wrong on purpose; ports vary, so what it prints is checked by shape, not recorded; and the
  server, `http::serve`, see "Serving HTTP"),
  `date.gaz` (`date::days`, `date::civil`, `date::format`: a date is a number of days from 1 January
  1970, with no clock, since a program that asked one what day it is could not be recorded, so a game
  keeps its own date), `random.gaz` (`random::shuffle`, `random::pick`, `random::key`, `random::chance`,
  `random::weighted`), `term.gaz` (`term::style`, the cursor and screen sequences,
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
  argc`, the callee's frame holding them in slots 0..argc-1, `RET` pushing the result. `..=`
  appends in place, to a plain variable with `CONCAT_ASSIGN` and to anything else with a
  `SET_PATH` whose path ends in `..=` (`store_path()`); only a value printing through
  `to_string()` joins first and writes again from the start, since running it could move what
  the walk points into. Other compound assignment, `++`/`--`, `foreach`, list patterns and `??=`
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
  `#source_line`, since `#line` is where in `lexer.gaz` it was raised. The one `try` in the lexer
  (`hex_value()`) holds only the arithmetic it is about, since `catch (Error)` also catches
  running out of call depth.
- **What it leans on instead of writing out**: `slice(to_string([$v]), 1, -1)` is a value as a
  literal (a string quoted, which is the inverse of reading one), `..` on a float formats it,
  `to_int($text, null)` is the overflow check.
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
  call to a function not yet defined is an error for that entry. Wanted after the chores batch
  (`read_line()` and friends), which it would use.
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
  the HTTP client, JSON, and the chores batch's file builtins).
  - **The blocker is stability**: a package written today breaks with the next language change,
    and with no releases it can't say which gazlang it needs. Versioned releases, and some
    promise about what changes between them, come first, and `gaz.json` then says
    `"gazlang": ">=0.3"`.
  - **Renaming the binary to `gaz`** is wanted with it (`gaz pkg add` over `gazlang pkg add`), and
    matches `.gaz`; no common package ships a `gaz` command, so the name is free. **Decided with
    it: the first bare argument is the file** (`gaz main.gaz a b`, as Python, PHP and Node do),
    everything after it the program's, and `-` names standard input as the source (`cat x.gaz |
    gaz - a b`). Standard input stays the program's whenever a file is given, as it is now; the one
    change is piped source with bare arguments (`cat x.gaz | gazlang a b`), which becomes `gaz - a
    b` or keeps `--`. `-f` and `--` go on working. Built with the rename, after the chores branch,
    which changes how standard input is shared. The cost is mechanical (the Makefile, `GazLangTestCase::binary()`, CI, the docs);
    a `gazlang` symlink could carry old uses through a release.
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
- **PGO is the default where the toolchain has it** (gcc, or clang with `llvm-profdata`), and
  plain `-O2` where it doesn't, so the bootstrap still needs only a C compiler: it makes every
  benchmark faster, by more than the layout noise and on both layouts, for about 5s more per
  build. It trains on the compiler, `examples/` and `tests/programs/`, never `vm/bench`, so the benchmarks stay an
  honest test; `bench.php` times whichever build `bin/gazlang` is, so compare a change with
  both builds PGO (or both `PGO=0`). `-O3` was a wash and `-flto` slower.
- **Why C**: over Rust, Zig and Go, since the heap (refcounts plus a cycle collector) is unsafe
  code in every one of them, Go has no refcounts for cheap copy-on-write, and Zig moves under a
  pinned toolchain; C bootstraps with nothing but a C compiler (`TLS=0`), and the differential harness
  under ASan and UBSan is the safety net C usually lacks. A separate program rather than PHP
  FFI, since converting values per call costs more than an instruction.
- `ponytail:` in the C: float printing tries up to 34 `printf`/`strtod` pairs per float.
