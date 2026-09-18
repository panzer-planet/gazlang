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
| `lib/` | the standard library, written in GazLang |
| `selfhost/` | the toolchain rewritten in GazLang: so far `lexer.gaz`, a port of `src/Lexer`, and `tokens.gaz`, which prints its tokens as `--tokens` does |
| `examples/` | sample programs |
| `tests/` | PHPUnit, plus `tests/gaz/` GazLang programs and `tests/lexer_corpus/` |

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
php bin/gazlang -f examples/functions.gaz                 # compile and run on the VM
php bin/gazlang --interpreter -f examples/functions.gaz   # the tree-walking interpreter
php bin/gazlang -c -f examples/functions.gaz              # print the compiled bytecode
php bin/gazlang --tokens -f examples/functions.gaz        # print the tokens
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
[docs/bytecode.md](bytecode.md) — which is what a C VM will be built from.

```bash
php bin/gazlang -c -f x.gaz > x.gzb     # write it
php bin/gazlang -f x.gzb                # run it (recognised by its first line)
```

A normal run compiles in memory and never goes through the text, but every test's VM side goes
through write-then-read, so the suite tests the format too. `Program::INSTRUCTIONS` is the one
table of every instruction's arguments and stack effect; `BytecodeTest` keeps it,
`docs/bytecode.md` and the VM's cases in step.

The loader checks everything — unknown instructions, undefined labels, bad slots, and a stack
walk through every jump — so a file that loads is one the VM can run.

## Where this is going

The roadmap is in [CLAUDE.md](../CLAUDE.md) under "Path to Self-Hosting". The short version:
the bytecode format is pinned, and next is a standalone VM in C, then the lexer, parser and
compiler rewritten in GazLang, then a bootstrap that drops PHP entirely. The PHP implementation
stays the reference throughout.

That is why new features get judged by what they cost **in C**, not only in PHP: anything that
leans on PHP's own behaviour (hashing, string conversion, float formatting) has to become a
rule GazLang defines and both runtimes implement.

## Style

PHP 8.5, PSR-4 under the `GazLang\` namespace. Classes are PascalCase, methods camelCase —
except in `Lexer` and `Parser`, where methods are snake_case and named after the grammar rule
they read (`get_next_token()`, `function_call()`, `left_associative()`). Properties are
snake_case, constants UPPERCASE. PHPDoc on classes and methods. Errors are exceptions with
descriptive messages.
