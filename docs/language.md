# The GazLang language

A reference. [The README](../README.md) is the showcase; this is the whole thing, briefly. Why
any of it is the way it is lives in [CLAUDE.md](../CLAUDE.md), which is longer and more
opinionated.

## Introduction

GazLang would rather stop than guess. `"5" + 5` is an error, not `10` or `"55"`; a missing map
key is an error unless you ask for a default with `??`; an integer that overflows is an error,
never a quiet switch to a float. Every runtime error names its file and line, with a stack
trace, and `Error` is a real kind a program can extend and catch.

Types are optional, not a mode: leave them out and nothing changes, write `int $n` or
`pub float #balance` and they are checked as the program runs, at the edges — a call, a return,
a field write — with errors that name the parameter or field. Nothing is converted to fit,
except that an int is welcome where a float is asked, and arrives as one.

Lists and maps are values, copied on assignment like numbers are, so nothing changes behind your
back; objects are handles, shared on purpose. `??`, `?.`, `|>` and `match` (with or without a
subject) exist so the common shapes of a program — a fallback, a null-safe chain, a pipeline, a
multi-way branch — read as themselves rather than as nested calls or `if`/`else`.

## Values

- **Ints**: `42`, `-7`, `0xFF`, `0o755`. 64-bit, and they never silently overflow — a literal or a
  result that will not fit is an `Integer overflow` error, never a quiet switch to a float.
  `0x` is hexadecimal and `0o` (a lowercase `o`) octal, for permission bits as `chmod()` takes
  them; a leading zero alone is still decimal (`007` is 7), and a digit 8 or 9, a dot or an
  exponent after `0o` is an error. Strings are never read as hex or octal: `to_int("0o17")` and
  `to_int("0x1F")` are errors, as any other text that isn't decimal is.
- **Floats**: `1.5`, `2e-3`, `3E+2`. Always finite; there is no INF or NAN. A float needs
  digits on both sides of the dot, so `1.` and `.5` are errors. Printing gives the shortest
  digits that read back as the same float (`0.30000000000000004`, `1.0`, `-0.0`). A result too
  large to hold is a `Float overflow` error. A float can't be a key, an index or a string position.
- **Strings**: byte strings, so `len("é")` is 2 and `upper` is ASCII. UTF-8 passes through
  untouched, and `utf8_valid`, `utf8_length` and `utf8_chars` read it as characters (see "Builtins").
- **Booleans**: `true` and `false`. A bool is not a number: `true == 1` is false, and
  `true + 1` is an error. Use `to_int(true)`.
- **`null`**: a keyword. It equals only itself, so `null == 0` and `null == false` are false.
  Arithmetic, ordering and unary `-` on it are errors; `echo null` prints `null`.
- **Lists, maps, functions, kinds and objects**, below.

`type_of($x)` gives `int`, `float`, `string`, `bool`, `null`, `list`, `map`, `function`,
`kind`, `object`, `socket`, `db` or `file`; those names are also what a declared type is written with
(see "Types").

## Variables

`$x` is local — to the running function call, or to the top level. `@x` is global, the same
variable everywhere. A function cannot see the top level's `$x`. Nothing is declared; reading
one that was never set is an `Undefined variable: $x` error.

## Constants

```gaz
const WIDTH = 3;
const AREA = WIDTH * HEIGHT;          // in terms of others, in any order
const HEIGHT = WIDTH + 1;
const KINDS = ["int", "float", {"nested" => AREA}];

kind Token {
    pub const EOF = "EOF";                           // pub, to be reached from outside
    const ENDS = [#EOF, Token::EOF .. "!"];
    fn is_eof($type) {                               // #NAME inside the kind
        return $type == #EOF;
    }
}
echo AREA .. " " .. Token::EOF;                      // Kind::NAME outside it
```
```
12 EOF
```

`const NAME = value;` at the top level or in a kind. The value is whatever needs nothing but
the source: literals, every operator, `?:`, lists and maps, and other constants. No variables, no
calls, no indexing. It is worked out before the program runs, so `const X = 1 / 0;` is an error
at that line whether or not `X` is ever used, and so is a constant that depends on itself.

A constant can be used before it is declared, like a function. A mistyped one is an error before
the program runs (`Undefined function or constant: WDITH`), which is the reason to give a string a name.
Nothing can change one: `X = 2` and `X[0] = 2` are syntax errors, and since lists and maps are
values, `$copy = KINDS; $copy[] = 1;` changes the copy.

A top level constant shares the namespace of functions and kinds. A kind's constant shares
the one its fields and methods are in, is inherited, and can't be declared again by a child.
Like every member it is the kind's own unless `pub` (or `kin`, for the kinds that extend it), so
`Token::EOF` outside `Token` needs `pub const EOF`. It is reached by name, `Token::EOF` or `#EOF`, not through a value: `$kind.EOF` and `$token.EOF`
are not constants. `::` resolves a name and `.` goes through a value, so `Token.EOF` is an error
that says to write `Token::EOF`. A `:` followed by a `:` is always `::`, so a ternary needs a
space: `$c ? Token::EOF : $x`.

## Strings

Double quotes interpolate; single quotes are raw (only `\'` and `\\` are escapes, every other
backslash is kept).

```gaz
echo "Hi $name";                  // a bare $name, greedily
echo "Hi $rows[0] and $m[key]";   // one index after a bare name
echo "{$user.name} owes {@total + 1}";
```

Braces interpolate any expression that **starts with a sigil** — `{$…}`, `{@…}`, `{#…}` — and
a **constant's name alone**: `{NAME}`, `{ns::NAME}` or `{Kind::NAME}`, the `}` right after the
name.

```gaz
const LIMIT = 20;
echo "at most {LIMIT} items";
```

```
at most 20 items
```

A name in braces that isn't a constant is an error when the program is read, so a misspelt
constant can't print as text: `Undefined constant LIMT in "{LIMT}": declare it, or write \{LIMT}
for the text`, and a function, kind or static field there says to put it in a `$variable` first
or join it with `..`. Anything else is literal, so `{round($n, 2)}` prints as written; assign it
to a variable first, or start the expression with a sigil and call from there
(`{$o.shout() .. to_string($n)}`). A lone `$`, `$5`, `me@example.com`, `{ $x}`, `{ X}`, `{3}`,
`{X:1}` and `{}` are all literal too, and `\{NAME}` is the text `{NAME}`.

The index after a bare name is `[0]`, `[-1]`, `[$i]` or `[key]`, read as the string `"key"`
(`[01]` is the string `"01"`). `#` and a property path interpolate only inside braces, so
`"#fff"` and `"$file.txt"` stay text, but `{#` starts an expression anywhere: outside a method,
write `\{#` or use single quotes.

The reason: `{` has to stay literal so a string holding JSON, CSS or braces needs no escaping,
so only `{$`, `{@` and `{#` start an expression, and a name only when the `}` follows it at once:
`{"a": 1}` and `body {color: red}` are not that shape. A constant has no sigil, and without the
name rule `"{LIMIT}"` would quietly be text. Once an expression has started, anything goes inside
the braces: `{$n + 1}` works.

Escapes in `"..."`: `\n \t \r \v \f \e \0 \\ \" \$ \{`, `\xHH` (exactly two hex digits) and
`\u{H…}` (1 to 6 hex digits, up to `10FFFF` and no surrogates, written out as UTF-8). Any other
escape is an error, and so is `\0` right before a digit.

`..` concatenates, converting each side the way `echo` does, so `"x" .. true` is `"xtrue"` and
`1 .. 2` is `"12"`. `..=` appends.

### Tagged strings

A name touching a double-quoted string, with no space between them, **tags** it: the string is
not joined into text but handed to the function of that name, as two lists.

```gaz
fn shout($strings, $values) {
    $text = $strings[0];
    foreach ($values as $i => $value) {
        $text ..= upper(to_string($value)) .. $strings[$i + 1];
    }
    return $text;
}

$name = "gaz";
$age = 3;
echo shout"hello {$name}, you are {$age}!";   // shout(["hello ", ", you are ", "!"], [$name, $age])
echo shout"no values";                        // shout(["no values"], [])
```

- **The text parts** come first, with their escapes already read. There is always one more part
  than there are values, so a value at the start or end, or two side by side, has an empty part
  next to it: `t"{$a}{$b}"` is `t(["", "", ""], [$a, $b])`.
- **The values** come second, exactly as they are, never converted to text, each evaluated once,
  left to right. Every form of interpolation works: `$x`, `$x[0]`, `{$expr}`, `{@global}`,
  `{#field}` in a method, a constant's `{NAME}`, and another tagged string inside the braces.
- **A tag is a name**, and a tagged string is an ordinary call by that name: a function, a
  qualified one (`db::sql"..."`), one brought in by `use`, a static method, a kind (which
  constructs) or a builtin that takes two arguments. It is checked like any call when the program
  is read, so an undefined, private or wrong-sized tag is an error before anything runs.
- Only a name: `$f"..."` and `$obj.m"..."` are errors, and so is a name before a single-quoted
  string (`sql'...'`). A keyword is never a tag, so `echo"x"` still echoes.
- A tagged string is already a call, so it can't follow `|>`; `t"..." |> len` pipes its result on.
- The standard library has two: `db::sql"..."` for SQL (see "Databases") and `web::html"..."`
  for HTML (see "Templates").

## Lists and maps

```gaz
$list = [1, 2, 3];                       // values at 0, 1, 2...
$map  = {"key" => 1, 5 => "five"};       // values by key, in insertion order
```

Keys are ints or strings, and `"1"` and `1` are different keys. Both types are **values**:
assigning or passing one copies it.

```gaz
echo $list[0];                  // read; a missing index or key is an error
echo $map["key"];
$list[] = 4;                    // append
$map["new"] = 1;
$rows[0]["total"] = 5;
delete $list[1];
delete $map["key"];
foreach ($map as $key => $value) {
    echo "{$key}: {$value}";
}
```

`...` spreads a list into a list literal, which is how lists are joined and prepended to:

```gaz
$all = [...$first, ...$rest];            // join
$list = [0, ...$list];                   // prepend
```

A map spreads into a map literal the same way, later entries winning, so options over
defaults, or a copy with a change, is one literal:

```gaz
$settings = {...$defaults, ...$options};
$moved = {...$player, "club" => "Harbour City"};
```

A key already there keeps its place and takes the later value. Only a list spreads into a
list and only a map into a map (anything else is an error at the `...`), and nowhere else:
not into a call's arguments.

A list pattern takes a list apart, its targets assigned left to right after the list is worked
out, so `[$a, $b] = [$b, $a]` swaps. It needs exactly as many elements as it has targets, except
that one target written `...$rest`, anywhere in it, takes as a list whatever the others leave:
then the list needs at least as many elements as the other targets, and the rest may be empty.
The same goes for a `foreach` value and a pattern parameter:

```gaz
[$first, ...$others] = ["a", "b", "c"];     // "a" and ["b", "c"]
[...$init, $last] = [1, 2, 3];              // [1, 2] and 3
[$head, ...$middle, $tail] = [1, 2];        // 1, [] and 2
```

A list index must be an int in range (no negative indexes); a map key must exist. Read through
`??` to get `null` instead of an error. A compound assignment (`+=`, `..=`) needs the key to
exist already, so start it with `??=`, which creates a missing last key and evaluates the key
once:

```gaz
foreach ($words as $word) {
    $counts[$word] ??= 0;
    $counts[$word]++;
}
```

`$a[] = v` appends and is only valid as an assignment target; there is no `pop` — take
`last($list)`, then `delete` it.

Strings index the same way, by an int position, to a one character string, read only.

`==` compares lists element by element in order, and maps by the same keys with equal values in
any order. A list never equals a map, even `[] == {}`.

## Operators

By precedence, loosest first:

| Level | Operators |
| --- | --- |
| assignment | `=` `+=` `-=` `*=` `/=` `%=` `**=` `..=` `??=` `&=` `\|=` `^=` `<<=` `>>=` (right associative) |
| ternary | `$c ? $a : $b` (right associative) |
| coalesce | `??` |
| logical | `\|\|` then `&&` |
| equality | `==` `!=` `<=>` |
| relational | `<` `<=` `>` `>=` |
| pipe | `\|>` |
| concat | `..` |
| bitwise | `\|` then `^` then `&` |
| shift | `<<` `>>` |
| additive | `+` `-` |
| multiplicative | `*` `/` `%` |
| unary | `-` `!` `~` `++` `--` |
| power | `**` (right associative) |
| postfix | `[index]` `(args)` `.name` `?.name` `::name` |

`throw` (see [Errors](#errors)) sits with the ternary and lambdas: it can start any expression,
and the right side of `??`, and its operand runs as far right as it can.

- `+ - * /` are numbers only. `/` **always** gives a float (`6 / 2` is `3.0`); `intdiv()`
  divides ints. `%` is ints only, and its sign follows the left operand.
- `**` raises to a power, by multiplying (square-and-multiply), never a library's `pow`, so every
  platform gives the same bits. An int to an int of 0 or more is an exact int, and `Integer
  overflow` when it doesn't fit, never a float. Otherwise the result is a float: a negative
  exponent is one divided by the power (`2 ** -1` is `0.5`, a power too small to hold is `0.0`,
  and `0 ** -1` is `Division by zero`), and a float on either side works if
  the exponent is a whole number (`2 ** 3.0` is `8.0`); `4 ** 0.5` is an error, since a fractional
  power needs a defined algorithm GazLang doesn't have yet (`sqrt()` is the square root). It
  binds tighter than a unary operator on its left and looser than one on its right, and is right
  associative: `-2 ** 2` is `-4`, `2 ** -1` needs no parentheses, `2 ** 3 ** 2` is
  `512`.
- `==` never converts between types. `"5" == 5` is false, `"1" != "01"`, `1 == 1.0` is true.
  There is no `===`. Ordering a string against a number is an error. Strings compare byte by
  byte (`"10" < "9"`), and numbers by their exact value, so `9007199254740993 !=
  9007199254740992.0`.
- `<=>` gives -1, 0 or 1, for comparison functions.
- Lists order element by element: the first pair that differs decides, and a shorter list the
  other starts with comes first (`[1, 2] < [1, 3]`, `[1] < [1, 0]`). That makes a sort by
  several keys one comparison, with `$a` and `$b` swapped in an element to sort it the other
  way: `sort($teams, ($a, $b) -> [$b.points, $a.name] <=> [$a.points, $b.name])` is most points
  first, then by name. Maps can't be ordered.
- `&& || !` short-circuit and return real booleans. A number is true unless it is zero, and a
  string is true unless empty — so `"0"` is true. An empty list or map and `null` are false;
  functions and objects are always true.
- `& | ^ << >> ~` are ints only. They bind tighter than the comparisons, so `$flags & MASK == 0` means `($flags & MASK) == 0`. A shift count must be 0 to 63.
  `>>` keeps the sign, `~$x` is `-$x - 1`, and bits shifted off the top by `<<` are gone (`1 << 63`
  is the smallest int). There is no `>>>`.
- `$a ?? $b` gives `$a` unless it is null or missing; an undefined variable or a missing key on
  its left is `null` rather than an error, and so is indexing something missing. `0`, `false` and
  `""` are kept.
- `|>` passes the value on its left to the call on its right, as the **first** argument:
  `$x |> f(a, b)` is `f($x, a, b)`, `$x |> f` is `f($x)`, and `$x |> $g` is `$g($x)`. Names with
  `::` and kinds work as their calls do (`$data |> json::encode`, `$x |> Point`), and a
  parenthesised expression is called with the value: `$n |> ($v -> $v * 2)`. It is left
  associative, so a chain reads in the order it runs, and a chain may start its lines with `|>`:

  ```gaz
  $slug = $title
      |> trim
      |> lower
      |> replace(" ", "-");
  ```

  It binds looser than `..` and arithmetic and tighter than comparisons, so
  `"Hello " .. $name |> upper` uppercases the whole greeting and `$items |> len > 3` compares the
  length. It is exactly the call it stands for: the same checks, errors and order of evaluation.
  A lambda right after `|>` must be in parentheses, since its body would run on over the rest of
  the chain, and a method can't follow `|>` yet (`$x |> $obj.m()`). A chain written right after
  `??` or a ternary's `:` without parentheses is an error, since it would pipe only that operand:
  write `($x ?? "") |> trim` to pipe the result, or `$x ?? ("" |> trim)` to pipe the default
  (`($c ? $a : $b) |> f` and `$c ? $a : ($b |> f)` likewise).

## Control flow

`if` / `else if` / `else`, `while`, `for (init; cond; step)` (all three clauses required),
`foreach ($x as [$key =>] $value)`, `break` and `continue`.

`match` has two shapes. With a subject, the subject is evaluated once and each arm's values are
compared with `==`:

```gaz
$kind = match ($type) {
    "int", "float" => "number",       // several values to an arm
    "list" => "a list",
    default => "other",
};
```

Without one, the arms are conditions, tested for truth as `if` does:

```gaz
$kind = match {
    is_digit($c) => "digit",
    $c == "_" || is_alpha($c) => "name",
    default => "other"
};
```

Either way there is no fallthrough, arms are tried in order, only the values before the
matching one are evaluated, and nothing matching with no `default` is an error (`No arm matches
"x"`, or `No arm matched` without a subject). `default` must be the last arm. Written as a
**statement**, an arm's body may be a block:

```gaz
match ($c) {
    "\"" => {
        read_string();
    }
    "\\" => {
        @pos += 2;
    }
    default => fail("bad character")
}
```

## Functions

```gaz
fn add($a, $b = 1) {
    return $a + $b;
}
```

Top level only, and callable before they are declared, so mutual recursion works. Defaults come
after the required parameters and are evaluated on each call that leaves the argument out.
A trailing comma is allowed after the last parameter and the last argument, as after the last
item of a list, a map or a `match`, so a list written one per line diffs cleanly.
A bare name is a value, builtins included:

```gaz
$f = add;
echo $f(1, 2);                  // 3

$g = len;
echo $g("four");                // 4

$handlers = {"save" => $f};
echo $handlers["save"](5, 6);   // 11: a value from a map, called
echo pick()(1, 2);              // and a function a call returned, called in turn
```

Anonymous functions are `->`:

```gaz
$double = $x -> $x * 2;
$sum = ($a, $b = 1) -> $a + $b;
$answer = () -> {                       // a block body returns only through return
    return 42;
};
$pair = $x -> ({"value" => $x});        // { after -> is a block, so a map is parenthesised
$nothing = () -> null;                  // -> {} is an error: an empty block, not a map
```

A parameter is a copy, so writing to its elements changes nothing the caller sees. A function
whose only use of a parameter is such a write is refused when the program is read; return the
list, or keep it in an object, which is shared:

```gaz
fn with_item($list) {           // the caller takes the result
    $list[] = 1;
    return $list;
}

fn add_to($list) {              // error: $list is a copy
    $list[] = 1;
}
```

A parameter can be a list pattern, which takes its argument apart as `[$a, $b] = $x` would
(exactly that many elements, or at least as many as the targets beside a `...$rest`, or an
error), in a lambda, a function or a method. A lambda whose only parameter is a pattern needs no
parentheses, and an empty slot takes an element and ignores it:

```gaz
echo map($scores, [$name, $points] -> "{$name}: {$points}");
echo map($scores, [, $points] -> $points);
[, $month, $day] = [2026, 8, 8];                    // an empty slot at the start
[$first, , $third] = ["a", "b", "c"];               // in the middle
[$x, $y, ,] = [1, 2, 3];                            // at the end: [$x, $y,] is only two elements

fn distance([$x1, $y1], [$x2, $y2] = [0, 0]) {
    return abs($x2 - $x1) + abs($y2 - $y1);
}
```

A closure **owns** the variables it captured: it copies them in when created, and its calls
read and write them there, so they persist between calls and the enclosing scope never sees the
changes. A plain `=` inside the body makes a variable local to each call instead. A lambda
assigned with `$f = ...` can call `$f` to recurse.

To let closures and the scope around them work on the same variable, declare it `shared`:

```gaz
shared $events = [];
$hear = $e -> {                         // fills the list the outside reads
    $events[] = $e;
};
$hear("kick off");
echo len($events);                      // 1

shared $count = 0;
$inc = () -> {
    $count++;
};
$get = () -> $count;
$inc();
$inc();
echo $get() .. " " .. $count;           // 2 2
```

`shared $x = value;` needs a value, and from there on `$x` is one variable to the function it is
written in and to every lambda in it: an assignment, `++`, `+=`, `??=`, `$x[] = ...` or `delete
$x[0]` in one is seen by all. Everything else about closures is as above: a variable that isn't
shared is still copied in. The declaration makes a new variable each time it runs, so a closure made
in a loop gets its own, and a call of a function gets its own:

```gaz
fn counter($start) {
    shared $n = $start;
    return () -> {
        $n++;
        return $n;
    };
}

$a = counter(10);
$b = counter(20);
echo $a() .. " " .. $a() .. " " .. $b();   // 11 12 21
```

A named function starts with no shared variables and can't read the top level's, as with any
variable. A lambda can declare its own. A lambda that assigns itself to a shared variable can
recurse through it. These are errors, when the file is read: sharing a name that is already
shared, already used or a parameter; a lambda parameter with the name of a shared variable; and a
shared variable as a `foreach` or `catch` variable (assign it in the body instead). A shared
variable holds a value like any other: a list is still copied when passed to a function or
assigned to another variable. `Shared` is a builtin kind name, as `Error` is, and `shared` is a
reserved word.

## Objects

```gaz
abstract kind Shape {
    kin #name;                           // Shape's and its children's, not anyone else's

    fn _($name) {
        #name = $name;
    }

    pub abstract fn area();

    pub fn to_string() {
        return "{#name} with area {#area()}";
    }
}

kind Circle extends Shape {
    fn _(pub #radius) {                  // pub #radius: a field, set from the parameter
        ##_("circle");                   // the parent's constructor
    }

    pub fn area() {
        return 3.14159 * #radius * #radius;
    }
}

$c = Circle(2);                          // constructing is a call; there is no new
echo $c;                                 // circle with area 12.56636
echo is_a($c, Shape) .. " " .. $c.radius;
```

- **Kinds** are top level only, single inheritance, usable before they are declared, and may
  implement any number of interfaces (see "Interfaces"). They are values: `type_of(Point)` is `"kind"`, and `kind_of($obj)` gives an object's own kind, so
  `match (kind_of($node)) { NumAST => ..., AddAST => ... }` dispatches from outside the kinds.
- **Fields are declared** (`#x;` or `#x = default;`). A default is evaluated per object, so a
  `[]` default is never shared. Reading one never set is an error; `??` reads it as null.
- **A constructor parameter written `#name` promotes it to a field**, `pub`/`kin` marking its
  visibility as they would on a plain field declaration: `fn _(pub #x, #y) {}` declares `#x`
  (pub) and `#y` (private) and assigns them from the parameters, as if written `#x = $x; #y =
  $y;` at the top of the body. A default on a promoted parameter is the parameter's own
  (evaluated per call, so it may use `$` earlier parameters), not a field default. A
  constructor with nothing else to do can end `;` instead of `{}`.
- **`#` is this object**, `#name` a field or method, `##name` the parent's version of a method.
  All checked at parse time against the kind.
- **Objects are handles**: `$b = $a; $b.x = 1` changes `$a`. `==` is identity. Lists and maps
  inside them are still values.
- **`.` reads and writes members**: `$user.name`, `$rows[0].total = 5`, `$obj.method(args)`.
  `$obj.method` on its own is a bound method. A write can go through what a call returns, as
  long as it writes a field: `$team.keeper().saves++` changes the keeper, and the call runs
  once. `make()[0] = 1` is an error, since it would change a copy no one sees.
- **`?.` is `.` for an object that might be null**: `$user?.name` is `null` when `$user` is,
  and so is everything after it in the chain, `$user?.friend.greet(f())` included, whose `.greet`
  and `f()` never run. Parentheses end the chain: `($user?.name).x` reads `.x` on the `null`.
  Only `null` is skipped: `?.` on a string is `.`'s error, and so is a member that isn't there or
  a field never set, which is what sets it apart from `$user.name ?? null` (that one also reads an
  unset field as null). Nothing can be written through it (`$user?.name = 1`, `++`, `delete`).
- **A member is private unless `pub`**, the same word and the same meaning as a namespace's:
  this name escapes the thing it is written in. The ladder is unmarked (mine) → `kin` (mine and
  my children's) → `pub` (anyone's), and it applies to a field, method, constant or static
  alike: `kin #energy = 100;`, `pub static #tally = 0;`. `#name`, `##name` and a constant or
  static reached by name (`Limits::MAX`, `Counter::next()`) are checked at parse time,
  `$obj.name` when it runs, all against the kind the code asking is written in, whichever kind's
  name reaches the member (a kind reaches its own private static through a child's name too) — a
  lambda's and a static method's is the kind they sit in, and code outside every kind (the top level, a
  function, a template) reaches only what is `pub`: `Limits::MAX is not pub, so only Limits can
  use it`, or `Limits::MAX is kin, so only Limits and what extends it can use it`.
- **`kin` is for what a kind declares on its children's behalf**: a field they set, a method
  they call, a hook they define. `protected` earns a rename where `extends` does not, since it
  famously protects less than the default does, and a level is better named after who can see
  it. `pub` and `kin` are the whole vocabulary of markers: `public`, `protected` and `private`
  are ordinary names.
- **A parent's private member is the parent's own.** A child can't name it, and may declare a
  method, constant or static of its own by the same name; both live on, and each kind's code
  reaches the one it can see. A field may not be reused, since a field is a slot: the name is
  taken across the hierarchy. The parent's own methods still reach it on a child's object.
- **An override escapes as far as what it replaces**, so `pub` on one and `kin` on the other is
  an error, and the level belongs to the kind that *declared* the member, not to whichever
  version runs: an ancestor that declared a `kin` method can still call it after a child
  overrides it. `to_string()` must be `pub`, since printing calls it from outside; an
  `abstract fn` must be `pub` or `kin`, since a child defines what it can see. A constructor
  takes no marker: it is reached by constructing, not by naming.
- **`final` closes a kind or a method**: no kind extends a `final kind`, and no kind below the one
  that says it overrides a `final fn`. The marker comes first, as with `abstract` (`pub final
  kind Receipt`, `kin final fn total()`); `final pub` is an error that says to write `pub final`.
  Both are checked when the program is read, at the child: `Kind Refund can't extend Receipt: it
  is final`, `Method Square.area can't override Shape.area: it is final`. Defining an abstract
  method may close it (`pub final fn area()` in the kind that defines it). `final` is an error
  wherever it would close nothing: on an abstract kind or method (each is there to be extended or
  defined), on a method a kind keeps to itself (a child's method of that name is its own, never an
  override), on a constructor (each kind's `_` is its own), on a method of a final kind (it is
  closed already), and on a function, field, constant, static member, interface or an interface's
  method.
- **`readonly` closes a field once its object is made**: the field is written only while the
  object is being constructed, by the constructor of the kind that declares it (a promoted
  parameter and a field default count), and refused everywhere else, from outside and from the
  kind's own methods alike, whatever the write (`=`, `+=`, `++`, `??=`, `..=`, `delete` under it,
  and a write inside a list or map it holds, since that changes the field's value). The marker
  comes after the visibility, as `final` does: `pub readonly #id;`, `pub readonly int #id = 0;`,
  `fn _(pub readonly #id) {}`; `readonly pub` says to write `pub readonly`. A write the parser can
  see (`#id = 1` in a method, or in a child's constructor, or in a lambda made in the constructor,
  which may run later) is refused when the program is read, the rest when it runs, with one
  sentence: `Account #id is read-only: only Account's constructor sets it`. The object a read-only
  field holds is still a handle anyone can write (`$a.owner.name = "x"`); only the field itself is
  closed. A `readonly kind` makes every field of its objects read-only, so a shared object nobody
  can change behaves like a value: money, points, colours, a record read from a request. It
  extends only a read-only kind and is extended only by one, so what its header says holds for
  every field an object of it has; `abstract readonly kind` and `final readonly kind` are written
  in that order. `readonly` is an error where it would close nothing: on a field of a read-only
  kind (it is read-only already), a static field (a value that never changes is a `const`), a
  method, a function, a constant, an interface or an enum.

  ```gaz
  readonly kind Money {
      fn _(pub int #cents, pub string #currency) {}

      pub fn plus(Money $other): Money {
          return Money(#cents + $other.cents, #currency);
      }
  }

  $price = Money(1500, "ZAR").plus(Money(250, "ZAR"));
  echo $price.cents;                     // 1750
  $price.cents = 0;                      // Error: Money #cents is read-only: only Money's constructor sets it
  ```
- **`fields()` and `echo` are not member access** and show every field that is set, whatever it
  escapes: reflection exists so a pass can walk an object without knowing its kind.
- **`to_string()`** is the one protocol method: `echo`, `..`, interpolation and `join` use it.

## Interfaces

An interface names methods that unrelated kinds can share. A kind says which interfaces it
implements, and the program is checked when it is read: a kind that claims one has every
method it names.

```gaz
interface Shape {
    fn area(): float;
    fn scale(float $by);
}

interface Named {
    fn name(): string;
}

abstract kind Polygon implements Shape {
    kin float #factor = 1.0;

    pub fn scale(float $by) {
        #factor = #factor * $by;
    }
}

kind Square extends Polygon implements Named {
    fn _(float #side) {}

    pub fn area(): float {
        return #side * #side * #factor;
    }

    pub fn name(): string {
        return "square";
    }
}

fn describe(Shape $shape): string {
    return "an area of " .. $shape.area();
}

$square = Square(2.0);
$square.scale(1.5);
echo describe($square);
echo is_a($square, Shape) .. " " .. is_a($square, Named);
echo Shape;
```
```
an area of 6.0
true true
interface Shape
```

- **An interface names methods and nothing else**: each one's parameters (with types and
  defaults, as a method's) and its return type, ended by `;`. No bodies, fields, constants or
  statics, no `pub` or `kin` (every method of one is `pub`), no `abstract` (there is nothing to
  leave out) and no constructor, and an interface extends nothing. Each is an error that says
  so. A kind that needs two interfaces implements both.
- **`implements` comes after `extends`**: `kind Square extends Polygon implements Shape, Named`.
  An interface is top level only, shares the namespace of functions, kinds and constants, and
  follows `pub` and namespaces as a kind does: `implements geometry::Measured`.
- **A kind that claims an interface has each of its methods**, declared or inherited, and each
  is checked as an override of it would be: it must be `pub` (an interface is a promise to any
  caller), accept every argument count the interface's does (`Method Square.scale must accept
  every argument count Shape.scale does (1)`) and keep its types (`Method Square.area must
  return float, as Shape.area does: an implementation keeps the interface's types`). A missing
  one is `Kind Square must define method area of interface Shape, or be abstract`.
- **An abstract kind may leave a method to its children**, which are checked in turn; one it
  does have is checked where it is. **A child of an implementer is an implementer**, with
  nothing said.
- **Two interfaces a kind claims may name the same method only alike** (the same argument
  counts and types), since one method answers for both: otherwise `Kind Square can't implement
  both Shape and Plot: they name method area differently`.
- **At run time an interface is a value and a type.** `is_a($x, Shape)` is true for an object
  whose kind, or any ancestor, implements `Shape`, and a parameter, return, field or static
  field typed `Shape` (or `?Shape`, or in any union) admits an implementer and refuses anything
  else with the usual words: `describe() expects $shape to be Shape, got Rock`. A kind with the
  right methods that doesn't say `implements` is not one: the claim is the contract.
- **It prints as `interface Shape`**, `kind_name(Shape)` is `"Shape"`, and `type_of(Shape)` is
  `"kind"`, as for any kind: an interface goes where a kind value goes (the second argument of
  `is_a`, a parameter typed `kind`). It can't be constructed: `Shape()` is an error when the
  program is read, and calling one held in a variable is `Cannot construct interface Shape`.
  `kind_of($x)` is still the object's own kind, and an interface can't be extended, caught or
  reached with `::` or `.`.
- **`to_string()` and `to_json()` stay protocols**, not interfaces: a kind has them or not, and
  printing and encoding ask.

## Enums

An enum is a closed set of named values: a kind whose cases are its only objects. A status or a
choice written as an enum is checked where a string never is: `Filter::Opne` is an error when the
program is read, and a parameter typed `Filter` takes nothing else.

```gaz
enum Filter: string {
    All = "all";
    Open = "open";
    Done = "done";

    pub fn label(): string {
        return match (#) {
            Filter::All => "Everything",
            Filter::Open => "To do",
            Filter::Done => "Finished",
        };
    }
}

enum Direction {
    North;
    South;
}

fn shown(Filter $filter): string {
    return $filter.label() .. " (" .. $filter.value .. ")";
}

$filter = Filter::from("open", Filter::All);
echo shown($filter);
echo Filter::from("archived", Filter::All);
echo Filter::cases();
echo $filter == Filter::Open;
echo Direction::North;
```
```
To do (open)
Filter::All
[Filter::All, Filter::Open, Filter::Done]
true
Direction::North
```

- **Cases are bare lines**, `North;`, named `Direction::North` anywhere and `#North` inside the
  enum, as a static field is. An enum is top level only, shares the namespace of functions, kinds
  and constants, and follows `pub` and namespaces as a kind does (`palette::Colour::Red`). It
  needs at least one case, and every case is `pub`.
- **A case is one object, made before the program runs**, so `==` and `match` compare it as
  itself: `Filter::Open == Filter::from("open")`. `type_of` is `"object"`, `kind_of` the enum,
  `is_a($x, Filter)` asks whether it is one of its cases, and a parameter, return or field typed
  `Filter` (or `?Filter`) takes its cases and nothing else. `match` promises no exhaustiveness: a
  case no arm names is the usual error, which names it without running anything, `No arm matches
  Filter::Done`.
- **Values are optional, and all or nothing**: `enum Filter: string` (or `: int`) gives every
  case a literal value of that type, `Open = "open";`, no two the same. Such an enum has three
  members it didn't write: `.value` on a case (`Filter::Open.value` is `"open"`),
  `Filter::from($value, $default)`, the case with that value, and `to_json()`, its value. `from()`
  without a default is an error for a value no case has (`Filter has no case with the value
  "archived"`), and with one gives the default instead, as `to_int($text, $default)` does; a value
  of another type is always an error (`Filter::from() expects $value to be string, got int`).
  `.value` on an enum without values is `Direction has no member value: enum Direction has no
  values, so its cases have none`.
- **Every enum has `cases()`**, its cases in the order declared.
- **Methods and constants, but no fields**: an enum may have methods (`pub`, `kin` or its own),
  constants and static methods, and may implement interfaces (`enum Filter: string implements
  Labelled`), checked as a kind's claim is. It has no fields, static fields or constructor,
  extends nothing and nothing extends it, so `abstract`, `final` and `extends` are errors. Its
  members share its cases' names, so a case can't be called like a method, and in an enum with
  values nothing else can be called `value`, `from` or `cases`.
- **Nothing makes a case or changes one**: `Filter()` is an error when the program is read (`Cannot
  construct enum Filter: its cases are its only objects, so name one, as Filter::All`), and so is
  assigning to a case or its value (`Cannot change Filter::Open: an enum's cases never change`);
  through a variable, or an enum held as a value, it is the same error when it runs.
- **It prints as its name**, `Filter::Open`, unless the enum has a `to_string()`; the enum itself
  prints as `enum Filter`.
- **JSON**: `json::encode` writes a case with a value as its value, through the `to_json()` the
  enum is given (an enum may write its own instead); a case without one is the usual `Direction has
  no member to_json`. Decoding never makes a case: a document must not choose which objects are
  built, so read the value and write `Filter::from($value, $default)`.
- **A case is not a map key**, as no object is: key a map by its `.value`, or give the enum a
  method that answers by `match`.

## Types

Types are optional, and checked when the program runs, or when it is read if a value's type is
plain from the source. Leaving one out means anything; writing one means the value is checked,
strictly, where it arrives.

```gaz
kind Account {
    pub string #owner;
    pub float #balance = 0;
    kin ?string #note = null;
    pub static int #made = 0;

    fn _(pub int #id, string $owner, int|float $start = 0) {
        #owner = $owner;
        #balance = $start;
        #made++;
    }

    pub fn deposit(int|float $amount): float {
        #balance += $amount;

        return #balance;
    }

    pub fn close(): null {
        #note = "closed";
    }
}

fn total(int $n, ?string $label = null): int {
    return $n * 2;
}

$double = (int $x) -> $x * 2;
$a = Account(1, "Ann", 10);
echo $a.deposit(5) .. " " .. total(4) .. " " .. $double(21);
echo Account::made;
```
```
15.0 8 42
1
```

- **The syntax**: the type goes before what it types. Parameters (`int $n`), the
  return after the parameters (`fn total(int $n): int`), fields (`pub float #balance = 0`), static
  fields (`static int #made = 0`), promoted constructor parameters (`fn _(pub int #id)`) and
  lambda parameters (`(int $x) -> $x * 2`) all take one. A constructor has no return type,
  since constructing gives the object, and a lambda has none either: after `(...)` a `:` is a
  ternary's, as in `$c ? ($a) : $b`. A list pattern takes no type: it is already a list.
- **A type is a `type_of()` name** (`int float string bool null list map function kind object
  socket db file`) **or a kind's or interface's name**, resolved like any other name, so `Shape` in
  `namespace shapes` is `shapes::Shape`. `?T` is `T|null`, and `A|B|C` is a union. `object` is
  any object; a kind admits its children and an interface its implementers, as `is_a` does. A kind can't be named after a
  builtin type. No `mixed` or `any`: leave the type out. No generics yet: `list<int>` is an
  error that says so.
- **`: null` is the return type of a function that returns nothing**, since a call that returns
  nothing gives `null`: it is the value's type, not a special word.
- **Checked when the value arrives, never converted**, with one exception: an int is accepted
  where `float` is asked and arrives as a float (`fn area(float $r)` called as `area(2)` has
  `$r` equal to `2.0`), in a parameter, a return and a field alike. `"5"` where `int` is asked
  is an error, `2.0` where `int` is asked is an error, `true` where `int` is asked is an error.
- **Where**: a parameter when the function starts, after its defaults have run, so a default
  that doesn't fit is an error too; the return on every `return` (once the function's own
  `finally` blocks have run, so a `catch` inside it can't catch the error: its caller gets it),
  the implicit `null` at the end of a function included (a bare `return;` in a function whose type excludes null is a
  syntax error); a field on every write that changes its value, from inside the kind or out,
  `=`, `+=`, `++`, `..=` and `??=` alike, its default when the object is made, and a promoted
  parameter. A write inside a field's value (`$o.items[] = 1`) changes nothing the type says.
  A typed field with no default is unset until written, as an untyped one is.
- **The errors are ordinary, catchable runtime errors**, located where the check is, with the
  trace showing the caller: `total() expects $n to be int, got string`, `Account.deposit()
  expects $amount to be int|float, got null`, `Account() expects $owner to be string, got int`
  (a constructor is named as the call that makes the object), `-> at file.gaz:12 expects $x
  to be int, got string` (a lambda as traces name one), `total() should return int, got
  string`, `Account #balance must be float, got string`, `Account::made must be int, got
  string`. What it got is named as `type_of()` would, an object by its kind.
- **A value whose type is plain from the source is checked when the program is read**, with the
  same sentence, so a mistake in code that hasn't run yet still stops the program before it
  starts: `fn g() { return total("x"); }` is refused even if `g()` is never called. That is a
  literal (a string, interpolated or not, a number, `-1`, a bool, `null`, a list or map literal, a
  lambda), anything joined with `..`, a constant, a case of an enum and a kind being constructed
  (`Square()`), wherever it meets a type: an argument of a call by name, a `#method()`, a
  `##method()` or a constructor, a `return`, and the default of a parameter, a field or a static
  field. A variable, a call's result and `$obj.method()` are checked when the program runs.
  A `#method()` is named by the version the kind it is written in sees, and an interface's or
  abstract method's default is held to its type too, though it never runs.
- **An override keeps the parent's types** where the parent declares them: the same type on
  each such parameter and on the return. Where the parent says nothing, the child may say what
  it likes. Constructors are each kind's own, as with argument counts.
- **Untyped code pays nothing**: a typed parameter or return is a check instruction in the
  function, and a typed field or static field a word on its record in the bytecode, so code
  without types compiles to no checks at all.

## Errors

```gaz
kind NotFound extends Error {
    pub #key;

    fn _($key) {
        ##_("Not found: {$key}");
        #key = $key;
    }
}

try {
    throw NotFound("id");
} catch (NotFound $e) {
    echo "{$e.message} ({$e.key}) at line {$e.line}";
} catch (Error $e) {
    echo $e.message;                     // division by zero, a missing key, bad operands...
} catch ($e) {
    echo "something else was thrown: {$e}";
} finally {
    echo "always";
}
```

- Every runtime failure is catchable: a failed operator or builtin, an undefined variable or
  key, division by zero, running out of call depth. Syntax errors happen before the program
  runs and are not.
- **`Error` is a builtin kind** with `#message`, `#file`, `#line`, `#trace` and `#cause`. Programs
  extend it; `catch (Type $e)` matches a kind or a child kind, and an untyped `catch` must be
  last.
- **`throw $value` raises any value.** A string becomes an `Error`'s message; anything else is
  caught as it is. An `Error` keeps the line and trace of where it was first thrown, so `throw
  $e;` in a catch passes it on unchanged, and if nothing catches it again it is reported as its
  first throw would have been. Any other value has no line of its own: thrown again, it is
  reported from where it was thrown last.
- **`throw` is an expression**, so it goes wherever a value is wanted: `$m[$k] ?? throw
  NotFound($k)`, `default => throw "Unknown cell"` in a `match`, either branch of a ternary, a
  lambda's body. Its operand is a whole expression and runs as far right as it can (`throw $a ??
  $b` throws whichever is there). As the operand of a tighter operator it needs parentheses:
  `$ok || (throw "failed")`.
- **`#trace`** is the calls that were running, innermost first, as a list of strings, each the call
  and where it was (`"inner at fib.gaz:3"`, `"top level at fib.gaz:7"`): a function by name, a
  method `Kind.name`, a constructor `Kind._`, a lambda `->`. A deep trace keeps the innermost and
  outermost 10 around `... N more`. An uncaught error prints it under the message, unless it is a
  single call. `#file` is `null` for piped source.
- **`#cause`** is the error that led to this one, so code that catches one error and throws its
  own keeps the first: `Error($message, $cause)`, or `$e.cause = $first` later. It is `null`
  unless given, and may be any value, so whatever a `catch` caught can be passed on. A kind with
  a constructor of its own passes it to its parent's:

  ```gaz
  kind NotSaved extends Error {
      fn _($what, $cause) {
          ##_("Could not save {$what}", $cause);
      }
  }

  fn insert($title) {
      throw "duplicate key value violates unique constraint";
  }

  fn save($title) {
      try {
          insert($title);
      } catch (Error $e) {
          throw NotSaved("the todo", $e);
      }
  }

  save("Write the VM");
  ```

  An uncaught error prints its causes under its trace, each with its own calls:

  ```
  Error: Could not save the todo
    save at todo.gaz:13
    top level at todo.gaz:17
  Caused by: duplicate key value violates unique constraint
    insert at todo.gaz:6
    save at todo.gaz:11
    top level at todo.gaz:17
  ```

  A cause is shown as `echo` shows it, and only an `Error` has a cause of its own to follow. The
  first 10 are shown and the rest counted (`... 3 more causes`). A chain that loops back to an
  error already shown ends with a line saying so; one that loops past the first 10 is counted to
  where it comes round, each error once. `http::serve` logs a handler's error with its causes the same
  way.
- **`finally`** runs however the block is left, including on `return`, `break` and `continue`.
- **`exit($code)`** ends the program at once with a code from 0 to 255, running no `finally`. It
  is not an error and `try` does not see it.

## Comments

`// to the end of the line`, and `/* ... */`, which **don't nest**: the first `*/` ends one, and a
`/*` inside it is only text, so a comment can hold a path like `src/*.gaz`. A `*/` anywhere ends
it, though, so a glob such as `**` followed by a slash can't be written in one. To comment out
code that has a block comment in it, put `//` on each line. An unterminated block comment is an error
at the line it opened on.

### Documentation comments

A block comment that opens with `/**` (and isn't the empty `/**/`) on the lines right above a
declaration, with no blank line between, is that declaration's **docblock**: its documentation,
for the reader rather than the program. It is a convention that tools read, not part of the
language: nothing of it reaches the program, and a running program can't see it. Its text is
Markdown, written with `*` down the side, and an example is indented under a blank line:

```gaz
/**
 * The price with VAT added, rounded to cents.
 *
 *     echo with_vat(100);         // 115.0
 */
pub fn with_vat(int|float $price): float {
    return round($price * 1.15, 2);
}
```

The website's pages of the standard library show each `pub` name's docblock (and a file's first
docblock, above its `namespace` line, as its overview), and the language server shows them on
hover, in completion and in signature help. A plain comment stays the source's own. Since `*/`
ends any block comment, it can't appear in a docblock's text.

## Names

The keywords are `echo if else while for foreach as break continue fn return null delete match
default const import try catch finally throw true false kind extends abstract interface
implements final readonly enum namespace use pub kin static shared`, and `include` stays a keyword so that
writing it says to write `import`. Other
languages' words (`function`, `class`, `public`, `private`, `protected`) are ordinary names.

Keywords are lowercase and matched exactly, so `kind If`, `fn Return()` and `$while` are all
ordinary names. Writing a keyword in the wrong case says so. Sigils and member names have their
own namespaces, so `$default`, `@match` and a method named `match` were always fine.

Functions, kinds, interfaces, enums, builtins and top level constants share one namespace, and so do
all of a kind's fields, methods and constants across its hierarchy, and an enum's cases with its members.

## Builtins

### Strings

| Builtin | What it does |
| --- | --- |
| `len($x)` | The number of bytes in a string, or of elements in a list or map |
| `slice($x, $start, $length = null)` | The piece of a string or list from `$start`, `$length` long or to the end; a negative position counts from the end |
| `lower($s)` | The string with its ASCII letters in lowercase |
| `upper($s)` | The string with its ASCII letters in uppercase |
| `trim($s, $chars = null)` | The string without the spaces, tabs, newlines and carriage returns at either end, or without the bytes of `$chars` |
| `split($s, $sep, $limit = null)` | The pieces of `$s` between each `$sep`, at most `$limit` of them |
| `join($list, $sep)` | The list's elements as text, with `$sep` between each two |
| `replace($s, $search, $replacement)` | The string with every `$search` in it replaced |
| `contains($s, $needle)` | Whether `$needle` is somewhere in `$s` |
| `starts_with($s, $prefix, $offset = 0)` | Whether `$prefix` is in `$s` at `$offset` |
| `ends_with($s, $suffix)` | Whether `$s` ends with `$suffix` |
| `index_of($s, $needle, $offset = 0)` | Where `$needle` first is in `$s`, from `$offset` on, or `null` |
| `repeat($s, $count)` | The string `$count` times over |
| `chr($byte)` | The one-byte string with that byte value, 0 to 255 |
| `ord($char)` | The byte value of a one-byte string |
| `utf8_valid($s)` | Whether `$s` is well formed UTF-8 |
| `utf8_length($s)` | The number of characters in well formed UTF-8; anything else is an error |
| `utf8_chars($s)` | The characters of well formed UTF-8, each a string of 1 to 4 bytes; anything else is an error |

The builtins for strings are `len`, `slice($x, $start, $length)`, `lower`, `upper`, `trim`, `split($s, $sep, $limit)`,
`join($list, $sep)`, `replace($s, $search, $replacement)`, `contains`, `ends_with`,
`starts_with($s, $prefix, $offset)`, `index_of($s, $needle, $offset)`, `repeat($s, $count)`, `chr`,
`ord`. `split`'s `$limit` (an int of 1 or more, or `null` for none) caps the parts, the last
holding the rest: `split("a=b=c", "=", 2)` is `["a", "b=c"]`. `trim`'s `$chars` is a set of
bytes to take off both ends instead of whitespace (`trim("dir///", "/")` is `"dir"`), byte by byte,
so a character of more than one byte isn't one of them; `""` takes nothing off. Both offsets are optional and count from the end when negative; one outside the string is
an error. `starts_with` at the end of the string (`$offset` = `len($s)`) is true only for an empty
prefix.

**Strings are bytes**, and every builtin above counts and cuts bytes: `len`, `slice`, `reverse` and
`split($s, "")` (which gives the bytes, one string each) can cut a character in half. Three builtins
read a string as UTF-8 instead:

```gaz
echo [len("café"), utf8_length("café")];
echo utf8_chars("é€😀");
echo [utf8_valid("café"), utf8_valid("caf\xe9")];
```
```
[5, 4]
["é", "€", "😀"]
[true, false]
```

`utf8_valid` follows RFC 3629: no character written in more bytes than it needs, no surrogates
(U+D800 to U+DFFF), nothing above U+10FFFF, and no character cut short; `""` is valid, and so is
`"\0"`. `utf8_length` and `utf8_chars` refuse text that isn't, naming the byte where the first bad
character starts (`utf8_length() expects well formed UTF-8, but byte 3 doesn't start a well formed
character`), since a count of something that isn't characters would be a wrong answer, not an
answer. **Check text from outside with `utf8_valid` first**: a file or a socket can hold any bytes,
and a database or a JSON document refuses what isn't UTF-8. What `http::query()`, `http::form()`,
`http::cookies()` and the router decode from a request is checked already (see "Libraries").

### Numbers

| Builtin | What it does |
| --- | --- |
| `to_int($x)` | `$x` as an int; a string that isn't a number, or a float too large for an int, is an error |
| `to_int($x, $default)` | `$x` as an int, or `$default` when it can't convert |
| `to_float($x)` | `$x` as a float; a string that isn't a number is an error |
| `to_float($x, $default)` | `$x` as a float, or `$default` when it can't convert |
| `to_string($x)` | `$x` as text, as `echo` writes it |
| `floor($x)` | The nearest whole number at or below `$x`, as a float |
| `ceil($x)` | The nearest whole number at or above `$x`, as a float |
| `round($x, $precision = 0)` | `$x` rounded to `$precision` decimal places, as a float; halves round away from zero (`round(1.005, 2)` is `1.01`), and a negative precision rounds to tens, hundreds... |
| `abs($x)` | `$x` without its sign, an int for an int and a float for a float |
| `intdiv($a, $b)` | `$a` divided by `$b` as an int, the fraction dropped |
| `sqrt($x)` | The square root, as a float |
| `min($a, $b)` | The smaller of the two |
| `max($a, $b)` | The larger of the two |
| `min($list)` | The smallest of a list's or map's values |
| `max($list)` | The largest of a list's or map's values |
| `sum($list)` | A list's or map's values added up with `+`, from 0 |

The builtins for numbers are `to_int($x, $default)`, `to_float($x, $default)` (without a default, a string
that isn't a number, or a float too large for an int, is an error; with one, it gives the
default: `to_int($arg, null) ?? 1`; a
null, list or map is an error either way), `to_string`, `floor`, `ceil`, `round($x, $precision)`,
`abs`, `intdiv`, `sqrt` (a float; a negative number is an error), `min($a, $b)`, `max($a, $b)`, and `min($list)`, `max($list)` and `sum($list)`
over a list's or map's values (`sum([])` is 0; `min` and `max` take all numbers or all strings,
a tie giving the first, and of nothing is an error; `sum`
adds with `+`, so an int overflowing or a string in the list is `+`'s error).

### Lists and maps

| Builtin | What it does |
| --- | --- |
| `len($x)` | The number of elements in a list or map, or of bytes in a string |
| `slice($x, $start, $length = null)` | The piece of a list or string from `$start`, `$length` long or to the end |
| `in_array($value, $list)` | Whether an element of the list is `==` to `$value` |
| `has_key($x, $key)` | Whether a map has the key, or a list the index |
| `keys($x)` | A map's keys, or a list's indexes, as a list |
| `values($x)` | A map's values as a list; a list as it is |
| `last($list)` | The last element of a list |
| `reverse($x)` | A list, a string or a map in the other order |
| `map($x, $f)` | What `$f` gives for each element, as a list or a map with the same keys |
| `filter($x, $keep)` | The elements for which `$keep` gives something true, as a list or a map with their keys |
| `reduce($x, $f, $initial)` | The values folded into one from `$initial`, left to right |
| `sort($x, $compare = null)` | The values in a new list, in the order `$compare`, or `<=>`, gives |

The builtins for lists and maps are `len`, `slice`, `in_array($value, $list)`, `has_key($x, $key)`, `keys`,
`values`, `last($list)` (the last element; an empty list is an error), `reverse($x)` (a list or
a string backwards, a string byte by byte, or a map's entries in the other order, keeping their
keys), and four that call a function, lambda, bound method, builtin or kind for each
element, in order:

- `map($x, $f)` — `$f($value)` for each; a list gives a list, a map a map with the same keys.
- `filter($x, $keep)` — the elements for which `$keep($value)` is true, as `if` reads it; a
  list gives a list, a map a map with the kept keys.
- `reduce($x, $f, $initial)` — folds the values left: `$carry = $f($carry, $value)`.

A function the program defines (a lambda, a named function, a method) that **needs two arguments** is
given the element's index (a list) or key (a map) as the second, and for `reduce` one that needs three is
given it as the third: `map($names, ($name, $i) -> "{$i}. {$name}")`,
`filter($xs, ($x, $i) -> $i % 2 == 0)`, `reduce($xs, ($carry, $x, $i) -> ...)`. One that needs
fewer, one with a default for its second parameter, a builtin and a kind are called with the value alone,
so `map($texts, to_int)` gives `to_int` one argument.
- `sort($x, $compare = null)` — the values in a new list, ordered by `$compare($a, $b)`, which
  returns an int below zero when `$a` comes first, as `$a <=> $b` does; anything but an int is an
  error. Without one (or with `null`) it is `<=>` itself, ascending, so `sort([3, 1, 2])` is
  `[1, 2, 3]` and a list of a string and a number is `<=>`'s error. Stable: a merge sort that splits in the middle and asks `$compare(right, left)`,
  taking from the right only when that is below zero, so a comparator that prints shows the
  same calls on every platform.

An error in the function comes out of the builtin, and its trace goes from the function
straight to where the builtin was called.

### Types

| Builtin | What it does |
| --- | --- |
| `type_of($x)` | The name of `$x`'s type: `"int"`, `"string"`, `"list"`, `"object"` and so on |
| `is_a($x, Kind)` | Whether `$x` is an object of that kind or of a child of it, or of a kind that implements it when it is an interface |
| `kind_of($x)` | An object's own kind |
| `kind_name($kind)` | The name a kind was declared with, as a string |
| `fields($object)` | An object's fields that are set, as a map by name |
| `object_id($object)` | An int that is this object's alone in the program |

The builtins for types are `type_of`, `is_a($x, Kind)`, `kind_of($x)`, `kind_name($kind)` (the name the kind was
declared with, as a string, namespace included: `"Point"`, `"tui::Rect"`; given an object, its
kind's), `fields($object)` (the fields that
are set, as a map by name, the parent's first; a never-set field is left out), `object_id($object)`
(an int that no other object in the program has or had, the same every run: keep a set of
objects as `$seen[object_id($x)] = true`).

### Input and output

| Builtin | What it does |
| --- | --- |
| `print($value)` | Writes the value to standard output as `echo` does, without a newline |
| `print_error($value)` | Writes the value to standard error as `echo` does, without a newline |
| `read_file($path)` | A file's contents, as a string |
| `write_file($path, $string)` | Writes the string to a file, replacing what it held |
| `read_stdin()` | All of standard input that is left |
| `read_line()` | The next line of standard input, or `null` at its end |
| `read_stdin_bytes($n)` | Exactly `$n` bytes of standard input; an error if it ends first |
| `file_open($path, $mode = "r")` | Opens a file, as a `file`: `"r"` to read, `"w"` to write it anew, `"a"` to add to its end, `"r+"` to read and write |
| `file_read_line($file)` | The next line of the file, or `null` at its end |
| `file_read($file, $length)` | Up to `$length` bytes of the file, or `""` at its end |
| `file_write($file, $string)` | Writes the string to the file |
| `file_seek($file, $offset, $from = "start")` | Moves to `$offset` from the `"start"`, the `"current"` position or the `"end"`, and gives the new position |
| `file_sync($file)` | Writes out what is waiting and makes it durable, on the disk itself |
| `file_truncate($file, $length)` | Cuts the file to `$length` bytes, or grows it with zero bytes |
| `file_close($file)` | Writes out what is waiting and closes the file |
| `flush_output()` | Writes standard output's buffer out now, instead of waiting |
| `args()` | The program's arguments, as a list of strings |
| `program_path()` | How `gaz` itself was invoked (see below) |
| `builtins()` | Every builtin's name, mapped to how many arguments it takes |

The builtins for input and output are `print`, `print_error`, `read_file($path)`,
`write_file($path, $string)`, `read_stdin()`, `read_line()`, `args()`, `builtins()` (every builtin's name
mapped to its parameter count, or `[fewest, most]` when some are optional).

`program_path()` is `argv[0]` exactly as the `gaz` binary was invoked (a bare name found on
`PATH`, a relative path, or an absolute one), not resolved to a canonical path. It exists for a
program that wants to reliably re-invoke itself, as `gaz test` does to run each `*_test.gaz` file
in its own process: passing this to `run()` reinvokes the same interpreter the same way it was
started, since `run()`'s `posix_spawnp` resolves a bare name, a relative path or an absolute one
exactly as the shell would have. It names the interpreter, not the program it is running: where
the program's own file is, is `main_dir()` (see "Paths").

`read_line()` is the next line of standard input without its `"\n"` or `"\r\n"` (the last line
may have neither), or `null` once the input has ended, so `while (($line = read_line()) != null)`
reads it all; output is flushed first, so a prompt printed with `print` shows before the wait.
`read_stdin()` is all of standard input that is left, so after some `read_line()`s it is the rest.
A program that was itself piped in has read its input already: both find nothing.

**`gaz -e 'code'` runs the code given in place of a file** (`--eval` is the same), so a one-liner
needs no file, and **standard input stays the program's data**: `read_stdin()` and `read_line()`
read what is piped in, since the program's own text came from the command line.
`ls | gaz -e 'while (($name = read_line()) != null) { echo upper($name); }'`. Single-quote the
code, so the shell leaves the `$` of a variable alone. Given more than once, each `-e` is a line
of the program, so an error names the line it is on. Everything after the options is the
program's own `args()`, never a file (`gaz -e 'echo args();' a -x` is `["a", "-x"]`), and `--`
ends the options as it does elsewhere. It is a complete program as any other is: statements
end in `;`, a value is printed with `echo`, and nothing is imported for you, so a library is
`import "std/lists.gaz";` first; its other imports are from the working directory and its
project, as piped source's are. `-c`, `--tokens` and `--ast` work with it, to see how a one-liner
was read. It can't be combined with a file, `--watch`, `-S` or `--tty`.

`file_open($path, $mode = "r")` opens a file as a handle, so a large one needn't be in memory
whole (`read_file()` gives all of it at once) and a program can read, write and move about in it.
The mode is `"r"` (read; the file must be there), `"w"` (write, making the file or emptying it),
`"a"` (write at the end, making the file if it isn't there; every write goes to the end, wherever
a seek moved to) or `"r+"` (read and write a file that is there, from its start); anything else is
an error naming the four. A file it makes can be read and written by everyone the umask allows.

`file_read_line($file)` reads a line as `read_line()` reads one, without its `"\n"` or `"\r\n"`
and with the last needing neither, and `null` is the end: `while (($line = file_read_line($f)) !=
null)` reads every line. `file_read($file, $length)` reads bytes, any bytes: up to `$length`
(1 to 16777216, 16 MiB), fewer only at the end of the file, and `""` there. **The end is only where
the file ends now**: once more has been written to it, by this program or another, the next read
gets it, so a log can be followed as it grows (`null` or `""`, wait, read again).
`file_write($file, $string)` writes all of the string and gives `null`. `file_seek($file, $offset,
$from = "start")` moves to `$offset` bytes from the `"start"`, the `"current"` position or the
`"end"` (an offset may be negative, and past the end is allowed) and gives the new position from
the start, so `file_seek($f, 0, "current")` is where the file is. A pipe has no position: seeking
one is an error.

**Writes wait in a buffer** until it fills, a seek, or `file_close($file)`, which writes out what
is waiting and closes the file, and **is an error when the writing fails** (`Cannot write
"out.txt": No space left on device`), so a program that closes what it wrote knows it arrived. A
file no variable holds any more is closed by itself, as a socket is, but nothing can report an
error then: close a file you wrote. Closing again does nothing.

**`file_sync($file)` makes what was written durable**: what is waiting is written out, and the
system is asked to put it on the disk itself, so it survives a power cut (on macOS that takes
`F_FULLFSYNC`, which gaz asks for, since a plain sync there stops at the drive's own cache). It is
slow, a disk's round trip, so it is for the moments that matter: a file about to be renamed into
place, a record that must not be lost. A new file's name is in its directory, which needs its own
sync: `sync_dir($path)` (see "Directories"). `file_truncate($file, $length)` writes out what is
waiting and then cuts the file to `$length` bytes, or grows it with zero bytes, leaving the
position where it was; with `"r+"` it is how a file is overwritten in place (write the new
contents from the start, then cut at `file_seek($f, 0, "current")`). Both want a file opened for
writing, and their failures name the file (`Cannot sync "out.txt": No space left on device`).

A file is a handle, as a socket is: copies share the position, `==` is identity, and it prints as
`file` (or `file (closed)`). Reading or writing a closed file, reading a file opened with `"w"` or
`"a"`, writing one opened with `"r"`, a path that can't be opened and a directory are errors
(`Cannot open "path": No such file or directory`). A pipe or `/dev/stdin` opens too, and lines
arrive as they are written. A file is not inherited by a program started with `run()`, and one
opened before `workers()` can't be used in a worker (see `workers()`): open it after.

`read_stdin_bytes($n)` reads exactly `$n` bytes, leaving the rest of the stream for the next
call, which is what a protocol framed by a byte count (a `Content-Length` header) needs: unlike
`read_stdin()`, it doesn't read to the end. It shares the same buffer as `read_stdin()` and
`read_line()`. Standard input ending before `$n` bytes have arrived is an error.

`flush_output()` is what `read_line()` and `read_stdin_bytes()` already call before they wait
(so a prompt is on the screen first), exposed for a program that is about to do something else
blocking and wants to be sure a line it printed is visible first, such as a startup banner
before a server's first `socket_accept()`.

### Directories

| Builtin | What it does |
| --- | --- |
| `list_dir($path)` | The names of what a directory holds, sorted byte by byte |
| `is_dir($path)` | Whether there is a directory at `$path` |
| `make_dir($path, $parents = false, $mode = null)` | Makes a directory, and with `true` every one along the way |
| `delete_dir($path)` | Removes an empty directory |
| `delete_file($path)` | Removes a file or a symlink |
| `rename_file($from, $to)` | Moves a file or directory to a new name, replacing a file there |
| `file_info($path, $follow = true)` | What is at `$path`: its kind, size, modification time and mode, or `null` |
| `chmod($path, $mode)` | Sets the permission bits (`0o644`) |
| `set_mtime($path, $seconds)` | Sets the modification (and access) time |
| `symlink($target, $link)` | Makes a symbolic link at `$link` whose text is `$target` |
| `readlink($path)` | The text of a symbolic link |
| `sync_dir($path)` | Makes a directory's entries durable, as `file_sync()` does a file's |

`list_dir($path)` is the names of what a directory holds, without `.` and
`..`, sorted byte by byte (so `"10"` before `"9"` and `"Z"` before `"a"`), the same on every
system. `is_dir($path)` is whether there is a directory there (through a symlink too).
`make_dir($path)` makes one directory, whose parent must be there; `make_dir($path, true)`
makes every directory along the path that isn't there, and one that is there already is fine
(something in the way that isn't a directory is still an error). `delete_dir($path)` removes an
empty one; `delete_file($path)` removes a file (or a symlink), never a directory. Each gives
`null`, and what it can't do is an error naming the path and the system's reason:
`Cannot make directory "out": File exists`.
`make_dir($path, $parents, $mode)` gives the new directory the permission bits `$mode` (as
`chmod()` takes them) less the umask, as the system makes it; with `$parents` only the last
directory gets them and the ones above it the default, since a directory without its owner's
`0o700` couldn't have the next one made inside it. A directory that was there already keeps its
mode.

`rename_file($from, $to)` gives a file or directory a new name, replacing a file already at `$to`,
in one step: whoever opens `$to` gets the old file or the new one, never part of either, which is
how a file is replaced safely (write a new one beside it, close it, rename it over the old). It
can't move between file systems (`Cannot rename "a" to "/mnt/b": they are on different file
systems`); other failures name both paths and the system's reason.

`file_info($path, $follow = true)` is what is at `$path` as a map, or `null` when nothing is:

```gaz
write_file("/tmp/info-example.txt", "hello");
$info = file_info("/tmp/info-example.txt");
echo [$info["kind"], $info["size"]];
echo file_info("/tmp/no such file");
delete_file("/tmp/info-example.txt");
```

```
["file", 5]
null
```

`"kind"` is `"file"`, `"dir"`, `"link"` or `"other"` (a pipe, a socket, a device); `"size"` the size
in bytes; `"mtime"` when it was last changed, in whole seconds since 1970 as `time()` counts them;
`"mode"` its permission bits as an int (`0o644`, which is 420: read and write for its owner and
read for everyone else; `format::sprintf("%o", $mode)` shows it as `644`). It follows a symbolic
link to what it points at (and a link to nothing is `null`); with `$follow` `false` the link is
itself, `"link"`, which is how a walk through a tree keeps out of a loop.

`chmod($path, $mode)` sets the permission bits, 0 to `0o7777` (setuid `0o4000`, setgid `0o2000`
and sticky `0o1000` included, as `file_info()` gives them back), following a symbolic link to what
it points at: `chmod("deploy.sh", 0o755)`. `set_mtime($path, $seconds)` sets the modification time
(and the access time with it) to whole seconds since 1970, also through a link, so a copy can keep
its source's time (`set_mtime($to, file_info($from)["mtime"])`) and a build tool can touch a file.
What they can't do is an error naming the path: `Cannot change the mode of "x": Operation not
permitted`, `Cannot set the time of "x": No such file or directory`.

`symlink($target, $link)` makes a symbolic link at `$link` whose text is `$target` exactly as
given. The target needn't exist, and a relative one is read from the link's directory when the
link is followed, not from the working directory: `symlink("v2", "releases/current")` points at
`releases/v2`. A name that is taken, even by a link, is an error (`Cannot make link "current" to
"v2": File exists`): remove it first, or make the link under a new name and `rename_file()` it over
the old one, which replaces it in one step. `readlink($path)` is a link's text, as `symlink()` was
given it, and an error for anything that isn't a link (`Cannot read link "notes.txt": it is not a
symbolic link`).

`sync_dir($path)` makes a directory's entries durable, as `file_sync()` does a file's contents: a
file made or renamed into a directory is there after a power cut only once the directory is synced
too. `fs::write_atomic()` does both.

### Paths

| Builtin | What it does |
| --- | --- |
| `cwd()` | The working directory |
| `chdir($path)` | Changes the working directory |
| `main_dir()` | The directory of the file `gaz` was asked to run, or `null` when there is none |
| `real_path($path)` | The absolute path, with every symlink, `.` and `..` resolved |
| `file_exists($path)` | Whether there is anything at `$path` |

`cwd()` is the working directory, which relative paths are resolved from. `chdir($path)` changes
it, for the rest of the program and the programs `run()` starts (`Cannot change directory to
"build": No such file or directory` when it can't). A program's imports were read before it ran,
so they never move with it. It is the process's: a worker's `chdir()` changes only that worker
(see `workers()`). `program_path()` relative to where the program started stops naming gaz after a
`chdir()`, so a program that runs itself again works out `real_path(program_path())` first when
it was invoked by a relative path.

`main_dir()` is the directory of the main file, the one `gaz` was asked to run, absolute and with
every symlink resolved, as `real_path()` gives it. It is worked out once, as the program starts, so
a `chdir()` doesn't move it and every worker gets the same answer. A program finds the files that
live beside it from there rather than from the working directory, so it runs from anywhere:

```gaz
$app.not_found(http::serve_static(main_dir() .. "/public"));
```

Run as bytecode (`gaz app.gzb`), the main file is the bytecode file, and under `gaz test` it is
each test file, so a test reaches its project's files from `fs::parent(main_dir())`. Piped source
and `gaz -e` have no main file, so `main_dir()` is `null` there, never the working directory.

`real_path($path)` is the absolute path with every symlink, `.` and `..` resolved (a directory
too), and an error when there is nothing there; `file_exists($path)` is whether there is, so
`file_exists("a/../b")` is false when `a` is missing, as the system sees it.

### The environment

| Builtin | What it does |
| --- | --- |
| `getenv($name)` | An environment variable's value, or `null` when it isn't set |

`getenv($name)` is the environment variable's value as a string, or `null`
when it isn't set. A name with a NUL byte in it is an error.

### Programs

| Builtin | What it does |
| --- | --- |
| `run($argv, $input = "", $options = {})` | Runs a program, with no shell, and gives its status and both of its outputs |

`run($argv, $input = "", $options = {})` starts a program and waits for it: `$argv` is a list
of strings, the program (found on `PATH` unless it has a `/`) and then its arguments, passed as
they are, with no shell to read `;`, `$` or `*` in them. It inherits the environment and the
working directory, reads `$input` as its standard input (any bytes, any size), and gives
`{"status" => 0, "stdout" => "...", "stderr" => "..."}`, both outputs whole. The status is its
exit code, or minus the signal's number when a signal killed it (`-9`). A program that can't be
started is an error (`Cannot run "nope": No such file or directory`), as are an empty list and
an argument that isn't a string or holds a NUL byte.

```gaz
$r = run(["git", "log", "-1", "--format=%s"]);
if ($r["status"] != 0) {
    throw $r["stderr"];
}
```

`$options` is a map, and a key it doesn't know is an error:

- `"output" => "inherit"` lets the program write straight to this program's own standard output
  and error as it runs, and read its standard input, so a build's progress shows as it happens and
  an editor (`git commit`) has the terminal. Nothing is collected: `"stdout"` and `"stderr"` are
  `""`. What this program printed before comes first, and `$input` must be `""`. If this program
  is in [raw mode](#the-terminal), the terminal goes back to how it was for the program's run, and
  into raw mode again after. `"output" => "collect"`, collecting both, is what happens without it.
- `"timeout" => seconds` (an int or a float, above 0) is how long the program may take. Past it,
  the program is sent SIGTERM, and SIGKILL two seconds later if it is still running, and `run()`
  raises an error (`run() stopped "sleep" after 2 seconds`) rather than giving a status the
  program could have given itself. A program the program started itself isn't stopped with it,
  and the limit is the program's alone: one that ends in time gives its status and the output read
  by the deadline, even if a program it started still holds its output, whose later writes aren't
  collected. (Without a timeout, `run()` waits until its outputs close.)

```gaz
run(["npm", "install"], "", {"output" => "inherit", "timeout" => 600});
```

### Databases

| Builtin | What it does |
| --- | --- |
| `db_open($url)` | Opens a SQLite or PostgreSQL database, as a `db` |
| `db_run($db, $sql, $params = [])` | Runs SQL and gives the rows and how many rows it changed |
| `db_error($db)` | What the last `db_run()` on it failed with, as the database said it, or `null` |
| `db_close($db)` | Closes the database |

SQLite and PostgreSQL work through one interface, in `lib/db.gaz` (`import "std/db.gaz";`)
on four builtins, so a driver adds no names to a program:

- `db_open($url)` — `sqlite:FILE` (made if missing), `sqlite::memory:`, or a `postgres://` URL, which
  libpq reads whole (`postgres://user:password@host:5432/name?sslmode=require`). Gives a `db`.
- `db_run($db, $sql, $params = [])` — gives `{"rows" => [...], "changes" => N}`: each row a map from
  column name to value (a repeated name keeps the last), and `changes` the rows an insert, update or
  delete changed (0 for a query). `db_close($db)` closes it; a `db` no variable holds any more is
  closed too. One opened before `workers()` can't be used in a worker (see `workers()`): each worker
  opens its own after it.
- Parameters are a list, sent apart from the SQL. The builtin takes the database's own placeholders
  (`?` for SQLite, `$1` for PostgreSQL) and a plain string, since it is the layer underneath:
  programs write `db::sql"..."`, below, which writes them. With parameters the SQL is one statement;
  without, it may be a script, and the last statement's result is the one given. There is no
  last-insert id: use `returning`.
- Values: SQLite's INTEGER is an int, REAL a float, TEXT and BLOB strings. PostgreSQL's int2, int4 and
  int8 are ints, float4 and float8 floats, bool a bool, and the rest (numeric, timestamps, json) the text
  Postgres prints. Both give `null` for NULL. Going in, `null`, ints, floats and strings are sent as
  they are and a bool as 0 or 1 (SQLite) or `t` or `f`; lists, maps and objects are errors, and so is an
  infinite float coming back, since floats here are always finite.
- Errors are catchable and start `sqlite:` or `postgres:`. After one the database itself gave,
  `db_error($db)` is `{"code" => ...}`: SQLite's extended result code (an int), or PostgreSQL's
  SQLSTATE with `"constraint"`, `"table"` and `"column"` (each `null` when the server names none).
  It is `null` after a run that succeeded, or that failed before reaching the database (a closed
  `db`, a parameter no database can store).

`db::open($url)` gives a `Db`, which has `run($sql)`, `query` (the rows), `row` (the first or null),
`value` (its first column), `exec` (the changes), `transaction($work)` and `close()`.
`transaction` runs `$work($db)` between begin and commit, rolls back and raises again if it raises, and
is a savepoint inside another one.

What the database refuses is raised as a **`db::Failure`**, an `Error` whose message is the one the
database gave, so a program tells one failure from another without reading words:

```gaz
import "std/db.gaz";

$db = db::open("sqlite::memory:");
$db.exec(db::sql"create table users (email text unique)");
$db.exec(db::sql"insert into users values ('ada@example.com')");
try {
    $db.exec(db::sql"insert into users values ('ada@example.com')");
} catch (db::Failure $e) {
    echo $e.problem;
    echo $e.code;
    echo $e.message;
}
```

```
db::Problem::Unique
2067
sqlite: UNIQUE constraint failed: users.email
```

- **`problem`** is a `db::Problem`, the same whichever database said it: `Unique`, `ForeignKey`,
  `NotNull`, `Check`, `Retry` (the transaction lost a serialization race or a deadlock: roll it
  back, as `transaction()` does, and run it again), `Busy` (a lock wasn't had in time), `Disconnected` (the connection is
  gone or the server is shutting down) and `Other`.
- **`code`** is the database's own: SQLite's extended result code, an int, with its name in
  `name` (`"SQLITE_CONSTRAINT_UNIQUE"`), or PostgreSQL's SQLSTATE, five characters (`"23505"`).
- **`constraint`**, **`table`** and **`column`** are what PostgreSQL names (`"users_email_key"`), and
  `null` with SQLite, which names them only in its message. `driver` is `"sqlite"` or `"postgres"`.
- A mistake gaz finds before the SQL reaches the database (a wrong count of parameters for SQLite,
  a list as a parameter, a closed `db`) stays a plain `Error`.

SQL is a tagged string, `db::sql"..."`, whose values are sent apart from the text, so nothing a value
holds is ever read as SQL:

```gaz
import "std/db.gaz";

$db = db::open("sqlite::memory:");
$db.exec(db::sql"create table users (id integer primary key, name text, age int)");
foreach ([["Ada", 36], ["Alan", 41], ["Grace", 85]] as [$name, $age]) {
    $db.exec(db::sql"insert into users (name, age) values ({$name}, {$age})");
}

$ids = [1, 3];
$least = 40;
$older = db::sql" and age > {$least}";               // a fragment, built apart
$column = db::ident("name");                        // a name that can't be a parameter
echo $db.query(db::sql"select {$column} from users where id in {$ids}{$older}");
```

```
[{"name" => "Grace"}]
```

- **Each `Db` writes its own placeholders**: `?` for a `sqlite:` URL, `$1`, `$2`... for `postgres:`
  and `postgresql:`, numbered across the whole statement.
- **A value** that is an int, float, string, bool or null is one parameter, sent as it is.
- **A list** is a placeholder for each item in parentheses, `(?, ?)`, for `in (...)`, or a row of
  `values`. Its items must be ints, floats, strings, bools or nulls, and **an empty list is an
  error**: `in ()` isn't SQL, and whether no items means nothing or everything is for the program to
  say.
- **Another `db::sql"..."`** is spliced in and its values numbered with the rest, so a condition can
  be built apart, and an empty `db::sql""` leaves one out.
- **`db::ident($name)`** is a table or column name, written quoted with any `"` in it doubled, so it
  is always exactly one name. Quoted names keep their case in PostgreSQL (`"Name"` is not `name`
  there), and a dot is part of the name: a table in a schema is two, `{$schema}.{$table}`.
- Anything else (a map, a function, an object) is an error when the `db::sql"..."` is made.
- **Only a variable or a constant is interpolated**: as in any string, `{$`, `{@`, `{#` and a
  constant's `{NAME}` start an interpolation and nothing else does, so `{db::ident($c)}` would be
  text. Put a fragment or a name in a variable first, `$column = db::ident($c);`, then write
  `{$column}`; the tag refuses what looks like a call in braces (`db::sql text holds
  {db::ident(...)}: ...`), while a brace before anything else (`'{1,2}'`, `'{"k": 1}'`) is text as
  usual. A constant (`limit {PAGE_SIZE}`) is a value like any other, so it is bound as a parameter;
  a word in braces meant as SQL text is written `\{word}`.
- **A numbered placeholder can't be in the text**: `$1` (PostgreSQL's) or `?1` (SQLite's) would be
  read as whichever value has that number. Interpolate the value instead. A bare `?`, or a named
  placeholder like `:name`, is caught by the database's own count of parameters. A false alarm, a
  `$1` inside an SQL string literal or a `$$...$$` function body, is what `db::raw()` is for.
- **The methods refuse anything but a `db::sql"..."`**, so a plain string can't reach the database
  by accident: `Db.query() takes db::sql"...", not a string`. **`db::raw($text)`** is the way
  round, for SQL built some other way (a migration read from a file): used as it is, with no
  values, and the one place to check by hand. The kind behind a `db::sql"..."` isn't public, so
  no program builds one from a string without meaning to; building one on purpose through
  `kind_of()` of a fragment is as deliberate as `db::raw()`.
- Printing a `db::sql"..."` shows its text with `?` for each value, never the values, which may be
  secrets.

A driver is built in when its library is found (`libsqlite3`, `libpq`); `make SQLITE=0` or `PG=0`
leaves one out, and `db_open` of that scheme is then an error. libpq is loaded when a program first
opens a `postgres://` database, not when gaz starts, so a program that never does costs nothing for
it; if it isn't installed then, `db_open` raises an error saying where gaz looked and how to install
it.

### Sockets

| Builtin | What it does |
| --- | --- |
| `socket_open($host, $port, $tls = false, $timeout = 30)` | Connects over TCP or TLS, and gives a `socket` |
| `socket_read($socket, $seconds)` | What has arrived, up to 64KB, or `""` once the other end has closed |
| `socket_write($socket, $string)` | Sends the whole string |
| `socket_close($socket)` | Closes the socket |
| `socket_listen($host, $port, $backlog = 128)` | A `socket` listening for connections |
| `socket_accept($listener, $timeout = 30)` | Waits for the next connection, and gives it as a `socket` |
| `socket_port($socket)` | The port this end of the socket has |
| `socket_peer($socket)` | Who is at the other end of a connection: `{"address" => "192.0.2.7", "port" => 54321}` |
| `socket_wait($sockets, $seconds)` | The index of the first socket in the list with something to read, or `null` |

A socket is a connection over TCP, or TLS for `$tls = true`:

- `socket_open($host, $port, $tls = false, $timeout = 30)` — connects, trying each address the
  name has, and gives a `socket`. With TLS the server's certificate must be one the system
  trusts (or that the file `SSL_CERT_FILE` names) and must name `$host`; TLS 1.2 or later.
  `$timeout` (seconds, an int or a float) bounds connecting and each read and write.
- `socket_read($socket, $seconds)` — what has arrived, up to 64KB, waiting for something
  to; `""` once the other end has closed. `$seconds` may be left out; given (above 0) and shorter than the socket's own
  timeout, it waits only that long, and gives `null` if nothing came: a deadline that holds to the
  moment.
- `socket_write($socket, $string)` — sends all of it.
- `socket_close($socket)` — closes it; closing again does nothing. A socket no variable holds
  any more is closed too.

A server listens and accepts:

- `socket_listen($host, $port, $backlog = 128)` — a listening `socket` on the first of the host's
  addresses that binds (`"127.0.0.1"` for this machine only, `"0.0.0.0"` or `"::"` for every
  interface). Port 0 is one the system picks.
- `socket_accept($listener, $timeout = 30)` — waits for the next connection, as long as it takes,
  and gives it as a `socket`; `$timeout` bounds each read and write on it. In a worker that has been
  asked to stop (see `workers()`), `null`.
- `socket_port($socket)` — the port this end has, which is how a listener on port 0 says which.
- `socket_peer($socket)` — who is at the other end of a connection, from `socket_accept()` or
  `socket_open()`, as a map of `"address"` (text) and `"port"` (an int): a server's way to know a
  client, for a log or a limit. The address is written as one spelling for one address: IPv4 as four
  numbers with dots; IPv6 as lowercase hexadecimal groups without leading zeros, the longest run of
  two or more zero groups as `::` (the first of two as long), as RFC 5952 says; and an IPv4-mapped IPv6
  address (`::ffff:192.0.2.7`, which is how an IPv4 client looks to a listener that takes both) as the
  IPv4 address. A listener has no peer, and a closed socket is an error; ask right after
  `socket_accept()`, since a client that has gone may no longer be known to the system. Behind a
  proxy the address is the proxy's: the client's is in its `X-Forwarded-For` header, which the program
  decides whether to trust (`http::client_address()`).
- `socket_wait($sockets, $seconds)` — waits until one of a list of up to 16 sockets has something
  to read, and gives the index of the first in the list that has: data, or the end, on a connection;
  a connection waiting to be accepted on a listener. Nothing is read, so the next `socket_read()` or
  `socket_accept()` on it doesn't wait. `null` if nothing came within `$seconds` (an int or a float,
  0 to only look), and `null` at once in a worker that has been asked to stop (see `workers()`), so
  a worker holding a quiet connection open still stops in about a second.

A listener only accepts: reading or writing one is an error. No TLS on this side; put a proxy
(Caddy, nginx) in front for https.

A socket is a handle: copies share the connection, `==` is identity, and it prints as `socket`
(`socket (listening)`, `socket (closed)`). Failing to find the host or connect, a certificate that doesn't check out,
a timeout, and reading or writing a closed socket are errors. A gaz built with `make TLS=0`
has no TLS, and `$tls = true` is an error. A connection made before `workers()` can't be used in a
worker (see `workers()`); a listener can.

### The terminal

| Builtin | What it does |
| --- | --- |
| `term_raw($on)` | Turns raw mode on or off for standard input |
| `term_read($timeout = null)` | What has arrived on standard input, up to 4KB, or `null` after `$timeout` seconds |
| `term_size()` | The terminal's columns and rows |
| `term_is_tty($stream)` | Whether standard input (0), output (1) or error (2) is a terminal |
| `term_is_virtual()` | Whether this is `gaz --tty`'s pretend terminal |

The terminal builtins are what a program needs to be interactive and GazLang can't do itself. Drawing
is escape sequences through `print`, and turning bytes into keys is GazLang's too (`lib/term.gaz`):

- `term_raw($on)` — `true` turns raw mode on for standard input: no echo, no line buffering, each
  key arrives as it is typed. Ctrl-C and Ctrl-Z arrive as the bytes 3 and 26 instead of ending or
  stopping the program, so the program decides. `false` puts the terminal back. Anything that ends
  the program (`exit()`, an uncaught error, SIGINT, SIGTERM, SIGHUP, SIGQUIT) puts it back too,
  so a crash doesn't leave the shell typing nothing. Standard input that isn't a terminal is an
  error.
- `term_read($timeout = null)` — what has arrived on standard input, up to 4KB, waiting for
  something to. `$timeout` (seconds, an int or a float) gives `null` when it runs out; `""` means
  the input ended. Output is flushed first, so a prompt is on the screen before the wait. It reads
  a pipe too, which is how programs that use it are tested.
- `term_size()` — `{"cols" => 80, "rows" => 24}` for the terminal standard output is, and 80 by 24
  when it isn't one. Call it each time it matters; nothing tells a program the window changed.
- `term_is_tty($stream)` — whether standard input (0), output (1) or error (2) is a terminal.
- `term_is_virtual()` — whether the program runs on `gaz --tty`'s pretend terminal (see
  [Without a terminal](#without-a-terminal-gaz---tty)). There, `term_is_tty()` is true for input
  and output, `term_size()` is the pretend terminal's, `term_raw()` does nothing, `term_read()` is
  an error (the keys are `term::Input`'s to read), and `monotonic_time()` starts at 0.0 and moves
  only when the program sleeps, which `sleep()` does at once.

`term_read` reads the descriptor, not the buffer `read_stdin()` and `read_line()` fill, so use one
or the other.

### Workers

| Builtin | What it does |
| --- | --- |
| `workers($count)` | Turns the program into `$count` processes, and gives each its number |
| `worker_recycle()` | Ends this worker on purpose; the master replaces it, keeping the pool `$count` wide |
| `worker_retire()` | Asks for this worker's replacement now, and serves on until it is ready; `true`, or `false` outside a worker |
| `worker_deadline($socket, $seconds, $answer = "", $line = "")` | Ends this worker in `$seconds` unless asked again first, writing `$answer` to `$socket` and `$line` to standard error; 0 or `null` seconds clears it; `true`, or `false` outside a worker |
| `worker_accept($listener, $rule)` | The next connection the master hands this worker once its request's head is in, as `[$socket, $bytes, $state, $served, $first_byte_at]`; `null` once asked to stop, `false` outside a worker |
| `worker_release($socket, $bytes, $served = 0)` | Gives a connection `worker_accept()` gave back to the master, with `$bytes` read past its requests and how many it has had answered, or closes it with `null`; `true`, or `false` outside a worker |

`workers($count)` turns the program into `$count` processes from that point on, each
carrying on with a copy of everything, for a server that answers more than one request at a time
(prefork: a pool of processes started up front). It returns the worker's number, 1 to `$count`, in each of them; the
process that called it never returns from it, but waits, starts a worker again when one ends with an
error or a signal, and ends once every worker has ended with code 0. A worker that fails within a
second of starting, before it has accepted a connection, is a program that can't start: the rest are
stopped and the program exits with its code. SIGTERM or SIGHUP stops them gracefully: each worker's
`socket_accept()` or `worker_accept()` gives `null`, so `http::serve()` returns once the request in hand is answered and
the program ends, and a worker still running after 10 seconds is killed. Ctrl-C reaches the workers
too and ends them at once. A signal the program was started ignoring stays ignored (`nohup` ignores
SIGHUP). Workers share nothing after the call, a
`socket_listen()` listener made before it aside, which is the point: they all accept on one port.
Each draws its own random numbers. At most 1024, and a worker can't start workers of its own.

A database connection, a socket connection or a file made before `workers()` can't be used in a
worker, since every worker would share the one connection or file position under it: their queries
and replies would interleave on one PostgreSQL connection, and SQLite forbids carrying a connection
into a new process at all. Using one is an error (`db_run(): this db was opened before workers(), and
workers can't share one: open one after workers()`), so a worker opens its own after `workers()`.
Letting go of one is fine, by closing it, dropping it or ending: a worker does that without
disturbing the connection or file for the others. The process that called `workers()` keeps its own
copy until it ends, so a PostgreSQL connection opened before it stays open, idle, as long as the
server runs.

On macOS the first SQLite open sets `OS_ACTIVITY_MODE=disable` in the process, which a program
started with `run()` inherits. Apple's SQLite logs a signpost on every open, and the logging
library's state does not survive a fork: without this a worker's open crashed now and then when the
process that started the workers had opened a database first (migrations at start-up).

`worker_recycle()` retires the calling worker on purpose (`http::serve()`'s `max_requests`): it
flushes standard output, then ends the process, and the master replaces it at once, so the pool
stays `$count` wide and whatever state the worker built up over its life goes with it. It never returns. The retiring is by a signal (SIGUSR2), not a reserved exit code, since
`exit($code)` already lets a program choose any code 0 to 255 freely: a reserved one could collide
with an unrelated `exit()` somewhere and be misread as a happy recycle, where a signal-terminated
exit can't. A recycle isn't logged as a failure and skips the "died within a second of starting"
check that guards against a program that can't start, since a low `max_requests` recycling fast is
deliberate, not a startup bug.

`worker_retire()` retires a worker without leaving a gap, which is how `http::serve()`'s
`max_requests` does it: the master starts the replacement at once, under the same number, while the
retiring worker carries on serving, and once the replacement waits for connections the retiring
one is asked to stop as a stop asks every worker, so its `socket_accept()` or `worker_accept()`
gives `null` after the request in hand; then it calls `worker_recycle()`, or ends however it likes, and isn't replaced
again (one still running 10 seconds after it was asked is killed, as on a stop). A worker that left first would leave the pool short for as long as the program takes to
start after `workers()` (connecting to a database, building an app), and empty when every worker
retires at once, which an evenly spread load makes them do. For that moment two processes have one
worker number. It gives `true`, or `false` outside a worker and when asked a second time, when there
is nothing to hand over.

`worker_deadline($socket, $seconds, $answer, $line)` is how `http::serve()`'s `handler_timeout`
ends a worker whose handler takes too long: if it is still set `$seconds` later (an int or a float),
the worker writes `$answer` to the connection `$socket`, `$line` to standard error, and ends, and the
master reports `gaz: worker N timed out on a request; starting another` and starts another. Calling
it again sets it afresh, and 0 or `null` seconds clears it. What the worker was doing is never
finished, and what it printed and hadn't flushed is lost. Outside a worker it does nothing and gives
`false`, as it does with a `null` socket: in a single process nobody would start another, so the
deadline needs `workers()`. The socket must be an open, plain connection of this process's (not a
listener, not TLS), checked in a single process too.

`worker_accept($listener, $rule)` and `worker_release($socket, $bytes, $served)` are how `http::serve()`
keeps slow clients off its workers: the master accepts every connection on the listener itself,
reads each one's request head in one process for all of them, and hands a worker the connection,
with every byte it read, only once the head is whole, so a client sending slowly or a kept-open
connection with nothing to say holds no worker. The master knows no HTTP: `$rule` tells it what a
head is, `{"ready" => "\r\n\r\n", "skip" => "\r\n", "skip_most" => 4, "most" => 65536,
"within" => 10, "idle" => 5, "max_connections" => 1024, "timeout" => 10}`: a head ends with
`ready`, after up to `skip_most` (at most 1000) of `skip` (each text 1 to 16 bytes), within `most`
bytes (at most 65536, so what passes between the processes fits their buffers) and `within`
seconds (from the connection's accept, or from its first byte after an idle wait); a connection
given back may wait `idle` seconds for its next request; at most `max_connections` are open at
once (fewer if the open file limit leaves less room); and `timeout` bounds each read and write on
a connection. The first call gives the master the rule and the listener, every call waits for a
connection, and every key is required. `$state` says why it was handed over: `"complete"`, a whole
head; `"too_large"`, `most` bytes with no end; `"timed_out"`, `within` passed after something came;
`"closed"`, the client closed in the middle of one. A connection that sent nothing is closed by the
master and never handed over. `$served` is how many requests the connection has had answered,
which comes back as `worker_release()`'s `$served`, for counting `requests_per_connection`.
`$first_byte_at` is when the head's first byte came, a `monotonic_time()`. `worker_release()`
takes only the connection `worker_accept()` last gave, `$bytes` holding no whole head and at most
the rule's cap (a longer one is the reader's to refuse); it closes this worker's copy either way,
and asks the master for the next connection, as the `worker_accept()` that should follow it would.
Once the worker is asked to stop it closes the connection rather than give it back. Outside a
worker both give `false`, after checking their arguments.

```gaz
$listener = socket_listen("0.0.0.0", 8080);
$n = workers(4);
while (true) {
    $connection = socket_accept($listener);
    socket_write($connection, "HTTP/1.1 200 OK\r\nContent-Length: 6\r\n\r\nfrom $n");
    socket_close($connection);
}
```

### Time

| Builtin | What it does |
| --- | --- |
| `time()` | Whole seconds since 1970 by the wall clock, as an int |
| `sleep($seconds)` | Waits that many seconds |
| `monotonic_time()` | Seconds on a clock that only counts on, for how long something took |

`time()` is the wall clock: whole seconds since 1 January 1970, UTC, as an int. It can
jump when the clock is set, so it tells what time it is, not how long something took; a program
that prints it can't be recorded. `sleep($seconds)` waits that long (an int or a float, 0 or more) and gives `null`; what was printed
before it is on the screen first. `monotonic_time()` is seconds, as a float, on the system's monotonic clock: it only
counts on, whatever the clock on the wall is set to, and only the difference between two readings
means anything (the starting point is not defined, and the resolution is a microsecond or better).
It is for measuring how long something took, or when something is due: `tui::interact` keeps its
`$tick` steady with it.

### The library

| Builtin | What it does |
| --- | --- |
| `std_source($name)` | The text of a file of the built-in standard library, or `null` |

`std_source($name)` is the text of one file of the built-in standard library
(`"json.gaz"`), or `null`; it is what `import "std/json.gaz"` reads, and a name is a file's, never
a path.

### Control

| Builtin | What it does |
| --- | --- |
| `exit($code = 0)` | Ends the program with that exit code |

`exit($code = 0)` ends the program; raising is the keyword `throw`.

### Random numbers

| Builtin | What it does |
| --- | --- |
| `rand_int($min, $max)` | A random int from `$min` to `$max`, both included |
| `rand_float()` | A random float from `0.0` up to but not including `1.0` |
| `rand_seed($seed = null)` | Starts the random numbers again from a seed, or from an unpredictable one |

These are not cryptographically secure: for games, simulations and sampling, never
for passwords, tokens or keys (for those, `random_bytes()` and `std/crypto.gaz`, below).

- `rand_int($min, $max)` — an int from `$min` to `$max`, both included; `$max < $min` is an
  error.
- `rand_float()` — a float from `0.0` up to but not including `1.0`.
- `rand_seed($seed = null)` — restarts the sequence from an int seed, so a program draws the
  same numbers on every run and every platform; with no seed, from an unpredictable one taken
  from the operating system. Every program starts as if it had called `rand_seed()`.

The generator is xoshiro256**, seeded from the int through SplitMix64. How its 64-bit outputs become numbers is GazLang's
own rule: `rand_float()` is the top 53 bits divided by 2^53; `rand_int()` takes the span
`$max - $min` as an unsigned 64-bit number, masks each output down to the bits the span uses,
and draws again until the result is at most the span, then adds it to `$min`, so every int in
the range is equally likely. `lib/random.gaz` builds shuffling and picking on these.

### Cryptography

| Builtin | What it does |
| --- | --- |
| `random_bytes($length)` | That many bytes from the operating system's secure generator |
| `sha256($data)` | The SHA-256 digest, 32 raw bytes |
| `hmac_sha256($data, $key)` | The HMAC-SHA256 of `$data` under `$key`, 32 raw bytes |
| `pbkdf2_sha256($password, $salt, $iterations, $length)` | A key of `$length` bytes derived with PBKDF2 |
| `scrypt($password, $salt, ...)` | A key derived with scrypt (full signature below) |
| `argon2id($password, $salt, ...)` | A key derived with Argon2id (full signature below) |

These are built into every `gaz`, with or without TLS, and give the same bytes on
every platform. Strings are bytes, and so is what these give: a digest is 32 raw bytes, which
`crypto::hex()` or `crypto::base64()` in `std/crypto.gaz` turn into text. Most programs want that
library's `crypto::hash_password()` and `crypto::token()` rather than these.

- `random_bytes($length)` — `$length` bytes (0 to 1048576) from the operating system's secure
  generator, for keys, salts and tokens.
- `sha256($data)` — SHA-256 (FIPS 180-4).
- `hmac_sha256($data, $key)` — HMAC-SHA256 (RFC 2104): the data first, as every builtin takes
  its subject, so `$payload |> hmac_sha256($secret)` reads as it runs.
- `pbkdf2_sha256($password, $salt, $iterations, $length)` — PBKDF2 with HMAC-SHA256 (RFC 8018),
  1 to 4294967295 iterations.
- `scrypt($password, $salt, $cost, $block_size, $parallelism, $length)` — scrypt (RFC 7914):
  `$cost` is N, a power of 2 greater than 1 and below 2^(16 × `$block_size`), and the memory it
  uses, 128 × `$block_size` × (`$cost` + `$parallelism` + 2) bytes, at most 4 GiB.
- `argon2id($password, $salt, $passes, $memory, $lanes, $length, $secret = "", $data = "")` —
  Argon2id version 1.3 (RFC 9106): `$memory` in KiB, from 8 per lane to 4194304 (4 GiB),
  rounded down to a multiple of 4 per lane; a salt of 8 bytes or more; 1 to 524288 lanes (as
  many as 4 GiB can give 8 KiB each), computed one after another; a `$length` of 4 or more; `$secret` is a key kept apart from the
  stored hashes (a pepper) and `$data` associated data, both optional.

A key derivation's `$length` is 1 to 1048576 bytes. An argument out of range, or memory the
system won't give, is an error that can be caught.

`std/crypto.gaz` (`import "std/crypto.gaz";`) is what a web app or tool uses:

- `crypto::hash_password($password, $options = {})` — a string to store, in the PHC string
  format, carrying its algorithm, parameters, a random 16-byte salt and a 32-byte hash, the last
  two in base64 without padding:
  `$argon2id$v=19$m=65536,t=3,p=4$<salt>$<hash>`. Argon2id with 64 MiB, 3 passes and 4 lanes
  (RFC 9106's recommendation for when 2 GiB is too much) unless `$options` says otherwise:
  `{"algorithm" => "scrypt"}` for scrypt with `ln=17,r=8,p=1` (a cost of 2^17, 128 MiB), or
  `{"algorithm" => "pbkdf2-sha256"}` for PBKDF2 with `i=600000`, and any parameter by the name the
  string gives it (`{"m" => 131072}`). Each default takes about a tenth to a third of a second.
- `crypto::verify_password($password, $stored, $limits = {})` — whether the password is the one
  `$stored` was made from, compared in a time that depends on the lengths and says nothing about
  where the hashes differ. The format is the common one, so Argon2id hashes made elsewhere verify
  here, and these elsewhere. A `$stored` it can't read is `false`, as a wrong password is:
  missing or extra parts, an unknown algorithm or version, a parameter that isn't a plain
  decimal of 1 or more, bad base64, a salt under 8 bytes, or a hash under 16 or over 64 bytes (a
  hash of one byte would match one wrong password in 256). So is one asking for more work than
  the limits allow, since a stored string can be planted to make every login cost gigabytes or
  hours: at most `m=262144,t=12,p=16` for Argon2id (256 MiB), `ln=18,r=16,p=4` for scrypt (512
  MiB) and `i=2400000` for PBKDF2, about four times each default. `$limits` changes them for the
  algorithms it names: `{"argon2id" => {"m" => 1048576}}`. A password or stored string that isn't
  a string, and limits naming what doesn't exist, are errors.
- `crypto::needs_rehash($stored, $options = {})` — whether `$stored` was made with other settings
  than `$options` give, so a program can store a new hash once a login has shown the password;
  `true` for a `$stored` it can't read. The limits don't apply here.
- `crypto::token($bytes = 32)` — that many random bytes in base64url: a session ID, a reset link,
  a CSRF token (43 characters for 32 bytes). Fewer than 1 byte is an error.
- `crypto::equals($a, $b)` — whether two secrets are the same, in a time that depends on their
  lengths and says nothing about where they differ (it compares HMACs of both under a key drawn
  for the call); `==` stops at the first difference, which lets a caller guess a secret a byte at
  a time.
- `crypto::hex($bytes)` and `crypto::from_hex($text)` (either case); `crypto::base64($bytes)` and
  `crypto::from_base64($text)` (RFC 4648, padded); `crypto::base64url($bytes)` and
  `crypto::from_base64url($text)` (`-` and `_`, no padding). Decoding refuses anything but the
  one canonical spelling: a missing or extra `=`, a character of the other alphabet, bits left
  over that aren't zero. These are written in GazLang, and how long they take can hint at the
  bytes they encode or decode; hashing and comparing don't.

```
import "std/crypto.gaz";

$stored = crypto::hash_password($password);          // at sign-up, into the database
if (crypto::verify_password($attempt, $stored)) {     // at login
    if (crypto::needs_rehash($stored)) {
        $stored = crypto::hash_password($attempt);   // the settings have moved on since
    }
}
```

## Statics

A static belongs to the kind rather than to an object: a field is one slot the kind owns, and
a method a function that needs no object. Both are reached by name, with `::`.

```gaz
kind Counter {
    pub static #count = 0;            // one slot, not one per object
    static #limit = 2 * 5;            // its value is a constant expression
    #id;                              // an ordinary field, one per object

    fn _() {
        #count++;
        #id = #count;
    }

    pub static fn next() {
        #count++;
        return #count;
    }

    fn mine() {
        return "{#id} of {#count}";
    }
}

kind Tally extends Counter {}        // shares the same slot

Counter();
Counter();
echo Counter::count .. " " .. Tally::count;
echo Counter::next();
Counter::count = 100;                 // a pub slot, so it can be written from anywhere
echo Counter::count;
```
```
2 2
3
100
```

- **`#name` inside the kind is the member**, static or not, since `#` already means "a member
  of the kind this is written in". A static method has no object, so naming an instance field
  or method in one is a parse error, and `#` on its own is too.
- **A static field's value is a constant expression**, worked out by the parser and written
  before the program's first instruction. Running one would bring initialisation order and
  bytecode before the top level, which is why constants refuse `Point(0, 0)` as well. It is
  required: `static #count;` is an error.
- **Read, called and assigned from anywhere the static escapes to**, as every member is:
  `Counter::next()`, `Counter::count = 1`, `Counter::count++`, `Counter::rows[] = $r` and
  `delete Counter::rows[0]` all work on a `pub static`, in a kind that extends `Counter` on a
  `kin` one, and inside `Counter` on one that says nothing. A kind constant is still not a slot, so
  `Counter::LIMIT = 1` is an error.
- **A child shares its parent's static** and can't declare one again, as with a constant: every
  member shares one namespace across the hierarchy, statics included.
- **Reached by name only**: `$counter.count` is not a static, and `$counter::next()` puts a
  value on the left of a parse-time operator, which is an error. `#next` without calling it is
  an error too; write `Counter::next` for the function.

## Modules

A program is made of files, and each file is a **module**: it sees what it declares and what it
imports, and nothing else. `import` brings another file in, only at the top of a file, after its
`namespace` line if it has one:

```gaz
namespace shop;

import "std/json.gaz";                              // the standard library, built in
import "std/chars.gaz" use is_digit, char_at as at; // and two of its names, unqualified
import "./cart.gaz";                                // this file's directory, or below
import "models/user.gaz";                           // from the project root
```

- **Paths**: `std/NAME.gaz` is a file of the standard library. `./` and a path is this file's
  directory and below. Any other path is from the **project root**: the directory of the nearest
  `gaz.json` at or above the importing file, or, with none, the main file's directory (piped
  source and `gaz -e` look from the working directory). A path can't climb (`..`), can't be
  absolute, and must name a `.gaz` or `.gazml` file; `pkg/` is kept for packages, which aren't
  built yet. A path can't reach into another project, a directory with a `gaz.json` of its own.
- **Moving a file changes only the imports that point at it**: what a module is reached by is
  its `namespace`, never its path.
- **Each module is read once**, however its path is spelt (by its real path, so a symlink is
  the file it points to), and two modules may import each other: a module only declares, so
  there is no order for a cycle to break.
- **An imported file only declares** (`namespace`, `import`, `fn`, `kind`, `const`). A
  statement in one is an error where it is written, so importing a file can never run anything.
  The **main file**, the one run (or given to `gaz -c`, or a test file `gaz test` runs, or piped
  in), may run statements. A file with no statements can be either, so a module compiles on its
  own: `gaz -c lib/forms.gaz` checks it as every program that imports it would.
- `include` is gone, and says to write `import`.

```json
{"name": "shop"}
```

**`gaz.json`** marks a project's root. For now it holds only `"name"`, a string; any other key is
an error until packages give it a meaning. A single file needs none.

### Namespaces

`namespace json;` first in a file, at most one, optional. A file without one declares names with
no namespace, which is what a small program wants.

```gaz
namespace json;                       // first in the file

pub fn decode($text) {                // reachable from outside
    return scan($text);               // its own namespace first, so this is json::scan
}

fn scan($text) {                      // private: only namespace json can use it
    return "<" .. $text .. ">";
}
```

**A name is private to its namespace unless `pub`.** A file is implementation, and only what it
says is public escapes it. `pub` means the same thing on a kind's member, so one keyword covers
the whole language: this name escapes the thing it is written in, whether that thing is a file
or a kind. Privacy is per namespace rather than per file, so several files of a project can
declare the same namespace and see everything of each other's that they import.

```gaz
import "std/json.gaz";                              // json:: becomes reachable
import "std/chars.gaz" use is_digit, char_at as at; // and these two, unqualified

echo json::decode("1");
echo is_digit("4") .. at("abc", 0);
echo json::scan("1");                               // Error: json::scan is not pub
```

**A file sees what it imports, and no further**: a module's declarations are seen by the module
itself and by the modules that import it directly, not by what imports those. A name declared in
the program but in a module this file doesn't import is an error that says which import to add:

```
Error: Undefined type: RegistrationForm (todo::RegistrationForm is declared in forms.gaz, which this file doesn't import: add import "forms.gaz";) at auth.gaz:20
```

A `use` clause only adds aliases, its names bare, since the string already said which file they
come from, and each must be declared in that file. There is no standalone `use` and no
`use ns::*`, so a file can only name what it imports itself.

**A namespace belongs to one project.** Every module of a namespace must be in the same project,
the standard library counting as one, so a program can't join `http` and reach what it keeps
private.

**Resolution** is the current namespace (declared in this file or one it imports), then this
file's aliases, then a qualified name of a namespace it imports, then a name an imported module
declares with no namespace, then the builtins (and the builtin kinds `Error`, `Shared` and `Html`,
which need no import). There is no fallback into another namespace. Only a name's first part is
resolved, since a namespace holds no namespace: inside `namespace gazlang`, `Token::EOF` is
`gazlang::Token::EOF`, while `json::decode` is already what it means.

A namespace's own name wins over a builtin of that name inside it, so declaring
`pub fn values()` in `namespace sorting` makes `values($x)` mean `sorting::values($x)` in the
namespace's own files (a file that imports it still gets the builtin, and writes
`sorting::values`); write `sorting.gaz`'s own calls to the builtin as they are meant, or pick
another name.

`::` resolves a name and `.` goes through a value, so `json::decode` and `Token::EOF` are names
the parser works out, and `$reader.decode` is a member of whatever `$reader` holds. A `:`
followed by a `:` is always `::`, so a ternary needs a space: `$c ? Token::EOF : $x`.

Modules and namespaces are resolved by the parser, so the VM never learns either word: a program
is still compiled whole into one bytecode file, which only sees longer names.

## Templates

A `.gazml` file is a template: HTML with GazLang in it, compiled into a function when it is
imported.

```gazml
@template user_page($user, $posts)
<h1>{{ $user.name }}</h1>
@if (len($posts) == 0)
  <p>No posts yet</p>
@else
  <ul>
  @foreach ($posts as $post)
    <li>{{ $post.title }}</li>
  @endforeach
  </ul>
@endif
```

```gaz
import "views/user.gazml";

$page = user_page($user, $posts);        // an Html
http::serve($listener, $request -> ({"body" => user_page($user, $posts)}));
```

- The first line is `@template name($parameters)`: the function the template becomes, with
  parameters as a function's, defaults and types included (`@template page(User $user, list
  $posts): Html`). Its file name doesn't matter.
- **A template is a module**: before the `@template` line it may have a `namespace shop;` line,
  as any file may start with, and then `import` lines, as any file has at its top. The namespace
  makes the function `shop::page`, private to `shop` unless it is written `@template pub
  page(...)`. The imports are what it sees: the kinds its parameters name (`User` above, which
  must be in a module, since the file that runs the program can't be imported), the other
  templates it calls, and the standard library (`import "std/format.gaz";` for
  `format::number`). An `import` after the `@template` line is an error. Lines are counted from
  the file's first, so an error is where an editor shows it.

  ```gazml
  namespace shop;
  import "std/format.gaz";
  import "./product.gaz";
  @template pub card(Product $product)
  <b>{{ $product.name }}</b> {{ format::number($product.price, 2) }}
  ```
- `{{ expression }}` writes the value as `echo` prints it, **escaped for HTML** (`& < > " '`).
  `{!! expression !!}` writes it as it is: only for HTML you trust.
- A template gives an `Html`, which `{{ }}` writes as it is, so templates call each other
  without escaping twice: `{{ header($title) }}`, or a layout given a page as a parameter.
  `Html($text)` marks text you trust as HTML, and `Html::escape($value)` escapes a value as
  `{{ }}` would. `http::serve` sends an `Html` body as `text/html; charset=utf-8`.
- **An `Html` can't be concatenated**: joined into a plain string, `{{ }}` would escape its
  markup a second time. `..`, `..=`, `join()` and interpolation refuse one with `Cannot
  concatenate Html: build it with web::html"...", or use .text for its markup as a plain
  string`; `echo`, `print`, `to_string($h)` and `$h.text` give its markup when a plain string is
  what you want. `Html` is `final`, so no kind extending it gets round the refusal.
- `@if (...)`, `@elseif (...)`, `@else`, `@endif`, `@foreach (...)` and `@endforeach` each stand
  alone on their line, which writes nothing. Their conditions are GazLang's, in parentheses.
- `{{-- comment --}}` writes nothing; a line holding only one writes nothing at all. `@{{` writes
  `{{`. Any other `@word` is text, so CSS's `@media` and email addresses are fine.
- Errors are at the template's own line: a mismatched `@endif`, a syntax error inside `{{ }}`,
  or a missing key when it runs.
- An expression can't contain `}}` (or `!!}` in a raw one), and stays on one line.
- Escaping is for HTML text and attribute values in quotes: a value in an unquoted attribute, a
  URL (`href="{{ $url }}"` takes a `javascript:` URL as it is), `<script>`, `<style>` or an event
  handler such as `onclick` needs checking or building by the program.

**HTML built in code** is `web::html"..."` (`import "std/web.gaz";`), a tagged string that gives
an `Html`: its text is markup as written, and each value is written for the place in the markup
it lands. The tag reads the text as a browser would, so a value in text is escaped, one in a quoted
attribute is escaped, and one in a URL is checked, which is where escaping alone is not enough. A
list of fragments makes a list in the page:

```gaz
import "std/web.gaz";

$names = ["Tom & Jerry", "<script>"];
$items = map($names, $name -> web::html"<li>{$name}</li>");
echo web::html"<ul class=\"names\">{$items}</ul>";
```

```
<ul class="names"><li>Tom &amp; Jerry</li><li>&lt;script&gt;</li></ul>
```

Where a value lands decides what is done with it:

- **In text**: an `Html` as it is, so fragments nest; anything else escaped (`& < > " '`). A list is
  its elements one after another, each by the same rule. This is also how `<textarea>`, `<title>`
  and a comment are read, except that a comment takes no markup.
- **In a quoted attribute**: escaped, and never markup: an `Html` is an error, since markup is not
  an attribute's value. A list or a map is an error too.
- **At the start of a URL** (`href="{$url}"`, and `src`, `action`, `formaction`, `poster`, `cite`
  and the like): escaped, unless it is a scheme that isn't `http`, `https`, `mailto` or `tel`, which
  becomes `about:invalid#blocked`. Relative URLs are fine. A browser drops tabs and line breaks
  and skips leading spaces before it reads a scheme, and so does the tag, so `"java\tscript:..."`
  is blocked.
- **Later in a URL** (`href="/users/{$id}?tab={$tab}"`, after a `/`, `?`, `#` or `:`): percent-encoded,
  byte by byte, so a value is one piece of a path or a query and can't end it.
- **In a refresh** (`<meta http-equiv="refresh" content="...">`, in either order): a value is the
  delay, alone at the start, and must be an int (`content="{$seconds}"`); or it is in the URL after
  a `url=` the text writes (`content="0;url={$url}"`), at its start or later as above. A URL value
  that starts with a quote is blocked too, since a browser skips the quote there.

```gaz
import "std/web.gaz";

$url = "javascript:alert(1)";
$tab = "a b&c";
echo web::html"<a href=\"{$url}\">profile</a> <a href=\"/search?q={$tab}\">search</a>";
```

```
<a href="about:invalid#blocked">profile</a> <a href="/search?q=a%20b%26c">search</a>
```

A value goes **nowhere else**: in an unquoted attribute, in `<script>`, `<style>`, `<xmp>`,
`<iframe>`, `<noembed>`, `<noframes>` or `<plaintext>`, in an event handler (`onclick`) or a
`style`, `srcdoc`, `srcset` or `ping` attribute, in a tag or attribute name or between attributes,
in an end tag or a declaration, right after a `<`, `</`, `<!` or `<!-`, inside the end tag of a
`<textarea>` or `<title>`, in a URL whose text already starts with another scheme
(`href="javascript:{$x}"`), or in a URL that loads code or a document (`<script src>`, an svg
`<script href>`, `<iframe src>`, `<frame src>`, `<embed src>`, `<object data>`, `<link href>`,
`<base href>`), anywhere else in a refresh's content (`content="0; {$url}"`, which a browser
reads as a URL without the `url=`), in a `<meta>`'s `http-equiv`, or in the content of a `<meta>`
with any other `http-equiv`, is an error that says why, since
HTML escaping doesn't make a value safe there. So is a value that could be part of a URL's scheme:
text before it with no `/`, `?`, `#` or `:` between (`href="java{$x}"`), two values side by side at
the start, or text right after a first value that begins with a `:` or an `&` (`href="{$scheme}:{$rest}"`,
where two harmless values make `javascript:`). Put the whole URL in one value. Write the text in the program,
or build an `Html` yourself and pass it in, which is the deliberate way to say a piece of markup is
trusted. A tagged string is read from the start as text and must end as text, so a tag isn't
opened in one string and closed in another; an `Html` value is taken to be whole markup.

A value prints as `echo` prints it (`null`, `true`, `12`, `1.5`, an object's `to_string()`) before it
is written. A map, or a list inside a list, is an error (`web::html can't put a map in markup:
interpolate its values one at a time`). The result goes into `{{ }}` and into another
`web::html"..."` as it is, and is refused by `..` like any `Html`. Templates (`.gazml`) escape text
and quoted attributes as before and don't read the markup around a value, so a URL in one is
checked by the program.

## Libraries

**The standard library is built into `gaz`**, so a program anywhere reaches it by name, with
no path to this repository: `import "std/json.gaz";`. A path that starts with `std/` is the
library, not a directory (write `./std/x.gaz` for a directory of your own by that name). Every file
in `lib/` declares a namespace, so its names are reached with `::`; everything in `lib/` is written in
GazLang. A file of the library imports its neighbours by their `std/` name (`import "std/chars.gaz";`), so
a test that loads one by its path shares its imports with the built-in library; under `GAZLIB` a file of that directory is the library's own module however it is imported.
Errors in it are located as `<std>/json.gaz:83`. While working on the library itself, `GAZLIB=lib`
makes `std/` read that directory instead of the built-in copy, so an edit needs no rebuild.

| File | What is in it |
| --- | --- |
| `sorting.gaz` | `sorting::values`, `sorting::by` |
| `lists.gaz` | Plain functions over plain lists, the list always first so each reads well after `\|>`; a key or predicate function is called once per element, with the element alone (`map()` and `filter()` pass an index too, these don't). `lists::flatten($lists)` (one level deep), `lists::unique($xs)` (each element once, in the order they first come, compared with `==`), `lists::max_by($xs, $key)` and `lists::min_by` (the element whose `$key($x)` is largest or smallest, the first on a tie; a list of keys breaks ties in order), `lists::group_by($xs, $key)` (a map from each key, an int or a string, to the list of elements with it, in the order the keys first come), `lists::count_by($xs, $key)` (the same with counts), `lists::partition($xs, $predicate)` (`[$matching, $rest]`), `lists::chunk($xs, $size)`, `lists::zip($a, $b)` (pairs, as many as the shorter list has), `lists::take($xs, $n)` and `lists::drop($xs, $n)` (`$n` is 0 or more; more than the list has is fine), `lists::pluck($xs, $key)` (that key of each map), `lists::sum_by($xs, $key)`, `lists::avg($xs)` and `lists::avg_by($xs, $key)` (a float; an error for an empty list), `lists::first($xs)` (an error for an empty list, as `last()` is), `lists::find($xs, $predicate)` (the first element it is true for, or `null`) and `lists::contains_by($xs, $predicate)` |
| `text.gaz` | `text::quote($value)`: a value as the literal that reads back as it, for a message (a string quoted, so a space or a NUL byte shows); `text::lines($text)`: the lines of a string as `read_line()` reads them (`"\n"` or `"\r\n"` ends one, a last line needs no end), without the empty line `split($text, "\n")` leaves after a final newline; `text::lines(read_stdin())` is a one-liner's whole input; `text::indentation($line)`: how many spaces and tabs a line starts with, and `text::unindented($line)` the line without them; `text::trim_start($s, $chars)` and `text::trim_end($s, $chars)`, `trim()`'s two halves (whitespace unless `$chars` names the bytes) |
| `json.gaz` | `json::decode`, `json::encode`; JSON is UTF-8, so decoding refuses a document that isn't well formed UTF-8 or that escapes half a surrogate pair, and encoding refuses a string or key that isn't well formed UTF-8; an object is encoded as what its `pub fn to_json()` returns (a map, say: a value, not JSON text), and one without it is an error; a case of an enum with values is its value (see "Enums"), and one without values is an error. Decoding gives maps and lists, never objects: a kind reads itself back with a `static fn from_json($data)` of its own, by convention |
| `csv.gaz` | `csv::parse`, `csv::records` (RFC 4180) |
| `db.gaz` | `db::open($url)` (a `Db`), `db::sql"..."`, `db::raw($text)`, `db::ident($name)`, and `db::Failure` with its `db::Problem` for what the database refuses; see "Databases" under Builtins |
| `crypto.gaz` | `crypto::hash_password`, `crypto::verify_password`, `crypto::needs_rehash`, `crypto::token`, `crypto::sign($value, $secret)` and `crypto::unsign($signed, $secret)` (tamper-evident values, for cookies), `crypto::equals`, hex and base64; see [the notes on cryptography](library.md#cryptography) |
| `chars.gaz` | `chars::char_at`, `chars::is_char` (a one-character string), `chars::is_digit`, `chars::is_alpha`, `chars::is_alnum`, `chars::is_space`, `chars::is_hex_digit`, `chars::span($s, $i, $predicate)` (how many characters from `$i` satisfy the predicate: `slice($s, $i, chars::span($s, $i, chars::is_digit))` is the number at `$i`) |
| `fs.gaz` | Files and directories for command line tools: `fs::copy($from, $to, $keep_time = false)` (the mode kept, and the modification time with `true`), `fs::copy_tree($from, $to)` (files, directories and their modes, and links as links; gives the paths of what is none of those, a pipe or a socket), `fs::walk($dir)` (every path below, links listed and never followed), `fs::glob($pattern)` (`*`, `?`, `[a-z]`, `**`), `fs::write_atomic($path, $data)` (synced, renamed into place and the directory synced, so it is the old file or the new one even after a power cut), `fs::remove_tree($path)`, `fs::touch($path)`, `fs::temp_dir($prefix)` (its owner's alone) and `fs::parent($path)` |
| `format.gaz` | `format::number`, `format::pad_left`, `format::pad_right`, and `format::sprintf($template, $args)` with the arguments as a list: `%s` (as echo prints it), `%d` (an int), `%f` (an int or float, 6 decimals or `%.2f`'s, rounded as `round()` does), `%x` (an int of 0 or more, lowercase hex), `%o` (an int of 0 or more, octal, so a mode `0o755` is `755`), `%%`; a width, `-` to pad on the right and `0` to pad a number with zeros after its sign (`%-8s`, `%05.1f`). A count of arguments that isn't the placeholders', a type `%d`, `%f`, `%x` or `%o` can't take, and a placeholder it doesn't know are errors |
| `cli.gaz` | `cli::Command($name, $summary)`, command line arguments with a generated `--help`; see below |
| `test.gaz` | `test::expect($label, $actual, $expected)`, `test::throws($label, $thunk, [$kind,] $message)`, `test::snapshot($label, $actual)` and `test::done()`, for `gaz test`; see below |
| `http.gaz` | `http::get($url, $headers = {})`, `http::post($url, $body, $headers = {})`, `http::request($method, $url, $headers = {}, $body = null)`, HTTP/1.1 on the socket builtins, a server, `http::serve($listener, $handler, $options = {})`, `http::handle($socket, $handler, $options = {})` (one connection), `http::http_date($time)`, `http::redirect($to, $status = 303)` (a response that sends the client elsewhere), `http::Router()` for routing requests to handlers, `http::serve_static($dir, $options = {})`, a handler that serves files under `$dir`, and cookies and signed sessions (`http::cookies`, `http::set_cookie`, `http::session`, `http::session_cookie`, `http::csrf_token`, `http::verify_csrf`), with middleware for a web app (`http::security_headers`, `http::sessions`, `http::csrf`, `http::with_session`, `http::flash`); see below |
| `web.gaz` | `web::html"..."`, an `Html` from a tagged string: the text as markup, each value written for where it lands (escaped in text and quoted attributes, checked in a URL, refused where HTML escaping is not enough), and `web::csrf_field($token, $field = "_csrf")`, the hidden field carrying a form's token for `http::csrf()`; see "Templates" |
| `date.gaz` | Three kinds of int, which the table under "Dates and times" below tells apart. Dates as whole numbers of days: `date::days($year, $month, $day)` (day 0 being 1 January 1970: an impossible date is an error), `date::civil($days)` (`[year, month, day]`), `date::year`/`month`/`day`, `date::weekday` (a `date::Weekday`, an enum from `Monday`, value 0, to `Sunday`, 6), `date::next_weekday($days, $weekday)`, `date::add_months`, `date::is_leap`, `date::days_in_month`, and `date::format` (`Sat 8 Aug 2026`), `date::short` (`8 Aug`) and `date::iso` (`2026-08-08`). Times as whole seconds since 1970 in UTC, as `time()` gives them, and the zones that show them: `date::utc()`, `date::fixed($seconds)` and `date::zone($name, $directory = "/usr/share/zoneinfo")` (a named zone from the time zone database, daylight saving time and all), or `date::tzif($name, $bytes)`; `$zone.at($time)` is a `date::Moment` (its date, time of day, offset and abbreviation there, with `rfc3339()`, `format()` and `short()` for its date, `clock()`, `short_clock()`, `offset_text()`, `weekday()` and `reading()`), `$zone.date($time)` the day number its clocks show, and `$zone.time($days, $seconds, $resolve = date::Resolution::Reject)` the time its clocks show a date and `date::time_of_day($hour, $minute, $second = 0)` (`$zone.occurrences($days, $seconds)` every such time: none, one or two); `date::parse($text, $default)` reads RFC 3339, `date::parse_date($text, $default)` a `2026-08-08` and `date::parse_reading($text, $default)` a `2026-08-08T14:02` with no offset (a `date::Reading`, its `#days` and `#seconds`, which `iso()` writes back). Nothing reads the clock: a program that needs today works it out (`$zone.date(time())`), which also keeps date code testable with fixed times; see below |
| `random.gaz` | `random::shuffle` (a shuffled copy of a list or string), `random::pick` (an element of a list or value of a map), `random::key`, `random::chance($p)`, `random::weighted` (from `[item, weight]` pairs) |
| `regex.gaz` | `regex::matches($s, $pattern)` (full match), `regex::search($s, $pattern)` (found anywhere), `regex::find($s, $pattern)` (the start index, or null), `regex::groups($s, $pattern)` (the first match and what each `(...)` in it took, a group that took no part `null`, or `null` for no match), `regex::replace($s, $pattern, $with)` (every match, left to right, by `$with`: a string where `$0` is the whole match, `$1`, `$2`, ... a group (`""` if it took no part) and `$$` a `$`, a missing group or any other `$` an error before anything is replaced; or a function given the match's groups as `regex::groups` gives them and returning a string; an empty match moves on a byte: `replace("abc", "x*", "-")` is `"-a-b-c-"`); literals, `.`, `* + ?` (greedy), `\|` (the first that matches wins), `(...)`, `[...]`/`[^...]` with ranges, `^ $`; `\d` (a digit), `\w` (a letter, digit or `_`), `\s` (space, tab, newline, carriage return) and their negations `\D \W \S`, also inside `[...]`, ASCII bytes as `chars.gaz` classifies them; `\t \n \r`; `\` before any other byte that isn't a letter or digit is that byte, and before a letter or digit an error; `{` and `}` are literal bytes, not repetition (`regex::matches("aaa", "a{3}")` is false); the leftmost match, and there the greedy repetition and the earlier alternative; no backreferences, no backtracking, so time is linear in the string for a given pattern; a pattern compiling to more than 2000 instructions (each entry of a `[...]` counting one more) or with more than 100 groups is an error before anything is matched, so a pattern's size alone can't make each byte expensive |
| `term.gaz` | `term::style`, `term::RESET`, cursor and screen sequences, `term::decode`, `term::Input`, `term::fullscreen`, on the terminal builtins; see below |
| `syntax.gaz` | GazLang's own lexer, the one the compiler reads every program with: `syntax::Lexer($text)`, whose `get_next_token()` gives each `syntax::Token` (its `type`, `value` and `line`) until one of type `"EOF"`, and whose `span()` says where in the text the last one lies; `syntax::LexError` for source it refuses, and `syntax::Lexer::KEYWORDS` |
| `highlight.gaz` | Code as the GazLang website shows it: `highlight::to_html($language, $source)`, an `Html` of `<span>`s with a class for each sort of token (`kw`, `str`, `com` and so on, for a stylesheet to colour), every piece escaped; `highlight::pieces($language, $source)` the same as `highlight::Piece`s, whose text joined gives the source back. GazLang (`"gaz"`) is read by `std/syntax.gaz`; bytecode, templates and shell commands have highlighters of their own |
| `tui.gaz` | `tui::Screen` (a grid of cells that renders only what changed), `tui::Rect`, `tui::box`, `tui::label`, `tui::progress`, `tui::table`, `tui::table_width`, `tui::Table`, `tui::wrap`, `tui::paragraph`, `tui::hints`, `tui::key_help`, `tui::centered($screen, $width, $height)` (`[col, row]` of a box that size in the middle), `tui::Metronome`, `tui::Menu`, `tui::TextField`, `tui::interact`, `tui::choose`, `tui::ask`; see below |

`http.gaz` returns
`{"status" => 200, "headers" => {"content-type" => "text/html", ...}, "body" => "..."}`, with
header names lowercased and a repeated header's values joined with `", "`.

- Every status is a response, 404 and 500 included. A request that gets none (no such host,
  refused, a certificate that doesn't check out, a response that isn't HTTP or is cut short)
  is an error.
- `https` checks the certificate as `socket_open()` does. Only `http` and `https` URLs work,
  without credentials in them (send an `Authorization` header).
- Redirects are followed, up to 20. A 303 becomes a GET (a HEAD stays a HEAD), and so does a
  POST after a 301 or 302, as browsers do; 307 and 308 keep the method and body.
  `Authorization` and `Cookie` are dropped when a redirect goes to another scheme, host or port.
- It writes `Host`, `Content-Length` and `Connection` itself, and giving one of those (or
  `Transfer-Encoding`) is an error; `User-Agent: gazlang` and `Accept: */*` unless given. No
  `Content-Type` unless given. The body can be any size and any bytes.
- A method or header name that isn't an HTTP token, a header value with a line break, or a URL
  with a space or control character is an error before anything is sent.
- Each request has its own connection, waiting 30 seconds at most to connect and for each
  read.

**The server**: `http::serve($listener, $handler, $options = {})` answers the connections on a
`socket_listen()` listener for ever, calling `$handler($request)` for each request with

```gaz
{"method" => "GET", "path" => "/users/7", "query" => "tab=posts", "headers" => {...}, "body" => "",
 "remote_address" => "192.0.2.7"}
```

and writing the map it returns: `"status"` (200 if left out), `"headers"` and `"body"` (a string,
`""` if left out). Header names are lowercased and a repeated header's values joined with `", "`,
as in a response; the path and query are as the client sent them, not decoded.

`"remote_address"` is the client's address as `socket_peer()` writes it (or `null` if the client has
already gone and the system no longer knows). Behind a proxy it is the proxy's: the client's is in the
`X-Forwarded-For` header the proxy adds, and whether to believe that header is the program's
decision, since a client can send one of its own.

`http::client_address($request, $trusted_proxies = [])` gives the client's address, or `null` when
`"remote_address"` is. With no `$trusted_proxies` it is `"remote_address"` itself and
`X-Forwarded-For` is never read, so the default can't be fooled by a client sending the header. When
`"remote_address"` is one of `$trusted_proxies` (a list of addresses, compared as text), the header is
read from the right, the end the proxies added to, and the first entry that isn't a trusted proxy is
the client: the entries to its left are whatever the client sent. An entry that is empty or isn't an
address stops the walk at the last hop a trusted proxy vouched for, as does a missing header or one
whose every entry is trusted. A `$trusted_proxies` that isn't a list of strings is an error.

```gaz
// nginx on the same machine, with proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
$address = http::client_address($request, ["127.0.0.1"]);
```

There are no address ranges (`10.0.0.0/8`), and IPv6 text is compared as written.

To send the client somewhere else a handler returns `http::redirect($to, $status = 303)`: 303 See
Other by default, which a browser follows with a GET, as a form's POST wants; 301 and 308 are the
permanent moves and 302 and 307 the temporary ones that keep the method. `$to` is written as given,
so a path of your own is safe, and one taken from a request (a `next` parameter) must be checked by
the caller, or it is an open redirect; one with a line break or a NUL byte is an error at the call.

- `Content-Length`, `Date` and `Connection` are written for you (giving one is an error, except
  `"Connection" => "close"`, in any case, which asks for the connection to close after this
  response); no `Content-Type` unless given. A HEAD request gets the headers without the body; a 204
  or 304 can't have one.
- A connection stays open for the client's next request, and its requests are answered in turn,
  in order when several come at once. It closes after a response that says `Connection: close`,
  which is one to an HTTP/1.0 request or to one that says `Connection: close`, one the server
  refused or a handler failed (the bytes after it can't be trusted to start a request), one whose
  handler asked, the connection's `requests_per_connection`th, the worker's last before
  `max_requests`, and any while it retires (and, in a single process, one handed over to a client
  waiting, below). It also closes, without a word, when the client sends nothing for
  `idle_timeout` seconds, or before its first request for `header_timeout`, or the server is asked
  to stop. Up to four empty lines before a request line are skipped.
- Under `workers()`, slow and idle clients hold no worker: the process that called `workers()`
  accepts every connection and reads each request's line and headers itself, for all of them at
  once, and hands a worker the connection only when they are all in (`worker_accept()`); after the
  response a kept-open connection goes back to it to wait for the next request. So a client
  trickling its headers, or a browser keeping six connections open, costs the server nothing but
  an open connection, and other clients are answered meanwhile. A body is the worker's to read, so
  a client sending its body slowly still holds a worker, for up to `request_timeout`. At most
  `max_connections` connections are open at once; past that, new ones wait in the system's queue
  until one closes.
- In a single process (`gaz -S`, or no `workers()`), open connections never keep the server from a
  new client. When one is waiting, a connection that has had its turn for 50 milliseconds closes
  after its next response, and an idle one is closed, without a word, once nothing else has taken
  the new client within 10 to 20 milliseconds (50 if its client had been asking again at once).
  Closing an idle connection can meet a request its client sends at that very moment, which then
  gets no response (a browser sends it again; a benchmark counts an error), while a response that
  says `Connection: close` meets nothing; so for 5 seconds after it has had to close an idle
  connection, it closes each connection after its response instead, unless the client asked again
  at once.
- A request that isn't well formed never reaches the handler: 400 (a bad request line or header
  line, no `Host` in HTTP/1.1, both `Content-Length` and `Transfer-Encoding`, a body cut short),
  408 (the request line and headers took longer than `header_timeout`, or the whole request longer than `request_timeout`), 413 (a body over `max_body`), 431 (a request line and headers over 64KB), 417 (an `Expect` other
  than `100-continue`, which is answered before the body is read), 501 (a transfer coding other than
  chunked), 505 (not HTTP/1.x). A connection that closes, or goes quiet, before sending anything
  gets nothing.
- A handler that raises, or returns a response that can't be written (a status outside 200 to 599,
  a header value with a line break), is a 500, and the error and its trace go to standard error.
  The worker carries on. What `http::query()`, `http::query_all()`, `http::form()` or
  `http::form_all()` refuses (see below), left uncaught, is answered 400 instead, with nothing
  logged, as the client's mistake: whenever they refuse, even on a request map the program built
  itself, which the server can't tell apart.
- Every request answered is a line on standard error, the access log, written once its response
  has been sent:

  ```
  2026-10-07T14:02:09Z 192.0.2.7 GET /search?q=gaz 200 1234 0.412ms
  ```

  the time (UTC), the client's address (the connection's, so a proxy's behind one), the method and
  target as the request line gave them, the status, the bytes of body sent (0 for a HEAD) and how
  long the request took from its first byte to its response written, in milliseconds to three
  places. That holds for the server's own refusals and 500s too (a 500's error is written first,
  as before), and for each request on a kept-open connection; a connection that closes, or goes
  quiet, before sending anything has no line. Fields are split by single spaces and none holds
  one: `-` is what wasn't read (a request line that couldn't be), every byte of the method and
  target other than the printable ASCII from `!` to `~` is written `\xHH`, and so is a backslash,
  so a request can't write a line or a terminal escape of its own (a 500's error line shows the
  method and path the same way); a target past 360 bytes is cut off with `\...`. Under
  `workers()`, a standard error nobody reads any more (a log reader that stopped) loses the lines
  and ends nothing.
- Options: `"timeout"`, seconds each read and write may wait, and the wait for a connection's first
  request (10); `"request_timeout"`, seconds each request may take to arrive (30), after which it is
  a 408, so a client sending a byte at a time can't hold a worker; `"header_timeout"`, seconds its
  request line and headers may take (10, at most `request_timeout`, `null` for `request_timeout`
  alone), also a 408, the body then having what is left of `request_timeout` (both count from when
  a connection is taken for its first request, and from a later request's first byte);
  `"max_body"` in bytes (1048576);
  `"idle_timeout"`, seconds to wait for the next request on an open connection (5);
  `"requests_per_connection"` (100; 1 closes every connection after its first request);
  `"max_connections"` (1024, at most 1000000), connections open at once under `workers()`, waiting
  for a request or being answered (fewer if the open file limit leaves less room, which the server
  raises as far as it can, saying so on standard error);
  `"access_log"` (true; false writes no access log, the errors still); `"handler_timeout"`,
  seconds the handler may take (10; at most 100000000; `null` for no limit), after which the client is answered `503
  Service Unavailable`, the access log has its line with the deadline as its time, and the worker
  ends, for the master to start another (`worker_deadline()`): it needs `workers()`, and in a single
  process (`gaz -S`) the handler runs on, since nobody would start another. A stop or a hand-over
  gives a worker 10 seconds to finish, so a larger value can be cut short by one, with no 503. A
  program `run()` started carries on after its worker ends, and so does a PostgreSQL query on the
  database's side, so set `statement_timeout` below it; and
  `"max_requests"` (unset, no limit), requests answered, however many connections they came on,
  after which the worker retires, so a long-lived worker's accumulated state doesn't outlive it: it
  calls `worker_retire()` and serves on, one request to a connection so it can leave at any moment,
  until its replacement is ready, so a retiring worker never leaves clients waiting for one; then
  `worker_recycle()`. Without `workers()` it calls `worker_recycle()` at once.
- It returns when its worker is asked to stop, after answering the request in hand.
- In production, run it behind a reverse proxy that buffers whole requests (nginx, Caddy): under
  `workers()` a client sending its headers slowly holds no worker, but one sending its body slowly
  holds one for up to `request_timeout`, and as many such clients as there are workers hold them
  all that long; in a single process one slow client holds the server. TLS is the proxy's job too.
- `http::http_date(time())` is a time as HTTP writes one: `Sat, 08 Aug 2026 14:02:09 GMT`.

**Dates and times**, with `std/date.gaz`:

```gaz
$zone = date::zone("Europe/London");
$moment = $zone.at(1786197729);                  // a time, as time() gives one
echo $moment.rfc3339();                          // 2026-08-08T15:02:09+01:00
echo $moment.format() .. ", " .. $moment.short_clock() .. " " .. $moment.abbreviation;
                                                 // Sat 8 Aug 2026, 15:02 BST
echo $moment.weekday();                          // date::Weekday::Saturday
echo $moment.reading().iso();                    // 2026-08-08T15:02:09
$today = $zone.date(time());                     // a day number
$meeting = $zone.time(date::days(2026, 10, 25), date::time_of_day(9, 30));
echo date::parse("2026-08-08T14:02:09+02:00").time;  // 1786190529
```

Five values pass through the library, three of them ints that the type system can't tell apart,
so know which one you hold:

| You have | What it is | How to make one | What it turns into |
|---|---|---|---|
| A day number, an `int` | a date: days since 1 January 1970, which is day 0 | `date::days(2026, 8, 8)`, `date::parse_date($text)`, `$zone.date($time)`, `$moment.days`, `$reading.days` | `date::civil()`, `date::year()`, `date::weekday()`, `date::format()`, `date::iso()`; a time, with `$zone.time($days, $seconds)` |
| A time, an `int` | seconds since 1970 began in UTC, as `time()` gives them | `time()`, `$zone.time($days, $seconds)`, `date::parse($text).time`, `$moment.time` | `$zone.at($time)`, a `Moment`; `$zone.date($time)`, a day number |
| Seconds after midnight, an `int` | a time of day, 0 to 86399 | `date::time_of_day(14, 2)`, `$reading.seconds` | a time, with `$zone.time($days, $seconds)` |
| A `Moment` | a time as a zone's clocks show it: its date, its clock and the offset they kept | `$zone.at($time)`, `date::parse($text)` | `rfc3339()`; `format()` and `short()`, its date's; `clock()`, `short_clock()`; `reading()`; `weekday()`; `.time`, `.days` |
| A `Reading` | a date and a clock reading with no zone, so no time yet | `date::parse_reading($text)`, `$moment.reading()` | `iso()`, the text a form sends; a time, with `$zone.time($reading.days, $reading.seconds)` |

- A time is an int, seconds since 1970 began in UTC, counting no leap seconds, so it is compared,
  sorted, stored and subtracted as one. A `Moment` is a time as a zone's clocks show it: `#days`
  (a day number), `#year`, `#month`, `#day`, `#hour`, `#minute`, `#second`, `#offset` (seconds
  ahead of UTC), `#dst` and `#abbreviation`, and `#time` itself. It formats its own date as
  `format()` and `short()` do for a day number, and its whole self with `rfc3339()`; the date alone
  in ISO form is `date::iso($moment.days)`.
- A weekday is a `date::Weekday`, an enum whose cases are `Monday` to `Sunday` with the values 0
  to 6, so `date::next_weekday($days, date::Weekday::Saturday)` says which day it means and a
  number in its place is an error. `date::Weekday::from(5)` is `Saturday`.
- `$zone.date($time)` is the day number the zone's clocks show at a time, and `$zone.time($days,
  $seconds)` turns a day number and seconds after midnight back into a time: `$zone.date(time())`
  is today there. Nothing in the library reads the clock itself, so date code tests with fixed
  times.
- `date::zone()` reads the system's time zone database unless given a directory, and follows every
  change of offset in it, and the rule at its end for times after them. A name it doesn't have is
  an error. Load a zone once and keep it.
- When the clocks go back an hour happens twice, and when they go forward an hour never happens, so
  `$zone.time()` is an error for such a reading unless its `$resolve`, a `date::Resolution`, says
  what to do: `Earlier` or `Later` of the two times it is with the offsets either side of the
  change, or `Compatible`, the earlier when it happens twice and the later when it never happens,
  which is when an alarm set for it goes off. `$zone.occurrences()` gives every time a reading is,
  so a form can tell the three cases apart and say which happened.
- `date::parse()` reads `2026-08-08T14:02:09+02:00`, `...Z`, a space for the `T`, a fraction of a
  second (dropped), and an offset of hours alone (`+02`, as PostgreSQL writes a `timestamptz`);
  `rfc3339()` writes what it reads. Anything else, a leap second or a date that doesn't exist is an
  error saying what is wrong, unless a `$default` is given, which is then returned instead, as
  `to_int($text, $default)` does.
- `date::parse_reading()` reads what a `datetime-local` field sends, `2026-08-08T14:02` or with
  seconds, and no offset: a `Reading` is a date and a clock reading and no time until a zone says
  which, `$zone.time($reading.days, $reading.seconds, date::Resolution::Compatible)`.
  `$reading.iso()` writes that text back (the seconds only when they aren't zero), and
  `$moment.reading()` is a `Moment`'s date and clock with its offset left behind, so a field
  shows a stored time as `$zone.at($time).reading().iso()`.

**Command line arguments**, with `std/cli.gaz`:

```gaz
$cli = cli::Command("todo", "Keep a list of things to do");
$cli.flag("verbose", "v", "Say more");
$cli.option("file", "f", "The list to use", "todo.txt");
$add = $cli.command("add", "Add a task", $args -> add_task($args["file"], $args["task"]));
$add.argument("task", "What to add");
$cli.run(args());
```

- `flag($name, $short, $help)` is `true` when given and `false` when not. `option($name, $short,
  $help, $default = null)` takes a value, a string. `$short` is one letter, or null for none.
- `argument($name, $help)` is required, `optional($name, $help, $default = null)` isn't, and
  `rest($name, $help)` is a list of whatever arguments are left.
- `$cli.parse(args())` gives a map by name. `$cli.command($name, $summary, $handler)` adds a
  subcommand, a `Command` of its own, and `$cli.run(args())` calls the handler of the one given.
- `--file x`, `--file=x`, `-f x`, `-fx` and `-vq` (two flags) all work, options and arguments in
  any order; `--` ends the options, and an option given twice keeps its last value.
- `-h` and `--help` print the help, written from the declarations, and exit 0. A mistake prints
  what is wrong on standard error and exits 2. `try_parse()` and `try_run()` raise a `cli::Stop`
  instead, whose `code` and `message` are what would have been printed.

**Testing**, with `gaz test [path...] [--update] [-v]` and `std/test.gaz`:

```gaz
// numbers_test.gaz
import "std/test.gaz" use expect, throws;
expect("two plus two", 2 + 2, 4);
throws("past the end", () -> [1, 2][5], "Index out of range: 5");
test::snapshot("a report", build_report());
test::done();
```

- Every check prints one line: `ok <label>`, or `FAIL <label>: ` and what was wanted and what came
  instead, cut short past 200 bytes of a value so it stays one line.
- `expect($label, $actual, $expected)` holds when the values are `==` and of the same type. For
  two lists or two maps, a FAIL line also says where they first differ
  (`first difference at [2]["name"]: expected 3 (int), got 4 (int)`).
- `throws($label, $thunk, $message)` calls `$thunk` and holds when it throws an error with that
  message. `throws($label, $thunk, $kind, $message)` also requires exactly that kind, not a child
  of it, so a test names the kind it means; leave the message out to check the kind alone. A
  runtime error and `throw "text"` are an `Error`, and a thrown value that isn't an `Error`
  (`throw 5`) is compared as it is, as `expect` compares.
- `test::snapshot($label, $actual)` compares `$actual`, as a literal, against a file recorded next
  to the calling test file, named after it and the label; run with `--update` to (re)write it
  instead of comparing, and review the diff as you would any recorded output.
- `test::done()` ends the program with status 1 if any check in it failed, and 0 otherwise, so a
  test file run on its own tells a script whether it passed.
- `gaz test` finds every `*_test.gaz` file under each path (the current directory by default),
  recursively, and runs each in its own `gaz` process, reinvoked with `program_path()`: `import`
  only takes a string literal, so a runner can't splice in a path it only learns at run time, and
  a process per file keeps one file's crash or endless loop out of another's run. For each file
  it prints the FAIL lines, what the file wrote to standard error, and
  `path: 17 checks, 0 failed`; `-v` prints everything the file printed instead of only its FAIL
  lines. A file fails if a check failed, it exited with a status other than 0, or it checked
  nothing (a test that tests nothing passes by mistake). Last comes `N files run, M failed`, and
  the exit status is 1 if any file failed.
- `gaz test` runs a file as `<program_path()> <file> <file> [--update]`: the file's own path,
  once to say what to run and again as its first program argument, since a running program has no
  builtin giving the *file's* own path (`program_path()` names the interpreter, not the script
  it is running, and `main_dir()` only the directory the file is in). `test::snapshot()` reads that argument to find where to record; a test file has
  no reason to read `args()` itself.

**Routing**, with `http::Router()` (in `std/http.gaz`, so nothing extra to import):

```gaz
$app = http::Router();
$app.get("/users/:id", $request -> ({"body" => "user {$request["params"]["id"]}"}));
$app.post("/users", $create_user);
$app.use(($request, $next) -> $next($request));      // middleware
http::serve($listener, $app.handler());
```

- `get`, `post`, `put`, `patch`, `delete`, and `route($method, $pattern, $handler)` for any
  other. A pattern starts with `/`, and its segments are literal or `:name`; a `:name` takes one
  whole, non-empty segment, percent-decoded (a `%2F` is a `/` in it), into `$request["params"]`.
- The first route added that matches wins. A path that matches only with other methods is a 405
  with an `Allow` header, and a GET route answers HEAD. A path that matches only with its trailing
  slash added or taken away is a 308 redirect to that spelling. A bad escape is a 400, and
  anything else a 404, or what `$app.not_found($handler)` gives.
- Middleware, `($request, $next) -> response`, wraps everything, 404s included; the first added
  is the outermost, and it can answer without calling `$next`.

**Static files**, with `http::serve_static($dir, $options = {})`: a handler, for `http::serve()` directly or as
a `Router`'s `not_found()`, that answers from files under `$dir`.

- The path is percent-decoded and split into segments; a segment of `..` is refused as a 404, the
  same answer a missing file gets, rather than resolved and hoped to stay inside `$dir`.
- A path ending in `/`, or one naming a directory, looks for `index.html` inside it.
- Content-Type is guessed from the extension (html, css, js, json, images, fonts, `pdf`, `wasm`,
  `mp4`, `txt`); anything else is `application/octet-stream`.
- Every file is sent with `ETag: W/"size-mtime"` (from `file_info()`), `Last-Modified` and
  `Cache-Control: no-cache`, so a browser keeps it but asks each time, and a GET or HEAD whose
  `If-None-Match` names the tag (or is `*`) gets a `304 Not Modified` with no body.
  `If-Modified-Since` is read only without an `If-None-Match`, and only the exact date
  `Last-Modified` gave counts; any other date gets the file.
- `http::serve_static($dir, {"cache_control" => "public, max-age=31536000, immutable"})` sets
  Cache-Control instead, for files whose names change with their contents (`app.3f9a1c.css`),
  which a browser can then keep without asking.
- `gaz -S host:port [--docroot DIR]` runs a zero-config server built on this (`std/devserver.gaz`,
  `--docroot` defaulting to the working directory); a program that wants routing or anything
  dynamic writes its own few lines on `serve_static()` instead.

Decoding what a request carries, when a handler asks:

- `http::query($request)` is the query string as a map of strings (`?page=2&q=a+b` is
  `{"page" => "2", "q" => "a b"}`), and `http::form($request)` a form body the same way. A key
  given twice keeps its last value; `http::query_all()` and `http::form_all()` give every value, a
  list for each key. `+` is a space in both, as HTML forms send it.
- `http::form()` needs the request's Content-Type to be `application/x-www-form-urlencoded`, and
  is an error otherwise.
- Every key and value must be well formed UTF-8 once decoded, or it is an error (`text that isn't
  UTF-8 once decoded`), so a handler is only ever given text: `?q=%ff` is refused as a bad escape
  is, since a database or a JSON document would refuse those bytes later. The router's params are
  held to the same rule (a 400), and so is `http::cookies()`, which leaves such a cookie out.
- `http::url_decode($text)` undoes percent-escapes (`%20` is a space, and `+` stays `+`), and a
  `%` without two hex digits after it is an error: `bad percent-escape "%zz" at 3`, a 500 in a
  handler like any other, since its text may be the program's own. It gives the bytes back whatever
  they are, so a handler that wants bytes that aren't UTF-8 decodes a piece of `$request["query"]`
  or `$request["body"]` with it.
- Each of these takes an optional `$default` last, as `to_int($x, $default)` does, given back
  instead of an error for what the client sent that can't be decoded (a bad percent-escape, text
  that isn't UTF-8, or a body that isn't a form), so a handler needs no `try`: `http::form($request,
  {})` reads a request that isn't a form as one with no fields, and `http::query($request, {})` a
  query string like `?show=%zz` as an empty one. Without one, the error left uncaught in a handler
  is answered 400. A mistake of the program's (a request that isn't a map) is still an error.
  `http::url_encode($text)` escapes everything but letters, digits and `- . _ ~`.

**Uploads**: a form with `enctype="multipart/form-data"` is read with `http::multipart($request)`:

```gaz
import "std/crypto.gaz";
import "std/http.gaz";

fn upload($request) {
    $sent = http::multipart($request);
    $photo = $sent["files"]["photo"] ?? null;   // {"filename" => ..., "content_type" => ..., "content" => ...}
    if ($photo == null || $photo["filename"] == "") {
        return {"status" => 422, "body" => "Choose a photo to upload"};
    }
    $stored = "uploads/" .. crypto::token() .. ".jpg";   // never $photo["filename"]
    write_file($stored, $photo["content"]);

    return {"body" => "Thanks for " .. ($sent["fields"]["title"] ?? "the photo")};
}
```

- It gives `{"fields" => {...}, "files" => {...}}`: each text field a string, as `http::form()`
  gives one, and each file a map of its `"filename"`, `"content_type"` (the part's own,
  `application/octet-stream` without one) and `"content"`, the bytes as sent. A name given twice
  keeps its last value; `http::multipart_all()` gives every value, a list for each name, which is
  how the files of an `<input type="file" multiple>` arrive. A file input left empty arrives as a
  file whose filename and content are `""`.
- **Never use `"filename"` as a path**: it is exactly what the browser sent, and a client can send
  `../../etc/passwd`. Name the stored file yourself. A quoted filename runs to the next `"` with
  every byte kept, backslashes too (`filename="dir\"` is `dir\`), as browsers send a `"` as `%22`.
- The whole body is in memory, so `http::serve()`'s `"max_body"` (1MB by default) bounds an
  upload, and a bigger body is answered 413 before the handler runs: raise it for a server that
  takes big files.
- A body of another Content-Type, or one that isn't well formed (no boundary or one over 70 bytes,
  a body cut off before its closing boundary, a part without a `name`, a header line without a
  colon, a parameter whose name isn't a token or whose unquoted value isn't one, a nested
  multipart body, a `filename*` parameter, more than 1000 parts), is an error, and
  so is a name, filename or field value that isn't UTF-8; a file's content can be any bytes. It
  takes a `$default` as `http::form()` does, and without one the error left uncaught is a 400.

**Cookies and signed sessions**, on `crypto::sign`, `crypto::equals` and `crypto::token`:

```gaz
$session = http::session($request, SECRET);           // {} for no cookie, or one that fails
$session["user_id"] = 7;
$response = http::session_cookie({"body" => "..."}, $session, SECRET);
```

- `http::cookies($request)` is the `Cookie` header as a map, each value `url_decode`d. A cookie
  with a bad escape, or a name or value that isn't UTF-8, is left out rather than refusing the
  request, since the header carries every cookie the browser holds for the domain, another site's
  too, which the program can't clear. `http::session()` decodes only its own cookie, so a stray
  `%` in another one leaves the session alone.
- `http::set_cookie($response, $name, $value, $options = {})` adds a `Set-Cookie` header to
  (a copy of) `$response`, url-encoding `$value`. A response can carry several: `write_response()`
  writes a header whose value is a list as that many lines, not joined with a comma, since
  RFC 6265 forbids joining `Set-Cookie` values (an `Expires` attribute has a comma of its own).
  `$options`: `"path"` (`"/"`), `"http_only"` (`true`: a cookie a page's own script can't read is
  one XSS can't steal), `"secure"` (`false`), `"same_site"` (`"Lax"`, so a cookie doesn't go out
  on a cross-site request that isn't a plain navigation), `"max_age"` in seconds (a session cookie
  with none).
- `http::session($request, $secret)` reads, verifies and decodes the session cookie into a map,
  or `{}` for no cookie or one that fails to verify or decode: a forged, expired or missing session
  is routine, not an error a handler must catch. `http::session_cookie($response, $session,
  $secret, $options = {})` is the reverse, adding the session's cookie to `$response`.
- `http::csrf_token($session)` gets or makes a CSRF token in the session, returning the session
  with it set (a handler saves the result with `session_cookie()`, as any other session change);
  `http::verify_csrf($session, $submitted)` checks one a form sent back, with `crypto::equals()`,
  as a signature is checked.

**Middleware for a web app** puts those pieces together for a `Router`, added in this order, the
first outermost:

```gaz
$app = http::Router();
$app.use(http::security_headers());
$app.use(http::sessions($secret, {"secure" => true}));
$app.use(http::csrf());
$app.use(authentication($users));              // your own: only your app knows what a user is
$app.post("/todos", $request -> http::with_session(http::redirect("/"), http::flash($request, "Added.")));
```

- `http::security_headers($options = {})` adds `Content-Security-Policy: default-src 'self';
  form-action 'self'; frame-ancestors 'none'; base-uri 'none'`, `X-Content-Type-Options: nosniff`
  and `Referrer-Policy: same-origin` to every response that doesn't set them itself, so one route
  can send a policy of its own. `$options` maps a header name to the value to send instead, or to
  `null` to send none. Names are matched ignoring case.
- `http::sessions($secret, $options = {})` gives the handler `$request["session"]`, the session
  from its signed cookie, always with a `"csrf_token"` in it, and `$request["flash"]`, the message
  the last request left for this one, or `null`; the flash is not in `$request["session"]`. A
  handler changes the session by adding `"session" => $map` to its response, which the middleware
  takes out again before the response is written. The cookie is written only when the session
  differs from what the cookie held, so a page that changes nothing sends no `Set-Cookie`, and a
  flash is shown once: the next session has none unless the handler sets one. `$secret` must be at
  least 32 bytes, or making the middleware is an error. `$options` are `http::set_cookie()`'s,
  with `"max_age"` two weeks unless given.
- `http::csrf($options = {})` answers a POST, PUT, PATCH or DELETE with a 403 unless it carries
  the session's token, in the form field `_csrf` or an `X-CSRF-Token` header, and, when the
  browser sent an `Origin` header, that it names the request's own host (`Origin: null` doesn't).
  A body that isn't a form carries no token. `$options`: `"field"`, the form field's name, and
  `"failure"`, a function from the request to the response to send instead of the 403. Without
  `http::sessions()` before it, a request is an error saying so.
- `http::with_session($response, $session)` is `$response` with `"session"` set, and
  `http::flash($request, $message, $session = null)` is this request's session, or `$session`
  when given, with `$message` to show on the next page.
- **A login builds a new session** rather than changing the old one, with a fresh
  `crypto::token()` as its `"csrf_token"` (`{"user_id" => $id, "csrf_token" => crypto::token()}`),
  so nothing an attacker planted in the session before it carries over. That is your app's code, as
  is deciding who `"user_id"` is. To greet the user on the next page, pass the new session to
  `http::flash($request, "Welcome back.", $session)`.

```gaz
import "std/http.gaz";

$listener = socket_listen("0.0.0.0", 8080);
workers(4);
http::serve($listener, $request -> match ($request["path"]) {
    "/" => {"body" => "hello\n", "headers" => {"Content-Type" => "text/plain"}},
    default => {"status" => 404, "body" => "not found\n"},
});
```

A client:

```gaz
import "std/http.gaz";

$r = http::post("https://example.com/api", "{\"n\": 1}", {"Content-Type" => "application/json"});
echo $r["status"] .. " " .. $r["headers"]["content-type"];
```

**Testing a handler**, with `http::TestClient($handler, $options = {})`:
a browser for one site that the handler answers as `http::serve()` would, with no server.

```gaz
import "std/test.gaz" use expect;
import "std/http.gaz";

$client = http::TestClient($app.handler());
$page = $client.get("/login");
$done = $client.post("/login", {"_csrf" => http::form_token($page), "email" => "ada@example.com"});
expect("logging in goes home", [$done["status"], $done["headers"]["location"]], [303, "/"]);
expect("and the next page knows who it is", $client.session($secret)["user_id"], 1);
```

- `$client.get($target, $headers = {})`, `$client.post($target, $fields, $headers = {})` (the
  fields form-encoded) and `$client.request($method, $target, $headers = {}, $body = "")` give
  the response as `http::request()` does: header names lowercased, an `Html` body as text with its
  `Content-Type`. `$target` is a path with its query (`"/search?q=gaz"`).
- The request is written out as HTTP and read by the server's code, and the response written by
  it, so a test sees what a browser would: a target the server can't read is a 400, a body over
  `"max_body"` a 413, and a handler that raises a 500, logged as `http::serve()` logs it. A method,
  header or target that could end a line of the request is an error, as with `http::request()`.
- The cookies a response sets are kept and sent with every later request (`Max-Age=0` forgets one);
  `$client.cookie($name)` and `$client.forget($name)` read and drop one, and a `Cookie` header
  given to a request is sent instead. `$client.session($secret)` is the session the cookie holds.
- `http::form_token($response, $field = "_csrf")` is the CSRF token a page carries, as
  `web::csrf_field()` writes it.
- A redirect is answered as it is; `$client.follow($response)` fetches where it points with a GET,
  a relative `Location` read against the last request's target. One on another site is an error.
- `$options`: `"host"`, the `Host` sent (`"example.test"`), `"remote_address"` (`"192.0.2.1"`,
  `null` for a client the system no longer knows), and `"max_body"`, `http::serve()`'s, so an app
  is tested against its own limit.

`term.gaz` is the terminal, on `term_raw`, `term_read`, `term_size` and `term_is_tty` (see the
builtins). Drawing functions *return* the escape sequence, so `print(...)` draws and results
compose with `..`; nothing in it needs a terminal but raw mode.

- **Styles**: `term::style($text, "bold red on_#003")` wraps text in a style and a reset;
  `term::start($spec)` is the sequence alone. Words: `bold dim italic underline blink inverse
  hidden strike`; `black red green yellow blue magenta cyan white`, with `bright_` in front for
  the bright ones; `on_` in front of a colour for the background; `#rgb` and `#rrggbb` for
  truecolour; `c0` to `c255` for the 256-colour palette. An unknown word is an error. One reset
  ends every style, so a style inside a style ends both at the inner one's end.
- **Moving**: `move_to($col, $row)` (the top left is 1, 1), `up`, `down`, `left`, `right`
  (`$n = 1`), `column`, `hide_cursor`, `show_cursor`, `save_cursor`, `restore_cursor`, `clear`,
  `clear_line`, `clear_to_end_of_line`, `clear_below`, `enter_screen` and `leave_screen` (a second
  screen that leaves the terminal as it was), `title`.
- **`term::fullscreen($f)`** runs `$f` on a screen of its own in raw mode with the cursor hidden,
  and puts everything back however `$f` ends, an error included. It gives what `$f` gives.
- **Measuring**: `term::chars($text)` is the list of its characters (`split()` splits bytes),
  `term::strip($text)` the text without its escape sequences, and
  `term::width($text)` the columns it takes, counting characters rather than bytes. A wide
  character (CJK, emoji) counts one where it takes two.
- **Keys**: `term::decode($bytes, $flush = false)` turns what `term_read()` gave into
  `{"keys" => [...], "rest" => "..."}`. A key is `{"key", "ctrl", "alt", "shift"}`, where `"key"`
  is the character typed (`"a"`, `"é"`, `"space"`) or a name: `enter tab backspace escape up down
  left right home end insert delete page_up page_down f1` to `f12`, and `unknown` (with the
  sequence as `"raw"`) for one it doesn't know, such as a mouse report. `rest` is the start of a
  key the bytes stop in the middle of, to put before the next read; with `$flush`, nothing more
  is coming and it is taken for what it looks like (a lone `ESC` is `escape`).
  `term::name($key)` writes a key as `"ctrl+alt+up"`, in the order ctrl, alt, shift, which a
  `match` can take, and `term::text($key)` is the character it types, or `null`.
- **`term::Input`** keeps what a read left over, so make one and keep using it:
  `$input.read($timeout = null)` is the next key, `null` when the time runs out, and
  `{"key" => "eof"}` when the input ends (again on every call after). A lone `ESC` waits 50ms for
  the rest of a sequence before it is `escape`.

```gaz
import "std/term.gaz";

term::fullscreen(() -> {
    $input = term::Input();
    print("press a key, q to quit");
    while (true) {
        $key = term::name($input.read());
        if ($key == "q" || $key == "ctrl+c" || $key == "eof") {
            break;
        }
    }
});
```

`tui.gaz` is widgets, on `term.gaz`. Nothing draws to the terminal directly: everything draws into
a `tui::Screen`, a grid of cells (one character and one style each, columns and rows counted
from 1), and `$screen.render()` gives the escape sequences that turn what the terminal shows into
what the grid holds, and only those. A row that hasn't changed costs one comparison and one that
has is redrawn from its first changed cell to its last, so a program clears and redraws its whole
interface every frame without flicker. A test draws into a `Screen` and reads `$screen.lines()`,
with no terminal.

- **`tui::Screen($cols, $rows)`**: `put($col, $row, $text, $style = "")`, `fill($col, $row,
  $width, $height, $char = " ", $style = "")`, `clear()`, `cursor($col, $row)` (where the
  terminal's cursor goes after a render, shown only then), `render()`, `lines()` and
  `cell($col, $row)` (`[character, style]`), `resize($cols, $rows)`, `fit()` (take the terminal's
  size, `true` when it changed; nothing tells a program the window changed, so poll it each frame)
  and `invalidate()` (draw everything at the next render). Drawing outside the screen is clipped.
  A wide character (CJK, emoji) takes two columns but one cell.
- **Drawing**: `tui::label($screen, $col, $row, $width, $text, $style = "", $align = "left")` (cut
  off or padded to `$width`; `"left"`, `"center"`, `"right"`), `tui::box($screen, $col, $row,
  $width, $height, $title = "", $style = "", $border = "single")` (`single`, `double`, `round`,
  `heavy`, `ascii`; the inside is blanked), `tui::progress($screen, $col, $row, $width,
  $fraction, $style = "green")` and `tui::table($screen, $col, $row, $headers, $rows, $style = "")`
  (columns as wide as their widest cell, numbers on the right; gives the rows it took).
- **`tui::Rect($col, $row, $width, $height)`** is a part of the screen (`$screen.bounds()` is all of
  it). A view is given one to draw in and cuts it up with `inset($n = 1)`, `split_left($width)`,
  `split_right`, `split_top($height)` and `split_bottom`, each of the last four giving `[a, b]`, so it
  does no column arithmetic of its own; a part that doesn't fit is smaller, never negative.
- **`tui::Table($headers, $rows, $style_of = null)`** is a table you move about in: `draw($screen,
  $rect, $focused = true)`, scrolling rows, a selected row and columns that sort. `handle($key)` gives
  `"select"` for enter and `null` otherwise (the arrows or `j`/`k`, home and end or `g`/`G`, page up
  and down; `.` and `,`, or `>` and `<`, sort by the next or previous column, `o` turns the order
  round and `x` goes back to the order given). `selected_index()` is the selected row's index in
  `$rows` however they are sorted now, `selected_row()` the row, `sort_by($column, $descending)` and
  `sorted_by()` say and set the sort, and `set_rows($rows)` shows others, keeping the selection on
  its index. A column of numbers is aligned on the right; `$style_of($row, $index)` gives a row's
  style, for marking the user's own club. The cells of a column must be alike, or sorting on it is
  an error. A table too wide leaves out the columns `drop_when_narrow($columns)` names (indexes,
  the first first; it gives the table, so it can follow the constructor), then squeezes the widest
  of the rest, then is cut off; `.` and `,` go past a column left out.
- **`tui::table_width($headers, $rows)`** is the width `tui::table()` draws at, for fitting what goes
  beside it.
- **`tui::wrap($text, $width)`** cuts text into lines at spaces, keeping its own line breaks, a word
  too long for a line starting one of its own and cut into whole lines; widths are the screen's.
  **`tui::paragraph($screen, $rect, $text, $style = "")`** draws it wrapped into a `Rect`, as much as
  fits, and gives how many lines that took.
- **`tui::hints($screen, $col, $row, $width, $hints, $more = "", $style = "dim")`** is a line of
  key hints: `$hints` is a list of `[keys, what they do]` (`["j/k", "move"]`, shown `j/k move`), or
  `["", text]` for a prompt shown as it is; as many whole ones as fit, and `$more` (`"? keys"`) at
  the right end. **`tui::key_help($screen, $groups, $title = "Keys")`** is all of them, in a box in
  the middle of the screen: `$groups` is `[[title, hints], ...]`, prompts left out. Together they
  are the usual shape: the keys that fit at the bottom, and `?` for the rest.
- **`tui::Metronome($interval, $now)`** is a steady beat: `due($now)`, `wait($now)` (seconds to the
  next, 0 if due) and `beat($now)` after taking one; a late beat isn't made up for with two.
- **`tui::Menu($items, $title = "")`** and **`tui::TextField($value = "")`** take keys
  (`handle($key)`) and draw themselves (`draw(...)`). `handle` gives `"select"` or `"cancel"` for
  a menu and `"submit"` or `"cancel"` for a field, and `null` for a key it dealt with itself: the
  arrows, `j`/`k`, home and end, page up and down; typing, backspace, delete, the arrows, home and
  end or ctrl+a and ctrl+e, ctrl+u and ctrl+k. `$menu.index()` and `$menu.item()` say what is
  selected and `$field.value()` what was typed.
- **`tui::interact($draw, $handle, $input = null, $tick = null, $interval = 0.25)`** runs
  `$draw($screen)` and `$handle($key)` on a screen of its own until `$handle` gives something
  other than `null`, which is what it gives (`null` too when the input ends). Keys come from
  `$input`, a `term::Input`, or a new one; a program that shows one screen after another passes the
  same one to each, since keys typed ahead are in it and a new one for each screen would lose them.
  With `$tick`, time passes: when no key has been pressed for `$interval` seconds `$tick()` is called
  and the screen drawn again, so it can move by itself (a clock, a match being played), and `$tick`
  ends the screen as `$handle` does. The beat is kept by `monotonic_time()` (in a `tui::Metronome`, which
  takes the time as an argument and can be tested without waiting), so a key held down doesn't hold it up. The lambdas share state through `shared` variables, or an
  object as `examples/dashboard.gaz` does. **`tui::choose($items, $title = "", $input = null)`**
  (the index picked, or `null` if cancelled or there is nothing to pick) and
  **`tui::ask($question, $initial = "", $input = null)`** (the answer, or `null`) are a
  `Menu` and a `TextField` run that way, in the middle of the screen. All three need a terminal,
  or `gaz --tty`'s.
- **`$screen.snapshot()`** is the screen as text to read: a ruler of columns, the rows numbered,
  then the text in each style (which is how a selection shows) and the cursor.

#### Without a terminal: gaz --tty

`gaz --tty app.gaz` runs a program on a pretend terminal, 120 columns by 40 rows
(`--tty=100x30` for another size), so it can be driven and seen with no terminal at all: from a
script, a test, or an AI agent working on it. Standard input is its keys, and its screens are
printed as `$screen.snapshot()`s: one wherever the keys say `snap`, and the last one when the
keys run out or the screen ends.

```bash
gaz --tty games/football/main.gaz 1 <<< '2 snap tab j snap c wait 3'
```

```
=== after: 2 ===
           10        20        30  ...
 1  Riverside FC   Wed 1 Jul 2026 · Pre-season · Premier Division ...
 2 ┌──────────────┐┌─ Squad ────────── ...
...
styles:
 4  2-15  inverse  " 2 Squad"
```

The keys are words, separated by spaces or lines, so a longer script can be a file
(`gaz --tty app.gaz < keys.txt`):

- A key is written as `term::name()` writes it: a character (`j`, `J`, `2`, `?`), or `enter`,
  `tab`, `backspace`, `space`, `escape`, `up`, `down`, `left`, `right`, `home`, `end`, `insert`,
  `delete`, `page_up`, `page_down`, `f1` to `f12`, after `ctrl+`, `alt+` or `shift+` when held
  (`ctrl+c`, `shift+tab`).
- `"text"` in double quotes is typed a character at a time, `\"` and `\\` in it for a quote and
  a backslash; it is also how to type a word that means something else (`"snap"`, `"enter"`).
- `wait 1.5` lets that many seconds pass. The pretend terminal keeps its own clock, which moves
  only then, so a screen run by a beat (a clock, a match being played) moves exactly as far
  every time, and the same program with the same keys prints the same screens.
- `snap` prints the screen as it is, headed by the keys that led to it.

A word that isn't one of these stops the program before it runs, with exit code 2. A full-screen
program run without a terminal says to use `--tty`; `tui::interact()` does all of this, so a
program made with it needs nothing of its own, and one that draws its own escape sequences gets
the keys and the clock but no screens.
