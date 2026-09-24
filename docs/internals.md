# Working on GazLang

How the pieces fit together, and what to know before changing them. The reasoning behind the
design is in [CLAUDE.md](../CLAUDE.md); what the language *is* is in
[docs/language.md](language.md).

## Layout

| Path | What it does |
| --- | --- |
| `compiler/` | the front end, in GazLang, all of it `namespace gazlang;`: `lexer.gaz`, `parser.gaz` and `nodes.gaz`, `codegen.gaz`, and `gazlang.gaz`, the driver, which prints what `-c`, `--tokens` or `--ast` would (`gazlang.gaz -- code\|tokens\|ast [FILE]`, reading piped source without a FILE). `gazlang.gzb` is its bytecode, checked in |
| `vm/` | the VM in C, built as `bin/gazlang` with `gazlang.gzb` inside it: it runs source by compiling it with that first. `vm/gazvm.h` says which file does what |
| `lib/` | the standard library, written in GazLang, a namespace per file (`json::decode`, `chars::is_digit`) |
| `examples/` | sample programs, which nothing tests |
| `games/` | programs built on the language, each with tests of its own (`games/football/`: a terminal football manager, in progress) |
| `tests/` | PHPUnit, which runs `bin/gazlang`; `tests/gaz/` GazLang programs; `tests/programs/` bigger programs that the tests run; the corpora `lexer_corpus/`, `parser_corpus/`, `codegen_corpus/`, `vm_corpus/` and `bytecode_corpus/`; `cli/`; and `expected/`, what every program must print |

## What holds it together

Everything the tests run is compared with what is recorded as its output, byte for byte:

- **Programs** (`CVMTest`): each entry of `vm/passing.txt` (every program and corpus file in the
  repository, the snippets the PHPUnit tests run, and the hand-written `.gzb` files) runs on the
  C VM built with AddressSanitizer and UndefinedBehaviorSanitizer, and must print the standard
  output, standard error and exit code recorded in `tests/expected/`. It must not leak either:
  `GAZVM_STATS` makes the VM count every reference-counted value, drop what the finished program
  still holds, and report what is left over, which must be nothing. A program runs from its
  source, a snippet is piped in from the project root, a `.gzb` runs as it is; in
  `tests/bytecode_corpus/` the files named `error_*` must be the ones refused.
- **The front end** (`SelfHostedLexerTest`, `SelfHostedParserTest`, `SelfHostedCompilerTest`):
  each corpus file has what `--tokens`, `--ast` or `-c` must print next to it.
- **The command line** (`CliTest`): a table of invocations, each its arguments, what is piped in
  and the working directory, with what each prints in `tests/cli/expected/`.
- **The compiler compiles itself** to exactly `compiler/gazlang.gzb`.
- **The fuzzer** (`php vm/fuzz.php`, not part of the suite; CI runs a minute of it): generated
  programs, mutated corpus programs and mutated bytecode on the sanitized build, failing on a
  sanitizer report, a crash, a leak, a time-out or an error inside the compiler. A failure is
  saved and shrunk in `vm/build/fuzz/` with the seed that replays it; once fixed, it goes into
  a corpus like any other case.

When output changes on purpose, record it and review the diff, since the recordings are the
spec: `php vm/progress.php --update` for programs (it also adds new ones to `vm/passing.txt`,
and removes what no entry records), `GAZLANG_RECORD=1 vendor/bin/phpunit --filter SelfHosted`
for the front end, `GAZLANG_RECORD=1 vendor/bin/phpunit --filter CliTest` for the command line.

Order matters as much as results: `KEY_CHECK` exists so that a bad key fails before later keys
and the value are evaluated. Anything that leans on a platform's behaviour (hashing, string
conversion, float formatting, rounding) is a rule GazLang defines and writes out step by step,
as `round()` is in `vm/builtins.c`.

The C is plain C11, libc, libm and pthreads, plus OpenSSL in `net.c` only (for TLS, left out
by `make TLS=0`, `GAZ_TLS` saying which). A function that can fail returns `bool`, with
the error in `vm_error`; values are reference counted (`incref`, `decref`), lists and maps are
copied on write, and the cycle collector (`vm/gc.c`) frees what counting can't.

## Commands

```bash
make -C vm                                          # bin/gazlang, with profile-guided optimisation
make -C vm PGO=0                                    # the same with plain -O2, quicker to build
make -C vm compiler                                 # after changing compiler/, see below

bin/gazlang -f tests/programs/functions.gaz               # compile and run
bin/gazlang -c -f tests/programs/functions.gaz            # print the compiled bytecode
bin/gazlang --tokens -f tests/programs/functions.gaz      # print the tokens
bin/gazlang --ast -f tests/programs/functions.gaz         # print the parser's tree
```

```bash
composer install                                    # PHPUnit, phpstan and pint

vendor/bin/phpunit                                  # everything
vendor/bin/phpunit tests/MatchTest.php              # one file
vendor/bin/phpunit --filter=testName tests/X.php    # one test
vendor/bin/phpstan analyse                          # must be clean
vendor/bin/pint                                     # formatting; --test to check only

php vm/snippets.php                                 # after adding tests: collect their snippets
php vm/progress.php [FILTER] [--update]             # what differs from tests/expected; records it
php vm/coverage.php [file.c]                        # which lines of the C VM the harness never runs
php vm/bench.php                                    # gazlang against PHP and Python
```

## Tests

- **PHPUnit**: every helper in `GazLangTestCase` runs `bin/gazlang` from the project root.
  `executeCode()` pipes a snippet in and gives what it printed, or throws a `ProgramError` with
  what followed `Error: `; `parse()`, `lex()` and `generateCode()` do the same with `--ast`,
  `--tokens` and `-c`; `runProgram()` and `cli()` run files and command lines.
- **`tests/gaz/**/*_test.gaz`** are GazLang programs that must print exactly their
  `*_test.expected` file (`GazProgramTest`). `tests/gaz/check.gaz` gives
  `check($label, $actual, $expected)`, which prints `ok <label>` or a FAIL line with both
  values. This is how GazLang code gets tested.
- **`tests/lexer_corpus/`** are lexing cases, including deliberately tricky ones; a file named
  `error_*` must be exactly one that fails to lex. Each `X.gaz` has the tokens `--tokens` must
  print in `X.tokens`.
- **`tests/parser_corpus/`** are parsing cases: a file per construct, and an `error_*` file for
  every message the parser can raise, which must be exactly the ones that fail to parse. Each
  `X.gaz` has the tree `--ast` must print in `X.ast`, and piped in `X.piped.ast`; `places/`
  holds the runs from other working directories. The dump is read off each node's fields, so a
  new field shows up in it without being asked. When a location matters, put the node's tokens
  on different lines: a one-line case cannot tell one token's line from another's.
- **`tests/codegen_corpus/`** are code generation cases, built to reach every branch of the
  code generator between them. Each `X.gaz` has the bytecode `-c` must print in `X.code`, and
  piped in `X.piped.code`.
- **`tests/cli/`** holds the programs `CliTest` runs through the command line. A change to its
  options, or to how it reads files and standard input, needs a row there.
- **`compiler/gazlang.gzb`** is the compiler's bytecode, built into the VM. After changing
  anything under `compiler/`, run `make -C vm compiler`. It compiles the compiler three times
  (the old compiler compiles the new one, which compiles itself twice), requires the last two
  to be the same, and only then replaces `gazlang.gzb` and rebuilds the VM, so a broken edit
  leaves a VM that can compile its fix. The compiler's own source can't use a new feature until
  it has been built with it once.
- **`tests/vm_corpus/`** are programs for the C VM that the rest of the repository doesn't
  reach, found with `php vm/coverage.php`: running out of call depth by every kind of call,
  traces cut short, failing `to_string()`s, floats of every shape, cycles.
  **`tests/bytecode_corpus/`** are bytecode files written by hand, one broken way per loader
  message. For the collector, `make -C vm stress` then
  `GAZVM=vm/build/gazvm-stress php vm/progress.php` collects cycles at every chance and takes
  over an hour (with `GAZVM` set, each program may run for two hours).
- `lib/json.gaz` is checked against PHP's own `json_decode` on every `tests/json/y_*.json` and
  `n_*.json`; `lib/csv.gaz` against `fgetcsv`.

## Bytecode

The compiler emits a text format, one instruction per line, specified in
[docs/bytecode.md](bytecode.md).

```bash
bin/gazlang -c -f x.gaz > x.gzb     # write it
bin/gazlang -f x.gzb                # run it (recognised by its first line)
```

`INFO` in `vm/load.c` is the one table of every instruction's arguments and stack effect;
`BytecodeTest` keeps it, `docs/bytecode.md` and the VM's cases in step. The loader checks
everything (unknown instructions, undefined labels, bad slots, and a stack walk through every
jump), so a file that loads is one the VM can run, and takes each block's greatest stack depth
from the walk to size its frames.

## Where things stand

GazLang is its own implementation: the front end in GazLang, the VM in C, and `bin/gazlang`
the two together, which rebuilds its own compiler (`make -C vm compiler`). CI builds and tests
it on Linux and macOS on every push. What is open in the language is in
[CLAUDE.md](../CLAUDE.md) under "Status and what is next".

## Style

GazLang in `compiler/` and `lib/`: functions and variables snake_case, kinds PascalCase,
constants UPPERCASE. The C in `vm/`: plain C11, commented where the C isn't obvious. The tests:
PHP 8.5, PSR-4 under `GazLang\Tests`, methods camelCase, PHPDoc on kinds and methods.
