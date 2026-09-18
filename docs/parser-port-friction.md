# Friction log: porting the parser to GazLang

Every workaround the language forced while `src/Parser/Parser.php` became `selfhost/parser.gaz`,
written down when it was met, with the options for removing it. Each entry was reproduced, not
assumed (three confident friction claims dissolved on inspection during the lexer port).

Hole numbers refer to "Holes to fill next" in CLAUDE.md.

## Summary

`parser.gaz` is 1763 lines for `Parser.php`'s 2290, and `nodes.gaz` 521 for `src/AST`'s 1278
(most of the difference is PHPDoc). Nothing the language lacks stopped the port: every
entry below is a workaround that worked. Ranked by what removing it would do for the code
generator port, which is next and larger:

| # | Friction | Workaround | Recommended |
| --- | --- | --- | --- |
| 8 | ~~No constants~~ | **built**: `const`, at the top level and in a class | done, see CLAUDE.md "Constants" |
| 9 | ~~No working directory, real path or existence test~~ | **built**: `cwd()`, `real_path()`, `file_exists()` | done, see below |
| 10 | A parser in GazLang has a nesting limit | none; a list as a stack for walking trees | the VM's call depth, later |
| 1 | ~~An object's fields can't be listed~~ | **built**: `fields($object)` | done, see below |
| 3 | ~~Builtins can't be asked about~~ | **built**: `builtins()` | done, see below |
| 2 | A class's bare name | `slice(to_string(class_of($x)), 6)` | `class_name($class)` |
| 7 | ~~Lists can't be joined~~ | **built**: spread, `[$x, ...$rest]` | done, see below |
| 5 | Two results out of one walk | an object | nothing: hole 1 already says so |
| 4, 6 | Calling a method by name; identity keys | not needed | nothing: both dissolved |

Entries 4 to 6 matter as much as the rest: three things the audits listed as holes were met
head on by real code and did not bite. `$obj.$name` was not needed because a bound method is a
value; `spl_object_id` was not needed because the state it kept in a side table belongs on the
node, or in one field; by-reference parameters were not needed because the walk is an object.

## 1. An object's fields can't be listed (hole 2, now with real code asking)

**Met:** before the first line of parser. The PHP side of the harness, `AST\Dumper`, is 118 lines
that know no node type: `get_object_vars()` lists a node's fields, so a field added to a node is
printed without anyone remembering to. `Parser::collect_variables()` walks the tree the same way
to find what a lambda captures.

**Workaround:** every node class in `selfhost/nodes.gaz` has a `parts()` method returning its own
fields as a map, by hand, in the PHP class's declaration order: one method per class, about 30 of
them, which is a fifth of that file (106 of 521 lines). A field added to a node and forgotten in `parts()` is
invisible to the dump *and* to capture analysis, silently. The harness catches the first (the PHP
dump has the field), nothing but a test catches the second.

**Options:**
- `fields($object)`, a builtin giving a map of the set fields by name in declaration order. One
  function, costs nothing in C (the layout is already a table), and `json_encode` could then take
  objects. **Recommended.**
- `foreach` over an object. Same information, but a language change where a builtin will do, and
  it makes objects look like maps, which `==` and indexing deliberately say they are not.
- Leave it. `parts()` is boilerplate, but it is boilerplate a compiler only writes once per node.

**Decided and built 2026-09-18: `fields($object)`**, a map of the set fields by name (no `#`),
in layout order, which is `echo`'s order and `get_object_vars()`'s. A field never set is left
out rather than given as null, since reading one is an error; anything but an object is an
error, as it is for `class_of`. `json_encode` still refuses objects: that is its own decision.

All 29 `parts()` methods are gone and `nodes.gaz` went from 538 to 413 lines. Nodes already
declared their fields in the PHP order, so the harness passed at the first run with only two
changes to the walkers: the collector calls `fields()`, and the dumper leaves out what
`AST\Dumper::SKIPPED` does plus the port's own `use` and `class_use` (standing in for #6's side
table). The silent failure is gone by construction, since there is nothing to forget, and the
loud one is tested: a field declared out of order, or one the PHP node doesn't have, fails the
harness (both tried).

## 2. A class's bare name (hole 2, already listed)

**Met:** the dump prints `Num`, and `to_string(class_of($node))` is `class NumAST`.

**Workaround:** `slice(to_string(class_of($value)), 6, -3)`, which leans on how a class prints.

**Options:** `class_name($class)` builtin (**recommended**, trivial); or accept the slice.

## 3. Builtins can't be asked about

**Met:** the parser checks every call by name against `Builtins::ARITIES`. GazLang has no way to
ask whether `len` is a builtin or what it takes.

**Workaround:** `Parser.#functions` starts as a hand-copied table of all 40 builtins, and
`SelfHostedParserTest::test_the_parsers_builtin_table_is_the_runtimes` fails when the two drift.
After the bootstrap the copy *is* the table and the C VM has the other one, so the drift check
has to survive in some form either way.

**Options:**
- `builtins()`, giving the map name => arity. The runtime already has it. **Recommended**: one
  table, no drift check.
- Leave it, and keep the test. It is one test and the table changes rarely.

**Decided and built 2026-09-18: `builtins()`**, the map name => arity (an int, or
`[fewest, most]`), which is `Builtins::ARITIES` and the shape a function's arity already has,
in no promised order. The port's table and the test that kept it in step are gone. "The table
changes rarely" was wrong: it went from 40 entries to 45 in three changes on the day this was
built (`cwd()`, `real_path()` and `file_exists()`, then `fields()`, then `builtins()` itself).

What replaces the drift check is an assumption, written down so it stays true: `builtins()`
describes the runtime the *compiler* runs on, not the one its output will run on. Those are
the same PHP runtime now, and will be the same C VM after the bootstrap; a loader already
refuses bytecode naming a builtin it doesn't have. A cross-compiler would break it.

**Met while building it:** the table is a field (`#builtins = builtins();`), not a constant,
since a constant's value is worked out by the parser and a call is not a constant expression.
It costs one map per `Parser`, which is one per program, and nothing writes to it.

## 4. Calling a method by name: not friction

`left_associative('unary', [...])` calls `$this->$operand()`. There is no `$obj.$name` (hole 2),
but it was not needed: `#left_associative(#unary, [...])` passes the bound method, which is
better than the PHP, where the name is a string no tool checks. Recorded because the hole's
"each wants real code asking for it first" should not count this as a request.

## 5. Two results out of one walk (hole 1: a mirage here too, with one sharp edge)

**Met:** `collect_variables(AST $node, array &$found, array &$assigned)` fills two arrays by
reference while it recurses.

**Workaround:** `VariableCollector`, an object with `#found` and `#assigned`, whose `collect()`
recurses. This is what hole 1 already concluded (mutable state belongs in an object) and it reads
better than the PHP. The sharp edge is the one hole 1 documents: the first draft passed `$found`
to a helper and appended to it there, which silently did nothing. Nothing new to decide.

## 6. A side table keyed by an object (hole 6: no identity key)

**Met:** `$this->lambda_heads[spl_object_id($paren)] = true` marks the `(` tokens that may head a
lambda, and `$this->member_uses[spl_object_id($node)]` finds a `#name`'s record again when it
turns out to be called or assigned to.

**Workaround:** neither needed a table. `#lambda_head` is one field holding the last `(` marked,
compared with `==` (identity): nothing is read between `ternary()` marking a `(` and
`parenthesised()` asking about it, so a set of every one ever marked was more than the PHP needed.
A `#name`'s record will hang off the node itself (stage 4). Both are better than the original:
`spl_object_id()` values are reused once an object is freed, so a set of ids of tokens long gone
can in principle claim a later token. That could not be provoked by hand; the parser fuzzer is
the thing to find it if it is real.

**Options:** none wanted yet. Two of the seven `spl_object_id` uses the audit counted dissolve
when looked at; the other five get the same look in stage 4.

## 7. Lists can't be joined (hole 6, already listed; two real uses now)

**Met:** `[$node->key, ...$node->value->targets]` in capture analysis, and
`array_unshift($root->statements, $error_class)` in `program()`.

**Workaround:** for the first, append the key afterwards, since the order of that list doesn't
matter. For the second, a new list and a loop. Both are three lines for what is one expression.

**Options:** a `concat($a, $b)` builtin, or `..` on two lists. `..` reads best and is what the
operator is for, but it converts both sides to strings today (`[1] .. [2]` is `"[1][2]"`), so it
would change what existing source means; a builtin is safe. **Recommended: the builtin**, when a
third use turns up. Two uses in 1500 lines is not much.

**Decided and built 2026-09-18, as spread in list literals rather than a builtin**, once the code
generator port brought five more uses: every one was a literal (`[$node->key, ...$targets]`,
`['locals', ...$names]`), which spread writes as the PHP does and a builtin would not. Both uses
here now read as they do in `Parser.php`. See CLAUDE.md, roadmap step 5.

## Found in the PHP parser along the way

Not friction, but the port is a second reader of `Parser.php` and the corpus is a test it never
had. Each was fixed in `Parser.php` and the port mirrors the fix.

- **A name was checked before anything confirmed it was a name.** `check_new_name()` and the
  duplicate-parameter check ran on the current token's value, whatever the token was: a bare `fn`
  at the end of a file was a PHP `TypeError` (null is not a string) rather than a GazLang error,
  `fn "len"() {}` was "len is a builtin function" and `fn f($a, '$a') {}` was "Duplicate
  parameter $a", where both are strings that `eat()` should refuse. Both now look only at a
  token of the right type, which keeps every other error where it was.

## 8. No constants (hole 6), and what stands in for them shares a namespace with methods

**Decided and built 2026-09-18**, the first option below and class constants with it: see
"Constants" in CLAUDE.md. The lexer's and parser's tables are constants now. Token types are
still bare strings; converting them is what will say whether an enum is wanted.

**Met:** `Parser.php` has five class constants: `ASSIGNMENTS`, `BUILTIN_CLASSES`, `BUILTIN_FILE`,
`RESERVED`, `EXPECTED`, and reads `Lexer::KEYWORDS` and `Builtins::ARITIES` from other classes.

**Workaround:** fields with defaults (`#assignments = [...]`). It costs little: a constant list or
map literal is built once at compile time, so the default is one `PUSH` per new `Parser`. Three
things are worse than a constant, though:
- **They share the member namespace**, so the constant `BUILTIN_CLASSES` beside the method
  `builtin_classes()` was "Parser already has a field #builtin_classes, and a method can't have a
  field's name". PHP keeps constants, properties and methods apart. The field is `#builtin_source`.
- **Nothing stops a write.** `#reserved[] = "x"` anywhere in the class changes the "constant".
- **Another class's table needs an instance.** `Lexer::KEYWORDS` is `#lexer.keywords`, which only
  works because the parser happens to hold a lexer; token types are bare strings everywhere
  (`"LEFT_PAREN"`), so a typo in one is a branch that silently never runs. The harness caught
  none of those because I made none, not because anything would have.

**Options:**
- `const NAME = literal;` at the top level and in a class (`Parser.ASSIGNMENTS`, `#ASSIGNMENTS`
  inside it), constant expressions only, checked at parse time like function names: an
  undefined one is a parse error, which is what makes token types safe. **Recommended**; it is
  the one item in hole 6 that every file of a self-hosted compiler wants. In C it is a value in
  the constant pool, cheaper than a field.
- Top level only (`const LEFT_PAREN = "LEFT_PAREN";`). Simpler, but included files share one
  namespace (hole 5), so every module prefixes its constants as it does its functions.
- Leave it. Fields work; typos in token types stay silent.

## 9. The file system can only be read from (hole 6: no `cwd()`, no file-existence test)

**Met:** `include`. The PHP parser uses `realpath()` (to include each file once, however it is
spelt), `getcwd()` (to show an included file relative to the working directory), `dirname()`,
and `is_file()`/`is_readable()` (to say "Cannot include file" rather than read nothing).

**Workaround:**
- `normalise()` resolves `.` and `..` textually and `dirname()` is a `split` and a `join`, 35
  lines together. A path given relative to the working directory stays relative to it, which is
  how PHP displays it, so the two agree on everything in the repository.
- Existence is `try { read_file($path); } catch (Error $e)`, with only the read in the `try`.

**Where it is wrong, knowingly** (marked `ponytail:` in `parser.gaz`): a symlinked file, or one
file included by both a relative and an absolute path, is included twice, so its functions are
"already declared" and a valid program is refused (the code review found the second; it is the
worst of the four, a rejection rather than a different display); a main file given by absolute
path shows its includes as absolute where PHP shows them relative; an include that climbs out of
the working directory shows as `../x.gaz` where PHP shows the absolute path. None can be fixed in
GazLang: all four need to know where the process is.

The review also found the textual version too forgiving, which *was* fixable: `normalise()`
cancels `nodir/..` whether or not `nodir` exists, so a mistyped include quietly resolved. The
file is now read by the path as written, which lets the system refuse it as `realpath()` does.

**Options:**
- `cwd()` and `real_path($path)` (null when there is nothing there, which is also the existence
  test). Two builtins, each one libc call in C. **Recommended**: they are the only entries in this
  log where the workaround is *wrong* rather than long, and the bytecode's `@ "file"` records are
  relative-path rewrites the self-hosted compiler must reproduce byte for byte (hole 6 says so),
  so the code generator port will hit this again harder.
- `file_exists($path)` alone: fixes the try/catch, not the three divergences.

**Decided and built 2026-09-18: `cwd()`, `real_path($path)` and `file_exists($path)`.**
`real_path()` is an error when nothing is there, rather than null, and `file_exists()` is
whether it would succeed (one definition, `Builtins::resolve()`, so the two can't disagree).
Each is one libc call in C: `getcwd`, `realpath`, and `realpath` again. Where PHP's
`realpath()` isn't `realpath(3)` the builtin follows C: `""` is nothing (PHP gives the working
directory) and so is a path holding a NUL byte (PHP throws, and a C string would be cut short
at it). A directory has a real path too; whether a path is a file to include is still asked by
reading it, which is the one question left in a `try`. No `dirname()` builtin: every path the
port takes one of is now real, so it has no `.`, `..`, doubled or trailing slash for the
8-line GazLang version to get wrong. `display_path()` is ported as it is, including showing
everything absolute when the working directory is `/`.

`normalise()` is gone and the port is 22 lines shorter. All four divergences are tested: a
symlinked file (`tests/parser_corpus/include/symlink_is_the_file_it_points_to.gaz`, through
`lib/link.gaz`), and, since a corpus file can't spell this machine's absolute paths or choose
its working directory, `SelfHostedParserTest` writes a program including one file both ways
into a temporary directory, and parses corpus files by absolute path and from other working
directories. Put back the textual version and all six fail.

**Met while building it:** the choice of an error over null costs every caller that doesn't
know the path is there two calls, `file_exists($p) ? real_path($p) : null`, which is two
`realpath` calls in C, and the path can vanish between them, which is then an internal error
rather than "Cannot include file". A `try` around `real_path()` alone would be one call, but is
the shape this log already warns about. Two calls in the parser, once per include; not worth
revisiting unless the code generator port does it in a loop.

## 10. Call depth: a parser in GazLang has a nesting limit, and a list was not a stack

**Met:** in the code review, not the port. Recursive descent is about nine GazLang calls for
each level of nesting, and walking a tree by recursion two. `Values::MAX_CALL_DEPTH` is 10000, so
source nested past about 1100 levels, or a chain of 5000 operators (as deep on its left as it is
long, and generated code reaches that with `..`), ran out of call depth where the PHP parser,
which has no such limit, parses it. It arrives as an internal error with a trace, not a
`ParseError`.

**Workaround:** the two tree walks (`VariableCollector.collect()`, the driver's `Dumper.dump()`)
use an explicit stack, 8% slower than recursion. The stack is a list: push with `$s[] = $x`, pop
by reading the last element and `delete`ing it, which is what CLAUDE.md says to do in place of a
`pop`. **That was quadratic**: `array_splice()` rebuilds a list whatever it removes, so every pop
copied the stack, 22s for 80,000. Fixed in `Values::remove()` (`array_pop()` for the last
element, 0.44s), so the idiom is now what it was documented to be. The nesting limit of parsing
itself is not worked around.

**Options:**
- Leave the nesting limit. 1100 levels is far from any real program, and the honest fix is the
  C VM's call depth (frames in an array it can grow), not a parser bent around a PHP constant.
  **Recommended.**
- `pop($list)` cannot exist: lists are values, so a function cannot change its argument. Read
  the last and `delete` it is the idiom, and with the fix it is cheap. Worth a line in
  `docs/language.md`.
