# Friction log: porting the parser to GazLang

Every workaround the language forced while `src/Parser/Parser.php` became `selfhost/parser.gaz`,
written down when it was met, with the options for removing it. Each entry was reproduced, not
assumed (three confident friction claims dissolved on inspection during the lexer port).

Hole numbers refer to "Holes to fill next" in CLAUDE.md.

## 1. An object's fields can't be listed (hole 2, now with real code asking)

**Met:** before the first line of parser. The PHP side of the harness, `AST\Dumper`, is 118 lines
that know no node type: `get_object_vars()` lists a node's fields, so a field added to a node is
printed without anyone remembering to. `Parser::collect_variables()` walks the tree the same way
to find what a lambda captures.

**Workaround:** every node class in `selfhost/nodes.gaz` has a `parts()` method returning its own
fields as a map, by hand, in the PHP class's declaration order: one method per class, about 30 of
them, which is a quarter of that file. A field added to a node and forgotten in `parts()` is
invisible to the dump *and* to capture analysis, silently. The harness catches the first (the PHP
dump has the field), nothing but a test catches the second.

**Options:**
- `fields($object)`, a builtin giving a map of the set fields by name in declaration order. One
  function, costs nothing in C (the layout is already a table), and `json_encode` could then take
  objects. **Recommended.**
- `foreach` over an object. Same information, but a language change where a builtin will do, and
  it makes objects look like maps, which `==` and indexing deliberately say they are not.
- Leave it. `parts()` is boilerplate, but it is boilerplate a compiler only writes once per node.

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

**Where it is wrong, knowingly** (marked `ponytail:` in `parser.gaz`): a symlinked file can be
included twice; a main file given by absolute path shows its includes as absolute where PHP shows
them relative; an include that climbs out of the working directory shows as `../x.gaz` where PHP
shows the absolute path. None can be fixed in GazLang: all three need to know where the process is.

**Options:**
- `cwd()` and `real_path($path)` (null when there is nothing there, which is also the existence
  test). Two builtins, each one libc call in C. **Recommended**: they are the only entries in this
  log where the workaround is *wrong* rather than long, and the bytecode's `@ "file"` records are
  relative-path rewrites the self-hosted compiler must reproduce byte for byte (hole 6 says so),
  so the code generator port will hit this again harder.
- `file_exists($path)` alone: fixes the try/catch, not the three divergences.
