# Working on GazLang

How the pieces fit together, and what to know before changing them. The reasoning behind the
design is in [CLAUDE.md](../CLAUDE.md); what the language *is* is in
[docs/language.md](language.md).

## Layout

| Path | What it does |
| --- | --- |
| `src/Lexer`, `src/Parser`, `src/AST` | source text to a tree. `Lexer::quote()` and `Lexer::parse_integer()` are the one definition of string and integer literals |
| `src/Runtime` | what values *mean*: `Values` holds the operators, truthiness, printing, keys and indexing as pure static functions; `Builtins` holds the builtin functions and their arities |
| `src/Interpreter` | walks the tree. It does not decide what values mean |
| `src/CodeGenerator` | compiles the tree to a `Program`: one block of code per function, each instruction tagged with its file and line |
| `src/VM` | runs a `Program`. This is the default backend |
| `vm/` | the VM in C, `vm/gazvm`: runs the bytecode `gazlang -c` writes, and must behave exactly as `src/VM` does (see below) |
| `lib/` | the standard library, written in GazLang |
| `selfhost/` | everything above the VM, rewritten in GazLang: `lexer.gaz`, a port of `src/Lexer`, `parser.gaz` and `nodes.gaz`, a port of `src/Parser` and `src/AST`, `codegen.gaz`, a port of `src/CodeGenerator`, and `gazlang.gaz`, the driver, which prints what `-c`, `--tokens` or `--ast` would (`gazlang.gaz -- code\|tokens\|ast [FILE]`, reading piped source without a FILE) |
| `examples/` | sample programs |
| `tests/` | PHPUnit, plus `tests/gaz/` GazLang programs, and the corpora: `lexer_corpus/`, `parser_corpus/`, `codegen_corpus/`, `vm_corpus/` and `bytecode_corpus/` |

## The two backends must agree

This is the rule that shapes everything else. `GazLangTestCase::executeCode()` runs every
snippet on **both** the tree-walking interpreter and the stack VM, and fails if the output
differs, or if the error's class, message, file or line differs. `GazProgramTest`, `JsonTest`
and `VMTest` do the same for whole programs.

So a new language feature needs both backends, or those tests fail. In particular, **any new
AST node needs a visitor in both `Interpreter/Interpreter.php` and
`CodeGenerator/CodeGenerator.php`** — patching one and leaving the other stale is the easiest
mistake to make here.

Order matters as much as results. The VM's `KEY_CHECK` exists so that a bad key fails before
later keys and the value are evaluated, exactly when the interpreter's does.

Both backends call `Runtime\Values` and `Runtime\Builtins` rather than reimplementing anything.
The VM has fast paths in its dispatch loop for the commonest cases whose result is obvious
(arithmetic on two ints that cannot overflow, `==` on two ints or two strings, `JZ`/`NOT` on
bools, and so on); everything else, errors included, falls through to `Values`. Keep them that
way.

## The C VM must agree with the PHP VM

`vm/` is the same VM in C (roadmap step 7, stage 2), and the PHP VM is its specification: the
same output, the same errors word for word, the same locations, traces and exit codes.
`tests/CVMTest.php` holds it to that. Each entry of `vm/passing.txt` runs on both VMs (the C
one built with AddressSanitizer and UndefinedBehaviorSanitizer), which must give the same
standard output, standard error and exit code, and the C one must not leak: `GAZVM_STATS` makes
it count every reference-counted value, drop what the finished program still holds, and report
what is left over, which the harness requires to be nothing. A program runs from its source on
both, the C VM compiling it with the self-hosted compiler it has built in; a snippet or a `.gzb`
runs as bytecode. An entry is a program in the repository (with its arguments after it, for the self-hosted
drivers), `snippet:<id>`, one of the `executeCode()` snippets the PHP tests run
(`tests/vm_snippets.txt`, collected by `php vm/snippets.php`), or a hand-written `.gzb` under
`tests/bytecode_corpus/`, for the loaders; there the files named `error_*` must be the ones
refused. `php vm/progress.php` tries every candidate and `--update` adds the ones that pass.

So a change to what a value means, to a builtin or to an error message is made in `src/Runtime`
and in `vm/` together, and the harness fails until both say the same thing. When they disagree
because PHP itself changed (`round()` did within 8.5), the rule is GazLang's to define: write it
out in `Runtime` step by step, as `Builtins::round()` is, rather than calling PHP's.

The C is plain C11, libc, libm and pthreads only. A function that can fail returns `bool`, with
the error in `vm_error`; values are reference counted (`incref`, `decref`), lists and maps are
copied on write, and the cycle collector (`vm/gc.c`) frees what counting can't. `vm/gazvm.h`
says which file does what.

## Commands

```bash
composer install

vendor/bin/phpunit                                  # everything
vendor/bin/phpunit tests/MatchTest.php              # one file
vendor/bin/phpunit --filter=testName tests/X.php    # one test

vendor/bin/phpstan analyse                          # must be clean
vendor/bin/pint                                     # formatting; --test to check only
```

```bash
make -C vm                                          # vm/gazvm, optimised
vm/gazvm -f x.gaz -- [arguments...]                 # run source on the C VM, with its built-in compiler
vm/gazvm -f x.gzb -- [arguments...]                 # run bytecode on the C VM (bin/gazlang's options)
php vm/progress.php [FILTER] [--update]             # which programs the C VM matches the PHP VM on
php vm/coverage.php [file.c]                        # which lines of the C VM the harness never runs
php vm/bench.php                                    # the C VM against the PHP VM and against PHP
```

```bash
php bin/gazlang -f examples/functions.gaz                 # compile and run on the VM
php bin/gazlang --interpreter -f examples/functions.gaz   # the tree-walking interpreter
php bin/gazlang -c -f examples/functions.gaz              # print the compiled bytecode
php bin/gazlang --tokens -f examples/functions.gaz        # print the tokens
php bin/gazlang --ast -f examples/functions.gaz           # print the parser's tree
```

## Tests

- **PHPUnit** for the implementation. Snippets run on both backends automatically.
- **`tests/gaz/**/*_test.gaz`** are GazLang programs that must print exactly their
  `*_test.expected` file (`GazProgramTest`), on both backends. `tests/gaz/check.gaz` gives
  `check($label, $actual, $expected)`, which prints `ok <label>` or a FAIL line with both
  values. This is how GazLang code gets tested.
- **`tests/lexer_corpus/`** are lexing cases, including deliberately tricky ones. A file named
  `error_*` must be exactly one that fails to lex. `SelfHostedLexerTest` requires
  `selfhost/lexer.gaz` to give the same tokens and errors as the PHP lexer on these and on every
  other `.gaz` file in the repository, so a change to `src/Lexer` needs the same change there.
  After changing either, also run `php tests/fuzz_lexers.php`, which compares the two on a few
  thousand generated inputs in about five seconds.
- **`tests/parser_corpus/`** are parsing cases: a file per construct, and an `error_*` file for
  every message the parser can raise, which must be exactly the ones that fail to parse.
  `SelfHostedParserTest` requires `selfhost/parser.gaz` to give the same tree and errors as the
  PHP parser on these and on every other `.gaz` file in the repository, so a change to
  `src/Parser` or to a node in `src/AST` needs the same change in `selfhost/parser.gaz` or
  `selfhost/nodes.gaz`. The expected tree is `php bin/gazlang --ast`, which `AST\Dumper` prints
  by reading each node's fields, so a new field shows up in it without being asked. After
  changing either parser, also run `php tests/fuzz_parsers.php`, which compares the two on a few
  thousand changed programs in under a minute. When a location matters, put the node's tokens
  on different lines: a one-line case cannot tell one token's line from another's.
- **`tests/codegen_corpus/`** are code generation cases, built to reach every branch of the
  code generator between them. `SelfHostedCompilerTest` requires `selfhost/codegen.gaz` to give
  byte for byte the bytecode `php bin/gazlang -c` writes, on these and on every other `.gaz`
  file in the repository, so a change to `src/CodeGenerator` or to how `Program` writes needs
  the same change there. After changing either, also run `php tests/fuzz_parsers.php 3000 1 code`.
- **`tests/cli/`** holds the programs `CliParityTest` runs through both command lines,
  `php bin/gazlang` and `vm/gazvm`, which must print the same standard output and standard
  error and exit with the same code: a table of invocations, each its arguments, what is piped
  in and the working directory. A change to either CLI's options, or to how they read files and
  standard input, needs a row there.
- The three ports run on the C VM, so they are checked on every `.gaz` file in every run. The
  self-hosted drivers on big inputs, which take seconds each on the PHP VM, are the one group
  left out: `php -d pcov.enabled=0 vendor/bin/phpunit --group whole-repository`, before
  merging anything that touches a port or either VM.
- **`selfhost/gazlang.gzb`** is the self-hosted compiler's bytecode, checked in and built into
  the C VM, which is how `vm/gazvm -f x.gaz` runs source. A test fails until it is what the PHP
  compiler writes for `selfhost/gazlang.gaz`, so after changing anything under `selfhost/`, run
  `php bin/gazlang -c -f selfhost/gazlang.gaz > selfhost/gazlang.gzb`.
- **`tests/vm_corpus/`** are programs for the C VM that the rest of the repository doesn't
  reach, found with `php vm/coverage.php`: running out of call depth by every kind of call,
  traces cut short, failing `to_string()`s, floats of every shape, cycles. **`tests/bytecode_corpus/`**
  are bytecode files written by hand, one broken way per loader message. After changing either
  VM, also run `php tests/fuzz_vms.php` (programs that throw every kind of value at every
  operator and builtin; `programs` as a third argument mutates the repository's programs
  instead), and for the collector `make -C vm stress` then
  `GAZVM=vm/build/gazvm-stress php vm/progress.php`, which collects cycles at every chance.
- `lib/json.gaz` is checked against PHP's own `json_decode` on every `tests/json/y_*.json` and
  `n_*.json`; `lib/csv.gaz` against `fgetcsv`.

Two environment notes:

- The suite runs **in-process under pcov**, where deep PHP recursion segfaults before the
  interpreter's call-depth limit is reached. Anything that recurses deeply must be tested
  through the CLI instead.
- `bin/gazlang` **restarts itself once** (`GAZLANG_RESTARTED`) to set `pcov.enabled=0` and
  `opcache.jit=1235`, through `proc_open` with its own streams so a program's stdout and stderr
  stay in the order it wrote them.

## Bytecode

The compiler emits a text format, one instruction per line, specified in
[docs/bytecode.md](bytecode.md), which is what the C VM was built from.

```bash
php bin/gazlang -c -f x.gaz > x.gzb     # write it
php bin/gazlang -f x.gzb                # run it (recognised by its first line)
```

A normal run compiles in memory and never goes through the text, but every test's VM side goes
through write-then-read, so the suite tests the format too. `Program::INSTRUCTIONS` is the one
table of every instruction's arguments and stack effect; `BytecodeTest` keeps it,
`docs/bytecode.md` and the VM's cases in step.

The loader checks everything — unknown instructions, undefined labels, bad slots, and a stack
walk through every jump — so a file that loads is one the VM can run. `vm/load.c` makes the same
checks with the same messages, and takes each block's greatest stack depth from the walk to size
its frames.

## Where this is going

The roadmap is in [CLAUDE.md](../CLAUDE.md) under "Path to Self-Hosting". The short version:
the bytecode format is pinned; the lexer, parser and code generator are rewritten in GazLang
and checked against the PHP ones; the VM is rewritten in C and checked against the PHP one.
The compiler already compiles itself to the same bytecode on the C VM, and the C VM runs source
with it built in. What is left is the bootstrap: the C VM gets the whole command line and
becomes `bin/gazlang`, and PHP is no longer needed to run or build GazLang. The PHP
implementation stays as the reference, `bin/gazlang-php`, that the harnesses check against;
the steps are in CLAUDE.md under "The bootstrap plan".

That is why new features get judged by what they cost **in C**, not only in PHP: anything that
leans on PHP's own behaviour (hashing, string conversion, float formatting) has to become a
rule GazLang defines and both runtimes implement.

## Style

PHP 8.5, PSR-4 under the `GazLang\` namespace. Classes are PascalCase, methods camelCase —
except in `Lexer` and `Parser`, where methods are snake_case and named after the grammar rule
they read (`get_next_token()`, `function_call()`, `left_associative()`). Properties are
snake_case, constants UPPERCASE. PHPDoc on classes and methods. Errors are exceptions with
descriptive messages.
