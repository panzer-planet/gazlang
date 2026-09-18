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

### 3. Object model and the write path — built: handles, identity `==`, tagged path steps
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

### 4. Errors — built: `error()` takes any value, `Error` objects, `catch (Type $e)`, `finally`
`error()` takes a string and catch gives a map. PHP, Python and Ruby have
class-based exceptions with typed catch; Lua's `error(any_value)` + `pcall` is the
minimal version. Programs will want `error(NotFound("x"))` and catch by type.
Recommendation: let `error()` take any value (Lua), keep the map shape for string
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
`class_of` arriving with classes. Built: `is_a` with objects (2026-09-17) and `class_of`
(2026-09-18), the latter once a pass over an AST turned out to need a dispatch key that
`is_a` chains could not give.

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

### 10. One array type for list and map — decided 2026-09-17: split
PHP's biggest wart, already hit: `json_encode` can't tell `{}` from `[]`, `filter` had to
guess with `is_list`, and `"1"` was the same key as `1` while `==` refuses that
conversion. Objects don't fix it: JSON objects, CSV rows and group-by totals are
dictionaries with runtime keys, not records. Decided: a list `[1, 2]` and a map
`{"k" => 1}` are separate types; map keys are int or string with `"1"` and `1` distinct;
a missing index or key is an error unless read through `??`; a list index must exist to
be written (append with `[]`); maps compare regardless of order and never equal a list.
Built on the `list-map` branch (lists stay PHP lists, maps are a `MapValue` wrapper) and merged.

### 11. Truthiness of objects — decided: always true
`[]` is false (PHP, Python); Ruby and Lua say everything but nil/false is true.
Objects should be always true, no `__bool__` protocol. Note the inconsistency with
empty arrays and move on.

### 12. Missing expressions — done: ternary and exit()
A C-style ternary `$c ? $a : $b` (right associative, between `??` and assignment, as in
PHP and JS) and an `exit($code = 0)` builtin that stops the program with that exit code,
not catchable by try/catch. Build after function values phase 1.

### 13. Closure state — decided 2026-09-17: closures own their captured variables
Capture by value at creation left two gaps: a lambda couldn't call itself through the
variable it was assigned to, and a closure couldn't keep state (`counter()`), so both
went through `@globals`. Considered: an explicit `use ($x)` list (PHP; clear but noisy),
implicit sharing with the enclosing scope (JS, Python; late binding in loops and a
silent change of meaning without block scope), and block scope with declarations (the
right home for shared closures, but `let` everywhere; not now). Decided: a captured
variable is still copied at creation but belongs to the closure, kept between calls and
shared by recursive ones. Which variables are captured is decided by the parser: a plain
`=`, `foreach` or `catch` anywhere in the body makes that name local to each call, so a
temporary that shares a name with an outer variable can't leak between recursive calls,
and `$n = $n + 1` on an outer `$n` is a loud "Undefined variable". `$f = <lambda>` binds
the closure's `$f` to itself. The enclosing scope never sees changes; objects will be
where shared mutable state goes.

## Fine as is, keep

Sigil scoping (`$` local, `@` global, `#` instance: Ruby's scheme with `@` and `$`
swapped). Value arrays with the interpreter's in-place write trick (maps onto PHP's
copy-on-write). Ints that never silently overflow, always-finite floats, `"0"` is
true, parse-time arity checks, byte strings for a lexer-hosting language, `Runtime\Values`
as the one definition of meaning for both backends.

## Object syntax (decided 2026-09-17, recorded in CLAUDE.md under "Objects")

Decided first: fields declared up front; `to_string()` the only protocol method; `#`
alone is the object; `type_of` gives `"object"` and `is_a($x, Point)` tests the class.

Settled later the same day, after closures:
- `fn` replaces `function` (which stays reserved). `class` stays: with inheritance it no
  longer suggests something missing, and Kotlin, Swift, TypeScript and Dart kept it.
  `type`, `struct`, `record`, `object` and `kind` were considered; `struct` and `record`
  imply value semantics or immutability, `object` a single instance.
- Inheritance after all, PHP's model taken in pieces: single `extends`, `abstract`, and
  loud parse-time checks (no redeclared fields, overrides accept the parent's arity)
  now; `interface`/`implements`, `final`, and `private`/`protected` with public implicit
  later; traits, late static binding and statics not planned. The keywords are reserved
  in phase 1.
- `##name` calls the parent's version of a method, `##_(...)` the parent constructor.
  `super.` was the alternative; `##` won on consistency with `#` (this object, and its
  parent's view), at a small readability cost for a rarely written construct, and is
  cheap to revisit once the self-hosted compiler is written with classes. The
  constructor stays `_`, leaving `init` free. `#.name` and bare `##` are parse errors;
  `##` alone is kept free (the parent class as a value is the candidate).
- Fields `#x;` / `#x = default;` (defaults per object); reading an unset field is an
  error, quiet under `??`; members are public and one namespace per class; class values
  print as `class Point` with `type_of` `"class"`; objects without `to_string` print their
  fields, `Account {...}` when already being printed.

Decided while planning and building phase 1 (2026-09-17):
- The override arity rule exempts constructors: a child's `_` can take other arguments.
- Field defaults run parent first, before `_`, even when a child's `_` never calls `##_`;
  they can use `#` but no `$` variables.
- `_` is only a constructor: `return value;` in it, `##_` outside one, `#_` and `$obj._` are
  errors.
- `??` reads an unset declared field, or a field of null, as null; an undeclared member is
  still an error, like a bad key type.
- A write path starts at a variable or `#`, never at a call (`make().x = 1`); assigning to a
  method is an error.
- `.name` is one token; whitespace before the dot is allowed, so chains can continue on the
  next line, and a number followed by `.name` is an invalid literal.
- Bound methods are `==` when object, class and method match (Python), so `in_array` finds them.
- Default printing shows only the fields that are set, parent's first.
- `to_string` must accept no arguments (parse time) and return a string (runtime).
- Keywords can be member names (`#class`, `$o.echo`, `fn if()`): the sigil or dot tells them apart.
- `to_string()` needs the runtime to call GazLang from inside printing, so the VM became
  re-entrant (a nested dispatch loop per call, as Lua and Python do); builtins could now call
  back into GazLang too, but none do yet.
- Found while building: `"{#"` in a string starts an interpolation anywhere, so a literal
  one outside a method is an error; write `\{#` or use single quotes.

Decided for phase 2 (2026-09-17):
- Runtime errors and `error("text")` are caught as objects of a builtin `Error` class
  (`$e.message`, `$e.file`, `$e.line`) rather than keeping the map: a thrown map would
  otherwise look like a runtime error, runtime errors couldn't be caught by type, and
  rethrowing couldn't keep the location. Any other value is caught as it is.
- An `Error` gets `#file` and `#line` where it is first thrown; `error($e)` keeps them.
- Several catch clauses, first match wins; an untyped catch must be the last.
- `finally` runs on every way out of the try and catch blocks except `exit()`; return,
  break and continue can't leave it; a return value is worked out before it runs (Java).
- An uncaught non-string value prints as echo would, with no location.

Decided 2026-09-17, after writing examples/football.gaz:
- List destructuring: `[$a, $b] = $list;` and `foreach ($x as [$a, $b])`, any assignable
  target in `=` and variables in foreach, exactly as many elements as targets (an error
  otherwise, not PHP's nulls). Nesting and map patterns wait until real code wants them.
- Fields and methods keep one namespace; the error explains it and suggests a name.

## Still open

`true == 1`: decided false (Ruby, Lua); bools are not numbers, `to_int(true)` is explicit.
Decided while building: an int and a float compare exactly (`9007199254740993 !=
9007199254740992.0`), unlike PHP; `/` still converts and loses precision above 2^53.
Nothing open on objects; details found while building phase 1 get recorded here.

## Suggested order

Done: items 1, 2, 8, 10, 12 and 13, function values, anonymous functions and
lib/functional.gaz, the `fn` rename, and objects phase 1 (classes, inheritance, `#` and
`##`, `.` with item 3's tagged path steps, items 5 to 7), and phase 2 (item 4's errors).
The long-term plan after the language settles is roadmap step 7 in CLAUDE.md.
