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
