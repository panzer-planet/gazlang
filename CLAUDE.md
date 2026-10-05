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
  libpq in `pg.c` only (on when found, `make SQLITE=0`/`PG=0` without), built warning-free by
  clang and gcc, commented where the C isn't obvious (a
  flexible array member, a `goto` into shared code), for readers who know a little C.
- **PHP** (the tests and `vm/*.php`): 8.5 or later, PSR-4 under `GazLang\Tests`, methods
  camelCase, PHPDoc on classes and methods. The pipe operator (`$x |> trim(...)`) where a chain
  of single-argument calls reads better.
- `ponytail:` comments mark known ceilings, with what would lift them.

## Layout

- `compiler/`: `lexer.gaz`, `parser.gaz` and `nodes.gaz`, `template.gaz` (`.gazml` templates into
  GazLang), `codegen.gaz`, `docblocks.gaz` (see "Docblocks"), and `gazlang.gaz`, the
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
- `gaz.json` (`{"name": "..."}`) marks a project's root: the repository's, `apps/todo`'s and
  `games/football`'s. Nothing reads one yet; `import` will resolve paths from it
  (`docs/design/modules.md`).
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
- **The roadmap**, in build order:
  1. **Server-side TLS and HTTP/2** in `http::serve`, on its keep-alive.
  2. **Regex shorthands and groups in a replacement**: `\d`, `\w`, `\s` and `$1` in `regex::replace`,
     the two things every reader expects first. Still a Thompson NFA, so no backreferences or
     lookaround.
  3. **A cause on `Error`**, so code that catches a database error and throws its own keeps the
     original (`#cause`, printed under the trace).
  4. **Interfaces**: `interface` and `implements` (reserved now), a parse-time check that a kind
     has every method an interface names, with matching arities and types as an override's, and
     `is_a($x, Shape)` true for an implementer. See "Decided, not built"; `final` follows with it
     or after it.
  5. **Enums**: a closed set of named values for a status or a kind of token, in place of string
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
  compiler's own `Lexer` and `Parser` as a test of the internals does (see "Modules and namespaces" and
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
  "Values"); what is left is mostly lists past four items, map headers and index arrays, closures'
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
    `read_line`, `sleep`, `getenv`, a directory builtin, `rename_file`, `chmod`, `symlink`, `readlink`,
    `sync_dir`, `set_mtime`, `chdir`, a `term_` builtin, a `file_` builtin
    (`/dev/stdin` waits and `/dev/zero` never ends) or a `socket_` builtin is skipped (`getenv` since what it gives isn't the seed's; `worker_recycle` since it ends the process by an unhandled signal, which prints no `GAZVM_STATS` line and would fail the harness for a reason that isn't a bug), an imported
    file's text included, which is sound because a builtin is reached only by its name.
  - **A mutant runs next to the program it came from** (`.fuzz-<pid>-<name>.gaz`, gitignored,
    deleted in a `finally`), so its `./` and root imports find what the original's did; a failure
    is saved with a `.dir` file naming that directory, where `--shrink` runs it again. What a
    mutant imports is the files its bytecode's `@` lines name (as `gaz --watch` finds them, from
    an unsanitized `gaz -c`), not a regex following import lines.
- **Known limits**:
  - The self-hosted parser runs out of call depth on source nested past about 9000 levels
    (recursive descent is about eleven calls a level), as an internal error. Its tree walks use
    an explicit stack for that reason.
  - A `make compiler` stage's own runtime errors name `vm/build/bootstrap/` as the source
    directory, since bytecode paths resolve against the bytecode file; the lines are right.
  - A main file given by an absolute path through a symlinked directory (macOS's `/var`) gives
    import locations that climb to the root and back through the real path.
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
  - `Error`'s members are reserved across its children, so a domain error can't declare its
    own `#line` or `#message`.
  - No copy-with-change for objects, no `catch (A | B $e)`.
  - `match ($x)` is a linear chain of `EQUALS`; no jump table.
  - No enum (roadmap item 5); the lexer's token types stay strings on purpose, being the
    `--tokens` format.
- **HTTP is HTTP/1.1 in GazLang (`lib/http.gaz`) on socket builtins, TLS through OpenSSL**,
  linked by default and optional (`make TLS=0`), so the bootstrap still needs only a C compiler.
  Not curl through `run()`: a process per request, the headers visible in `ps`, and curl as a
  runtime dependency; not TLS of our own, which would be thousands of lines of crypto whose bugs
  no output shows. Client keep-alive, proxies, compression and HTTP/2 wait for a program that
  needs them.
- **Serving HTTP is `http::serve()` in the same file, in `workers()` processes**, the PHP-FPM
  model rather than Node's. **Prefork, not an event loop**: share-nothing processes suit value
  semantics and refcounting, need no locks, and a crash takes one request, where an event loop
  needs non-blocking sockets and callbacks or coroutines the language doesn't have.
  - `workers($n)` is one builtin, not `fork()`/`wait()`/`kill()`, so no program can leave zombies or
    orphans; the master stays in C (`workers.c`) for good. Workers report in a page of shared memory
    (`mmap`, a `Slot` per number), not a pipe, since the master only reads it when one dies.
  - **A handle made before `workers()` is the process's that made it**: every `Db`, `Socket` and
    `File` records `vm_process` (`workers.c`: 0 at start, raised in each worker's child by
    `fork_worker()`; a counter, since `getpid()` would be a system call on every
    `file_read_line()`) as its owner; listeners are exempt. **Releasing one is an abandon, not a
    close**, in the paths every release takes (`db_close()`, `net_close()`, `file_close()`: decref,
    the collector, the end of the program and an explicit close): a close says goodbye on the shared
    connection and ends it for its owner too. PostgreSQL's socket is pointed at `/dev/null`
    (`abandon_fd()`) before `PQfinish()`, so its Terminate goes nowhere; TLS skips `SSL_shutdown()`'s
    close_notify; a file's descriptor is pointed at `/dev/null` before `fclose()`, whose seek back
    would move the shared offset (nothing can read it after the fork today, but a `parallel()`
    parent would); SQLite's is never closed in a worker (a close can roll back the owner's
    journal), only kept reachable, a `ponytail:` in `sqlite.c`. The per-driver part is
    `DbDriver.abandon`. **On macOS `open_sqlite()` sets `OS_ACTIVITY_MODE=disable`** first: Apple's
    libsqlite3 makes an `os_signpost` on every open, and libtrace's state doesn't survive a fork, so a
    worker's open after the master had opened one crashes in `os_signpost_enabled`. Tested by `tests/gaz/workers/inherited_test.gaz` (under the
    sanitizers, each worker's `GAZVM_STATS` line checked by `CVM::leaks()`) and `DbPgTest`.
  - **`worker_recycle()` is a third case**, neither a graceful exit nor a crash: the master tells
    `WIFSIGNALED(status) && WTERMSIG(status) == SIGUSR2` apart before the generic failure path,
    restarts at once without the start-up check, and logs `worker N recycled; starting another`.
  - **`worker_retire()`** sets `retiring` in its `Slot` (`accepted`, `listening`, `retiring`); the
    master starts the replacement under the same number at its next poll, keeping the retiring pid
    in a second half of its pid table, and sends the retiring one SIGTERM once the replacement has
    set `listening` (on entering `socket_accept()`), or once the number has ended for good. A
    builtin of its own because the retiring worker must go on running the handler while it waits,
    which `worker_recycle()`, never returning, can't. After asking, the worker writes nothing more
    in its slot (`slot = NULL`), which is the replacement's from the fork on. The retiring one is
    reaped, never replaced (an end other than a recycle or a clean exit is a line, `worker N exited
    with code 3 while retiring`); a second hand-over of one number waits until the first one's
    leaver has gone; one still there `STOP_GRACE` after it was asked is killed. Tested by
    `tests/gaz/workers/retire_test.gaz` (the order, the lines, a slow replacement),
    `retire_grace_test.gaz` (the kill) and `HttpServerTest` (a worker slow to start and
    `max_requests` 1: no request waits for a start). **The master polls every 5ms while a hand-over
    is under way** (`handing_over()`), 50ms otherwise, because the retiring worker serves a request
    per connection until relieved and every reconnect costs.
    `ponytail:` noticing the hand-over still waits for a 50ms poll, since the master can't be woken
    (see the program's own thread below). A replacement that never reaches `socket_accept()` (a
    start-up that hangs) leaves the retiring worker serving a request per connection with nothing
    bounding it, so `max_requests` stops limiting its life; a deadline after which the master
    relieves it anyway would lift that. A replacement that dies within a second of starting without
    accepting stops the whole pool, as a recycled worker's always did, though the retiring worker
    is healthy; keeping it and retrying would lift that. `Slot.listening` is set on entering any
    `socket_accept()`, so a worker that waits on a second listener (an admin port) before the
    shared one relieves the retiring worker early.
  - **Stopping**: a worker catches SIGTERM without `SA_RESTART`, so a waiting `accept()` wakes;
    `socket_accept()` waits in `poll()` a second at a time, so a stop that lands between its check
    and the wait is still seen. The grace is `STOP_GRACE`.
  - **The program runs on its own thread** (see "The C VM"), so a fork is that thread alone, with
    no `main()` to end the process: `run()` in `vm.c` exits a worker itself. And a signal to the
    master may land on `main()`'s thread, which is why the master polls every 50ms instead of
    sleeping until one; the stop signals are blocked across each `fork()`, or one sent before a new
    worker has put its own handlers back would only set its copy of the master's flag.
  - `http::serve`'s refusals: both `Content-Length` and `Transfer-Encoding` are refused as the way
    requests are smuggled past a proxy, and so is a chunk size line or trailer with a lone CR or LF
    (a line end to some proxies, so the same request would be two). `HttpServerTest` runs
    `tests/programs/web_server.gaz` and speaks to it over raw sockets, so requests no client would
    send can be sent.
  - **`"request_timeout"`** exists because the per-read `"timeout"` alone let a client trickling a
    byte every few seconds hold a worker for hours; `Reader` checks it before each read, so it can
    overrun by one read's timeout.
  - **`"max_requests"` is opt-in**, since forcing it by default would be gaz second-guessing an app
    that has no accumulating state to worry about.
  - **Keep-alive is on by default**, since every browser and proxy expects it and a TCP handshake
    per request is the cost it saves; `"requests_per_connection"` 1 is the off switch, so there is
    no flag.
    - **No HTTP/1.0 keep-alive**: the rarer the path the fewer its bugs (`ab -k` gets a connection
      per request). A closing response's `Connection: close` is written by `write_response()` alone.
    - A quiet connection gets no 400 or 408, which nobody would read (`Reader.heard_anything()`).
    - **The idle wait is `socket_wait()`**, which sees a stop within a second. `ponytail:` a request
      already read in part (pipelined) is answered even after a stop (bounded by
      `requests_per_connection` and the master's grace), and the response written after a stop can't
      say `Connection: close`; a `worker_stopping()` builtin would let it.
    - **An idle connection yields to a waiting client**, which is what makes keep-alive safe on by
      default in a prefork pool, where each idle connection would otherwise hold a whole worker for
      `idle_timeout` (a browser opens up to six). Between requests (never before a connection's
      first) a worker waits on its socket and its listener together; when the listener is ready it
      gives its client one to two `YIELD_GRACE`s (10ms), then looks again: a client still queued
      means no worker is free, so it closes; one gone means a free worker took it, and it waits out
      the rest of its idle time (a deadline, not a fresh wait each time round). The grace is drawn
      by each worker because idle workers that look again together all see the client still queued
      and all give up their connections, where one is enough.
      `http::handle()` has no listener and waits on its socket alone.
    - **A connection is handed over at a response where it can be** (`Turns` in `http.gaz`, one per
      worker), since closing an idle one races with a request its client sends at that moment (a
      browser resends; `wrk` counts a read error, a proxy may not resend a POST), and HTTP/1.1 has
      no way to tell an idle client not to send, while a response saying `Connection: close` races
      with nothing. Hence `TURN` (50ms; `requests_per_connection` alone lets a slow handler's 100
      requests keep a waiting client out for seconds), `CROWDED` (5s, since a crowded worker's
      clients pause and a pause is when the next idle close would come) and `HOT_GAP` (5ms: a
      connection per request costs such clients a good part of their throughput, and one that is
      late is late, not gone). The last look at the client is just before the close. Tested by
      `http_turns_test.gaz` (the rules) and `HttpServerTest` (two clients taking turns on one worker
      see one idle close in twenty turns; a busy connection on a 30ms handler gives way within a
      second). `ponytail:` the first idle close of each crowded spell still races, as the
      `idle_timeout` close always does; nothing short of the client saying when it will send next
      would remove it. A proxy avoids both by keeping no more idle upstream connections than there
      are workers and closing them before `idle_timeout` (the README says so). Whether a client is
      waiting is a readable listener, which also holds for the instants before an idle sibling
      accepts it, so a connection past its `TURN` can be closed for a client no worker lacked;
      that costs one reconnect. A connection is closed after its `Connection: close` response
      without draining, so a client that pipelined behind it gets a reset, not an orderly end; a
      lingering close (a half-close and a short read) would lift that, and needs a builtin to
      half-close.
    - The empty lines skipped before a request line are bounded (`MAX_EMPTY_LINES`) so a client
      can't hold a worker with them.
    - Tested by `tests/gaz/lib/http_connection_test.gaz` (`http::handle()` over a socket pair,
      responses compared byte for byte without the `Date` line) and `HttpServerTest` (idle close,
      stop while idle, `max_requests` on one connection, a 500 closing, the yield both ways).
  - **`"max_requests"` jitter, not built**: workers under an evenly spread load retire together; the
    hand-over means that no longer leaves the pool short, only their start-up work lands at once,
    which matters only to an app whose start-up is heavy. If wanted, it is library code:
    `"max_requests"` taking `[$min, $max]`, each worker drawing `rand_int($min, $max)` once (each is
    already reseeded from OS entropy by `fork_worker()`).
  - **`workers($n)` is a fixed pool, not built: dynamic sizing (PHP-FPM's `pm = dynamic`/`ondemand`)**,
    the one real capability gap against FPM's process manager. The master knows only whether a
    worker is alive, has started or retires, not whether it is idle in `socket_accept()` or busy,
    since workers race to accept with no coordination through it. Scaling needs the `Slot` to hold
    a state (idle/busy, with a last-transition time) written on each transition and read by the
    poll loop, spawning more when nothing has been idle for a stretch and sending one worker the
    stop's SIGTERM when it has been idle past a timeout with others to spare, which needs a *target*
    pool size apart from the live count so a scale-down isn't respawned as a crash (the hand-over's
    reaped-not-replaced pid is a start on that). `workers($min, $max)` is the likely shape;
    `ponytail:` no jitter on synchronized scale-down either, matching FPM's own lack of one.
  - **Decoding a request is asked for, not done for every request**: `query()`/`form()` give maps of
    strings so a handler never checks a value's type, and `query_all()`/`form_all()` lists (Go's
    `Get` against the full list, not PHP's `tag[]` convention, where a key's type would depend on
    what the client sent). A bad escape is an error, not passed through as PHP and browsers do, so a
    mangled value can't arrive looking valid. `form()` refuses another Content-Type rather than
    giving `{}`. Pieces split as the WHATWG parser splits them (empty ones skipped, no `=` is a value
    of `""`). Tested by `tests/gaz/lib/http_decode_test.gaz`, and end to end by `HttpServerTest`.
  - **Routing is `http::Router()`, in `http.gaz` itself**, not a `router.gaz` read as
    `router::Router()`, one namespace naming the other redundantly, for a program already reaching
    for `http::serve`. `$app.handler()` is an ordinary handler, so the server learns nothing. No
    regex in patterns; the path is split before it is percent-decoded. Params go into
    `$request["params"]`, so a handler keeps one argument and tests with a plain map. First match
    wins (Express's rule: nothing to rank). The 308 is so a link works either way and a page has one
    URL. Tested by `tests/gaz/lib/router_test.gaz`.
  - **`http::redirect()`** is public because every handler that answers a form needs one (the
    router's own 308 is a separate private function). Tested by
    `tests/gaz/lib/http_redirect_test.gaz`.
  - **`http::serve_static($dir)`**: a segment that decodes to hold a `/` is refused too (`..%2f` is a
    `..` the segment check never sees; `%2F` is never a separator here). The regression tests aim at
    a file that exists above the served directory, since a 404 alone can't tell a refusal from a
    miss. `gaz -S` (`std/devserver.gaz`, run by `vm.c`'s `-S`) takes no router script as `php -S`
    can, since `import` takes a string literal resolved at parse time, never a runtime-named file
    (the same reason there is no type-tag deserialization). Tested by
    `tests/gaz/lib/http_serve_static_test.gaz` and, end to end, `DevServerTest`.
  - **Cookies and signed sessions**: a session is a map, `json::encode`d and signed as one value with
    `crypto::sign`/`crypto::unsign` (HMAC-SHA256, checked with `crypto::equals`, never `==`).
    `write_response()` writes any header's list value as one line each, not only `Set-Cookie`'s.
    Tested by `tests/gaz/lib/http_session_test.gaz` and end to end by `HttpServerTest`.
  - **Middleware for a web app** is the glue the todo app proved (`apps/todo`, whose tests passed
    unchanged on it): library middleware because the glue is where the security bugs live, and each
    app writing its own gets them its own way. Headers outermost so a 403 and a redirect get them
    too; sessions before csrf, which reads the token. Tested by
    `tests/gaz/lib/http_sessions_test.gaz`.
    - **A handler asks for a session change by adding `"session"` to its response**, since the
      response is the one thing a handler returns: no global, mutable request or second return
      value. The flash is kept out of the session so a handler that passes the session on doesn't
      show it again.
    - **The cookie is compared against the session as it came, *before* the token is added**:
      compared with the session after, a new visitor's token is never written and every form of
      theirs is a 403. A test plants exactly that mutation.
    - **`http::csrf()` checks the `Origin` as well as the token**, a second, independent line that
      holds if a token leaks; a hostile non-form POST is a 403, not a 500. Without `http::sessions()`
      before it every request is an error, since a wiring mistake that answered 403 for ever would
      look like a user's problem.
    - **The secret must be 32 bytes or more**: a short signing secret is the program's mistake, and
      one that can be guessed forges every session.
    - **Who the user is stays the app's** (and the login that builds a *new* session, the
      session-fixation defence): the library doesn't know what a user is.
  - `ponytail:` writing a response has only the per-write timeout; the stop grace
    is fixed; while workers drain, new connections queue in the listener's backlog (the master
    holds it too) and are reset when the program ends, where closing the listeners first would
    need `workers()` to know which sockets are listeners; a master killed with SIGKILL leaves its
    workers running (Linux's `PR_SET_PDEATHSIG` would end them, macOS has nothing like it).
  - **Next, when a program asks** (the order they would be built in):
    - `quote($value)` as a builtin (`value.c` has the function): `text::quote()` in `lib/text.gaz`
      is the idiom `slice(to_string([$x]), 1, -1)` with a name, used by the library, `lib/test.gaz`
      and the compiler. A builtin takes its name from every program, so it waits for a program that
      needs the speed or the bare name.
    - A measurement against `php-fpm` behind nginx, with a real worker pool on either side (against
      PHP's built-in server, `workers(1)`, gaz ties PHP with opcache and JIT except on a tight
      arithmetic loop).
    - An access log line per request
      (`http::http_date(time())`, method, path, status, bytes, `monotonic_time()` for how long).
- **Decided, not built** (roadmap item 4): `interface`/`implements` (a parse-time check
  that the methods exist, plus `is_a`), and `final`. The keywords are reserved.
- **Modules and namespaces** are resolved by the parser: functions and kinds carry `::` in
  bytecode, while a method block stays `Kind.method`, which is what lets the loader tell the two
  apart. Resolution is one pass before anything else is checked, so nothing below it knows
  modules or namespaces exist. `docs/design/modules.md` is the design.
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
- **Not yet designed**, each built when real code shows what it needs: traits, late static
  binding, operator overloading, `log`/`exp`/fractional powers (each wants an algorithm GazLang
  defines, as `round` has), variadic parameters and spread in calls (pass a
  list), `foreach` over a string (`split($s, "")`). A REPL is possible and wanted: see "A real
  REPL" under the C VM.
- **Regular expressions**: `lib/regex.gaz`, a Thompson NFA (Pike's VM) so there is no
  backtracking and no ReDoS. **Perl's match**: threads run in priority order carrying their group
  slots (`save` instructions), and one reaching `match` drops the threads after it while those
  before run on, so repetitions are greedy and the first alternative wins; a new start is seeded
  each step only until something matched, which is what makes it leftmost. `groups` gives a group
  that took no part as null since `""` can't be told from an empty capture, a repeated one's last.
  `ponytail:` an empty iteration of a starred group dies at the loop, so `(a*)*` reports its group
  as null where Perl says `""`; no `$1` in replacements or a function for `$with`, no
  backreferences, no `\d`/`\w`/`\s` shorthands (`lib/chars.gaz` has those as named functions) —
  add them if a program needs one.

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
  - **Refused**: a method (`|> $obj.m()`, `|> $obj.m`, `|> #m()`), the restrictive choice,
    loosenable later; anything else that isn't a name, a variable or a parenthesised expression
    (`|> 5`, `|> $h["k"]`). `ponytail:` `|> ($a, $b) -> ...` gets the plain `Unexpected ','`.

## Numbers

- Literals: `0O` is refused, being hard to tell from `00`; `0o78`, `0o7.5` and `0o7e5` are each
  one invalid literal rather than two tokens (`octal()` in `lexer.gaz`). Hex has no exponent
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
- `quote()` in `value.c` is the exact inverse of a literal, and everything that shows a string
  as source uses it.
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
- `fn` is top level only so `break` can't reach a caller's loop. The parser checks every call's
  name and argument count once the whole program is read, so the VM trusts calls. A default is
  evaluated inside the function, so it sees earlier parameters and a `[]` default is never
  shared; the arity is then `[required, total]`.
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

- **UTF-8**: **strings stay bytes**, since a text type would split every API in two. `utf8_valid`
  is RFC 3629 written out in C (`utf8_character()` in `builtins.c`, no locale), and the three
  share that one decoder so they can't disagree. **Builtins, not a library**: a check belongs on
  every request path, over every byte of a form field, which is C's work. `tests/Utf8Test.php`
  checks all three against PCRE's UTF-8, which shares no code with them.
- Lists and maps: `map`, `filter`, `reduce` and `sort` call back through `call_value()` in `vm.c`,
  checked as a call written in the program is, after every type is checked. A comparator-less
  `sort` is `binary_op(OP_CMP)`; `sort` is a defined merge sort (`merge_sort()` in `builtins.c`)
  because a comparator can see which comparisons are made. The index or key goes to a callback
  by what it *needs* (`callable_min_args()` in `vm.c`: its fewest, or -1 for a builtin, a kind or
  a non-function), not what it accepts, so a callback's meaning never depends on a default
  someone adds, and a builtin's extra parameters stay options (`to_int($x, $default)`).
- Types: `kind_name` gives the name with its namespace, since the bare name is
  `last(split(..., "::"))` and the other way would be impossible. `object_id` is a counter from 1
  in `object_new()`, reset by `run_program()`, not the address, which is reused and differs from
  run to run.
- I/O: `real_path()` treats `""`, a NUL byte, `file/` and `file/..` as nothing, where platforms
  disagree.
- **File handles**: `T_FILE`, refcounted like a socket or a db. **Modes are one argument, not more
  names.** `file_read` reads into the heap, never `call_builtin()`'s frame (`FILE_READ_MAX`, 16
  MiB). No `file_tell`: `file_seek($f, 0, "current")` is the position.
  - **The end is where the file ends now**: C's end-of-file flag sticks, so every short read
    clears it (`finish_read()`). Tested with two handles on one file. `ponytail:` a line read
    while it is half written comes back as two lines (getline can't tell a last line from one
    still arriving); a reader that cares reads bytes.
  - **stdio wants a flush or a seek between a write and a read** on an `"r+"` handle, so each
    handle remembers what it did last (`File.last`) and seeks to where it is when that changes
    (`usable_file()`).
  - **`file_close()` reports a failed write** (`fflush` and `fclose`'s errors; the test provokes
    `EFBIG` with `ulimit -f` and SIGXFSZ ignored). **Releasing the last reference can't raise**
    (it happens in `decref`), so a file dropped unclosed loses such an error. `ponytail:` a
    `finally`-shaped release that could raise would lift it.
  - **`file_sync()` is GazLang's rule, not the platform's**: `fflush`, then `fcntl(F_FULLFSYNC)`
    where the system has it, `fsync()` where it hasn't or `F_FULLFSYNC` fails (`sync_fd()` in
    `builtins.c`). A reader is refused, being a mistake to name, not a no-op. Nothing outside the
    kernel can see the disk, so the tests check that it succeeds, writes the buffer out (a second
    handle reads it before any close), reports a failed write (`ulimit -f`) and refuses what it
    should; the `F_FULLFSYNC` branch is checked by reading the code.
  - **`file_truncate()`** is `fflush` then `ftruncate`; a negative length is an error of its own.
  - **Three builtins, not `read_line($f)`**: an optional handle would make `read_line`'s meaning
    depend on an argument's type. A directory is refused in every mode (`EISDIR` written out,
    after an `fstat()` of what `fopen()` opened, since it opens one for reading); a pipe is what
    streaming is for, where `read_file()` insists on a regular file. Close-on-exec, as a socket
    is. **`workers()` calls `fflush(NULL)` before it forks**, so a writer's buffer isn't copied
    into every worker to be written again by one that ends with `exit()`. Tested by
    `tests/gaz/workers/inherited_writer_test.gaz`. `ponytail:` a `foreach` can't be lazy, so a
    program loops on `file_read_line()`.
- `file_info()`: null for `ENOENT`, `ENOTDIR` and a NUL byte, any other failure an error; `"mtime"`
  whole seconds (nanoseconds wait for a program that needs them). **One builtin and a map, not
  `file_size`/`file_mtime`/`is_link`**: one name, one system call, and room for a field.
- `chmod()` follows a link (there is no `lchmod` on Linux, and a link's own bits mean nothing
  there); `check_mode()` is shared with `make_dir`.
- `set_mtime()`: `utimensat()`. `ponytail:` whole seconds, so a tool comparing times can't tell
  two writes in one second apart; nanoseconds in `file_info()` and here would lift it.
- `symlink()`/`readlink()`: an empty target is `ENOENT`, written out, since systems differ on it;
  `readlink` reads into a heap buffer that doubles until the text fits, and words "not a link" its
  own way, since `EINVAL`'s are "Invalid argument". Named as the system calls are, where
  `make_link`/`read_link` would be a fourth spelling to learn.
- `sync_dir()`: `open(O_DIRECTORY)` and `sync_fd()`. **A builtin of its own rather than
  `file_sync()` taking a path too**: an argument whose type changes what a builtin is would be the
  `read_line($f)` mistake again.
- `chdir()`: `cwd()` is `getcwd()`, never cached. Tested by `tests/gaz/workers/chdir_test.gaz` (a
  worker moves and recycles itself, and the next one the master forks is where the master is).
- `rename_file()`: `EXDEV` written out, since its words differ between Linux and macOS. No
  copy-and-delete fallback: a move that can't be atomic shouldn't pretend to be.
- Directories: `list_dir()` sorts with `str_cmp`, since `readdir()`'s order is the file system's;
  `make_dirs()` works on a heap copy of the path it cuts up; `delete_file()` writes out `EISDIR`,
  since `unlink()` says EPERM on macOS; a NUL byte in a path is `ENOENT`, as no name holds one.
  `StdlibTest::test_directories` runs one snippet that makes, lists and clears `tests/.tmp/dirs`,
  clearing a failed run's leftovers first so it can be recorded; its names avoid differing only in
  case, which macOS's file system can't hold.
- `read_line()`: `getline()` on `stdin`, through the stdio buffer `read_stdin()` reads too, so the
  two share the input without losing a byte. Tested through `CliTest` rows with
  `tests/corpora/cli/lines.txt`.
- `run()`: `posix_spawnp`. Standard input is `$input` in a temporary file unlinked before the
  program starts (a pipe would need writing while reading two, and SIGPIPE when the program stops
  reading, which Linux can't turn off per pipe), or `/dev/null` when empty, so a piped program's
  own input stays its own. Both outputs are read together with `poll()` so neither pipe fills and
  blocks the child. A signal's status is negative (Python's rule), where a shell's 128 + N is
  ambiguous.
- Sockets (`net.c`): **the address text is `ipaddr.c`'s, not `inet_ntop()`'s**, whose IPv6
  spelling differs between systems; checked against Python's `ipaddress` on 295 addresses
  (`tests/IpTextTest.php`, on its own so no network is needed) and by
  `tests/gaz/sockets/peer_test.gaz`. `workers($n)` is `workers.c` (see "Serving HTTP"); the fuzzer
  skips it with the socket builtins. `HttpTest` trusts `tests/fixtures/tls/` through
  `SSL_CERT_FILE`; an IP is checked as an address, with no SNI. SIGPIPE is ignored around each
  call and put back after, as curl does: per socket only macOS can turn it off, and ignoring it for
  good would change what a program writing to a closed pipe does. OpenSSL reports a socket timeout
  as wanting to read; `net.c` says `timed out`.
  `socket_wait()` exists for kept-alive connections: a worker idle on one must see a stop
  (`socket_read()` retries on `EINTR`, so a stop never interrupts it) and a client waiting on the
  shared listener, both one `poll()`, waited a second at a time as `socket_accept()` does. A
  hang-up or error counts as ready, since `socket_read()` won't wait on either, and so do bytes
  OpenSSL already decrypted. Tested by `tests/gaz/sockets/wait_test.gaz`, `inherited_test.gaz` and
  `HttpServerTest` (a SIGTERM during a 60-second wait ends it). `ponytail:` at most 16 sockets, a
  fixed array; a TLS record only partly arrived reads as ready and `socket_read()` then waits for
  the rest.
- Databases (`db.c`, drivers `sqlite.c` and `pg.c`): **three builtins whatever the drivers**, since
  a builtin takes its name from every program: the URL's scheme picks a `DbDriver` (open, run, close)
  in C, so a new database is a file and a table entry. **The builtin takes the database's own
  placeholders**, not rewritten: rewriting means reading string literals in C.
  - **`Sql` isn't `pub`**, so `sql()`, `raw()` and `Db` (one namespace) are what make and take one; a
    pub function may still declare `: Sql`, and `kind_of($fragment)([...], [])` stays as deliberate as
    `db::raw()`, its constructor still checking parts and values. `Sql.render($placeholder)` gives
    `[$text, $params]`, walking the parts with one counter; values are checked when the `Sql` is made,
    so the error is at the line that wrote it. An empty list is an error because rendering it `(null)`
    makes `not in` quietly match nothing.
  - **The numbered-placeholder rule**: with PostgreSQL `db::sql"select $1, {$x}"` would render
    `select $1, $1` and bind `$x` twice, and SQLite reads `?1` as the first value however many `?`
    there are; a false alarm is accepted, loud being the safe side. The call-in-braces check is a
    linear scan (`check_no_call()`). `transaction()` uses the builtin for its own fixed statements.
  - `tests/gaz/lib/sql_test.gaz` renders both styles with no database; `db_test.gaz` and
    `pg_check.gaz` run them. `ponytail:` no helper joins a list of fragments (a bulk insert of many
    rows is one `values {$row}` per row, or a loop in a transaction).
  - `make SQLITE=1`/`PG=1` make a missing library an error, which CI asks for. SQLite is tested by
    `tests/gaz/lib/db_test.gaz` on `:memory:` (recorded, sanitized, leak-checked); PostgreSQL by
    `DbPgTest` running `tests/db/pg_check.gaz` against the server `GAZLANG_TEST_PG` names (skipped
    without), on temporary tables. `ponytail:` a bool parameter is 0/1 in SQLite, no blobs going in,
    and PostgreSQL's numeric, timestamps and json come back as text.
- `monotonic_time()` is `CLOCK_MONOTONIC`; a program that prints it can't be recorded, so tests
  check its type and that it never goes back, and its uses (`tui::Metronome`) take the time as an
  argument.
- `time()` was added for a server's `Date` header and logs; it is tested by type and range and by
  `HttpServerTest` against PHP's clock. `date.gaz` still keeps no clock, so a game keeps its own date.
- `sleep($seconds)` is `nanosleep()` a day at a time (any finite float fits), carrying on after a
  signal. `getenv()`'s NUL byte is an error rather than null, being a mistake. Both are tested by
  shape (`time_test.gaz`) and `getenv`'s value by `StdlibTest` setting one, since neither can be
  recorded.
- The terminal (`term.c`) is only what GazLang can't do itself, so the rules of drawing and decoding
  keys are written out in `lib/term.gaz` and testable from a pipe. **Raw mode outlives the program
  unless something puts it back**, and `exit()` and an uncaught error skip `finally`, so `term.c`
  restores it in an `atexit` handler and in signal handlers that then end the program as the signal
  would have, so its exit status is still the signal's. Always `TCSANOW`: `TCSADRAIN` waits for the
  terminal to take the output, which never ends once the terminal is gone. Raw mode turns off
  `ISIG`, so a program in a loop that never reads can't be interrupted from the keyboard. Resize is
  polled, not signalled, so nothing runs asynchronously. `tests/fixtures/pty_run.py` runs a program
  on a pty, since raw mode can't be seen from a pipe; `TermTest` skips those tests without python3.
  Its two limits (`PTY_START`, to enter raw mode, 60s; `PTY_DEADLINE`, to end, 30s) only guard
  against a hang and end with the program, so a loaded machine can't trip them; a test of a program
  meant to hang gives a short deadline.
  `ponytail:` `run()` hands a child the terminal as raw mode left it.
- Random numbers: xoshiro256** through SplitMix64, as PHP's `Xoshiro256StarStar` does; the range
  mapping is `random_between()`, pinned for several seeds by `StdlibTest` against an independent
  port. The state is per program, reseeded by `run_program()`, since the compiler runs first.
  **Anything that prints random values calls `rand_seed()` first**, or what it prints can't be
  recorded as expected (snippets and corpus files included); unseeded behaviour is tested by type
  and range only.
- **Cryptography** (`crypto.c`): raw bytes in and out, hex and base64 being `lib/crypto.gaz`'s. Six
  names, since a builtin takes its name from every program: one per algorithm rather than a
  `kdf($name, $params)` map, so each argument is type-checked where it is and a misspelt parameter
  can't be a key nobody reads; HMAC in C because PBKDF2 needs it there anyway, and at 600000
  iterations only C is fast enough.
  - **Our own C, not OpenSSL**: from FIPS 180-4, RFC 2104, 8018, 7914, 9106 and 7693 (BLAKE2b),
    so a `TLS=0` build has all of it, the bootstrap still needs only a C compiler, and every
    platform gives the same bytes. Bytes are loaded into words of a stated order one at a time, so
    the host's order never matters. The defaults take roughly 0.15s (Argon2id), 0.25s (scrypt) and
    0.3s (PBKDF2) on the development machine.
  - **Checked against the specifications and against code that shares none with it**:
    `tests/gaz/lib/crypto_test.gaz` holds every vector FIPS 180-4, RFC 4231, RFC 7914 (PBKDF2 and
    scrypt) and RFC 9106 give (scrypt's fourth, 1 GiB, runs unsanitized in `CryptoTest`), plus
    lengths either side of each block and padding edge; `CryptoTest` compares with PHP's hash
    extension, libsodium, libargon2 (`password_hash()` in both directions), Python's
    `hashlib.scrypt` and `openssl kdf` (lanes, secret and data, which libsodium lacks), skipping
    what the machine doesn't have. Its inputs come from a small LCG written on both sides, so the
    programs print only digests and are recorded and sanitized like any snippet. A planted mistake
    fails both, except in Integerify's high word, which can't matter while scrypt's memory cap keeps
    N below 2^32.
  - **The builtins' limits are what C can do safely, not what a login should cost**, each refused
    before anything is allocated; so is a size a 32-bit `size_t` can't hold, and a failed `malloc`
    of the big buffers is a catchable error, not `xmalloc`'s exit. A call within them can still take
    4 GiB and hours; bounding what a stored string may ask for is `verify_password`'s job. Memory is
    on the heap (4 GiB is past the thread's stack), and secrets are wiped by `wipe()`, a `memset`
    through a volatile pointer the compiler can't prove is one. Argon2's lanes run one after
    another: the result is a lane-parallel implementation's, and threads would only divide the wall
    time.
  - **`random_bytes` is `getentropy()`**, 256 bytes a call, as `rand_seed()` without a seed is.
    `vm/fuzz.php` skips programs naming it (what they print wouldn't follow from the seed) and the
    three password hashes (their cost is their arguments, so a slow one isn't a bug).
  - **`lib/crypto.gaz`**: the PHC format is what PHP, libsodium and libargon2 write, so Argon2id
    hashes move between them. `verify_password`'s refusals are canonical-only on purpose (each
    parameter a plain decimal, in order); it derives as many bytes as the stored hash has.
    `needs_rehash` compares the header `hash_password` would write.
  - **`crypto::equals` compares HMACs, not bytes in a loop**: a loop would index one-byte strings,
    each made the first time its byte value is seen, which is a timing difference that depends on
    the secret, while the MACs' first difference is at a place nobody can predict or steer. It costs
    two HMACs and a `getentropy()`, and its time still grows with the lengths (a `ponytail:` there).
  - Hex and base64 are GazLang (a digest is 32 bytes; speed would matter only for bulk data, which
    nothing encodes yet). They go through one-byte strings and `index_of`, which leak timing about
    the bytes; `ponytail:` comments mark each, and an encoder and decoder in C would lift them.
- `builtins()` is the builtins of the runtime running the program, which the self-hosted parser
  checks calls against. That is right because the compiler always runs on the runtime that will
  run its output, and a loader refuses bytecode naming a builtin it lacks.
- **The standard library is built into the VM** (`vm/build/std.c`, made from `lib/*.gaz` by the
  Makefile with `od`, as the compiler's bytecode is). The parser resolves `std/` (and a `./` or root
  path inside a library file, whose directory and root are `<std>`) through `std_source()`. The VM,
  the bytecode and the collector learn nothing: the module is read as any other, and a name in angle
  brackets is what bytecode never rewrites, so bytecode runs from anywhere. **Embedding, not a search
  path** (Python's `sys.path`, Lua's `package.path`): one file, `bin/gaz`, works from any directory
  with no install layout to get wrong and no skew between a binary and the library it runs; the
  cost, a rebuild after editing `lib/`, is met by `GAZLIB=lib`. Everything in the repository loads
  the library by `std/`, as any other program would: the tests (`tests/gaz/lib` tests the built-in
  copy, which the tests build from `lib/`), the games, the website, the language server and
  `compiler/`, whose `std/chars.gaz` is the built-in copy of the `bin/gaz` that compiles it. A
  library file imported by path as well is another module of the standard library's namespace, the
  ownership error, unless `GAZLIB` names its directory, which makes it the same module. A library
  file therefore names its siblings `std/x.gaz`, never `./x.gaz`: one loaded by its path (a test
  that joins a namespace to reach what is private, `tests/gaz/lib/http_turns_test.gaz`) then shares
  its imports with the built-in library and not a second copy of them. `StdLibraryTest` checks that
  what is built in equals `lib/`.
- The library files, each its own namespace (`docs/language.md` lists what each has); the reasons
  behind them:
  - `lists.gaz`: plain functions over plain lists, not a wrapping object: a `Collection` kind would
    have to be a handle, since every `kind` is, reintroducing the "did I get a copy?" ambiguity
    value semantics exist to remove. `max_by`/`min_by` take the first on a tie so they replace a
    stable `sort(...)[0]` exactly; `take`/`drop` are named wrappers over `slice()` for how they read
    mid-chain; `pluck` takes only maps until `$obj.$name` exists; `find` gives `null` since not-found
    is an ordinary outcome. A list helper goes here rather than into the builtins, since a builtin
    takes its name from every program and a namespace only from those that import it.
  - `text.gaz`: `text::lines` strips `"\r\n"` only where a `"\n"` follows, as `read_line()` and
    `file_read_line()` do; `text::indentation`/`unindented` are what the website's Markdown and
    highlighter read; `text::trim_start`/`trim_end` are in GazLang rather than two more builtins, since a path's trailing slash is the one place
    they are needed.
  - `fs.gaz`: `fs::copy` goes in 64KB pieces; onto itself is an error, since opening the target
    would empty it; the mode is set before a byte is written, so a private file's copy is never
    readable by others, and once more after writing, since a write clears setuid on Linux.
    `fs::copy_tree` sets directory modes deepest first once everything is in, so a read-only one is
    filled first, and gives what it left out rather than failing or quietly dropping it. `fs::glob`:
    a hidden name only by a pattern name starting with a dot; a link the pattern names is followed,
    one `**` finds never is, so no loop; each `*` matched by the two-pointer method, backing up only
    to the last star, so never exponential. `fs::write_atomic` gives the temporary the replaced
    file's mode and removes it if anything fails; `fs::touch` opens with `"a"`, so never empties.
    **Paths only, no path kind**: strings joined with `"/"`, as every builtin takes them. **No
    kinds-with-the-walk**: `fs::walk` gives paths, and a caller wanting sizes or times asks
    `file_info()` again, a second `lstat` per entry, which the examples didn't notice next to what
    the walk costs. **No `fs::mode_string`** (`rwxr-xr-x`): no example lists modes, and
    `format::sprintf("%o", $mode)` shows one. `ponytail:` a copy onto a read-only file is an error
    (`fopen` can't write it) where `cp -f` would remove it first. Tested by
    `tests/gaz/lib/fs_test.gaz` on a tree it plants with links in it.
  - `format.gaz`: display helpers take any value, string functions stay strict; `%x` or `%o` of a
    negative is an error, not two's complement; `%f` goes through `format::number()` so it rounds as
    `round()` does and never by the platform's printf, which caps it at an int's worth of digits and
    18 decimals (`ponytail:`); every placeholder is read and the count checked before anything is
    formatted.
  - `http.gaz`'s client: `HttpTest` runs it against `tests/fixtures/http_server.php`, over TCP and
    TLS, which writes framing out by hand so it can get it wrong on purpose; ports vary, so what it
    prints is checked by shape, not recorded. The server and `http::Router`: see "Serving HTTP".
  - `date.gaz` has no clock, since a program that asked one what day it is could not be recorded:
    every function is given the time.
    - **A time is an int**, seconds since 1970 in UTC without leap seconds, as `time()` and
      `file_info()` give it, so it compares, sorts and is stored as a number; a `Moment` is one
      read in a zone, for its fields and formatting, made when shown rather than passed around,
      since a kind is a handle. Formatting is named pieces (`rfc3339()`, `clock()`,
      `date::format()`), not a format string to learn.
    - **Named zones are TZif files read in GazLang** (RFC 8536), the system's
      `/usr/share/zoneinfo` unless a program names a directory, never libc's `localtime()`, whose
      answer depends on `TZ` and the platform. The POSIX TZ rule at a file's end is written out
      (`Rule`, `Change`), with RFC 8536's hours from -167 to 167; files counting leap seconds are
      refused. Tests read `tests/fixtures/zoneinfo`, never the machine's; `DateTest` compares with
      PHP's own database, on zones whose rules the two copies agree on.
    - **A clock reading that happens twice or never is an error unless the call says**
      (`"earlier"`, `"later"`, `"compatible"`): the two candidates are the reading at the offsets a
      day before and after it. `ponytail:` two changes within two days would hide the first.
    - **`parse()` is RFC 3339 and what PostgreSQL writes** (a space for the `T`, `+02`); a leap
      second is refused and a fraction dropped (`ponytail:`, until times have nanoseconds). Its
      `$default` is told from none by a private kind (`NoDefault`), as a parameter can't say
      whether it was passed, and only `Unreadable` is caught, so running out of call depth isn't.
  - `term.gaz`'s drawing functions return their sequence, so a program prints them and a test
    compares them; `tui.gaz` draws into a `Screen`, a grid that `render()` diffs against what it last
    drew, so a program redraws it all every frame and a test reads `lines()` without a terminal.
  - Scan long strings with `index_of`, not a character at a time.
## Function values and closures

- A bare name is unambiguous as a value because variables have sigils. Named functions are
  interned, so `add == add`; closures compare by identity of creation.
- Only `name(...)` is checked at parse time; a call on a value evaluates the callee, then the
  arguments, then checks callability and arity (the parser's wording), then calls.
- `echo add` prints `function add`, a closure `function -> at file.gaz:12`; errors name a
  closure by where it was made, then where it was called.
- **Lambdas**: an expression body extends as far right as it can, so a lambda sits at the
  ternary's level; `break`/`continue` can't leave a block body. A lambda returning a map writes
  `$x -> ({"v" => $x})`, since `{` after `->` is a block. The parser needs no lookahead:
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
  `interface`, `implements` and `final` say they are reserved wherever they land: where a
  statement, a member (after a marker too) or an expression starts (`refuse_reserved_member()`).
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
  data all the way down rather than leaving nested objects to the encoder.

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
  `{{ }}`; a kind extending `Html` too, and never its contents in the message. The loader notes the
  kind named `Html` (`html_kind`, as `error_kind`) and `append_joined()` in `value.c` refuses it on
  the conversion path, one tag compare ahead of echo's conversion. `format`'s helpers call
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
    needs one); a `<meta http-equiv="refresh" content="0;url={$u}">` isn't read as a URL; text
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
  location by hand (easy to forget; the corpus checks it). The writer's paths stay textual, since
  a path written into bytecode needn't exist.
- **The tree dump** (`--ast`) prints each node's fields as `fields()` gives them, skipping those
  that are derived or the code generator's (the driver's `SKIPPED`). On an error there is no
  partial tree, since the whole-program checks write into nodes parsed long before; nothing
  catches a `ParseError` and carries on, so the parser restores no state.
- **Files that only declare** (`lexer.gaz`, `parser.gaz`, `nodes.gaz`, `template.gaz`,
  `codegen.gaz`), as the compiler requires of any file that is imported; the driver, the main
  file, is separate. Each imports what it names, `parser.gaz` and `template.gaz` each other.
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
  runs from other working directories, below the main file and through a symlinked path.

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
    as piped source's are, not a made-up file name, which would break resolving imports from the
    working directory and its project. **Plain program semantics, on purpose**: no implicit `echo` of a last
    expression (a rule that changes what a statement does by where it sits), no `-n`/`-p` line loop
    (C would wrap the text, shifting line numbers and inventing `$line`), and no prelude of
    libraries (implicit, and about 70ms a run): `import "std/lists.gaz";` is the price, and a
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
    import is watched from its first run; a compile that fails keeps the last list. Compiling
    twice costs the restart one compile, but needs no change to the front end. `ponytail:` a file
    that leaves no instruction (only constants) isn't watched.
  - **Polled every 0.25s**, modification time (nanoseconds too), size and inode, portable where
    inotify and kqueue are one system each. A change or a file gone restarts: SIGTERM to the
    group (graceful for `workers()`), SIGKILL after 1s, where production's grace is 10s, so a
    save feels instant. A compile error (the child prints it) or a program that ends by itself
    waits for the next change.
  - **Messages on stderr**: `gaz: watching app.gaz and 6 files it imports` (again whenever the
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
  reason the standard library is embedded. `import "pkg/router/router.gaz"` (reserved now, refused
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
