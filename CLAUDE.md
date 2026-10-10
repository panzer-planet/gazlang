# GazLang Development Guidelines

GazLang is self-hosting: the lexer, parser and code generator are written in GazLang
(`compiler/`, and the lexer in the standard library, `lib/syntax.gaz`), compiled to bytecode
(`compiler/gazlang.gzb`, checked in) and built into a VM in C
(`vm/`); together they are `bin/gaz`. The tests are PHP (PHPUnit classes, run in parallel by
Pest), which runs `bin/gaz`.
`README.md` is the invitation, `docs/language.md` the language reference, `docs/internals.md`
the contributor guide, `docs/bytecode.md` the bytecode spec, and `docs/vm.md` (the C VM),
`docs/http.md` (HTTP and the worker pool) and `docs/library.md` (the builtins and `lib/`) the
rules of each of those parts; the website publishes all of them. This file holds the rules and the
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
php tools/snippets.php

# Fuzz the sanitized VM for a minute (--seed N to replay, --seconds S, --shrink FILE)
php tools/fuzz.php

# What differs from tests/expected; --update adds new programs to tests/passing.txt and records
# what every entry prints (review that diff)
php tools/progress.php [FILTER] [--update]

# After a change to what the front end or the command line prints: record it, review the diff.
# Recording (this, tools/snippets.php, progress.php --update) runs one test at a time, never in parallel
GAZLANG_RECORD=1 vendor/bin/phpunit --filter 'SelfHosted|CliTest'

# The self-hosted front end from its source; without a file it reads piped source
bin/gaz compiler/gazlang.gaz code tests/programs/functions.gaz
bin/gaz compiler/gazlang.gaz ast < tests/programs/errors.gaz

# The C VM's coverage by the harness, its speed, and a build that collects cycles at every chance
# (over an hour: collecting is quadratic, and the entries that compile the compiler take longest)
php tools/coverage.php [file.c]
php tools/bench.php
make -C vm stress && GAZVM=vm/build/gazvm-stress php tools/progress.php

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
  **A `pub` name's documentation is a docblock**: `/**` alone on its first line, ` * ` down the
  side, ` */` last, Markdown (no `@param` tags: the signature has the names and types), right
  above the declaration, and a file's overview the first one, above its `namespace` line. Tools
  read it (the website, the language server); a plain comment stays the source's. `lib/` is
  complete and the site's build holds it so; other code changes when it is worked on.
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
  libpq in `pg.c` only (on when found, `make SQLITE=0`/`PG=0` without; libpq is loaded with
  `dlopen` on the first `postgres://` open, so building needs only its header), built warning-free by
  clang and gcc, commented where the C isn't obvious (a
  flexible array member, a `goto` into shared code), for readers who know a little C.
- **PHP** (the tests and `vm/*.php`): 8.5 or later, PSR-4 under `GazLang\Tests`, methods
  camelCase, PHPDoc on classes and methods. The pipe operator (`$x |> trim(...)`) where a chain
  of single-argument calls reads better.
- `ponytail:` comments mark known ceilings, with what would lift them.

## Layout

- `compiler/`: `parser.gaz` and `nodes.gaz`, `template.gaz` (`.gazml` templates into
  GazLang), `codegen.gaz`, `docblocks.gaz` (see "Docblocks"), and `gazlang.gaz`, the
  driver: `gazlang.gaz -- code|tokens|ast [FILE]`, reading standard input without a FILE, a
  usage message and exit 2 otherwise. `gazlang.gzb` is its bytecode. The lexer is
  `lib/syntax.gaz` (`std/syntax.gaz`, `namespace syntax`), so the compiler, the website, the
  language server and any program read GazLang with one lexer.
- `vm/`: the VM in C. `gazvm.h` says which file does what: `value.c` and `ops.c` are what values
  mean (operators, truthiness, printing, keys, indexing, write paths), `builtins.c` the builtins
  and their arities (`builtin_info[]`), `load.c` reading and checking bytecode, `vm.c` running it
  and the CLI, `gc.c` the cycle collector, `net.c` sockets and TLS, `db.c` with `sqlite.c` and `pg.c` databases, `term.c` raw mode and keys, `workers.c` `workers()` and its master reading request heads, `watch.c` `gaz --watch`, `crypto.c` random bytes, hashes and password hashes, `siphash.c` the hash behind every map.
- `tools/`: the PHP scripts that work on the whole project rather than run as tests:
  `progress.php` and `snippets.php` record, `fuzz.php` fuzzes, `coverage.php` measures the C VM's
  coverage, and `bench.php` times gaz against PHP and Python on the programs in `tools/bench/`.
  What they build or save goes in `vm/build/` (gitignored).
- `lib/`: the standard library in GazLang. `examples/`: sample programs that nothing tests
  (see "Programs are tests or examples"). `tests/programs/`: programs the tests do run.
  `games/`: programs built on the language, each with tests of its own (see "A game is neither").
  `apps/`: web apps built to find what hurts, in the repository for the same reason as the games
  (`apps/todo`: registration, login and todos on PostgreSQL, with `FRICTION.md`, the evidence for
  what the library and language lack, in the order a stranger would meet it). Its tests need a
  PostgreSQL database, so CI doesn't run them: `bin/gaz test apps/todo/tests`. `apps/course`:
  students register and take a course on GazLang and its toolkit, on SQLite, written from the docs
  alone; its lessons are Markdown files whose every `gaz` example its tests compile and run
  (`bin/gaz test apps/course/tests`). Both find their files from `main_dir()`, so they run from
  any directory.
- `gaz.json` (`{"name": "..."}`) marks a project's root: the repository's, `apps/todo`'s,
  `apps/course`'s and `games/football`'s. A root-relative `import` resolves from the nearest one
  above the module, and the compiler refuses any key but `"name"` (see "Modules and namespaces").
- `editors/`: TextMate grammars, `gaz/gaz.tmLanguage` for source, `gzb/gzb.tmLanguage` for
  bytecode and `gazml/gazml.tmLanguage` for templates (HTML with GazLang embedded: `{{ }}`,
  `{!! !!}`, the directive lines and the `import` lines before them, also inside tags and attribute
  values; VS Code, Sublime and most editors read them). The source grammar marks `include`, a
  removed word the lexer still knows, as invalid and not as a keyword. `EditorGrammarTest` fails when the
  first misses a builtin or keyword, the template grammar's directives differ from `directive()`'s in
  `compiler/template.gaz`, or the second doesn't name exactly `INFO`'s instructions in
  `vm/load.c`, so a new instruction needs a word in the grammar; the bytecode grammar marks what
  doesn't fit a line's shape as invalid, and accepts all the loader reads (comments,
  single-quoted strings and hex in a `PUSH`), not only what the compiler writes.
- `site/`: the website, a GazLang program (see "The website"): `build.gaz` the driver,
  `pages.gaz` and `templates/*.gazml` the pages, `markdown.gaz`, `library.gaz` (the library's
  pages from its source), `documents.gaz` (links and the repository), `verify.gaz` (the checks
  every page passes), `style.css`. Its tests are `tests/gaz/site/` and `SiteTest`.
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
  after `Error: `. `tools/snippets.php` collects the snippets, as JSON, for `CVMTest`. Order matters
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
  `tests/passing.txt` (every program and corpus file, the snippets in `tests/vm_snippets.txt`, and
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
  (CI runs it). They import `std/test.gaz` `use expect, throws`: `expect($label, $actual,
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
  commit as the game code that needed it: there is no package manager yet, so a game in a
  repository of its own would need a checkout of gazlang at a known path, and every language
  change would be two commits. It is a project of its own (`games/NAME/gaz.json`), so its imports
  are from its own root (`import "engine/teams.gaz";`) and moving it out needs no rewrite. Its tests are GazLang programs in
  `games/NAME/tests/`, run by their own PHPUnit suite (`--testsuite games`; `--testsuite core` for
  the language alone; a plain run does both), and they assert what stays true however the game is
  tuned (a season plays every match, a table adds up), not what a seeded run prints: the odds are
  meant to change, and recordings would be re-recorded with every tweak. What stays byte-exact is
  `tests/programs/football.gaz`, the frozen simulator the game started from, the VM's biggest test
  program and the benchmark workload; it is not tuned for the game. The duplication will drift, on purpose. A game reaches the standard library by `import "std/..."`,
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
writing real GazLang and fixing what hurts. **A feature is built when a real program asks for it,
or when it is foundational and plainly going to be needed**: other features would be built on it,
or every web server or CLI tool will reach for it (an access log, caching headers, a test client
for handlers), so waiting for the first program to trip on it only delays the obvious. "Nobody has
asked" alone is no longer a reason to wait; what stays gated on a program is a shape that isn't
clear yet, or a builtin that would take a name from every program for the sake of one. When a workaround in the repository's GazLang is
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
- **A change to `lib/syntax.gaz` is a change to the compiler**: `make compiler` too, since
  `gazlang.gzb` holds the lexer compiled in. The compiler imports it as `std/syntax.gaz`, the
  copy built into the `bin/gaz` compiling it, so `make compiler` rebuilds `bin/gaz` with `lib/`
  first (`compiler` depends on it, and it on `lib/*.gaz`) and every stage reads the new lexer.
- The bytecode is built in with `od` into `vm/build/compiler.c`, and the standard library the same
  way into `vm/build/std.c` (see `docs/library.md`): numbers only, so nothing to
  escape, and no trigraphs, which `-std=c11` turns on and the `??=` in it would be.

## Open work is in GitHub Issues

What is open lives in the repository's issues, not in these files: the roadmap, what waits for a
program to ask for it, what isn't designed yet, known limits and the todo app's open friction.
Each is one issue saying what is decided and what is open; the docs keep the rules and link the
issue where a decided design waits to be built. `ponytail:` comments stay at the line they
describe. To find it:

```bash
gh issue list --label roadmap      # what is next
gh issue list --label limitation   # the kinds: roadmap, on-demand, not-designed, limitation, friction
gh issue list --label http         # the topics: language, library, http, vm, tooling, todo-app
```

A new open item is filed as an issue with a topic and a kind label, never added as a list here.

## Status

- **The goal**: GazLang is the PHP Werner always wanted, for **web servers and CLI tools**, and
  the project succeeds when **one person besides him chooses to use it**. So work is ranked by
  what makes real web apps and CLI tools pleasant, then by what one stranger needs to find it,
  install it, get a first program working and trust it; not by what would win many users
  (Windows, a registry and a playground wait for someone to ask).
- **Not building**: taint mode (a mark on strings leaks, since one-byte strings are shared and
  `url_decode()` rebuilds text with `chr()`; Ruby removed taint as useless; tagged literals
  prevent the bug instead), contracts (types and a guard line cover them),
  `sh"..."` (`run()` already takes an argv list, which is safe), `do ... while` (`while (true)`
  with a `break` says it, and most such loops leave from the middle, which it can't; it would take
  `do` from every program), native decimals and full record and replay (for now).
- **`gaz test`** (`std/test.gaz`, `test::main()`): every check is one line, `ok ` or `FAIL `,
  which is what the runner counts, so a value's newlines on a FAIL line are escaped too.
  - **`throws` matches a kind exactly** (`kind_of($e) == $kind`), not by `is_a`: under `is_a`,
    naming `Error` would accept every error, so a test couldn't say that a specific kind was not
    what came.
  - **`done()`'s count is a static field** (`Tally::failures` in the namespace, so no global a
    test could collide with), raised by every failing check.
  - **`program_path()`** isn't resolved to a canonical path: a
    `/proc/self/exe`/`_NSGetExecutablePath`-based one is a different, bigger feature nobody has
    asked for yet.
  - **`gaz test` is a bareword subcommand**, dispatched in `vm.c`'s `main()` before any of the
    usual `-`-prefixed option parsing, since `test` names a shape (`cargo test`, `go test`), not a
    flag; a file literally named `test` needs `-f test` to run instead, an accepted, negligible
    edge case. It runs a small fixed bootstrap program (`import "std/test.gaz"; test::main();`)
    with everything after `test` as that program's own `args()`.
  - Fixtures are `tests/fixtures/gaz_test` (`TestCommandTest`), which all pass since `gaz test
    tests` runs over them too; the failing files are written into a temporary copy.
    `tests/gaz/lib/test_test.gaz` checks what passes directly and what fails by running
    `tests/fixtures/test_library/failures.gaz` (and `gaz -e` snippets) with `run()`, comparing their
    lines and exit status, so it prints no FAIL line of its own.
- **A language server** (`lsp/server.gaz`, `bin/gaz lsp/server.gaz`), so an editor gets errors and
  eventually more without a stranger installing anything but gaz. Diagnostics
  (`textDocument/didOpen`/`didChange` reparses the whole document, full sync, and
  `publishDiagnostics` the first error, the document taken as the main file, so a module opened
  alone is checked by its own imports as the compiler checks it; one in an imported file is shown
  at the document's import of it, or its first line when reached through another file), hover (a builtin's arity from `builtins()`, or
  a function's, kind's or constant's signature as written and its docblock, as Markdown),
  go-to-definition (the same search, followed across the document's own `import`
  statements — resolved by the compiler's own rules, `import_target()` and `project_root()` in
  `parser.gaz`, and only into files that exist on disk, so a `std/` import isn't chased — cycles ended by a
  set of real paths already visited on that branch), and completion (every keyword worth
  completing: `Lexer::KEYWORDS` in its order, skipping the ones the parser only refuses,
  `Parser::RESERVED` and `Parser::REMOVED` (`include`), so a new keyword is offered by itself, which `LspTest` checks against
  the lexer's table; every builtin with its arity; every function, kind and constant the
  document can reach by name, itself and what it imports, directly or not (further than the
  compiler lets it see, so a completion can nudge toward an import), each once even if declared reachably
  more than once, a docblock's first line as its `detail` and the whole as its `documentation`; no filtering by what is typed, which editors do themselves; inside the string
  of an `import`, the `.gaz` and `.gazml` files and folders it could name instead, from the file's own
  directory after `./` and from its project root otherwise, `std/` having no directory to list) and
  document links (each `import` path links to the file it names, `std/` and missing files left out)
  are done. Declarations, docblocks and imports come from `compiler/docblocks.gaz`, the lexer's
  reading, not from the parsed tree, since the tree doesn't exist while the document has an unrelated
  syntax error, which is the common case mid-edit (the lexer reads up to its own first error); each
  file's outline is kept until its text changes, so a hover lexes only what changed. Nothing else is planned yet; add what a real
  session of using it shows is missing. It is `namespace gazlang`, not its own, reusing the
  compiler's own `Parser` as a test of the internals does (the lexer is `std/syntax.gaz`'s; see
  "Modules and namespaces" and
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
  --filter CliTest`), commit, then tag and push the tag. The binaries have TLS, SQLite and
  PostgreSQL, **built in but not bundled**: libpq is loaded when a program first opens a
  `postgres://` database, not linked, so a binary works wherever libpq is installed (on macOS,
  Homebrew's: the release bakes in its opt directory) and gives a clear error where it isn't, which
  the release tries both ways, and from two workers on macOS (#75). On macOS OpenSSL
  is linked in, so they need nothing but the system, and `net.c` trusts the system's
  `/etc/ssl/cert.pem` too (unless `SSL_CERT_FILE` says otherwise), since a linked-in OpenSSL looks
  for certificates where Homebrew keeps them; without it https fails on a Mac without Homebrew. No promise about what changes between releases yet.
- **CI** (`.github/workflows/ci.yml`) runs on Ubuntu (the latest) and on macOS, Apple silicon and
  Intel, for every push: it builds gaz without TLS or databases (the bootstrap needs only a C
  compiler), then with them, and rebuilds its compiler before PHP is even installed, then the
  suite, in two jobs per platform (`pest --parallel --shard=N/2`, each building gaz for itself),
  with `gaz test` and the minute of fuzzing on the second Linux one only. phpstan and
  pint are a job of their own on Ubuntu, which needs no build and so reports first; phpstan runs
  cold there (no result cache) at a 1G limit, as `composer ci` does locally, since a warm local cache can hide a table that needs a gigabyte. LeakSanitizer runs in the sanitized build on
  Linux only: Apple's clang has none, and Homebrew's LLVM gave only system-library noise.
  Development is on an Intel Mac.
- **The website** is built by gaz: `bin/gaz site/build.gaz` (from the root; into `site/dist`,
  gitignored; open `site/dist/index.html`, or `php -S localhost:8000 -t site/dist`) makes the home
  page from the README, a reference page per `##` section of `docs/language.md`, a page per
  `lib/*.gaz` from its `pub` names and the docblocks above them (read from the source by
  `compiler/docblocks.gaz`, so it can't drift and a new file appears by itself), and `docs/bytecode.md` and `docs/internals.md`. Pages
  are `.gazml` templates (`site/templates/`), so escaping is the templates' and not remembered
  at each concatenation; a link's scheme must be http, https or mailto, anything else leaves its
  text. **The library's docblocks are Markdown as the docs are**, through the same converter and
  links, except that a backslash is always itself (`"\"` in a regex comment means a backslash),
  and an example indented under a blank line is GazLang code. **Every name a library page lists
  needs a docblock** (`verify::undocumented()`): one without fails the build, naming the page and
  the line to write it at, so the library can't drift from its pages; a plain comment isn't shown.
  `.github/workflows/pages.yml` publishes it.
  **GazLang is highlighted by the compiler's own lexer** (`std/highlight.gaz` on
  `std/syntax.gaz`, whose `Lexer.span()` says where each token lies, in the library so an app
  can show code as the site does), so a colour can't disagree with the language, and every
  piece is cut from the source rather than printed from a token, so whitespace, comments and
  escapes come out as written: `round_trip_test.gaz` requires the pieces, and the HTML read
  back, to give every docs block and every file of `lib/` and `examples/` byte for byte. Source
  the lexer refuses is shown plain, not fatal. A fence names its language (`gaz`, `gzb`,
  `gazml`, `bash`); a plain fence right after code is shown as its output. No JavaScript, fonts or
  anything fetched from elsewhere.
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
- **Speed** is measured by `php tools/bench.php` (CPU time, interleaved, best of several; the README's
  table is its output on the default PGO build, so a `PGO=0` build runs a little slower). gaz is
  around PHP's speed and faster than Python. The arithmetic loop is still about nine dispatches an
  iteration after fusion (a register form would need about six), which only a register bytecode
  or a JIT would close; lists, maps, strings and objects spend theirs in malloc/free (about 17% of
  a football run) and the collector. A list keeps its first four items in its own header (see
  "Values" in `docs/vm.md`); what is left is mostly lists past four items, map headers and index arrays, closures'
  captured arrays and strings, so small maps come before a pool; measure with a sampling profile
  first.
- **Bytecode has no compatibility promise yet**: stable so far, but free to change; a change
  old files can't load under bumps the version.
- **The fuzzer** (`php tools/fuzz.php`, a minute; CI runs one on each push, seeded by the run's
  id) needs no oracle: generated programs, mutated corpus programs and mutated bytecode
  run through `CVM::runC()`, and it fails on a sanitizer report, a crash, a leak, a time-out,
  an error raised inside the compiler, or bytecode the compiler wrote that the loader refuses.
  What they print isn't checked, since nothing says what it should be.
  - **Generated programs are made to end**: calls only go down (a function calls the ones
    before it, a method the ones below it), loops run a few times, and a lambda calls nothing
    that calls back, so a time-out in one is a bug. A mutant may just loop, so its time-out is
    only reported. Half of them declare an interface of their first kind's methods, which that
    kind implements and `is_a` and a typed function ask about.
  - **Mutations aim at instruction lines**, in the file being mutated and in the one a line is
    taken from: a block's header lines (`top`, `locals`, `fn`, `kind`) are a good part of a
    small bytecode file, and damage to one is refused by the header parser before an
    instruction is read, which the corpus already covers. One try in five still lands anywhere.
  - **Everything follows from the seed**, so `--seed N --runs M` replays a run. A failure is
    saved in `vm/build/fuzz/` and shrunk, a minute in a run and to the end with
    `--shrink FILE`. A try costs about 0.1s of the sanitized build's start-up, whatever the
    program, which is why shrinking takes the time, not the run.
  - **Nothing opens a socket, starts a program, exits, waits or writes a file**: a program naming
    `run`, `exit`, `workers`, `worker_recycle`, `worker_retire`, `worker_deadline`, `worker_accept`, `worker_release`, `write_file`, `read_stdin` (or `read_stdin_bytes`),
    `read_line`, `sleep`, `getenv`, a directory builtin, `rename_file`, `chmod`, `symlink`, `readlink`,
    `sync_dir`, `set_mtime`, `chdir`, a `term_` builtin, a `file_` builtin
    (`/dev/stdin` waits and `/dev/zero` never ends) or a `socket_` builtin is skipped (`getenv` since what it gives isn't the seed's; `worker_recycle` and `worker_deadline` since each ends the process by an unhandled signal, which prints no `GAZVM_STATS` line and would fail the harness for a reason that isn't a bug), an imported
    file's text included, which is sound because a builtin is reached only by its name.
  - **A mutant runs next to the program it came from** (`.fuzz-<pid>-<name>.gaz`, gitignored,
    deleted in a `finally`), so its `./` and root imports find what the original's did; a failure
    is saved with a `.dir` file naming that directory, where `--shrink` runs it again. What a
    mutant imports is the files its bytecode's `@` lines name (as `gaz --watch` finds them, from
    an unsanitized `gaz -c`), not a regex following import lines.
- **Before changing `lib/http.gaz` or `vm/workers.c`, read `docs/http.md`**: the HTTP client and
  server (prefork `workers()` processes, the master reading request heads and handing workers
  whole ones, keep-alive and the single process's idle yield, the worker hand-over, decoding,
  routing, sessions, middleware) and the rules each keeps, such as refusing
  both `Content-Length` and `Transfer-Encoding`, and a handle made before `workers()` being
  abandoned, never closed, by the processes that inherit it.
- **Modules and namespaces** are resolved by the parser: functions and kinds carry `::` in
  bytecode, while a method block stays `Kind.method`, which is what lets the loader tell the two
  apart. Resolution is one pass before anything else is checked, so nothing below it knows
  modules or namespaces exist.
  - **A file sees what it declares and what it imports, and no further**, so a module checked
    alone gives the answer it gives in every program that contains it; `import` only at the top
    so a file's first lines say what it depends on, for a reader, the language server and the
    fuzzer. Each scope (`Module` in `parser.gaz`) keeps the keys of the modules it imports;
    `resolve()` filters on them, and `import_hint()` names the import to add, spelt as the file
    would write it.
  - **Paths**: the project root is found from the module's own real path, not the main file's,
    which is what lets a package resolve its own imports later; the `gaz test`/`-S` bootstraps
    take `cwd()`'s as piped source does. A `..` step is refused so a file has one spelling and
    nothing reaches out of a project; an empty or `.` step is refused too. `import_target()`,
    `path_refusal()` and `project_root()` are top level functions of `parser.gaz`, which the
    language server uses too.
  - **Identity** is the real path, or `<std>/name.gaz`; without `GAZLIB`, `lib/text.gaz` and
    `std/text.gaz` are different files, so a duplicate is the ownership error rather than `already
    declared`. The compiler's own `parser.gaz` and `template.gaz` import each other.
  - **An imported file only declares**, and the main file imported back by a cycle is refused at
    its first statement. So a plugin registers itself by a `pub fn register()` its main file
    calls, and a kind a template names lives in a module.
  - **`gaz.json`** is checked when the root is first found (the compiler imports `std/json.gaz`
    for it); any other key is an error so nothing written today can mean something else later.
  - **`include`** stays the `INCLUDE` token, in `Parser::REMOVED` (next to `RESERVED`, which is
    for features to come; the language server's completion skips both), and `top_level()` and
    `statement()` answer it with `include is gone: write import "..."; ...`.
  - **Privacy is per namespace, not per file**, so `compiler/`'s files declare `namespace
    gazlang;` and see each other's everything they import, and a test of the internals joins the
    namespace rather than making them public.
  - **A namespace belongs to one project** (`Namespace text is std/text.gaz's: lib/text.gaz can't
    declare it too`), so a program can't declare `namespace http` and call its private functions.
  - **`use` clauses**: a module already read gives no statements again but still answers its `use`
    clause. No `use ns::*`, which is how the flat namespace came back.
  - **Resolution** has no fallback into another namespace, which is PHP's wart. `ponytail:` a bare
    name whose namespace's declaration isn't imported falls to a builtin of that name rather than
    being an error, as the order says. A namespace's own name winning over a builtin is so a new
    builtin can't take a name a namespace already used.
  - Errors are the parser's: a `use` on a file that declares no namespace, a name that isn't
    `pub`, a name in a `use` clause that is qualified or declared in another file, an alias
    already taken, a namespace or name the file doesn't import.
- **Static members**: not through a value, since `$obj::next()` would put a value on the left of
  the parse-time operator, the one place PHP's `::` means something else. `.` on a kind stays an
  error until a program asks for `kind_of($obj).next()`.
  - **A static field is a slot**, addressed like a global: `statics` in the bytecode header
    names each one `Kind::field` after the kind that *declares* it, so a kind and its
    children name the same slot, and `LOAD_STATIC`/`STORE_STATIC`/`SET_PATH_STATIC`/
    `DELETE_PATH_STATIC` are the global instructions again. A **static method is a function**
    named `Kind::name`, so calling one is an ordinary `CALL` and the VM learns nothing: a
    method block keeps its dot, which is still what says "this one needs an object".
  - **A static field's default is required** since a slot with nothing in it would need the quiet
    load a static never wants.
  - **Assigned through `pub`/`kin` alone**, so a static needs no rule of its own to protect it.
    `Counter::count` and `#count` compile to the same instruction, since `::` resolves when it is
    parsed (a path written with a `#` takes the static as its root, `static_start()` in
    `codegen.gaz`, not `#`, which a static method hasn't got); `store_path()` has a fifth root for
    it, next to a local, a global, a capture and `#`, and `Kind::NAME = ...` is refused as
    `#NAME[0] = 1` is.
  - A bound static (`#next` as a value) would be the same value as `Counter::next` by another
    spelling, so it waits for a program that wants it.
  - **No late static binding**: a static method is resolved when the program is read, so one
    a child inherits can't learn which kind it was called through. Binding late would make the
    VM learn what a static is, which is the one thing this design keeps it from needing.
- **Packages: git only to begin, not built** ([#32](https://github.com/panzer-planet/gazlang/issues/32)). A package is a directory of GazLang source (no C,
  so one binary and a C compiler stay the whole install; no bytecode, which has no compatibility
  promise), a git repository with version tags. A project has `gaz.json` (its name and
  `"requires": {"router": "github.com/someone/gaz-router@1.2.0"}`), `gaz.lock` (the exact commit
  of every package, direct or not) and `packages/` inside it, never a global install, for the
  reason the standard library is embedded (see `docs/library.md`). `import "pkg/router/router.gaz"` (reserved now, refused
  as "packages aren't built yet") would map to `<root>/packages/router/router.gaz`, whose own
  imports resolve against its own `gaz.json`, since a module's root is the nearest one above it,
  not the main file's; a namespace belongs to one project, so a package can't join the app's.
  `gaz.json` holds only `"name"` today and refuses any other key, so `"requires"` can't mean
  something else before this is built. One version of a package per project, since namespaces
  are program-wide; versions by minimal version
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
- **Regular expressions**: `lib/regex.gaz`, a Thompson NFA (Pike's VM) so there is no
  backtracking and no ReDoS. **Perl's match**: threads run in priority order carrying their group
  slots (`save` instructions), and one reaching `match` drops the threads after it while those
  before run on, so repetitions are greedy and the first alternative wins; a new start is seeded
  each step only until something matched, which is what makes it leftmost. `groups` gives a group
  that took no part as null since `""` can't be told from an empty capture, a repeated one's last.
  `ponytail:` an empty iteration of a starred group dies at the loop, so `(a*)*` reports its group
  as null where Perl says `""`; no backreferences. The shorthands are `lib/chars.gaz`'s
  predicates, called, so they can't disagree; see `docs/library.md` for escapes and `$1`.

# The language

Rules, with the reason where the choice isn't obvious. `docs/language.md` is the reader's
version.

## Operators, truthiness and equality

- **Precedence**: bitwise is Rust's and Python's, not C's. `..` sits looser than the bitwise
  operators and tighter than comparison, so `"x = " .. $f & MASK` and `$f & MASK .. "!"` both do
  the obvious thing (between the bitwise levels, every unparenthesised mix would be an error);
  shifts stay tighter than `..`, unlike Lua, so `"n = " .. $x << 2` works. `**` is Python's
  (`power()` in `parser.gaz` reads a postfix, then `**` and a unary).
- **Truthiness** is `is_truthy()` in `value.c`, the one place a non-bool is read as a bool.
- **`==`** is `values_equal()` in `value.c`, shared with `in_array` and `match`; functions, kinds and objects compare by identity (bound
  methods: the same object, kind and method). `===` lexes as `==` then `=`, a syntax error.
- **List ordering** is `order()` in `ops.c`, as in Python and Rust; each pair follows `==`'s
  rules, so `[1] < ["a"]` is an error, but only once that pair is reached.
- **Bitwise**: a shift count above 63 is an error (PHP quietly gives 0), and bits shifted off the
  top are gone, as in C, Java and Rust: a shift moves bits rather than scaling a quantity. No
  `&`/`|` on bools.
- **`??`**: a bad key type or indexing an int on its left still errors.
- **`?:`** evaluates only the taken branch; `$x ?? $y ? 1 : 2` tests the coalesced value.
- **`|>` is parser sugar only** (`pipe()` in `parser.gaz`): the right side is read at the next
  tighter level, as any operand is, and becomes an ordinary `FunctionCallAST` or `CallValueAST`,
  so nothing below the parser learns of it. `#grouped` (the last expression `parenthesised()`
  gave) is how a parenthesised expression is told from anything else that starts with `(`.
  - Its precedence costs `$x |> f .. "!"`, which pipes into `f .. "!"` and is refused saying `|>`
    binds looser than `..`.
  - **An unparenthesised chain as `??`'s right side or a ternary's else is refused**
    (`pipes_alone()`: the operand is `#piped`, the call `pipe()` gave last, and not `#grouped`),
    since `$x ?? "" |> trim` pipes only the default while reading as piping the result, and either
    is one pair of parentheses away. A ternary's middle is closed by `:`, so it can't mislead.
    The precedence stays: moving it moves the trap to another pair.
  - **Refused**: a method (`|> $obj.m()`, `|> $obj.m`, `|> #m()`), the restrictive choice,
    loosenable later; anything else that isn't a name, a variable or a parenthesised expression
    (`|> 5`, `|> $h["k"]`). `ponytail:` `|> ($a, $b) -> ...` gets the plain `Unexpected ','`.

## Numbers

- Literals: `0O` is refused, being hard to tell from `00`; `0o78`, `0o7.5` and `0o7e5` are each
  one invalid literal rather than two tokens (`octal()` in `lib/syntax.gaz`). Hex has no exponent
  (`0x1e5` is 485); no `0b`. `parse_number()` in `value.c` reads the same syntax for
  `to_float()`, and JSON numbers are valid.
- An infinite float literal is a lexer error.
- **`**` is square-and-multiply** (`power()` in `ops.c`), never libm's `pow`, whose rounding
  differs between platforms and would break recordings. Squaring the base overflows only when the
  result would. `log`, `exp` and fractional powers stay open until GazLang defines an algorithm for them, since
  libm's differ in the last bit. `sqrt` is libm's, as IEEE 754 requires a square root to be
  correctly rounded.
- `/` converts ints first, so it loses precision above 2^53.
- **Printing** is `format_float()` in `value.c`, always with a dot or exponent (`1.0E+25`), used by
  echo, `to_string`, interpolation, `--tokens` and bytecode. It tries both neighbours at each
  length, since next to a power of two the correctly rounded candidate can fail to read back.
- `round()` is PHP's, with its pre-rounding, written out in `php_round()` in `builtins.c`, since
  PHP's own changed between 8.5 releases. `sum` is
  `binary_op(OP_ADD)`. `to_int` truncates a float; `to_float(true)` is 1.0.
- **`to_int($x, $default)` and `to_float($x, $default)`** (`fall_back()` in `builtins.c`) exist so
  bad input needs no `try`, which would also catch running out of call depth; a type other than a
  string stays an error, being a mistake rather than bad input.

## Strings

Names are ASCII.

- `\0` before a digit is a lexer error because it is octal in PHP and C.
- Interpolation: the lexer emits `STRING_START`, the tokens, `STRING_MIDDLE`, `STRING_END`, with a stack
  so strings nest; the parser desugars to `..`, so the VM needs nothing.
- **A constant's name alone in braces interpolates it** (`{NAME}`, `{ns::NAME}`, `{Kind::NAME}`,
  the `}` right after the name), since a constant has no sigil, and without it `"{LIMIT}"` would
  quietly be text. The shape is exact (`at_braced_name()` in `lib/syntax.gaz`), so
  `{ X}`, `{X:1}`, `{"a": 1}` and CSS stay text. The lexer gives each part as an `IDENTIFIER`, a
  keyword too; an `IDENTIFIER` can start an interpolation only this way, so `interpolated_value()`
  in `parser.gaz` marks the name `#braced`, and once the program is read anything but a
  constant's is an error (`fail_braced_name()`): a misspelt constant names `\{` too, since the
  text may have been meant. Any name, not only uppercase ones, since the language doesn't enforce
  a constant's case. This broke `{word}` text in strings, which has no compatibility promise yet.
  The bytecode loader still reads `"{X}"` in a `PUSH` as text; the compiler writes `\{`.
- `quote()` in `value.c` is the exact inverse of a literal, and everything that shows a string
  as source uses it (a template's text too, which is how `{X}` in its HTML stays text).
- **Tagged strings are parser sugar only**: the lexer gives a `TAG` token (the name) instead of
  `IDENTIFIER` when a non-keyword word touches `"`, the string's tokens following as usual, and
  `tagged_literal()` in `parser.gaz` makes an ordinary `FunctionCallAST` of two list literals (the
  parts all constants, so pushed as one value), marked `#tagged` only so an arity error can say
  what was passed.
  - **Names only**, since a tag is looked up when the program is read. **No raw text list**:
    nothing needs one yet, and `name'...'` is refused so that spelling stays free for a raw tag.
  - **A keyword is never a tag**, so a keyword can't become one later without breaking programs;
    a capitalised keyword is a name, so `ECHO"x"` is a call (with the lowercase hint when
    undefined).
  - **Refused with a message**: a name, a space, then a string (`A tag touches its string`, which
    no valid program has, and a miscapitalised keyword keeps its own hint), and a tagged string
    after `|>` (`pipe()` would otherwise add a third argument).
  - The library's tags (`db::sql`, `web::html`) are each in its namespace, never a global name;
    `lib/` can use a tag only through a `bin/gaz` whose built-in compiler knows them, since the
    library is compiled by it.

## Lists and maps

- **Two types, not PHP's one array**, so a list's indexes are always 0 to len - 1. Duplicate keys
  in a literal keep the last; `[k => v]` is a parse error pointing at `{}`.
- Writing in place is only ever through a variable's path.
- `Undefined key: "k"` quotes strings so `"1"` and `1` differ.
- Writing: keys are evaluated left to right, then the value, then the variable is read, so side
  effects are kept. A list index must exist, a map may gain its last key, missing keys along the
  way are errors, appending to a map is an error.
- `delete`'s target is written like an assignment's and must end at an index; a list's later
  elements move down, a map keeps its order, removing what isn't there is an error. It can't
  take a field (`Cannot delete a field`), a variable, `$a[]`, a call's result or a string; a
  method may still be named `delete`. No `pop` because a function can't change its argument.
- Spread: `Cannot spread map: only a list can be` and `Cannot spread list: only a map can be`,
  raised before the entries after it run (a list's indexes as keys would be a silent surprise).
  `MAP_EXTEND`, `map_set()`. `f(...$args)` and a bare `...$a` are parse errors that say so; each
  could be added later without breaking anything. `...` is one token (longest match, so `.....`
  is `...` then `..`).
- `echo` prints them as literals; arithmetic and unary `-` on them throw.

## Statements, functions and scope

- `for` is desugared in the parser into a `while` whose step runs after the body and on
  `continue`. `break;`/`continue;` outside a loop are parse errors.
- `foreach` iterates the list or map as it was when the loop started; the loop variables (`$` or
  `@`, or a list pattern for the value) keep their last values. Anything else is `foreach expects
  a list or map`.
- **A trailing comma** is allowed after the last item of every bracketed list (a list, a map, a
  `match`, a call's arguments, a parameter list, a lambda head), so one item per line diffs
  cleanly; a comma alone (`f(,)`) or two in a row (`f(1,,)`) is still an error (in a list
  literal two in a row are an empty slot), and `($a,)` without `->` is the comma error
  `($a, $b)` is. Lists that end at a word or a `;` (`implements`, a
  `use` clause, a match arm's values) take none, since nothing closes them.
- `fn` is top level only so `break` can't reach a caller's loop. The parser checks every call's
  name and argument count once the whole program is read, so the VM trusts calls. A default is
  evaluated inside the function, so it sees earlier parameters and a `[]` default is never
  shared; the arity is then `[required, total]`.
- **Appending to a list parameter changes only the call's copy**, so a parameter whose every use
  in the body is a write through an index is a parse error (`fn add_to($l) { $l[] = 1; }`,
  `check_lost_writes()` in `parser.gaz`, at the first such write), in functions, methods and
  lambdas. Conservative on purpose: any other use (`return $l`, a read, a reassignment) lets it
  pass, and a path with a field in it (`$bag.items[] = 1`, `$rows[0].n = 1`) is never noted,
  since it could reach an object. `ponytail:` `$l[] = len($l);` with no other use still passes,
  by design: a read only inside a lost write's own right side could be ignored safely, but the
  check stays one shape a reader can predict. Mutable state belongs in an object,
  or, for closures, in a `shared` variable. Explicit by-reference parameters stay refused ("values,
  not references": a parameter would alias the caller's variable).
- `return` outside a function is a parse error; no return gives `null`. Variables holding `null`
  are still defined.
- Calls are capped at 100000 deep (`MAX_CALL_DEPTH` in `gazvm.h`), a catchable GazLang error.
  The cap is bounded by the C stack, not the value stack: a callback (`map`, `sort`, `to_string()`)
  nests on the 1GB thread stack, about 5KB a level in a release build and 36KB or more in a
  sanitized one, where every local of `call_builtin()`'s `switch` gets a slot of its own. So **no
  stack buffer belongs in `call_builtin()`**: a big one goes in a `noinline` function (`read_stream()`),
  and `-Wframe-larger-than=4096` on a sanitized build names any that crept back.

## Builtins and the standard library

Builtins are `builtin_info[]` in `builtins.c` (name to an arity, or `[fewest, most]`), can't
be redeclared, compile to `CALL_BUILTIN name argc`, and check argument types by their
`type_of()` names.

**Before adding or changing a builtin or a file of `lib/`, read `docs/library.md`**: the rules
behind each builtin and library file (files, directories, `run()`, sockets, databases, time, the
terminal, random numbers, cryptography, how the library is built into the VM) and how each is
tested, such as `sleep()` and `time()` being tested by shape since they can't be recorded, and
anything that prints random values calling `rand_seed()` first.

## Function values and closures

- A bare name is unambiguous as a value because variables have sigils. Named functions are
  interned, so `add == add`; closures compare by identity of creation.
- Only `name(...)` is checked at parse time; a call on a value evaluates the callee, then the
  arguments, then checks callability and arity (the parser's wording), then calls.
- `echo add` prints `function add`, a closure `function -> at file.gaz:12`; errors name a
  closure by where it was made, then where it was called.
- **Lambdas**: an expression body extends as far right as it can, so a lambda sits at the
  ternary's level; `break`/`continue` can't leave a block body. A lambda returning a map writes
  `$x -> ({"v" => $x})`, since `{` after `->` is a block; a `=>` where the `;` of a block body's
  first statement goes says so (`#lambda_block_start`), since that shape is only ever a map
  meant as the value. An empty block body (`-> {}`) is an error rather than a quiet `null`,
  for the same reason: `-> null` says it. The parser needs no lookahead:
  `ternary()` marks a `(` as a possible head and checks the elements only if `->` follows.
- **Captures**: a `$` variable the body uses that isn't a parameter and that no plain `=`,
  `foreach` or `catch` in it assigns is copied in (if it exists) and shared by recursive calls,
  so temporaries can't leak between them. A closure is one value (`$g = $f` shares its
  variables), a captured name that didn't exist stays undefined until the closure sets it with
  `??=`, `@globals` are never captured, and a closure made in a method keeps its object.
- **`shared` is parser sugar only** (`shared_declaration()` and `variable()` in `parser.gaz`):
  the statement assigns a `Shared` box (a builtin kind holding one `value`, compiled only into
  programs that use it) to a hidden `$#shared_x`, and every later use of `$x`, in the function
  and its lambdas, is `$#shared_x.value`. That is an ordinary field, so write paths, `++`, `??=`
  and `delete` need nothing, a lambda captures the box (a handle) by value, and the VM, the
  bytecode and the collector learn nothing.
  - **A declaration and a keyword, not a mark on each use or on the closure**: a `$$x` sigil is
    wanted for variable variables; PHP's `use (&$x)` on the closure makes every use of the variable
    in the scope around it quietly a shared one, and a closure that forgets it reads a snapshot;
    and it is the declaration, which makes a new box each time it runs, that gives each pass of a
    loop and each call of a function its own variable.
  - **`variable()` counts each plain use**, so a `shared $x` after one is refused; a lambda's parameters are read as expressions before the `->` shows what they are, so `lambda()` takes them back (`forget_uses()`).

## Objects

Declared fields and single inheritance give every kind a fixed layout, so fields are slots
and methods a table in C.

- **Kinds** share the namespace of functions, builtins and constants. `abstract kind` can't be
  constructed; `abstract fn` must be defined by a concrete child kind. A kind body holds only
  fields, methods and constants. `echo Point` prints `kind Point`; a call by name is checked
  like a function call. A kind is a kind of thing, hence the word.
- **Constructing** sets the field defaults (the parent's first, in order), then runs `_` with
  the arguments. A kind without `_` inherits its parent's. `##_(...)` is allowed only in a
  constructor, and nothing calls the parent's automatically. `return value;` in `_` and `_` as a
  member are errors.
- **Promoted parameters** are parser sugar only (`parameters()`/`promoted_assignment()` in
  `parser.gaz`): a synthesised `#x = $x;` prepended to the body, indistinguishable from a
  hand-written one below the parser. A promoted field takes no default of its own.
- **`#name`** outside a method is `Cannot use #name outside a method`. **`##name`** is decided at
  parse time from the kind it is written in, methods only, not abstract ones. Bare `##` is a
  parse error, kept free for the parent kind as a value; `#.name` says to write `#name`. Member
  names can be any word, keywords included.
- **Members** share one namespace across the hierarchy: a child can't redeclare a field or
  constant or give a field a method's name (the error suggests a new name). An override must
  accept every argument count the parent's does (constructors exempt), and an abstract method
  can't replace a concrete one.
- **Visibility**: `pub` means one thing everywhere, so one keyword for the whole language; `kin`
  and `kind` are one root. Markers are only `pub` and `kin`, so a reader learns one spelling.
  `Parser::RESERVED` is empty, kept for the next word decided before it is built: one there says
  it is reserved wherever it lands, where a statement, a member (after a marker too, and in an
  interface) or an expression starts (`refuse_reserved_member()`).
  - **The asking kind is where the code is written**, checked by `check_member_escapes()` from
    the kind each use in `#uses` was written in; a kind's own private member named through a
    child (`Tally::n` in `Counter`) is its own (`as_asked()`). A block's header carries the kind
    (`in Kind`, or the dot in a method's name), so the VM pays nothing until a member is looked up.
  - **A parent's private member is the parent's own**, which is why a method table can hold two
    of one name (the child's declared by the child, not an override, so it takes any marker and
    its own children override it; `claim()` in `parser.gaz`). The initialiser sets every slot the
    kind has (`KIND_INITIALISER` in `ops.c`).
  - **An override escapes as far as what it replaces**, the restrictive choice on purpose:
    loosening it later breaks nothing. A `method` line carries a declarer once an override makes
    the two differ (`method area Square kin Shape`). A private `to_string()` would silently print
    the default form, hence `pub`.
  - **`fields()` and `echo` show every set field** because `--ast` walks objects that way, and an
    `echo` that hid half an object would be a debugging footgun.
- Objects are always true, and operators, keys, indexes and `foreach` on them are errors.
- **`.` is member access**, checked when it runs (`Account has no member foo`). `.name` is one
  token glued to its name, but may start a line so chains continue. `$obj.name(args)` evaluates
  the object, looks the member up, then the arguments, then calls. A bound method is `==`
  another when object, kind and method match.
- **Write paths**: a variable or `#`, then any index or property steps; `store_path()` in `ops.c`
  is the one definition. A path starting at a call: the code generator holds what the call
  returned in a hidden `$#root_n`, evaluated once and before the keys (`write_root()`), and
  writes through that, so the VM learns nothing. Without a field it is a parse error
  (`writes_through_call()` in `nodes.gaz`); a field on what isn't an object is the runtime's
  error, as on a variable's path. Other expressions (`[$o][0].x = 1`) are still refused.
- **`?.`** skips the whole chain (JavaScript's, C#'s and PHP 8's rule, not one step at a time).
  **Parser and code generator only**: the lexer glues `?.name` into one `NULLSAFE_PROPERTY`
  token, the parser makes a `NullsafePropertyAST` (a `PropertyAST`, so everything that asks
  `is_a` treats it as a member) and wraps the whole chain in a `NullsafeChainAST`, which no write
  path accepts, and each `?.` compiles to `JNN go; PUSH null; JMP chain_end` with the chain's end
  label on a stack (`nullsafe_ends`), so the VM and the loader learn nothing. On the left of `??`
  the chain is read quietly, as `.` is there.
- `kind_of($x)` is strict: anything but an object is an error.
- **`to_string()`** is also used inside lists and maps; it must return a string and take no
  arguments. Without one an object prints as `Account {#owner => "Werner", #balance => 75}`,
  and one already being printed as `Account {...}`.
- **`to_json()` is a protocol**, like `to_string()`, rather than a callback at each call (every
  caller would have to remember it) or writing `fields()` (which shows private fields on purpose,
  so an API would leak a password hash the day someone returns the object, and would duplicate
  shared references). Decoding into kinds by type tags is refused for good: a document choosing
  which kinds are built is PHP's `unserialize` object injection. A missing `to_json()` is the
  runtime's own `Team has no member to_json`; an object inside its own is an error by
  `object_id()`, not a loop. GazLang only (`lib/json.gaz`). A `to_json()` whose data must also
  compare with `==` or round-trip without JSON (the football game's tests do both) returns plain
  data all the way down rather than leaving nested objects to the encoder. `to_string()` and
  `to_json()` stay protocols, not interfaces: printing and encoding ask the object, and a kind
  that has one needn't say so.

## Interfaces

- **Methods and nothing else**: `interface Shape { fn area(): float; }` names signatures, which
  `parameters()` reads as a method's (types and defaults; a default only sets the arity). No
  bodies, fields, constants, statics, markers (every method of one is `pub`, since an interface
  is a promise to any caller), `abstract`, constructor (each kind's `_` is its own) or `extends`,
  each refused by name in `signature()` and `interface_declaration()`: the restrictive choices,
  and loosening any later breaks nothing. Constants and statics are reached by `::` on a kind,
  which an interface isn't.
- **A name like a kind's**: `#interfaces` in the parser beside `#kinds`, one namespace with
  functions and constants, `pub` and namespaces resolved as a kind's are; `implements` after
  `extends` (`implements_clause()`), resolved in `resolve_names()` and checked in
  `check_claims()`. Kept apart from `#kinds` so everything that takes a kind (`extends`, a catch,
  constructing, `::`, `.`) refuses one by default, each with a sentence (`not_a_kind()`).
- **Checked when the program is read, as an override is** (`check_interfaces()` at the end of
  `resolve_kind()`): every method an interface names, declared or inherited, is `pub`, accepts
  every argument count and keeps the types (`check_override_types()` with its reason). An
  abstract kind may leave one to its children, but one it has is checked where it is; an
  inherited method that fails is reported at the method, naming the kind whose claim it fails.
  Two of a kind's interfaces naming one method must name it alike (`same_signature()`): one
  method answers for both. A kind with the methods but no claim is not an implementer: the
  claim is the contract, and it can't be had by accident.
- **At run time it is a kind value** (`T_KIND`, `Kind.interface`, abstract), so `is_a`, types,
  `==`, `kind_name` and `echo` (`interface Shape`) need no tag of their own, and `type_of` says
  `"kind"`: an interface goes where a kind value goes, and a new type name would be one more
  word in every type. Constructing one is a parse error by name and `Cannot construct interface
  Shape` through a value. `kind_is_a()` reads the kind's flattened list (`Kind.interfaces`,
  worked out once by the loader, ancestors included) only after the parent walk has failed and
  the ancestor is an interface, so `type_admits()` costs a kind or a builtin type nothing more.
- **In bytecode**: an `interface` block (after the functions, before the kinds) of `method NAME
  FEWEST MOST` lines and an empty `locals`, and an `implements` line per interface a kind claims
  itself. The loader checks the claim again (each method `pub` with a fitting arity in every
  kind that can be made, types being instructions it doesn't read), so hand-written bytecode
  can't make `is_a` true of a kind without the methods; it also refuses a kind that is its own
  ancestor, which hung `is_a` before. Only `PUSH_KIND` and types may name an interface. Old
  files load unchanged, so the version stays 3.

## final

- **`final` closes a kind to children and a method to overrides**, so a kind can guard an
  invariant no child should get round (`Html`, whose children could be concatenated as plain
  strings). Checked when the program is read, at the child (`resolve_kind()`); the marker comes
  first (`pub final fn`, as `pub abstract fn`), and `final pub` says so (`refuse_after_final()`).
- **Loud where it would close nothing**, the restrictive choice: `abstract final`, a private
  method (a child's of that name is its own, never an override, `claim()`), `_` (each kind's is
  its own), a method of a final kind, and fields, constants, statics, functions, interfaces and
  their methods are each an error saying why. Loosening any later breaks nothing.
- **Set only when true** (`KindDeclarationAST.final`, `FunctionDeclarationAST.final`), so a tree
  without it dumps as it did. **In bytecode** `final kind` and a `method` line's last word
  `final`, repeated on every record that has the entry (`method_records()` in `codegen.gaz`), so
  the loader checks each kind against its parent alone (`check_final()` in `load.c`). Old files
  load unchanged, so the version stays 3.

## readonly

- **`readonly` closes a field once its object is made**, so an id, a key or a record read from a
  request can be shared without any "did someone change it?" question, and a `readonly kind`
  closes every field, so a shared object nobody can change behaves like a value (money, points,
  colours). Objects stay handles: `==` is still identity, and the object a read-only field holds
  is still writable through it (shallow, as everywhere else). A changed copy (`with`) and
  field-by-field `==` for read-only kinds stay open in #88.
- **Written only while the object is being constructed, and only by the declaring kind's own
  constructor**: `_` itself, a promoted parameter, a field default, and a parent's `_` reached by
  `##_(...)` for the parent's own fields. A child's constructor, a helper method and a lambda made
  in the constructor (it may run later) are refused: the restrictive choice, Swift's and C#'s, and
  loosening it later breaks nothing.
- **Two checks, one sentence**: the parser refuses every `#field` write it can see
  (`check_member_use()`, as `case_never_changes()` refuses a case: `=`, compound, `++`, `??=`,
  `..=`, `delete` under it, `#tags[] = ...`), and the VM refuses the rest where every field write
  already goes (`check_field_readonly()` in `ops.c`: `SET_FIELD`, `SET_FIELD_POP` takes the long
  way, `write_path()` and `remove_path()` at every field step that isn't followed by another
  field step, since a write inside a list or map the field holds changes the field and a step on
  through a field writes another object), both with `Account #id is read-only: only Account's
  constructor sets it`. The VM's window is `Object.constructing` (in the padding, so no object
  grows), set by `NEW` and cleared when its frame returns or is unwound by an error, so an object
  that escaped from a failing constructor is closed too; inside it a write is taken from the
  declaring kind's code or the initialiser (`KIND_INITIALISER`), so a helper function of the kind
  writing through a handle to the object works while the constructor runs and never after. A kind
  with no read-only field pays a test of a NULL pointer.
- **After the marker, as `final` is** (`pub readonly int #id`, `fn _(pub readonly #id)`,
  `pub readonly kind`, and `abstract readonly kind`/`final readonly kind` in that order);
  `readonly pub` says to write `pub readonly`. Refused where it closes nothing, each with a
  sentence (`refuse_after_readonly()`, `READONLY_REFUSED`): a field of a read-only kind, a static
  field (that is a `const`), a method, a function, a constant, an interface and its methods, an
  enum and its methods, a plain parameter.
- **Inheritance is read-only all the way**: a read-only kind extends only a read-only kind and is
  extended only by one (`resolve_kind()`), so a header speaks for every field an object of it has
  and no child reopens a parent's field. The restrictive choice; loosening it breaks nothing.
- **In bytecode**: `readonly` before `kind` on the header (after `abstract`/`final`), and on a
  field line after its marker and before its type, the declaring kind's word repeated on every
  record that has the slot, so the loader checks each kind against its parent alone
  (`check_readonly()` in `load.c`: the headers agree, and each field line says `readonly` exactly
  where the parent's does). Old files load unchanged, so the version stays 3. `--ast` shows
  `readonly` and `readonly_fields` only where set.

## Enums

- **A closed kind whose cases are its only objects**, so typed parameters, `is_a`, `kind_of`,
  methods and `implements` need nothing new, and `==` and `match` compare a case by identity. A
  KindDeclarationAST with `#cases` set (`enum_declaration()` in `parser.gaz`), in `#kinds`, so
  everything that takes a kind takes one and only what must refuse it asks (`is_enum()`).
- **A case is a static slot the VM fills** (`make_cases()` in `vm.c`, when a run starts, since
  object ids and statics are the run's), so `Filter::Open` is `LOAD_STATIC` and the parser
  registers each case as a static (`check_new_static()`): nothing below the parser learns a new
  way to name one. Writing one, or under one, is refused when the program is read
  (`case_never_changes()`), as is constructing one.
- **Values are all or nothing, `string` or `int` literals, unique**, so `from()` finds one case.
  Literals rather than constant expressions: the restrictive choice, loosening it breaks nothing.
- **`cases()`, `from()` and `to_json()` are the compiler's code, not source** (`#generated` on
  the node, `compile_function()` in `codegen.gaz`): `from($value, $default)` needs to know whether
  its default was passed, which is `ARGC` and no GazLang can say, and nothing in C would serve a
  builtin taking its name from every program. A backed enum's `to_json()` is its value, so
  `json.gaz` learns nothing; one the enum writes itself wins. `.value` is a real `pub` field,
  typed with the backing type, so reading it is an ordinary field read with the inline cache.
- **Immutability is the type check's**: every write into a typed field goes through
  `check_field_type()`, which refuses a case (`Object.case_number`), so no write path needs a check
  of its own and untyped code pays nothing. An enum without values has no fields to write.
- **No fields, static fields, constructor, `extends`, `abstract` or `final`**, each an error that
  says why: the restrictive choices. No exhaustiveness for `match`: the subject's type isn't known
  when the program is read. A case is not a map key, as no object is (`ponytail:` key by
  `.value`).
- **In bytecode**: an `enum Name [string|int]` block, its record as a kind's, a `case NAME
  [literal]` line each, and no code; the loader checks the record (`check_enum()`,
  `find_case_slots()` in `load.c`) and refuses `NEW`, `CALL_CONSTRUCTOR` and a store into a
  case's slot. Old files load unchanged, so the version stays 3. `LOAD; LOAD_STATIC; op` is a
  superinstruction so a `match` on cases costs what one on string constants did.
- **`lib/web.gaz`'s scanner states and placements are private enums**, so a misspelt state is an
  error when the library is read. Measured on one binary, `web::html` costs what it did with string
  constants once a template is scanned, and a percent or two more scanning a new one: identity is
  a pointer compare, as comparing two constant strings in effect was, so enums buy checking, not
  speed. `date::Resolution` replaced `Zone.time()`'s strings, and `apps/todo`'s filter
  is `enum Filter: string` with `label()` and `empty_text()` in place of parallel maps.

## Command line arguments

- **Builder methods, not a spec map**, so a misspelt method is caught when the program is read
  and each piece of help sits by what it describes. A declaration that can't work (a second
  `--verbose`, `-h`, an argument after `rest()`, a required one after an optional one) is an
  ordinary error: the program's mistake, not its user's.
- **It exits for the program** (2, the Unix code for usage, with `Run 'tool --help' ...`), since
  every tool wants that, so none repeats it.
- A subcommand's handler gets its parents' flags and options too, accepted after the command
  word and shown in its help. A command with subcommands takes no arguments of its own. `-`
  alone is an argument.
- `ponytail:` a negative number as an argument needs `--` first (`-5` is read as an option); no
  "did you mean" for an unknown option; values aren't typed (`to_int($args["n"], null)`).
- Tested by `tests/gaz/lib/cli_test.gaz` and by `CliTest` running `tests/corpora/cli/todo.gaz` for the
  exits and which stream each goes to.

## Templates

- **Compiled by the compiler when imported**, not a library: GazLang has no eval, so a library
  could only offer logic-less templates. `compiler/template.gaz` translates a `.gazml` file into
  GazLang source, **one line of source for each line of the template**, then lexed and parsed like
  any file, so every error is at the template's own line and the code generator, the VM and the
  bytecode learn nothing. The translator checks the blocks nest, so a mismatch is a sentence about
  the template, not a parse error in source the user never wrote.
  - The `namespace` and `import` lines before `@template` are found by `is_namespace_line()` and
    `is_import_line()` and passed on as lines of the generated source, so the parser checks them.
    Tested by `tests/gaz/templates/namespaced_test.gaz` and the `template_namespace`,
    `modules/template_imports` and `error_template_*` corpora.
- **`{{ }}` leaves an `Html` as it is (`Html::escape()`)** so `{!! !!}` is rare, which is the
  point: each one stands out in review, where if every template call needed one, a
  `{!! $comment !!}` would hide among them. It is how Rails, Django and Jinja stay safe.
- **Concatenating an `Html` is an error** because the plain string it gave was escaped again by
  `{{ }}`, never with its contents in the message. `Html` is `final`, so a kind extending it is a
  parse error. The loader notes the kind named `Html` (`html_kind`, as `error_kind`) and
  `append_joined()` in `value.c` refuses it on the conversion path, one tag compare ahead of
  echo's conversion; it still refuses a child of it (`kind_is_a()`), since bytecode written before
  `final` can hold one and still loads. `format`'s helpers call
  `to_string()`, so make no `..`; `{!! !!}` writes `to_string((...))` for the same reason.
- **`web::html"..."`** (`lib/web.gaz`; not the `Html` kind and not a global `html`, so it takes no
  name from a program) reads the text as a browser would (`Scanner`, in the same file, over the
  tokenizer's states: text, tags, attribute names and quoted, unquoted values, comments,
  declarations, raw-text elements, `<textarea>` and `<title>`, `<svg>` and `<math>` as foreign
  content) to find where each value falls. No VM or parser change.
  - **A bad URL scheme becomes `about:invalid#blocked`, not an error**, since a bad link typed
    into a profile field shouldn't 500 the page and the result is still safe (Go's
    `html/template`, Angular and templ all neutralise too). An int later in a URL is written as it
    is. **Whether a value is at the start is read from the program's text, not from earlier
    values**, so the scan doesn't depend on what a value holds and can be kept; the `:`/`&` check
    after a first value is `after_start`, made as the next text is read.
  - **Refused besides what the docs list**: without a value at all, **a `<script>` holding
    both `<!--` and `<script`**, which a browser doesn't end at the first `</script>` and the end
    tag search here would. A comment ends at `--!>` as well as `-->`, and an `=` where an
    attribute name should start is part of the name, as the tokenizer reads them. Errors are the
    call's, at run time, as `db::sql`'s checks are.
  - **A refresh's content is a URL sink** (`Refresh` in `web.gaz`, read as the standard's shared
    declarative refresh steps read it): a value is the delay, alone at the start and an int, or in
    the URL after a `url=` the text writes, placed as in `href` (and blocked if it starts with a
    quote, which a browser skips there); anywhere else it is refused, since without `url=` a
    browser takes whatever follows the delay as the URL. `http-equiv` may come after `content`, so
    a `<meta>`'s content values are placed as text and placed again at the tag's `>`
    (`settle_meta()`). A value in `http-equiv`, or in the content of any other `http-equiv`
    (`content-type`'s charset), is refused: the restrictive choice, loosening it breaks nothing.
  - **Scanned once for each tagged string** (`Scans::of`, a static map keyed by the parts as a
    literal, which tells `["a\0b", "c"]` from `["a", "b\0c"]`, emptied at 2000), since the parts are
    literals in the source: a tag site costs one scan, and a call about a microsecond more than
    plain escaping.
  - **Checked against an HTML5 parser that shares no code with it** (`tests/HtmlContextTest.php`,
    PHP's `Dom\HTMLDocument`): each template with ~45 hostile values must give a page of the same
    shape as with a harmless value (same elements, attribute names and comments), with no URL that
    runs script because of a value, and a text value read back exactly; the refused templates must
    be refused for every value; and **templates stitched together at random from pieces of markup
    must be refused or written safely**. That test only sees unsafe *acceptance* (refusing more is
    always safe), so the hand-written cases cover over-refusal; each rule was broken on purpose and
    caught. **Its templates use one value for every placeholder, so it can't see two harmless
    values make something together**: `test_two_values_cannot_make_a_script_url_between_them`
    fills two-value URL templates with every pair of 22 scheme fragments. URLs are compared with
    the harmless page's where the template writes a `javascript:` link itself.
    `tests/gaz/lib/web_context_test.gaz` has the rules and every message written out, and
    `tests/gaz/lib/web_test.gaz` and `tests/gaz/templates/web_html_test.gaz` the rest.
  - `ponytail:` no `<script>` or `<style>` data (a `web::json` helper is the way, when a program
    needs one); text
    split across a value (`</scr{$x}ipt>` in a part that a browser would read as one tag) is
    only refused where a value could finish it; `<noscript>` is read as markup.
- **`Html` is a builtin kind in `BUILTIN_SOURCE`**, like `Error` and `Shared`, compiled into a
  program that imports a template or names `Html` itself (not because its own code does). It is
  the first builtin kind with a static method, which `program()` places itself, since only
  `top_level()` puts a kind's static methods after it.
- **The output is gathered in `$#html`**, a name no program can write: the lexer reads it only in
  source translated from a template (`Lexer($text, true)`).
- `ponytail:` an expression ends at the first `}}` or `!!}`, so it can't hold one, and none spans
  lines; escaping is for HTML text and quoted attributes, not JavaScript or URLs inside a page.
- **Templates are frozen for now**: no new template features until a real web app has used them. They are
  the right shape for whole pages, with loops, conditions and layouts that `web::html` would make
  nested `map` calls, and their errors are at the template's own line, so they stay; but they don't
  read the markup around a value, so **a URL in one isn't checked** (`web::html` does). If the app
  keeps them, the compile-time version of the same scan (the text is known when the program is read,
  so each `{{ }}` can pick its escaper at no run-time cost, and a mistake can be an error then) is
  the next piece, sharing the scanner with `web.gaz`; if it never reaches for them, delete them.

## match

- **Two forms, kept apart**: `match` without a subject exists because `match (true)` silently
  misses a condition returning a truthy non-bool, and keeping `match ($x)` all-`==` leaves a jump
  table possible.
- **No ranges** as arms: `"a".."z"` is already concatenation.
- `No arm matches "x"` quotes a string and names anything else by its type, since printing it
  could run `to_string()`, which must never happen while raising. No arms is a parse error.
- Only a statement `match` may have block arms, and it ends at its `}` (a `;` after it is an
  error); in a statement a map after `=>` is written `({...})`.

## Constants

- **Folded by the parser**: `check_constant_expression()` checks what may appear on the source,
  then `fold()` in `parser.gaz` evaluates it with GazLang's own operators, so errors are the
  runtime's, located at the operator, and short-circuited sides aren't evaluated; a cycle is
  `Constant A depends on itself: A uses B uses A`. Not any expression: one evaluated once at run
  time (`Point(0, 0)`) would bring initialisation order, mutation through a handle, and a slot and
  instruction in the VM.
- **A use is its value**: the parser stamps each use and the compiler pushes the value, so there
  is no constant in the tree below the parser, the bytecode or the VM.
- Constants are immutable because no write path can start at one; the one that could,
  `#NAME[0] = 1`, is refused (`Cannot change constant #NAME`).
- **A kind constant can't be redeclared by a child** since `#NAME` is resolved from the kind it is
  written in; the restrictive choice on purpose: loosening it later breaks nothing. That `::`
  resolves a name and `.` goes through a value is why `$kind.NAME` can't be a constant: the
  operator says so, not a rule of its own.
- `ConstTest::expressions()` requires a constant to give what a running program gives, for
  every operator and kind of value.

## Assignment

- Assignments are expressions whose value is the new value. `++`/`--` are numbers only; prefix
  gives the new value, postfix the old. Appending (`$a[] = v`) is plain `=` only.
- Keys are evaluated once, left to right, then the right side, then the target is read and
  written, so `$k += $k *= 2` sees the updated `$k`. A compound update needs the variable and
  every key to exist (reading a missing key as null would make `$a["n"] ..= "x"` quietly give
  `"nullx"`), and nothing is written if it fails.
- **List patterns**: the count is checked before anything is written; the right side runs first,
  then each target left to right, so `[$a, $b] = [$b, $a]` swaps. Targets are anything `=` can
  assign. No nesting, map patterns, compound operators or append targets. The parser reads an
  empty slot as an empty entry in the list literal (`list_literal()`) and a pattern takes it as a
  `null` target (`list_pattern()`, the code generator skips it); a literal that keeps one without
  becoming a pattern is an error once the program is read, and a pattern of empty slots alone has
  nothing to take apart.
- **A rest** (`...$rest`, one per pattern, anywhere in it, as one in a list literal can be) is
  lowered by the code generator: `DESTRUCTURE_REST` checks for at least the other targets, those
  before it index from the start, the rest is `slice()`, and those after it index from `len()`.
  An instruction for the check, since a thrown string would carry no location and no `Error`.
- **A list pattern parameter is parser sugar**, like a promoted parameter (`pattern_parameter()`
  in `parser.gaz`): a hidden `$#pattern_N` parameter in its place and the destructuring prepended
  to the body, an expression body becoming a block that returns it, so the variables are the
  call's own and a lambda never captures them. Nothing below the parser learns of it. A lambda's
  one pattern needs no parentheses because `ternary()` sees a list literal followed by `->`.
- `..=` appends in place (`concat_assign()` in `ops.c`), on a variable, a field or an element
  alike, so building a string with it is linear rather than a copy per append.

## Types

- **Where the checks live**: a typed parameter or return is an instruction in the function
  (`CHECK_PARAM` after the defaults, `CHECK_RETURN` before every `RET`, after the function's own
  try blocks are left), a typed field or static field a word on its record in the bytecode,
  checked in the two places a field write goes through (`SET_FIELD` and `write_path()` in `ops.c`)
  and in `STORE_STATIC`. Records rather than instructions for fields, since a field is written by
  many instructions and from outside its kind; instructions rather than records for parameters
  and returns, since `CALL` and `RET` are the hot path and a record would put a test on every call.
- **A value whose type is plain from the source is checked when the program is read**
  (`check_known_types()` in `parser.gaz`, after every name is resolved): a literal, a `..`, a
  constant, a case or a kind being constructed (`known_type()`), against the parameter types of
  a call by name, a `#method()` (the version the kind it is written in names, since an override
  keeps the types), a `##method()` or a constructor, a typed return, and parameter, field and
  static defaults, with the VM's own sentence so the error reads the same whichever finds it.
  Two places differ from what the VM could say, on purpose: a `#m()` names the version the
  kind it is written in sees, where the VM names the override that runs (only the kind in the
  name differs, since an override keeps the types), and an interface's or abstract method's
  default is refused though it never runs, a default contradicting its own type being a
  mistake wherever it is written.
  Only certainty, no inference: a variable, a call's result and `$obj.method()` stay the VM's,
  so nothing correct is ever refused. A test of the VM's check passes its value through a
  variable (or `opaque()` in `tests/corpora/vm/types.gaz`); the parser corpus's
  `error_known_type_*` are the early ones.
- **No lambda return type** because telling a ternary's `:` after `(...)` from a return type would
  take lookahead past the type. A lambda's typed parameters are read as an expression first
  (`int|null` is two bare names and a `|`) and taken for a type when a `$parameter` follows, which
  no expression can be followed by (`lambda_item()` in `parser.gaz`); a type before a pattern
  parses as an index and gives the `]` error, which is the price of no lookahead.
- Names are `TypeAST`, resolved in `resolve_names()` and checked to be kinds in `check_types()`;
  `kind` and `null` are keywords, so `type_name()` takes those tokens too. **No generics yet**,
  since checking an element type at run time would walk the list on every call; they come later as
  a static check only.
- **The one conversion, int to `float`**, is `type_admits()` in `ops.c`, widening in the slot, the
  stack or the field, because a program that computes an area shouldn't have to write `2.0`;
  nothing else converts, as `==` and `+` don't, or a type would be one more place a string quietly
  became a number. **`: null`, not `void`**: the value's type, with no rule of its own.
- An object in a type error is named by its kind (`describe_type()`), since `object` would leave
  out what the check was about.
- **An override keeps the parent's types** (`check_override_types()`): the restrictive choice,
  loosening it to covariance later breaks nothing.
- **The tree dump shows types only where they are**: `param_types`, `return_type`,
  `field_types` and `static_types` are fields set only when a declaration has a type, since
  `fields()` leaves out what was never set, so an untyped program's `--ast` is what it was.

## Errors and try/catch

- **`Error` is written in GazLang** (`BUILTIN_SOURCE` in `parser.gaz`, located as `<builtin>`), compiled only into programs that can catch, name or extend
  it: a program that throws but never catches doesn't need it, since only a catch turns an error
  into an object.
- **Throwing never runs `to_string()`**: uncaught, gaz prints `Error: ` and the value as echo
  would, with no location (runtime errors keep theirs), the text made only then. `throw` compiles
  to `THROW`, which ends its path as `RET` does.
- **A keyword, not a builtin**: raising is control flow, like `return`, and a reader (and the
  loader's stack walk) should see that nothing after it runs; a builtin also takes its name from
  every program, and `error` is a name programs want. **Replacing `error()`, not joining it**:
  two spellings of one thing would be the first thing a style guide had to settle. A call to an
  undeclared `error()` says to write `throw` (`undefined_hint()` in `parser.gaz`); a program may
  declare its own `error`.
- **`throw` is parsed where a lambda is**, at the start of `coalesce()`, so it can begin any
  expression down to `??`'s right side. As the operand of anything tighter it is refused with a
  message saying to parenthesise it, since `1 + throw $e` reads as a mistake. `throw;` is an
  error: a rethrow names what it throws.
- **`#trace`**: a method run from inside an expression (`to_string()` by echo, `..`
  or a builtin) is called from where the expression is running, and its trace carries on through
  the calls outside it; once the program has ended (printing an uncaught error) it has none. The
  VM reads its frames when an error happens, which costs nothing until then. Its nested loops
  learn where they were called from through the instructions that can run program code (`PRINT`,
  `CONCAT`, `CONCAT_ASSIGN*`, `CALL_BUILTIN` and `CALL_VALUE` of a builtin), which say where they
  are; `trace_test.gaz` has a case for each, so one that forgets fails there.
- **`#cause`** is a constructor argument (`Error($message, $cause = null)`) as well as a `pub`
  field, so a wrap is one expression; untyped, since what a plain `catch` holds may be any value
  and wrapping it must never fail while handling an error. The VM makes no new instruction for it:
  `caught()` in `vm.c` sets it null on the Errors it builds itself, when the kind has the field
  (bytecode from before it has none, and still loads). Uncaught, `report_causes()` prints each
  cause as echo would, with its whole trace (a caught error's message never says where, so even
  one call is shown), the first `MAX_CAUSES` (10) and a count of the rest, ending at an Error
  already walked, since `$e.cause = $e` is a loop. `http.gaz`'s 500 log writes the same lines in
  GazLang (`describe_causes()`); `tests/corpora/vm/uncaught_cause_*.gaz` print the log's version
  and then die, and `http_causes_test.gaz` requires the two to agree byte for byte.
- An unmatched error carries on unchanged.
- **`finally`**: a return's value is worked out first; an error in it replaces what was in
  flight; `return`, `break` and `continue` can't leave it. A `try` needs a catch or a finally.

## Comments and names

- **Block comments don't nest** (as in C): nesting once let a region holding a comment be
  commented out, but nothing used it while globs and paths in doc comments kept tripping it.
- **Docblocks are a convention, not syntax**, so no program can depend on its documentation.
  `compiler/docblocks.gaz` is the one reader, `pub` in `namespace gazlang` for the website and the
  language server, on the compiler's lexer so a `/**` in a string is never one; the compiler never
  imports it, so the seed doesn't change with it. It gives a file's namespace, overview, imports
  and declarations (top level and a kind's members, each with whether it is `pub`, its signature
  as written, its line and its docblock's text without the ` * ` margin). One across a blank line,
  or with a plain comment between, documents nothing: "right above" is the one rule a reader can
  see. No tags. `editors/gaz/gaz.tmLanguage` scopes one as `comment.block.documentation.gaz`; the
  site's highlighter shows it as any comment.
- **Keywords are lowercase and exact**, which a self-hosted AST wants (`kind If`). PHP matches
  keywords *and* names case-insensitively; matching only keywords that way was its wart without
  its rule. The miscapitalised-keyword hint is `keyword_hint()` in `parser.gaz`, built only while
  an error is, which also answers a statement that starts with PHP's `elseif` (`write 'else if'`),
  unless a function of that name is declared.

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

- **Its shape, and why**: the lexer's scanner is an object, because `import` needs two lexers
  alive at once; its operators are one table matched longest first. The parser's twelve binary
  levels are one table and a loop (precedence climbing) rather than a method each, 28% faster.
  `lambda_head` is one field, since nothing is read between marking a `(` and asking. A member
  use's record is found by an index the node holds, not a reference, so a kind's tree isn't a
  cycle for the collector. Trees are walked with an explicit stack, since a chain of 5000
  operators is 5000 deep. The lexer's operator table is matched longest first, which assumes every
  prefix of an operator is an operator too (except `..`'s; a lone `.` isn't one): a new operator
  that breaks that needs handling. The code generator dispatches with one `match
  (kind_of($node))`, since GazLang can't build a method name, and copies a rebuilt node's
  location by hand (easy to forget; the corpus checks it). The writer compares directories as real
  paths where they exist, since an import is its real path and the main file keeps the spelling
  it was given, and textually where they don't, since a path written into bytecode needn't exist.
- **The tree dump** (`--ast`) prints each node's fields as `fields()` gives them, skipping those
  that are derived or the code generator's (the driver's `SKIPPED`). On an error there is no
  partial tree, since the whole-program checks write into nodes parsed long before; nothing
  catches a `ParseError` and carries on, so the parser restores no state.
- **Files that only declare** (`parser.gaz`, `nodes.gaz`, `template.gaz`, `codegen.gaz`, and
  the lexer, `std/syntax.gaz`), as the compiler requires of any file that is imported; the
  driver, the main file, is separate. Each imports what it names, `parser.gaz` and
  `template.gaz` each other.
- **The lexer is the standard library's** (`lib/syntax.gaz`), not `compiler/`'s, so a program
  outside the repository's project (`apps/course` highlighting its lessons) can read GazLang as
  the compiler does: a namespace belongs to one project, and the library is the one every
  project can import. Its public surface is what the compiler, the highlighter and the language
  server need (`Lexer`, `Token`, `LexError`, `Lexer::KEYWORDS`, `TEMPLATE_BUFFER`), the rest
  private to `syntax`.
- **The driver raises `LexError` and `ParseError` messages again from the top level**
  (`throw $e.message`), so `--tokens` and `--ast` print only the message; a bug in a port is a
  different error and still arrives with its trace. `LexError` carries `#reason` and
  `#source_line`, since `#line` is where in `syntax.gaz` it was raised. The one `try` in the lexer
  (`hex_value()`) holds only the arithmetic it is about, since `catch (Error)` also catches
  running out of call depth.
- **What it leans on instead of writing out**: `slice(to_string([$v]), 1, -1)` is a value as a
  literal (a string quoted, which is the inverse of reading one), `..` on a float formats it,
  `to_int($text, null)` is the overflow check.
- **Paths** resolve with `cwd()`, `real_path()` and `file_exists()`; the parser harness also
  runs from other working directories, below the main file and through a symlinked path.

## The C VM

**Before changing `vm/`, read `docs/vm.md`**: how the C VM runs a program (values and keyed
hashing, frames on one value stack, errors as return values, the cycle collector,
superinstructions, PGO), the command line with `-e`, `--watch` and `--tty`, and what a REPL would
take. The rule against a stack buffer in `call_builtin()` is under "Statements,
functions and scope" here.

