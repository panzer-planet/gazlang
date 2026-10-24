# Issues to file (temporary, 2026-10-24)

Found while building the terminal toolkit's groundwork for the text editor and reviewing it. Each
is one GitHub issue: a title, the labels the repository uses (a topic and a kind), and a body
saying what is decided and what is open, as the existing issues do. Delete this file once they
are filed; the repository's rule is that open work lives in issues, not in a list here.

---

## Named arguments

Labels: `language`, `not-designed`

**Evidence.** The terminal toolkit's drawing functions end in optional settings, and every call
that wants a later one spells the earlier ones: a round box is `tui::box($screen, $r, "", "",
"round")`, a right-aligned label `tui::label($screen, $r, $text, "", "right")`, and the library
itself writes `"", "right"` in `progress()`. Three independent reads of the toolkit (the critique
of 2026-10-23, my own, and a style review that wrote two programs on it) each put this first.
Rects removed three positional arguments from each call and the trailing settings remain.

**Decided.** Nothing. It is foundational by CLAUDE.md's rule (every toolkit with options and
every CLI builder reaches for it), so it needn't wait for one more program, but its design has
two real choices in it and is not parser sugar:

- A call by name is checked once the whole program is read, so the parser could reorder named
  arguments into positional ones for those. But a default is evaluated inside the function (so a
  `[]` default is never shared and a default can see earlier parameters), so a caller that skips
  a middle argument can't fill it: the callee has to be told which arguments were passed, which
  is a bit set beside `ARGC`, or a sentinel value no program can make.
- A method call through a value (`$obj.draw(focused: true)`) resolves at run time, so the VM and
  the bytecode would learn what a named argument is; `CALL_METHOD` and `CALL_VALUE` would carry
  the names or a mapping.

**Open.** The spelling (`align: "right"` as PHP 8 and Ruby; `$align = "right"` reads as an
assignment and is taken); whether positional arguments may follow a named one (no, as PHP);
whether a lambda's parameters can be named at the call; what the arity error says (`label() has
no parameter $aling`, with the lowercase or nearest-name hint the parser already gives for
undefined names); and how the language server's signature help shows the names (it already
shows the signature as written, so this is the easy part).

---

## A constructor that only promotes its parameters should cost a list literal

Labels: `vm`, `limitation`

**Evidence.** The toolkit's `readonly kind Cell` (`fn _(pub #character, pub #width, pub
term::Style #style);`) is made once per character put on a 200x60 screen, 12000 a frame.
Measured (PGO=0, best of 20): a steady frame went from 3.0 ms with `[character, style]` lists
to 5.1 ms with Cells; the constructor call is about 80 ns against about 8 ns for a list literal.
A per-(character, style) cache of Cells measured slower than constructing (a string key and a
map lookup, 1.36 vs 1.0 ms per 12000), so cells are constructed.

**Decided.** The kind stays (a cell is a value, and `==` by fields is what lets the screen diff
work). What the VM could do: a constructor whose body is only the synthesised `#x = $x;` lines
of promoted parameters (the parser knows, since it wrote them) needs no frame: `NEW` could set
the fields from the stack directly, the type checks (`check_field_type()`) and the read-only
window kept. The parser or the code generator would mark such a constructor (a word on the
`method _` record or a flag on `CALL_CONSTRUCTOR`), so the loader can check the claim.

**Open.** Whether the mark is the record's or the instruction's; whether a constructor with
promoted parameters and a body keeps the frame (yes, the restrictive choice); what a
`##_(...)` call from a child into a promote-only parent does (the frame exists then; take the
slow path). Measure again on the editor, which will have a Cell per visible character.

---

## A static field's default can't construct an object

Labels: `language`, `limitation`

**Evidence.** The football shell wanted `static #KEYS = [Hint("1-7", "sections"), ...]` once
`Hint` became a kind, and couldn't: a static default is folded as a constant is, so it may hold
only what a constant may. The list of Hints now lives in a method, `keys_everywhere()`, called
on every draw. `Cell::blank()` is a lazily set static for the same reason (`static #blank =
null`, set on first use), which is a pattern a reader has to recognise.

**Decided.** CLAUDE.md's rule for constants stands (one evaluated at run time would bring
initialisation order, mutation through a handle, and a slot and instruction in the VM). A
static field is a slot already, filled when the run starts (`make_cases()` fills enum cases
the same way), so a static default that is an expression evaluated once when the program
starts, in declaration order, is the shape; the order across kinds and files is the question.

**Open.** Whether to allow any expression or only constructions of read-only kinds and literals
(the restrictive choice: a read-only object can't be mutated through the shared handle, which
is the trap the constant rule guards); the order (declaration order within a file, files in
import order, the main file last); what an error in a default says (located at the field).

---

## A list pattern can't discard an element

Labels: `language`, `not-designed`

**Evidence.** `[, $bar] = $rest.split_left(GAP)` is the idiom for "the rest after the first":
an empty slot in a pattern is a `null` target already. But the other way round, "the first and
not the rest", can't be written: `[$left, ] = ...` is a trailing comma, one target, and the
count is checked, so it is an error; `[$left, ...$rest]` needs a name for what is thrown away.
The style review and the football shell both wrote `$rect.split_left($n)[0]`, a list read by
position in a file whose rule is against them.

**Decided.** Nothing. The toolkit side is being answered by `Rect.without_left($n)` and
`without_top($n)`, so the idiom goes from that one caller; the language question stays.

**Open.** `[$left, _]` (an underscore as a discard, as Rust, Python and Go; `_` isn't a
variable sigil here, so it is free) against `[$left, ...]` (a rest without a name, which the
spread syntax already reserves). Either is additive.

---

## A braced call in a string is text, silently

Labels: `language`, `friction`

**Evidence.** The style review wrote `"{len(#listing.entries)} entries"` and got the braces
printed as text, with no error, since interpolation opens on a sigil or on a constant's bare
name (`{NAME}`); a braced *call* fits neither shape, so it is text by the rule. The program
needed `$count = len(...)` and `" {$count} entries"`.

**Decided.** The rule (`at_braced_name()` in `lib/syntax.gaz`): a name alone in braces
interpolates a constant, and the shape is exact so that `{ X}`, `{X:1}`, JSON and CSS stay text.
That is right, and `{len(...)}` as text is the price. What could change: a name followed by
`(` inside braces is never text anyone meant (`{f(x)}` in CSS or JSON isn't a thing), so it
could be refused with a message, as a misspelt constant is, rather than kept quiet; or
expressions could interpolate, which is PHP's `{$...}` expanded and a much bigger door.

**Open.** Refuse or allow; and whether the lexer can tell `{f(` from text cheaply at every `{`
(it already looks for a sigil or a name, so one more byte).

---

## Incremental highlighting for an editor

Labels: `library`, `on-demand`

**Evidence.** `highlight::gaz()` lexes the whole source: 51 ms for the 5559-line
`compiler/parser.gaz`, 73 ms through `highlight::lines()`. An editor redrawing on every
keystroke can't pay that on a big file. (Highlighting to the first error, and pieces by line,
were built on 2026-10-23, so an editor can draw from `lines()` today.)

**Decided.** Wait for the editor to show the shape: re-lexing from the start of the edited
line's enclosing token is the likely one, which needs the lexer to start mid-file in a known
state (outside any string or comment), and the editor to keep the token boundary before each
line. Noted as a `ponytail:` at `gaz()` in `lib/highlight.gaz`.

---

## #88, what is left: `with`

Not a new issue: the `==` half of #88 was built on 2026-10-23 (objects of a read-only kind
compare by fields, same kind exactly, a pair already being compared equal at that level, depth
bounded by `MAX_CALL_DEPTH`). #88 should be edited to hold only the changed copy (`with`).
