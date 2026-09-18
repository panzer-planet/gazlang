# GazLang bytecode

The format a GazLang compiler writes and a GazLang VM runs. `gazlang -c -f x.gaz` prints it
and `gazlang -f x.gzb` runs it. `selfhost/codegen.gaz` writes it and `vm/load.c` and
`vm/vm.c` read and run it in `bin/gazlang`; in the PHP reference, `src/CodeGenerator/Program.php`
writes it, `src/CodeGenerator/BytecodeReader.php` reads it, and `src/VM/VM.php` runs it. `Program::INSTRUCTIONS` holds every instruction with its arguments and
stack effect, and `BytecodeTest` keeps that table, this document and the VM in step.

The version is `1`. A loader refuses any other version; there is no compatibility promise
until the compiler is self-hosted. The same source always gives byte-identical bytecode.

## The file

Text, one instruction per line, with no comments. Blank lines are ignored, as is
leading and trailing whitespace on a line; a line's parts are separated by whitespace.

```
GAZLANG BYTECODE 1
globals @total @seen
```

The first line is the magic and the version. The second lists the global variables, one name
per global slot, in slot order; a program with no globals writes `globals` on its own. The
rest of the file is blocks.

## Blocks

A block is a unit of code with its own frame and its own labels: the top level, a function, a
class, or a lambda. A block starts at its header line, which begins with a lowercase word, and
runs until the next header line or the end of the file. Instruction names are uppercase, so a
block needs no end marker.

The top level block comes first; a loader starts the program at its first instruction, and
falling off its end ends the program. Then come the functions in declaration order, then each
class followed by its methods, then the lambdas in index order.

Every block ends with a `locals` line naming the variable in each local slot, in slot order. A
block's parameters are its first slots, as many as its arity allows.

```
top
locals $c $next
```

A **function** gives its name and its arity as fewest then most arguments (they differ when it
has default parameters). A method is a function whose name is `Class.name`; a GazLang function
name can't contain a `.`, so the two never collide.

```
fn safe_div 2 2
locals $a $b $e
```

A **class** is a record followed by the code that makes one of its objects: that code sets the
field defaults (the parent's first), calls the constructor and returns the object. `field`
lines give every field an object of the class has, in layout order, each with the class that
declares it; `method` lines give every method it can call, including the constructor `_`, each
with the class whose version runs. The constructor's arity is that method's arity.

```
abstract class Shape
field name Shape
method _ Shape
method area Shape
locals $#argument_0

class Circle extends Shape
field name Shape
field radius Circle
method _ Circle
method area Circle
locals $#argument_0
```

A **lambda** gives its index, which `MAKE_CLOSURE` names, and its arity. A `capture` line per
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
[k].total 0` writes `$a[k].total`, with the key pushed before the value.

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
| `ARGC` | `-- n` | Pushes how many arguments the running call was passed, for default parameters. |

### Operators

Every one means what `Runtime\Values` says, including the error messages.

| Instruction | Stack | What it does |
| --- | --- | --- |
| `ADD`, `SUB`, `MUL` | `a b -- c` | Arithmetic. Ints give an int, a float on either side gives a float. Fails on a non-number, or on "Integer overflow" or "Float overflow". |
| `DIV` | `a b -- c` | Always gives a float. Fails on "Division by zero". |
| `MOD` | `a b -- c` | Ints only. Fails on a float or on "Division by zero". |
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
| `CALL_VALUE count` | `f … -- v` | Calls whatever the value under the arguments is: a function, a closure, a bound method or a class. Fails with "Cannot call int" on anything else, or with an arity message. |
| `RET` | `v --` | Returns the value, dropping the frame and any try handlers it still has. |
| `PUSH_FN function` | `-- f` | Pushes a function or builtin as a value. |
| `MAKE_CLOSURE lambda` | `-- f` | Makes a closure of that lambda, copying in the captured variables that exist, then setting its `self` if it has one. Its location is this instruction's. |

### Lists and maps

| Instruction | Stack | What it does |
| --- | --- | --- |
| `NEW_ARRAY` | `-- l` | Pushes an empty list. |
| `ARRAY_PUSH` | `l v -- l` | Appends to the list below. |
| `ARRAY_EXTEND` | `l v -- l` | Appends the elements of a list to the list below (`...$v` in a list literal). Fails with "Cannot spread map: only a list can be" unless it is a list. |
| `NEW_MAP` | `-- m` | Pushes an empty map. |
| `MAP_SET` | `m k v -- m` | Sets a key of the map below. |
| `KEY_CHECK` | `k -- k` | Fails unless the value can be a key, so a bad key fails before later keys and the value run. |
| `FOREACH_CHECK` | `x -- x` | Fails with "foreach expects a list or map" unless it is one. |
| `DESTRUCTURE count` | `l -- l` | Fails unless the value is a list of that many elements. |
| `INDEX_GET` | `x k -- v` | Reads an element of a list, map or string. Fails on "Index out of range: 5", "Undefined key: \"k\"", or a bad target or key. |
| `INDEX_GET_QUIET` | `x k -- v` | The same, but null when the target is null or the key is missing (the left of `??`). |
| `INDEX_GET_EXISTING` | `x k -- v` | The same as `INDEX_GET`, for a compound update, which needs the key to exist. |
| `SET_PATH path slot` | `… v -- v` | Writes through the local in that slot, taking the path's `[k]` keys from the stack below the value, and leaves the value. Fails on a missing variable or key, or a bad step. |
| `SET_PATH_GLOBAL path slot`, `SET_PATH_CAPTURED path slot` | | The same for a global or a captured variable. |
| `SET_PATH_THIS path` | `… v -- v` | The same, starting at the object the method runs on. |
| `DELETE_PATH path slot` | `… --` | Removes the element its path ends at, through the local in that slot, taking the path's `[k]` keys from the stack and leaving nothing. A list's later elements move down; a map keeps the order of the rest. Fails on a missing variable, step or element. The path ends in `[k]`: a field can't be removed. |
| `DELETE_PATH_GLOBAL path slot`, `DELETE_PATH_CAPTURED path slot` | | The same for a global or a captured variable. |
| `DELETE_PATH_THIS path` | `… --` | The same, starting at the object the method runs on. |

### Objects and classes

| Instruction | Stack | What it does |
| --- | --- | --- |
| `PUSH_CLASS class` | `-- c` | Pushes a class as a value. |
| `NEW class count` | `… -- o` | Makes an object of that class with that many arguments: the class's block sets the field defaults, calls the constructor and returns the object. |
| `CALL_CONSTRUCTOR class` | `-- v` | In a class's block: runs that class's `_` on the object being made, with the same arguments. |
| `LOAD_THIS` | `-- o` | Pushes the object the running method or initialiser is on. |
| `LOAD_FIELD member` | `-- v` | Pushes a field of that object. Fails with "Property x of C is not set". |
| `SET_FIELD member` | `v -- v` | Sets a field of that object, leaving the value. |
| `GET_PROPERTY member` | `o -- v` | Reads a member of an object: a field's value, or a method bound to it. Fails with "C has no member foo" or "Cannot use . on map". |
| `GET_PROPERTY_QUIET member` | `o -- v` | The same, but null for a field that is not set or an object that is null. |
| `GET_PROPERTY_EXISTING member` | `o -- v` | The same as `GET_PROPERTY`, for a compound update. |
| `GET_METHOD member` | `o -- o m` | Pushes the object again with the method to run on it, or the member's value with nothing when it isn't a method. |
| `CALL_METHOD count member` | `o m … -- v` | Calls what `GET_METHOD` found, or the value as `CALL_VALUE` would. Fails with "Method C.m expects 1 argument, 2 given". |
| `CALL_PARENT class member count` | `… -- v` | Runs that class's version of a method on the running object (`##name(...)`). |
| `BIND_PARENT class member` | `-- f` | Pushes that class's version of a method, bound to the running object (`##name`). |

### Errors

| Instruction | Stack | What it does |
| --- | --- | --- |
| `TRY label` | | Installs a handler: an error until the matching `END_TRY` unwinds to the frame and stack depth of this instruction, pushes the error and jumps to the label. |
| `END_TRY` | | Removes the innermost handler. |
| `CATCH_MATCH class label` | `e -- v` or `e -- e` | Replaces the error with what catch sees when that is an object of the class or a subclass; otherwise jumps to the label, leaving the error for the next clause. |
| `CATCH_VALUE` | `e -- v` | Replaces the error with what catch sees: an `Error` object, or the value the program threw. |
| `RETHROW` | `e --` | Raises the error again, so an outer handler or the program's caller sees it. |

## What a loader checks

A file that loads is one the VM can run, so the checks are part of the format:

- The magic and the version, and that the first block is the top level.
- Every instruction name is known and takes the arguments it is given.
- Every label a jump names is defined in the same block, and every function, builtin, class
  and lambda a name or index refers to exists.
- Each block's stack balances: walking it from the top and following every jump, an
  instruction is reached at the same depth on every path, nothing pops from an empty stack,
  and a handler's block starts one deeper, holding the error. The greatest depth reached is
  what a VM needs to size the block's stack.

What a loader does after that is its own business. The PHP VM collapses `STORE x; LOAD x;
POP` into `STORE x`, resolves labels to positions, and splits the instructions into parallel
arrays; a C VM would also turn names into indexes and could merge common sequences into
superinstructions. None of that belongs in the file.
