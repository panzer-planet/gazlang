# Modules: `import` replaces `include`

Status: design, for the owner's decisions. Everything said here about today's code was checked
against the repository at `dccbd87`, and every claim about behaviour was reproduced with `bin/gaz`
in `/tmp/modules-design/`.

## Decisions

Accepted as recommended in section 13: Q1 namespaces stay declared and are seen through imports, one project per namespace (D); Q2 `gaz.json` is validated, `name` the only key; Q3 cycles are allowed; Q4 `site/text.gaz`'s functions move into `std/text.gaz`; Q5 the cut is one commit; Q6 no "program directory" builtin yet; Q7 `./forms.gaz` and `forms.gaz` both allowed at the root; Q8 imports at the top of a file only; Q9 a root-relative import into another project's tree is refused; Q10 the `include` error is permanent. Implemented on the branch `import-modules`.

## 0. What the code does today, and where it contradicts the brief or CLAUDE.md

`include` is `include_statement()` in `compiler/parser.gaz` (lines 3839-3905). It splices the
file's top level into the includer at that point. Each file goes in once, keyed by `real_path()`
(or `<std>/name.gaz`), which is also what ends cycles. Name resolution (`resolve()`, 2400) runs once
the whole program is read: the current namespace first, then the file's `use` aliases, then the
global namespace. A qualified `ns::x` is refused only when `ns` isn't in the file's `#reachable`
set.

Measured over `tests apps games examples lib compiler site lsp`, leaving out `tests/.tmp`, which
holds scratch the snippets write:

| | include lines | files | `../` climbs | `std/` | `.gazml` |
|---|---|---|---|---|---|
| tests/gaz | 125 | 66 | 46 | 67 | 11 |
| tests/corpora | 77 | 60 | 5 | 4 | 15 |
| tests/programs | 30 | 10 | 19 | 0 | 1 |
| tests/fixtures + tests/db | 19 | 16 | 7 | 4 | 0 |
| games | 61 | 13 | 15 | 29 | 0 |
| apps | 43 | 13 | 2 | 20 | 5 |
| site | 27 | 7 | 1 | 1 | 7 |
| lib | 18 | 11 | 0 | 0 (plain names = std) | 0 |
| compiler, lsp, examples | 17 | 10 | 6 | 5 | 0 |
| **total** | **417** | **206** | **101** | **130** | **39** |

About 156 of the lines carry a `use` clause. 62 of the 101 climbs go into `lib/`, which means the
standard library loaded by path. The other 39 reach `site/`, `compiler/`, a game's `engine/` or
the app's root from a `tests/` directory. There are 32 `.gazml` files.

Findings that change the brief:

1. **Pain 1 reproduces**: `include "std/text.gaz"; include ".../lib/text.gaz";` gives `Function
   text::lines is already declared at .../lib/text.gaz:14`. `lsp/server.gaz` (lines 10-14) has a
   comment saying it loads `lib/` by path for exactly this reason.
2. **Namespaces merge silently across unrelated files.** `site/text.gaz` declares `namespace
   text;`, and so does `lib/text.gaz`. A file of mine that declares `namespace text` and is
   included next to `std/text.gaz` joins it with no error, and can call text's private
   functions. The site works today only because none of its files pulls in `std/text.gaz`. If it
   included `std/format.gaz`, `lists`, `crypto`, `http`, `term`, `tui` or `devserver` (every one
   of them includes `text.gaz`), its two `text` files would merge. So "private unless `pub`"
   protects nothing from a program that declares the same namespace.
3. **Pain 2 as described is already fixed** (commit `2c8b15c` added the includes to `auth.gaz`).
   The rule behind it still stands. A two-file repro, `na.gaz` (`namespace a; fn f() { return
   B(); }`) and `nb.gaz` (`namespace a; kind B {}`), runs from a main file that includes both.
   `gaz -c na.gaz` alone fails with `Undefined function: B`. Same-namespace names resolve against
   the whole program, not against what a file includes.
4. **Pain 3 is half about includes.** Include paths are relative to the including file, so
   `bin/gaz /tmp/modules-design/sub/m.gaz` run from `/` resolves `../t2.gaz` correctly. What
   depends on the working directory is the program's own run-time paths (`migrate($db,
   "migrations")` in `apps/todo`, `read_file`) and piped source. Imports fix the `../` climbing
   and the tests' `../../../lib`. They don't fix run-time paths (see §10).
5. **Pain 4 costs nothing in real code.** No included file in the repository has a top-level
   statement except two fixtures written to test exactly that (`tests/fixtures/include/cycle.gaz`,
   and `tests/fixtures/include/lib/math.gaz`, which sets `@loaded`). Under `--ast`, the only non-declarations in the
   modules are `StaticInit` nodes, which the parser makes itself.
6. **The compiler already has an include cycle**: `parser.gaz` includes `template.gaz`, which
   includes `parser.gaz` (template.gaz:29-31). That is relevant to §5.
7. **Small bugs met on the way** (not part of this design, worth their own commits):
   - `StaticInitAST` is located with `#at(..., $expr)` after every file has been left
     (parser.gaz:3204). It gets the main file's name and another file's line: `@ "main.gaz" 256`
     in `apps/todo`, where 256 is `lib/web.gaz`'s `static #kept`.
   - The language server places an error from an included file at that line number in the open
     document (`diagnostics()` ignores `$e.file`).
   - `vm/load.c`'s `KEYWORDS` (line 308) is already stale: it still has `class`, `function`,
     `public`, `private` and `protected`. It only names tokens in messages about bad `PUSH`
     literals.

## 1. Syntax

```
import_statement := IMPORT STRING [use_clause] SEMICOLON
use_clause       := USE use_item (COMMA use_item)*
use_item         := IDENTIFIER [AS IDENTIFIER]
path (the STRING, no interpolation):
    "std/" NAME ".gaz"               the standard library
    "pkg/" ...                       reserved: "packages aren't built yet"
    "./" SEGMENTS                    this file's directory or below
    SEGMENTS                         from this module's project root
SEGMENTS := SEGMENT ("/" SEGMENT)*, ending in .gaz or .gazml; no "", ".", "..", no leading "/"
```

- **Placement**: only at the top of a file, after the optional `namespace` line and before any
  declaration or statement. Then a reader, the language server, `--watch`'s successor and the
  fuzzer all see a file's dependencies in its first lines. Today only `compiler/lexer.gaz` (a
  `const` before its include) breaks this rule, and it moves. Errors: `import must come before
  the file's declarations and statements`, and inside a block or function, `import can only be
  used at the top of a file`.
- **`use` is unchanged** in syntax. It gets stricter in meaning: each name must be declared *in
  the module the string names*. Today the name is looked up as `{namespace}::{name}`, so with
  shared namespaces it could pick up a sibling's declaration.
- **What an import makes visible**: the module's top-level names, in its namespace. That means
  bare names if the importer shares the namespace, otherwise `ns::name`, plus whatever the `use`
  aliases add. **What a module is reached by is its `namespace` line, never its path.** Moving a
  file changes the import lines that point at it and nothing else.
- **`include` is reserved for good** and stays the `INCLUDE` token. `top_level()` and
  `statement()` answer it with a message of its own, which belongs next to the `elseif` hint:

  ```
  Error: include is gone: write import "std/json.gaz"; (a path is from the project root, or ./ for this file's directory) at app.gaz:3
  ```

  It isn't in `Parser::RESERVED`, which means "a feature to come". It goes in a new `REMOVED`
  list next to it, which `lsp/server.gaz`'s completion also skips. The hint costs one branch, so
  **it stays as long as the word is reserved, which is permanently** (as `elseif`'s does).
- `undefined_hint`/`library_hint` say `write import "std/lists.gaz";`. `Namespace x is not
  included here` becomes `Namespace x is not imported here: a file only sees what it imports`.

## 2. Resolution: roots, paths, identity

**Project root.** The root of a module is the directory of the nearest `gaz.json` at or above the
module's *own real path*. It is not the entry's. For one project the two are the same. Taking the
module's own root is what lets a package resolve its own imports later (§9), and it means a
nested project (`apps/todo/gaz.json` inside the repository's `gaz.json`) is a world of its own.

| program | root | `./` means |
|---|---|---|
| a file under a `gaz.json` | that directory | the file's directory |
| a file with no `gaz.json` above it | the file's directory | the same |
| piped source, `gaz -e`, `gaz test`/`-S` bootstraps | nearest `gaz.json` above `cwd()`, else `cwd()` | `cwd()` |
| a `std/` module | `<std>` | `<std>` |

So a stranger's single `hello.gaz` anywhere, with or without `std/` imports, runs as today. The
search uses `real_path()`, so the macOS `/var` symlink case finds the same root however the file
was named.

**Refused paths** (each `Cannot import "...": ...` at the import line): one with a `..` segment
(`a path can't climb: write it from the project root, or ./ below this file`), an absolute one, a
missing file, a directory, one ending neither `.gaz` nor `.gazml`, `pkg/...`, and one that lands
inside a different project's root (`games/football/engine/teams.gaz belongs to the project at
games/football (it has its own gaz.json): a project can't import another's files`).

**Identity.** The key is `<std>/name.gaz` for the standard library and the real path for
everything else, as `#included` uses today. One addition settles std against `lib`: when `GAZLIB`
is set, `std/x.gaz` is read from it (as `std_source()` does now), and **any file whose real path
is inside `real_path(GAZLIB)` gets the key `<std>/x.gaz`**. Under `GAZLIB=lib`, then,
`lib/text.gaz` *is* `std/text.gaz`. Without `GAZLIB` the two are different files. The built-in
copy may differ from what is on disk, so treating them as one would be a lie. In that case the
ownership rule below makes a duplicate a pointed error instead of `already declared`:

```
Error: Namespace text is std/text.gaz's: lib/text.gaz can't declare it too (a namespace belongs to one project) at lib/text.gaz:13
```

The repository never needs that error, since after the migration nothing loads `lib/` by path.
`GAZLIB` needs `getenv` in the compiler, which runs as its own program (the fuzzer's `getenv` rule
is about programs it generates).

**Locations.** These stay as today: `display_path()` relative to the working directory for
messages, and `@ "file" line` records in bytecode relative to the main source's directory, with
`<std>/x.gaz` never rewritten. `docs/bytecode.md` doesn't change.

## 3. Modules declare, the entry runs

A module that is **imported** may contain `namespace`, `import`, `fn`, `kind`, `const` and `pub`
before any of them. Any other top-level item is refused where it is written:

```
Error: An imported file only declares (fn, kind, const): move this statement into a function, or into the program's main file at tests/fixtures/include/lib/math.gaz:3
```

The **entry** (the file run, the file given to `gaz -c`, a `*_test.gaz` that `gaz test` runs,
piped source) may do everything. A file that has statements can't be imported. If one is, the
error names the statement, so importing a `main.gaz` says why. A file with no statements can be
imported and can also be an entry: that is "checked alone" (§6). This closes the language gap
"Including a file also runs its top level code". `CLAUDE.md`'s "Files with no top level code"
note in the front end section becomes a rule the compiler enforces.

Consequences, which I judge to be right:

- A kind a template names has to live in a module, not in the entry. `Product` moves out of
  `namespaced_test.gaz` (§7).
- Registration by import ("include this plugin and it hooks itself in") is impossible. A plugin
  exposes `pub fn register($app)`, and the main file calls it. That is explicit, and it fits the
  framework vision's hooks better than order-dependent side effects of imports.

## 4. Namespaces (decision 1)

| option | what it is | judgement |
|---|---|---|
| A. keep as is | declared, optional, shared, flat, visible program-wide | fails the decided rule "a file only sees what it imports", and keeps finding 2 |
| B. one module = one namespace, from the path | `forms.gaz` is `forms::` | the compiler's 43 private kinds in `nodes.gaz` would all need `pub` and a qualifier or `use` in `parser.gaz` (77 `...AST(` constructions) and `codegen.gaz`. Tests of the internals and the language server can't join `gazlang` any more. `apps/todo`'s 13 files reach each other bare today and would all need qualifying. Renaming a file renames its API. `site/text.gaz` and `std/text.gaz` would still clash. |
| C. path as nested namespace | `app::forms::LoginForm` | breaks "a namespace holds no namespace" (`resolve()` only resolves the first part), makes `db::sql"..."`-style tags longer, and multiplies the diff |
| **D. declared namespaces, seen through imports** | as today, but a declaration is visible only to its own module and to modules that import it directly (no transitive visibility); a namespace belongs to one project | keeps `json::decode`, the tags, `use ... as`, per-namespace privacy and `namespace gazlang`. The diff is the missing imports, nothing more. |

**Recommendation: D.** The rules, precisely:

1. `namespace x;` stays optional, at most one, first. Without it a module's names are unqualified
   (today's "global"), but **visible only to its importers**, like everything else.
2. A name written in module M resolves in this order: (a) M's own namespace, declared in M or in
   a module M imports; (b) M's `use` aliases; (c) `ns::name` declared in a module M imports whose
   namespace is `ns`; (d) an unqualified name declared in an imported module without a namespace;
   (e) builtins and the builtin kinds `Error`, `Shared` and `Html`, which need no import. As today,
   only the first part of a name is resolved.
3. **Privacy stays per namespace.** Two modules of `namespace todo` see each other's private
   names *once one imports the other*. `pub`/`kin`/private on members are unchanged.
4. **Ownership**: every module of a namespace must have the same project root (std counts as
   one). That ends finding 2: a program can't join `http` and call its private functions, and
   `site/text.gaz` must rename its namespace or give its two functions to `std/text.gaz`
   (question Q4).
5. When a name is declared in the program but not visible, the error says which import is
   missing, spelt as the migration script would write it:

   ```
   Error: Undefined type: RegistrationForm (todo::RegistrationForm is declared in forms.gaz, which this file doesn't import: add import "forms.gaz";) at auth.gaz:20
   ```

Implementation: `#declared_in` gets a sibling, `#declared_module[name] = module key`. Each scope
in `#scopes` keeps the set of module keys it imports (its own included). Steps (a), (c) and (d)
of `resolve()` filter on that set. `#reachable` is then the namespaces of the imported modules.
Nothing below `resolve_names()` changes, so the code generator, bytecode and VM learn nothing,
as CLAUDE.md requires.

What D leaves alone: kinds stay "usable before their declaration" (resolution still runs after
the whole program is read). Constants still fold across modules, through resolved names, and the
`Constant A depends on itself: A uses B uses A` cycle message spans files. `Kind::NAME`,
`Kind::count` and `Kind::next()` need `Kind` visible, which is the same rule as any name.
`db::sql"..."`/`web::html"..."` need their std module imported, as they need it included today.

## 5. Cycles (decision 2)

Options: an error, allowed for declarations only, or allowed.

Under §3 every imported module is declarations only, and everything is resolved after the whole
program is read. So there is no initialisation order for a cycle to break: static defaults are
constants that the parser folds (`StaticInitAST`), and constants already detect their own cycles.
"Allowed for declarations only" is therefore the same thing as "allowed". The compiler needs its
cycle: `parser.gaz` and `template.gaz` use each other (`ParseError`, `TemplateTranslator`), and
`nodes.gaz`/`parser.gaz` refer to each other's kinds. Making it an error would mean merging those
files or adding an interface module for no gain in safety.

**Recommendation: allowed.** A module already being read, or already read, gives nothing again,
which is today's once-by-identity rule. A cycle that reaches the entry can only happen if the
entry has no statements, and then it is harmless.

If the owner prefers errors, the message would be `Import cycle: compiler/parser.gaz imports
compiler/template.gaz imports compiler/parser.gaz`. The cost is the compiler's restructuring and
an extra rule for every program, without a defect avoided.

## 6. Compile model and "checked alone"

**Still one whole program, compiled to one bytecode file.** The parser reads modules depth-first
in import order, with each module's imports before its own declarations (pre-order, the order a
splice of top-of-file includes gives today). Because every include in the repository except
`lexer.gaz`'s already sits at the top, **a migrated program's bytecode is the same as today's
apart from `@` paths**. That is the migration's main check (§8). No bytecode format change, no
loader or VM change. The VM's only contact is the two bootstrap strings in `vm.c` (`TEST_BOOTSTRAP`
and the `-S` one, `include "std/..."` to `import`). `--watch` (`source_files()`, from `@` lines)
is unchanged. Optionally, `load.c`'s stale token table can learn `import` (finding 7).

**Cost**: the same parse as today, plus one set lookup per resolved name and one directory walk
per entry and per directory a module sits in, cached. Measured on today's binary: `gaz -c
compiler/gazlang.gaz` takes 0.32s and 35 MB RSS, and `gaz -c apps/todo/main.gaz` 0.20s and 30 MB.
Expect no measurable change. Re-measure on the prototype before trusting that.

**Checked alone.** `gaz -c FILE`, the language server and `gaz --ast FILE` make FILE the entry and
compile the closure of its imports. Guaranteed:

- Every name FILE writes resolves through FILE's own imports, the same way in the closure as in
  any program that contains FILE. Visibility depends only on FILE's imports.
- Every check that depends on FILE's names alone gives the same answer alone and in the full
  program: arity, types, privacy, members against a kind and its parents (a parent must be
  imported), overrides, constant folding and `use` clauses. So an error found alone is an error
  in every program that contains FILE.

Not guaranteed, because these are program-wide by nature:

- duplicate declarations: two modules of one namespace declaring `f` where FILE imports only one
  of them;
- namespace ownership clashes between modules FILE doesn't reach;
- whether `Error`/`Html`/`Shared` get compiled in, and static slot numbering. These change the
  bytecode, not whether it is valid.

So: if FILE compiles alone, the full program can fail only on uniqueness, and the message names
both declarations.

## 7. Before and after

**`gaz.json`** marks a project root. For now it is a JSON object whose only key is `"name"`,
validated (question Q2). The repository root gets one, and so do `apps/todo/` and
`games/football/`, so the game can move to its own repository with no rewrite:

```json
{"name": "todo"}
```

**`apps/todo/auth.gaz`**

```gaz
// before
namespace todo;

include "std/crypto.gaz";
include "forms.gaz";
include "users.gaz";
```

```gaz
// after: unchanged except the keyword; this file already imports what it names
namespace todo;

import "std/crypto.gaz";
import "forms.gaz";
import "users.gaz";
```

Before `2c8b15c` this file had no includes and compiled only inside `app.gaz`. Under the new
rules that version is refused alone and in the app, with `Undefined type: RegistrationForm
(todo::RegistrationForm is declared in forms.gaz, which this file doesn't import: add import
"forms.gaz";) at auth.gaz:20`.

**`apps/todo/main.gaz`**: the comment's `../../bin/gaz main.gaz` is unchanged (run-time paths
still depend on the working directory). The imports:

```gaz
namespace todo;

import "std/http.gaz";
import "app.gaz";
import "config.gaz";      // Config::from_environment(): was visible through app.gaz's includes
import "database.gaz";    // connect(), migrate()
import "throttle.gaz";    // LoginThrottle
```

`main.gaz` is the only file in the app with statements, so the rule is that it imports what it
uses. The views import what their parameters name:

```gaz
namespace todo;
import "forms.gaz";
import "users.gaz";
@template layout(string $title, ?string $flash, ?User $user, string $csrf, Html $body)
```

The template's header lines are passed through one for one (`is_namespace_line()` grows an
`is_import_line()`), so errors stay at the template's own line. `apps/todo/tests/forms_test.gaz`
loses its climb: `import "forms.gaz";` (from the app's root).

**A test that loaded a library by path**, `tests/gaz/lib/http_session_test.gaz`:

```gaz
// before
include "std/test.gaz" use expect, throws;
include "../../../lib/http.gaz";
include "../../../lib/crypto.gaz";
```

```gaz
// after: the built-in copy, which the tests build from lib/ and StdLibraryTest checks equals it
import "std/test.gaz" use expect, throws;
import "std/http.gaz";
import "std/crypto.gaz";
```

**`lib/http.gaz`**:

```gaz
// before                                   // after
namespace http;                             namespace http;

include "chars.gaz" use is_alnum, ...;      import "./chars.gaz" use is_alnum, is_digit, is_hex_digit;
include "date.gaz";                         import "./date.gaz";
include "crypto.gaz";                       import "./crypto.gaz";
include "json.gaz";                         import "./json.gaz";
include "text.gaz" use quote;               import "./text.gaz" use quote;
```

Inside `<std>`, `"./x.gaz"` and `"std/x.gaz"` have the same key. The library writes `./`, the
spelling of a sibling.

**A template that needs `format::number`**. Today, `tests/gaz/templates/views/shop_card.gazml`
can't reach it (`Namespace format is not included here`), and its `Product` type lives in the
test's entry file:

```gaz
namespace shop;
import "std/format.gaz";
import "./shop.gaz";            // Product, moved out of namespaced_test.gaz, which has statements
@template card(?Product $product, string $note = "")
<div class="card">
<b>{{ $product.name }}</b> {{ format::number($product.price, 2) }} {{ $note }}
@if ($product.price > 100)
<em>premium</em>
@endif
</div>
```

**A compiler file**, `compiler/lexer.gaz` (the `const` moves below the import) and
`compiler/template.gaz`, which now says what it uses instead of reaching it through the cycle:

```gaz
namespace gazlang;                                   // lexer.gaz

import "std/chars.gaz" use char_at, is_digit, is_hex_digit;

const TEMPLATE_BUFFER = "$#html";
```

```gaz
namespace gazlang;                                   // template.gaz

import "./lexer.gaz";      // TEMPLATE_BUFFER
import "./parser.gaz";     // ParseError; parser.gaz imports this file too, which is allowed
```

`codegen.gaz` gains `import "./nodes.gaz";` and `import "./lexer.gaz";` next to `./parser.gaz`,
because it names AST kinds and `Token` directly. `lsp/server.gaz` becomes `import
"compiler/parser.gaz"; import "std/json.gaz" use decode, encode; import "std/chars.gaz" use
char_at, is_alnum, span; ...` (from the repository root), and its comment about duplicate
`chars.gaz` is deleted.

**The football game** (`games/football/gaz.json`):

```gaz
// games/football/main.gaz: unchanged but the keyword
import "std/term.gaz";
import "std/tui.gaz";
import "game.gaz" use Game;
import "ui.gaz" use Shell;
```

```gaz
// games/football/tests/engine_test.gaz: the climbs become root paths
import "std/test.gaz" use expect, throws;
import "engine/players.gaz" use Goalkeeper, Defender, Midfielder, Forward;
import "engine/teams.gaz" use Team, Formation, Scout, InvalidLineup, TransferRefused;
```

**A stranger's first project** (the README):

```
hello/
  gaz.json          {"name": "hello"}
  main.gaz          import "greet.gaz";  echo greet::hello("world");
  greet.gaz         namespace greet;  pub fn hello($who) { return "Hello, {$who}!"; }
```

`gaz main.gaz` runs from anywhere: `gaz hello/main.gaz` or `cd hello && gaz main.gaz`. A single
file needs no `gaz.json`.

## 8. Migration

### Script (a throwaway PHP script, run once)

For every `.gaz`/`.gazml` under `apps compiler examples games lib lsp site tests`, leaving out
`tests/.tmp`, `site/dist` and `vendor`, take each `include "P"[ use ...];`:

1. Find the target T exactly as `include_statement()` does now. `std/X` and a plain name inside
   `lib/` go to std. Otherwise T is `realpath(dirname(F)/P)`.
2. If T is `lib/X.gaz` or a std target, write `"std/X.gaz"`. Inside `lib/`, write `"./X.gaz"`.
3. Otherwise R is the nearest `gaz.json` above F (the markers are added first). If T is outside R,
   or inside a nested root, stop and report it (expected: none). If F is not at R and T is in
   F's directory or below, write `"./" . rel(T, dirname(F))`. Otherwise write `rel(T, R)`, so a
   file at the root writes `"forms.gaz"`, not `"./forms.gaz"`.
4. Keep the `use` clause byte for byte, and replace the keyword.
5. Move every import line to right after the `namespace` line (or the header comment), keeping
   their order. Today only `compiler/lexer.gaz` needs it. Corpus files are skipped and redone by
   hand, because their line numbers are what they test.
6. Rewrite the strings in `tests/*.php` snippets, `vm.c`'s two bootstraps, README/docs code blocks
   and `site/pages.gaz`'s `include_line()` (with `library_index.gazml`'s sentence) the same way,
   from the project root (piped snippets run there).

### By hand

- **Missing imports**, driven by the new error, which names the line to add. These are files in a
  shared namespace or that use a name they reached transitively: `compiler/` (6 files),
  `apps/todo` (13 files, 5 views, 4 tests), `site/` (8), `games/football` (main and tests).
- `tests/gaz/templates/*` and views: kinds move out of entry files into modules (`Product` to
  `views/shop.gaz`, and the same for `templates_test.gaz`'s kinds where views name them).
- `tests/fixtures/include/` (the statement-in-a-module and cycle fixtures), `tests/corpora/parser/
  include/` (20 cases) and `namespaces/` (17): rewritten as the new error cases of §11, and
  re-recorded. `IncludeTest.php` (8 tests) becomes `ImportTest.php`.
- `site/text.gaz`'s namespace (Q4).
- The fuzzer (§10).

### Commits, each green

1. **Rename `site/text.gaz`'s namespace** (or merge it into `lib/text.gaz`). This fixes finding 2
   for the site.
2. **Load the standard library by `std/` everywhere** (still `include`). That covers the 62
   `lib/` climbs in `tests/`, `tests/programs/football/*`, `lsp/server.gaz`, and
   `compiler/lexer.gaz` (`include "std/chars.gaz"`, then `make compiler`). Re-record what prints a
   library path (`lib/x.gaz:N` becomes `<std>/x.gaz:N`), and rewrite CLAUDE.md's "tested by path"
   lines. On its own this ends pain 1 and pain 6's precondition, and it is a good change even if
   imports never land.
3. **Fixtures and template tests without top-level code in included files; `lexer.gaz`'s include
   hoisted.** Still `include`.
4. **Add the `gaz.json` markers** (inert under `include`).
5. **The cut**, one commit:
   - lexer `IMPORT`, `include` as a removed word;
   - the parser's module model (§2-§5);
   - template header imports;
   - the script's output and the hand fixes;
   - the language server, the fuzzer, the editor grammar, the site, the VM bootstrap strings;
   - docs;
   - re-recorded corpora, a `vm/snippets.php` run, `progress.php --update`;
   - `compiler/gazlang.gzb`, built through a **local intermediate compiler**: (a) edit the parser
     to accept both `include` (old meaning) and `import`, then `make -C vm compiler`, which gives
     an intermediate `gazlang.gzb` that is never committed; (b) apply the final source (`include`
     refused, compiler sources on `import`), then `make -C vm compiler`. Stage 1 is the
     intermediate compiling the final source. Stages 2 and 3 are the final compiler compiling
     itself, which must be equal. Only stage 2 is committed. A fresh clone holds a seed that
     understands `import` and sources that use it. `make` builds from the seed with only a C
     compiler, and `make compiler` reports "up to date". Check that in a fresh clone, since the
     working tree hides missing files.
6. **Afterwards**: the compiler uses the library where it repeats it (pain 6). For example, the
   `slice(to_string([$v]), 1, -1)` idiom becomes `text::quote`.

If the owner prefers smaller reviews, step 5 can be two commits, (5a) "the compiler learns
`import`, keeping `include`" and (5b) "the cut", merged together with no release in between. That
leaves a short stretch of `master` where both exist. The one-commit form has none and reverts in
one step. **Recommendation: one commit.**

### How the cut is verified

- For every program and test file, compare `gaz -c` before and after with `@` paths normalised
  (`lib/X` → `<std>/X`, the new spelling of each moved file). The bytecode must be identical apart
  from block order in files where hand-added imports changed the reading order. Run this as a
  script over the `vm/passing.txt` source entries.
- Everything in `tests/expected/` must be unchanged except path strings and the
  include/import messages, and that diff gets reviewed.
- The self-compile test, `make compiler` reporting up to date, the full suite, `gaz test tests/gaz
  games`, `apps/todo`'s tests against PostgreSQL, and `site/build.gaz`.

**Risks and rollback.** The cut touches ~250 files. A missed import is loud (a compile error),
never silent. The one silent risk is rule 2(a): a bare name that used to mean an unimported
same-namespace declaration now falls through to a builtin of the same name. The bytecode
comparison catches it, because the call changes from `CALL` to `CALL_BUILTIN`. Rollback is
`git revert` of the cut, whose seed is included. Commits 1-4 stay, since they stand alone.

## 9. Packages later, with no second migration

The design already has what `pkg/` needs. Each module resolves against its own project's root,
identity is by real path, a namespace belongs to one project, and `pkg/` is a reserved first
component. Later, `gaz.json` gains `"requires"` and `"gazlang"`, the tool fetches into
`packages/`, and `import "pkg/router/router.gaz"` maps to `<root>/packages/router/router.gaz`. That
file's own root-relative imports resolve against `packages/router/gaz.json`'s root, by the rule
already in force.

Now the marker must be a JSON object, with `"name"` the only key accepted. Every other key is an
error (`gaz.json: "requires" isn't read yet: packages aren't built`), so nothing written today
can mean something else later. This needs the compiler to `import "std/json.gaz"`, which adds
33 KB to the 552 KB seed, about 6% (Q2).

## 10. Tooling

- **Language server**: the root and path rules become `pub` functions in `parser.gaz` (the server
  is in `namespace gazlang`), and `included_paths()` uses them instead of `dirname + match`.
  Completion and go-to-definition follow `import` lines. That is still textual, because the tree
  doesn't exist mid-edit, and now resolvable since imports sit at the top. Diagnostics treat the
  document as the entry. A module opened on its own now gets the real answer (pain 2), and an
  error whose `file` isn't the document is shown at that document's import line ("in forms.gaz:
  ..."), which fixes finding 7. `Parser::REMOVED` is excluded from completion, and `LspTest`
  checks that.
- **Editor grammar**: `import` joins the keyword rule at `editors/gaz/gaz.tmLanguage:193`.
  `include` stays in it, because `EditorGrammarTest` requires every `Lexer::KEYWORDS` word and
  `include` remains one.
- **Website**: `include_line()` shows `import "std/x.gaz";`, and the index text changes.
  `round_trip_test` needs nothing beyond the lexer knowing the word. `library.gaz` reads `pub`
  names from source, so it is unaffected.
- **`gaz --watch`**: unchanged (it reads `@` lines). `gaz.json` isn't watched until it means
  something.
- **`gaz test`**: each `*_test.gaz` is an entry. A test's root is its own project's, so `gaz test
  tests/gaz games` works across the nested roots.
- **Fuzzer**: it can't make imports absolute any more. It writes each mutant next to its original
  as `.fuzz-<pid>-N.gaz` (gitignored, deleted in a `finally`), so `./` and root paths still
  resolve. Its forbidden-builtin scan gets the module list from `gaz -c`'s `@` lines, as `--watch`
  does, instead of following `include` with a regex.
- **Docs**: `docs/language.md` gets a "Modules" section in place of "Namespaces" plus the include
  paragraph (~1507), and the edits to Templates, `gaz -e`, the keyword list (607) and ~41 other
  mentions. README: 9 mentions, plus the first-project block. CLAUDE.md: "Namespaces", the
  include bullet in "Statements, functions and scope", the embedding paragraph in "Builtins and
  the standard library", "Templates" (namespace bullet, "can't include"), "Language gaps"
  (strike "Including a file also runs its top level code"), "Packages", the language server,
  fuzzer, "A game is neither", the self-hosted front end's "Files with no top level code", and
  the CLI's piped-source and `-e` notes.

## 11. Tests

Parser corpus, `tests/corpora/parser/modules/`. Every `error_*` must fail and nothing else may:

| file | must say |
|---|---|
| `error_include_is_gone` | the include hint |
| `error_import_climbs`, `error_import_absolute`, `error_import_missing`, `error_import_directory`, `error_import_not_gaz`, `error_import_interpolated`, `error_import_pkg` | each refusal of §2 |
| `error_import_after_declaration`, `error_import_in_function` | placement |
| `error_statement_in_imported_module` | §3, at the module's line |
| `error_name_not_imported` | same namespace, sibling not imported, with the hint |
| `error_qualified_not_imported`, `error_transitive_not_visible` | A imports B imports C, and A names C's |
| `error_use_from_wrong_module` | `use` of a name a sibling of that namespace declares |
| `error_namespace_owned_by_std`, `error_import_other_project` | ownership and nested roots |
| `cycle_kinds_across_modules` | two modules whose kinds extend and construct each other |
| `template_imports` (+ `views/priced.gazml`) | a template using `format::number` and another template |
| `error_template_import_after_header` | an import line after `@template` |

Each runs from source and piped (`.piped.ast`), and some from `places/`. `lexer/import.gaz`
records the `IMPORT` token. `codegen/modules.gaz` shows block order across modules.

PHP and GazLang tests:

- `ImportTest`: std and `lib` identity under `GAZLIB=lib` (one module), and without it (the
  ownership error).
- Running from another working directory and through a symlinked path.
- `gaz -c` on a non-entry module (compiles, does nothing). The same module missing an import fails
  alone and in its program, at the same line.
- `CliTest` rows: `-e` and piped imports resolve from `cwd()`'s project.
- `LspTest`: no diagnostics for a correct module opened alone, and an imported file's error placed
  at the import line.

**Breaking each checker on purpose**:

| break | what must fail |
|---|---|
| the scope filter in `resolve()` | `error_name_not_imported` and `error_transitive_not_visible` compile |
| the `GAZLIB` aliasing | the identity test reports a double declaration |
| making cycles an error | `cycle_kinds_across_modules` and `make compiler` |
| the statement check | `error_statement_in_imported_module` |
| the ownership check | `error_namespace_owned_by_std` |
| root from the entry instead of the module | `error_import_other_project` and the games' tests |
| `is_import_line()` | `template_imports` |
| the module filter on `use` | `error_use_from_wrong_module` |

## 12. Costs, what is deferred, and the first slice

What gets harder:

- A script that wants a helper in `../shared/` needs a `gaz.json` at a common ancestor.
- An imported file can't run code at import time.
- Kinds a template names must sit in a module.
- Piped source and `gaz -e` gain a `cwd()`-based root (as their includes are `cwd()`-relative
  today).
- The compiler reads one more file (`gaz.json`) and walks some directories.

The std embedding is untouched.

Deferred:

- `import "x" as ns` (renaming a namespace);
- packages;
- run-time paths relative to the program, a `program_dir()`-style builtin, which is the other
  half of pain 3 (Q6);
- the compile-time scan for templates.

**The smallest first slice**, if the owner wants to start before every question is settled, is
commits 1-2 of §8. They are useful whatever is decided and end pain 1 today. Then a branch
prototype of the parser's module model, run over `lib/` and `apps/todo` only. That proves three
things on real code: the missing-import errors find real gaps, the bytecode is identical modulo
`@` paths, and the compile time stays the same.

## 13. Open questions for the owner

- **Q1. Namespaces:** D (declared, seen through imports, one project per namespace) or B
  (namespace from path)? *Recommend D.*
- **Q2. `gaz.json` now:** a validated JSON object (`name` only; costs json in the compiler, about
  6%) or a bare marker whose content is ignored until packages? *Recommend validated: loud over
  silent.*
- **Q3. Cycles:** allowed or an error? *Recommend allowed.*
- **Q4. `site/text.gaz`:** give `indentation`/`unindented` to `std/text.gaz` as `pub`, or rename
  its namespace? *Recommend move to std: the site is the evidence a library function is wanted.*
- **Q5. The cut:** one commit with a local intermediate seed, or 5a+5b with no release between?
  *Recommend one commit.*
- **Q6. Run-time paths:** add a "this program's directory" builtin with the cut, or wait for a
  program to ask? *Recommend wait: it is a separate gap, and `apps/todo` is the only evidence.*
- **Q7. Spelling:** let a file at the root write `"./forms.gaz"` and `"forms.gaz"`
  interchangeably, or refuse the `./` form where the root form names the same file (one
  spelling)? *Recommend allow both; the script writes `./` only from a file below the root, for
  its own directory and below.*
- **Q8. Imports at the top only**, or anywhere at the top level? *Recommend top only.*
- **Q9. Nested projects:** refuse a root-relative import into another `gaz.json`'s tree, or allow
  it? *Recommend refuse: that is a package.*
- **Q10. The `include` hint:** permanent, or dropped at the first release with a compatibility
  promise? *Recommend permanent: one branch, as `elseif`'s.*
