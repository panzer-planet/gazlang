# Design review before OOP (2026-09-17)

A review of the language as it stands, with objects coming next, against PHP, Python,
Ruby and Lua. Each item has a status; update it when a decision is made and record the
outcome in CLAUDE.md as usual. Ranked most fundamental first.

## Decide before OOP

These get harder to change once objects exist.

### 1. `+` is concatenation with silent conversion — decided: `..` for concatenation, `+` numeric only
`1 + "2"` is `"12"`; two CSV fields added give a string, so `examples/csv_report.gaz`
has to `to_float` everything. JavaScript is the only major language with this overload
and it is its most-cited mistake. PHP uses `.`, Lua `..`; Python and Ruby raise on
`"x" + 1`. With objects one operator would have to serve both a to-string protocol
(`"total: " + $order`) and numeric overloading (`$a + $b`).
Recommendation: a dedicated concat operator (`..`, `~` or `&`), `+` numeric only with
a loud error on strings, and interpolation desugars to the concat operator. Only the
parser's interpolation desugar and `Values::binary` change.

### 2. `==` coerces numeric strings, and `===` exists — decided: `==` strict (int/float by value), `===` removed
`"5" == 5` is true but `[1] == ["1"]` is false. Python, Ruby and Lua have one `==` with
no coercion; PHP and JS have the pair and every style guide says use the strict one.
Objects would need `==` defined on top of a coercing operator.
Recommendation: `==` strict everywhere (identity for functions and objects, structural
for arrays), drop `===` or keep it as a synonym for a while. Number-to-string
comparison becomes an explicit `to_float`.

### 3. Object model and the write path — decided: handles, identity `==`, tagged path steps
Arrays are values (fine, PHP-like). Objects should be handles (PHP 5+, Python, Ruby,
Lua all agree): `$b = $a; $b.x = 1` changes `$a`; arrays inside objects stay values.
This also gives closures the shared state that capture-by-value withholds.
Needed now:
- `Values::store()` walks plain keys. With `$rows[0].total = 5` a path is a list of
  steps of two kinds (index, property), and the VM's `SET_PATH n slot` needs to say
  which. Design the tagged step before phase 1's `CALL_VALUE` lands so the path
  opcodes aren't redesigned twice.
- `==` on objects is identity (the Python, Ruby and Lua default). PHP's structural
  object `==` is a trap once objects hold objects.

### 4. Errors — decided: `error()` takes any value, `catch (Type $e)`, `finally` with objects
`error()` takes a string and catch gives an array. PHP, Python and Ruby have
class-based exceptions with typed catch; Lua's `error(any_value)` + `pcall` is the
minimal version. Programs will want `error(NotFound("x"))` and catch by type.
Recommendation: let `error()` take any value (Lua), keep the array shape for string
errors, reserve `catch (Type $e)` syntax. Otherwise `$e["message"]` in `lib/` becomes
unchangeable. There is also no `finally`; every language above has one.

### 5. `#` as the instance sigil inside strings — decided: only inside braces; closures bind the receiver
`"color: #fff"`, `"#1"`, `"#hashtag"` are common. If `"#name"` interpolated like
`"$name"`, they would all break. Ruby: `#` alone never interpolates, only `#{}`.
Recommendation: `#name` interpolates only in braces, `"{#name}"`; `$name` shorthand
stays. Also decide that a closure created inside a method binds the receiver
automatically (PHP closures, JS arrows); with an implicit sigil the Python/Lua
"name self" alternative doesn't work.

### 6. Classes as values, constructors as calls — decided: `Point(1, 2)`, no `new`
Phase 1 makes a bare name a function value. Python's `Point(1, 2)` is the natural
extension: a class name is a value, calling it constructs. No `new` (PHP) or `.new`
(Ruby); one postfix call rule; the parse-time check becomes "names a function or
class". `type_of` should return `"object"` (PHP `gettype`), with `is_a($x, Point)` or
`class_of` arriving with classes.

### 7. Methods as function values — decided: bound methods
`$obj.save` should be a bound method (Python), since `$handlers["save"]` is the phase 1
use case. JS's unbound `this` and Lua's `obj.method` vs `obj:method` are perennial bug
sources. For phase 1: `FunctionValue` must be able to grow a receiver and captures,
and nothing may key off `FunctionValue::named()`.

## Worth reconsidering, less urgent

### 8. `/` is value-typed — decided: always float
`6 / 2` is int, `7 / 2` is float, so `type_of($a / $b)` depends on the data. Python 3
and Lua 5.3 make `/` always float; `intdiv` already exists. One line in
`Values::arithmetic` plus test expectations.

### 9. Namespacing — accepted for now
All functions and `@globals` share one namespace; `lib/json.gaz` prefixes everything
`json_` and keeps parser state in `@json_text` (the Lua-before-modules experience).
Classes fix most of it (`Json` with methods and instance state). Don't design
`include` further until classes exist.

### 10. One array type for list and map — accepted
PHP's biggest wart, already hit: `json_encode` can't tell `{}` from `[]`. Changing it
is a rewrite of `lib/`. Let objects take the record role so arrays trend toward lists.

### 11. Truthiness of objects — decided: always true
`[]` is false (PHP, Python); Ruby and Lua say everything but nil/false is true.
Objects should be always true, no `__bool__` protocol. Note the inconsistency with
empty arrays and move on.

### 12. Missing expressions — decided: both wanted
A C-style ternary `$c ? $a : $b` (right associative, between `??` and assignment, as in
PHP and JS) and an `exit($code = 0)` builtin that stops the program with that exit code,
not catchable by try/catch. Build after function values phase 1.

## Fine as is, keep

Sigil scoping (`$` local, `@` global, `#` instance: Ruby's scheme with `@` and `$`
swapped). Value arrays with the interpreter's in-place write trick (maps onto PHP's
copy-on-write). Ints that never silently overflow, always-finite floats, `"0"` is
true, parse-time arity checks, byte strings for a lexer-hosting language, `Runtime\Values`
as the one definition of meaning for both backends.

## Object syntax (decided 2026-09-17, recorded in CLAUDE.md under "Objects")

Fields declared up front (syntax open, `#x;` the candidate); constructor is `_`;
`to_string()` the only protocol method; `#` alone is the object, `##` tentatively the
class (PHP `self::`); no inheritance in the first cut; `is_a($x, Point)` and
`type_of` gives `"object"`.

## Still open

Whether `true == 1` should stay true under strict `==` (Ruby and Lua say no).
Decided while building: an int and a float compare exactly (`9007199254740993 !=
9007199254740992.0`), unlike PHP; `/` still converts and loses precision above 2^53.
The field declaration syntax and whether `##` earns its keep.

## Suggested order

Done on branch function-values: items 1, 2 and 8, then function values phase 1.
Item 3's tagged path step comes with objects. Items 4 to 7 are decisions to
record in CLAUDE.md before the objects phase; they need no code yet.
