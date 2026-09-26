# GazLang bytecode

The format a GazLang compiler writes and a GazLang VM runs. `gaz -c x.gaz` prints it
and `gaz x.gzb` runs it. `compiler/codegen.gaz` writes it and `vm/load.c` and
`vm/vm.c` read and run it in `bin/gaz`. `INFO` in `vm/load.c` holds every instruction with
its arguments and stack effect, and `BytecodeTest` keeps that table, this document and the VM
in step.

The version is `3`. A loader refuses any other version. There is no compatibility promise
yet: the format has been stable, but it may still change, and a change that old files can't
load under gets a new version. The same source always gives byte-identical bytecode.

## The file

Text, one instruction per line, with no comments. Blank lines are ignored, as is
leading and trailing whitespace on a line; a line's parts are separated by whitespace.

```
GAZLANG BYTECODE 3
globals @total @seen
statics Counter::count
```

The first line is the magic and the version. The second lists the global variables, one name
per global slot, in slot order; a program with no globals writes `globals` on its own.

A `statics` line may follow, one name per static field slot, in slot order. A name is
`Kind::field`, the kind being the one that *declares* it, so a kind and its children name
the same slot. The line is left out by a program with no static fields. The rest of the file
is blocks.

## Blocks

A block is a unit of code with its own frame and its own labels: the top level, a function, a
kind, or a lambda. A block starts at its header line, which begins with a lowercase word, and
runs until the next header line or the end of the file. Instruction names are uppercase, so a
block needs no end marker.

The top level block comes first; a loader starts the program at its first instruction, and
falling off its end ends the program. Then come the functions in declaration order, then each
kind followed by its methods, then the lambdas in index order.

Every block ends with a `locals` line naming the variable in each local slot, in slot order. A
block's parameters are its first slots, as many as its arity allows.

```
top
locals $c $next
```

A **function** gives its name and its arity as fewest then most arguments (they differ when it
has default parameters). A method is a function whose name is `Kind.name`; a GazLang function
name can't contain a `.`, so the two never collide.

A name in a namespace carries it, with `::` between the parts: `json::decode` is a function and
`json::Reader.read` a method of the kind `json::Reader`. The parser resolves namespaces, so a
loader needs to know nothing about them beyond treating a name as one word; the dot is still
what tells a method from a function.

A header may end with `in Kind`, naming the kind the block's code is written in. That is who a
member use in it is asking as, which is what says whether a member that isn't `pub` answers it.
A method's own name already carries its kind, so only a static method's header and a lambda's
need to say it; a kind block is the initialiser of its own objects and reaches all of them.

```
fn safe_div 2 2
locals $a $b $e

fn json::decode 1 1
locals $text

fn Counter::next 0 0 in Counter
locals
```

A **kind** is a record followed by the code that makes one of its objects: that code sets the
field defaults (the parent's first), calls the constructor and returns the object. `field`
lines give every field an object of the kind has, in layout order, each with the kind that
declares it; `method` lines give every method it can call, including the constructor `_`, each
with the kind whose version runs. The constructor's arity is that method's arity.

A `field` or `method` line may end with `pub` or `kin`, which say how far the member escapes
the kind that declares it: `pub` to any code, `kin` to that kind and everything that extends
it. Nothing said means the member is that kind's own, and only its own code reaches it: a child
inherits the slot and the entry, since the parent's methods still run on the child's objects,
but cannot name them. A field name is unique across a hierarchy; a method name is not, so two
entries of one name may sit side by side, and which one answers depends on the kind asking.

A `field` line may end with a type (see "Types" below) after the marker, or where the marker
would be: every write into that field checks the value against it, however the write is
spelt, so the check belongs to the field and not to the instruction. The declaring kind's
type is on every record that has the slot, a child's included.

A `method` line may then name the kind that *declared* the member, when an override made that
differ from the kind whose version runs: `method area Square kin Shape` is Square's version of
a method Shape declared, and it is Shape's marker that says who may name it. Nothing said means
the declarer is the definer.

A `static` line gives a static field the kind declares with a type: its name (without the kind,
which is the block's own) and the type. Its slot is the one the `statics` header names as
`Kind::name`; a static field without a type has no line, since the header already has it.

```
abstract kind Shape
field name Shape kin string
method _ Shape pub
locals $#argument_0

kind Circle extends Shape
field name Shape kin string
field radius Circle pub float
method _ Circle pub
method area Circle pub Shape
static made int
locals $#argument_0
```

## Types

A type names what a value may be, and is written as one word: the alternatives of a union
joined with `|`, each a `type_of()` name (`int float string bool null list map function kind
object socket db`) or a kind's name, so `int|float`, `string|null` (what the source writes
`?string`) and `Shape`. There is no `?` in the file. A check is strict and never converts, with
one exception: an int where `float` is one of the alternatives is accepted, and arrives as a
float. `object` is any object; a kind admits its children, as `is_a` does.

`CHECK_PARAM` and `CHECK_RETURN` carry a type and check a parameter or a returned value. A
`field` or `static` record carries one and the VM checks every write into that slot. Untyped
code has none of these, so it pays nothing.

A **lambda** gives its index, which `MAKE_CLOSURE` names, and its arity, then `in Kind` when it
is written inside one. A `capture` line per
captured variable, in capture order, gives the variable's name and where the enclosing frame
holds it: `local` and a slot, or `captured` and an index in the enclosing closure. A `self`
line names the captured variable that holds the closure itself, for a lambda assigned to a
variable it uses (`$f = $n -> $f($n - 1)`).

```
lambda 0 0 0
capture $c local 0
self $f
locals
```

## Locations

An `@` line gives the source the instructions after it came from, and holds until the next
`@` line. Locations start afresh in each block, so a block can be read on its own.

```
@ "lib/json.gaz" 42
@ 42
```

The path is a string literal, relative to the directory of the main source file, so bytecode
saved next to its source reports the same paths as running the source does. A loader resolves
it against the directory the bytecode file is in. A name in angle brackets, like `<builtin>`,
is not a path and is never rewritten. The second form, a line with no path, is source that had
no file: piped or inline input.

A `LABEL` is a position rather than an instruction, so it carries no location.

## Values

`PUSH` takes the rest of the line as a GazLang literal: an int, a float, a string, `true`,
`false`, `null`, or a list or map of literals. Numbers may be written with a leading `-`, and a
loader must read the sign and the digits as one number: the smallest int is written
`-9223372036854775808`, whose digits alone don't fit an int (a folded constant can be it).
Floats are written as GazLang prints them, with the shortest digits that read back as the same
float. Strings are quoted as `Lexer::quote()` quotes them, so a literal never spans lines.
Because a literal can hold spaces, an instruction that takes a value takes it as its last
argument.

```
PUSH 42
PUSH 1.0E+25
PUSH "caught: "
PUSH {"a" => [1, 2.5]}
```

A running program also has values a program can't write, which some instructions push and
others consume. A loader must allow for them in its value type:

- a **method entry**, which `GET_METHOD` pushes and `CALL_METHOD` consumes: the method to run
  on the object below it, or nothing when the member wasn't a method.
- a **raised error**, which the loader's error handling pushes when it enters a catch block,
  and `CATCH_MATCH`, `CATCH_VALUE` and `RETHROW` consume. A finally block stores one in a
  local until it rethrows it.

## Write paths

`SET_PATH` and its variants take a path spelling the steps from a variable to what is written:
`[k]` takes a key from the stack, `.name` is a field, and a final `[]` appends. `SET_PATH
[k].total 0` writes `$a[k].total`, with the key pushed before the value. A path ending in `..=`
appends the value to what it reaches, as `..` joins, and leaves the result instead of the
value: `SET_PATH_THIS .log..=` is `#log ..= v`, and `SET_PATH_STATIC ..= 0`, with no steps,
appends to the static field itself. What it reaches must exist. A string that isn't shared is
appended to in place, so a loop of appends is linear.

## Instructions

The stack column is what the instruction pops and pushes, bottom first: `a b -- c` pops two
values and pushes one. `…` stands for as many arguments as the count says. Every error listed
is a GazLang error a `try` can catch, and gets the location of the instruction that raised it.

### Stack and variables

| Instruction | Stack | What it does |
| --- | --- | --- |
| `PUSH value` | `-- v` | Pushes a literal. |
| `POP` | `v --` | Drops the top value. |
| `PRINT` | `v --` | Prints the value as `echo` does, then a newline. Runs `to_string()` for an object. |
| `LOAD slot` | `-- v` | Pushes a local. Fails with "Undefined variable: $x" if it is not set. |
| `LOAD_QUIET slot` | `-- v` | Pushes a local, or null if it is not set (the left of `??`). |
| `STORE slot` | `v --` | Sets a local. |
| `CONCAT_ASSIGN slot` | `v -- w` | `$s ..= v`: appends to a local and pushes the new value. Fails if it is not set. The string is never loaded onto the stack, so the append is in place and a loop of them is linear; `..` otherwise, converting both sides as `echo` does. |
| `LOAD_GLOBAL slot`, `LOAD_QUIET_GLOBAL slot`, `STORE_GLOBAL slot`, `CONCAT_ASSIGN_GLOBAL slot` | | The same for a global. |
| `LOAD_CAPTURED slot`, `LOAD_QUIET_CAPTURED slot`, `STORE_CAPTURED slot`, `CONCAT_ASSIGN_CAPTURED slot` | | The same for a captured variable of the running closure, addressed by capture index. |
| `LOAD_STATIC slot`, `STORE_STATIC slot` | | The same for a static field, addressed by its slot in the `statics` line. There is no quiet form: a static field always has a value, since the compiler writes its default, a constant, before anything else runs. A store into a static field with a type (a `static` record) checks the value: "Counter::count must be int, got string". |

### Operators

Every one means what `Runtime\Values` says, including the error messages.

| Instruction | Stack | What it does |
| --- | --- | --- |
| `ADD`, `SUB`, `MUL` | `a b -- c` | Arithmetic. Ints give an int, a float on either side gives a float. Fails on a non-number, or on "Integer overflow" or "Float overflow". |
| `DIV` | `a b -- c` | Always gives a float. Fails on "Division by zero". |
| `MOD` | `a b -- c` | Ints only. Fails on a float or on "Division by zero". |
| `POW` | `a b -- c` | `a ** b` by square-and-multiply. Two ints and `b` of 0 or more give an exact int or fail on "Integer overflow"; otherwise a float, `b` a whole number (a whole float too), negative for one divided by the power. Fails on "Exponent must be a whole number, got 0.5", "Exponent is too large", "Division by zero" (0 to a negative power) or "Float overflow". |
| `BIT_AND`, `BIT_OR`, `BIT_XOR` | `a b -- c` | Ints only; fails on anything else. |
| `SHL`, `SHR` | `a b -- c` | Ints only. A count outside 0 to 63 fails on "Shift count must be between 0 and 63, got 64". `SHL` drops the bits shifted off the top, so the result wraps; `SHR` keeps the sign. |
| `BIT_NOT` | `a -- b` | Flips every bit of an int, two's complement. Fails on anything else. |
| `CONCAT` | `a b -- c` | Joins two values as text, as `..` does. |
| `EQUALS`, `NOT_EQUALS` | `a b -- c` | Never converts between types; a bool equals only itself. |
| `LT`, `LE`, `GT`, `GE` | `a b -- c` | Ordering. Two numbers, or two strings byte by byte. Fails on anything else. |
| `CMP` | `a b -- c` | `<=>`: -1, 0 or 1 by the ordering rules. |
| `NOT` | `a -- b` | Truthiness, negated; always a bool. |
| `NO_MATCH` | `a --` | Always fails, on "No arm matches 5": a `match` fell past every arm and had no `default`. |
| `NO_CONDITION` | `--` | Always fails, on "No arm matched": a subject-less `match` found every condition false and had no `default`. |
| `NEG` | `a -- b` | Negates a number. Fails on anything else. |
| `INC`, `DEC` | `a -- b` | Adds or subtracts one. Numbers only; fails on overflow. |

### Control flow

| Instruction | Stack | What it does |
| --- | --- | --- |
| `LABEL name` | | A position in this block, named by jumps. Not an instruction. |
| `JMP label` | | Jumps. |
| `JZ label` | `v --` | Jumps if the value is not truthy. |
| `JNN label` | `v --` or `v -- v` | Jumps if the value is not null, keeping it; otherwise drops it. The `??` operator. |
| `HALT` | | Ends the program. |

### Calls

A call gives the callee a frame whose first slots are the arguments, and `RET` returns a value
to the caller. Every call fails with "Maximum call depth of 10000 exceeded calling x" when the
depth limit is reached.

| Instruction | Stack | What it does |
| --- | --- | --- |
| `CALL function count` | `… -- v` | Calls a function of the program by name, with that many arguments. |
| `CALL_BUILTIN builtin count` | `… -- v` | Calls a builtin. Fails as the builtin does, on an argument of the wrong type. |
| `CALL_VALUE count` | `f … -- v` | Calls whatever the value under the arguments is: a function, a closure, a bound method or a kind. Fails with "Cannot call int" on anything else, or with an arity message. |
| `ARGC` | `-- n` | Pushes how many arguments the running call was passed, for default parameters. |
| `CHECK_PARAM slot type` | `--` | Fails with "total() expects $n to be int, got string" unless the local in that slot is of the type (see "Types"), widening an int to a float in the slot where the type asks for a float. The compiler writes one per typed parameter after the defaults have run, so a default is held to the type too. |
| `CHECK_RETURN type` | `v -- v` | The same for the value on top, which a `RET` is about to return: "total() should return int, got string". The compiler writes one before every `RET` of a function with a return type, the implicit `null` at its end included. |
| `RET` | `v --` | Returns the value, dropping the frame and any try handlers it still has. |
| `PUSH_FN function` | `-- f` | Pushes a function or builtin as a value. |
| `MAKE_CLOSURE lambda` | `-- f` | Makes a closure of that lambda, copying in the captured variables that exist, then setting its `self` if it has one. Its location is this instruction's. |

### Lists and maps

| Instruction | Stack | What it does |
| --- | --- | --- |
| `NEW_ARRAY` | `-- l` | Pushes an empty list. |
| `ARRAY_PUSH` | `l v -- l` | Appends to the list below. |
| `ARRAY_EXTEND` | `l v -- l` | Appends the elements of a list to the list below (`...$v` in a list literal). Fails with "Cannot spread map: only a list can be" unless it is a list. |
| `MAP_EXTEND` | `m v -- m` | Sets every key of a map in the map below, in the spread map's order (`...$v` in a map literal): a key already there keeps its place and takes the new value. Fails with "Cannot spread list: only a map can be" for anything but a map. |
| `NEW_MAP` | `-- m` | Pushes an empty map. |
| `MAP_SET` | `m k v -- m` | Sets a key of the map below. |
| `KEY_CHECK` | `k -- k` | Fails unless the value can be a key, so a bad key fails before later keys and the value run. |
| `FOREACH_CHECK` | `x -- x` | Fails with "foreach expects a list or map" unless it is one. |
| `DESTRUCTURE count` | `l -- l` | Fails unless the value is a list of that many elements. |
| `INDEX_GET` | `x k -- v` | Reads an element of a list, map or string. Fails on "Index out of range: 5", "Undefined key: \"k\"", or a bad target or key. |
| `INDEX_GET_QUIET` | `x k -- v` | The same, but null when the target is null or the key is missing (the left of `??`). |
| `INDEX_GET_EXISTING` | `x k -- v` | The same as `INDEX_GET`, for a compound update, which needs the key to exist. |
| `SET_PATH path slot` | `… v -- v` | Writes through the local in that slot, taking the path's `[k]` keys from the stack below the value, and leaves the value. Fails on a missing variable or key, or a bad step. A path ending at a field with a type checks the value against it first ("Account #balance must be int, got string"), an int widened to a float on the stack too where the type asks for one; a path ending in `..=` there must find a type that allows a string. |
| `SET_PATH_GLOBAL path slot`, `SET_PATH_CAPTURED path slot`, `SET_PATH_STATIC path slot` | | The same for a global, a captured variable or a static field; `SET_PATH_STATIC ..=` on a typed static field needs its type to allow a string. |
| `SET_PATH_THIS path` | `… v -- v` | The same, starting at the object the method runs on. |
| `DELETE_PATH path slot` | `… --` | Removes the element its path ends at, through the local in that slot, taking the path's `[k]` keys from the stack and leaving nothing. A list's later elements move down; a map keeps the order of the rest. Fails on a missing variable, step or element. The path ends in `[k]`: a field can't be removed. |
| `DELETE_PATH_GLOBAL path slot`, `DELETE_PATH_CAPTURED path slot`, `DELETE_PATH_STATIC path slot` | | The same for a global, a captured variable or a static field. |
| `DELETE_PATH_THIS path` | `… --` | The same, starting at the object the method runs on. |

### Objects and kinds

Every instruction that names a member resolves it against the object's kind *and* the kind the
running block says its code is written in (`in Kind`, or the dot in a method's name). A member
that says nothing answers only its own kind, so the same instruction can find a member in one
block and not in another.

| Instruction | Stack | What it does |
| --- | --- | --- |
| `PUSH_KIND kind` | `-- c` | Pushes a kind as a value. |
| `NEW kind count` | `… -- o` | Makes an object of that kind with that many arguments: the kind's block sets the field defaults, calls the constructor and returns the object. |
| `CALL_CONSTRUCTOR kind` | `-- v` | In a kind's block: runs that kind's `_` on the object being made, with the same arguments. |
| `LOAD_THIS` | `-- o` | Pushes the object the running method or initialiser is on. |
| `LOAD_FIELD member` | `-- v` | Pushes a field of that object. Fails with "Property x of C is not set". |
| `SET_FIELD member` | `v -- v` | Sets a field of that object, leaving the value. A field with a type checks the value first, as `SET_PATH` does. |
| `GET_PROPERTY member` | `o -- v` | Reads a member of an object: a field's value, or a method bound to it. Fails with "C has no member foo", "C.foo is not pub, so only the kind that declares it can use it" or "Cannot use . on map". |
| `GET_PROPERTY_QUIET member` | `o -- v` | The same, but null for a field that is not set or an object that is null. |
| `GET_PROPERTY_EXISTING member` | `o -- v` | The same as `GET_PROPERTY`, for a compound update. |
| `GET_METHOD member` | `o -- o m` | Pushes the object again with the method to run on it, or the member's value with nothing when it isn't a method. |
| `CALL_METHOD count member` | `o m … -- v` | Calls what `GET_METHOD` found, or the value as `CALL_VALUE` would. Fails with "Method C.m expects 1 argument, 2 given". |
| `CALL_PARENT kind member count` | `… -- v` | Runs that kind's version of a method on the running object (`##name(...)`). |
| `BIND_PARENT kind member` | `-- f` | Pushes that kind's version of a method, bound to the running object (`##name`). |

### Errors

| Instruction | Stack | What it does |
| --- | --- | --- |
| `TRY label` | | Installs a handler: an error until the matching `END_TRY` unwinds to the frame and stack depth of this instruction, pushes the error and jumps to the label. |
| `END_TRY` | | Removes the innermost handler. |
| `CATCH_MATCH kind label` | `e -- v` or `e -- e` | Replaces the error with what catch sees when that is an object of the kind or a child kind; otherwise jumps to the label, leaving the error for the next clause. |
| `CATCH_VALUE` | `e -- v` | Replaces the error with what catch sees: an `Error` object, or the value the program threw. |
| `RETHROW` | `e --` | Raises the error again, so an outer handler or the program's caller sees it. |
| `THROW` | `v --` | Raises the value (`throw`): a string as the message of an `Error`, anything else as it is. An `Error` object keeps the location and trace it was first thrown with. Like `JMP`, `RET` and `RETHROW`, nothing after it runs on this path. |

## What a loader checks

A file that loads is one the VM can run, so the checks are part of the format:

- The magic and the version, and that the first block is the top level.
- Every instruction name is known and takes the arguments it is given.
- Every count (a slot, a capture, an argument count) fits in a 32-bit int: one that doesn't is
  refused rather than clamped, since it is a number nothing meant.
- Every label a jump names is defined in the same block, and every function, builtin, kind
  and lambda a name or index refers to exists.
- Nothing that needs the object a method runs on (`LOAD_FIELD`, `SET_FIELD`, `CALL_PARENT`,
  `BIND_PARENT`, `CALL_CONSTRUCTOR`) is in a block that can run without one: the top level, a
  function, a method called or pushed as a function, or a lambda made in any of these.
- Each block's stack balances: walking it from the top and following every jump, an
  instruction is reached at the same depth on every path, nothing pops from an empty stack,
  and a handler's block starts one deeper, holding the error. The greatest depth reached is
  what a VM needs to size the block's stack.
- Each block's handlers balance the same way: an instruction is reached with the same handlers
  open on every path, and `END_TRY` closes one that a `TRY` opened. (`RET` needs none of this:
  a call's handlers go with its frame.)
- A kind named `Error` declares `message`, `file`, `line` and `trace` as `pub`, which is where
  a VM puts an error the program didn't throw itself and what every program reads off one, and
  a file holding `CATCH_VALUE` or `CATCH_MATCH` has that kind at all, since that is what a
  caught error is made as.
- A block's `in Kind` names a kind the file declares, and so does a `method` line's declarer.
- Every name in a type, on a `field` or `static` line or in a `CHECK_PARAM` or
  `CHECK_RETURN`, is a `type_of()` name or a kind the file declares, and a `static` line names
  a slot the `statics` header has.
- `HALT` is in the top level, whose end it is. In a call it would end that call's run instead,
  leaving whatever started the run without a value.

Whether a value really is one of these, the instructions that consume them check when they run,
as `ADD` checks its operands: `CATCH_MATCH`, `CATCH_VALUE` and `RETHROW` that the top is a
raised error, `CALL_METHOD` that it has a method entry and an object under it, and
`ARRAY_PUSH`, `ARRAY_EXTEND` and `MAP_SET` that they are building a list or a map. A walk of the
stack can't tell, since a finally block stores its error in a local and loads it back.

What a loader does after that is its own business. The C VM collapses `STORE x; LOAD x; POP`
into `STORE x`, resolves labels to positions, turns names into indexes and merges common
sequences into superinstructions. None of that belongs in the file.
