# The GazLang language

A reference. [The README](../README.md) is the tour; this is the whole thing, briefly. Why any
of it is the way it is lives in [CLAUDE.md](../CLAUDE.md), which is much longer and much more
opinionated.

## Values

- **Ints**: `42`, `-7`, `0xFF`. 64-bit, and they never silently overflow — a literal or a
  result that will not fit is an `Integer overflow` error, where PHP would switch to a float.
- **Floats**: `1.5`, `2e-3`, `3E+2`. Always finite; there is no INF or NAN. A float needs
  digits on both sides of the dot, so `1.` and `.5` are errors. Printing gives the shortest
  digits that read back as the same float (`0.30000000000000004`, `1.0`, `-0.0`).
- **Strings**: byte strings, so `len("é")` is 2 and `upper` is ASCII. UTF-8 passes through
  untouched.
- **Booleans**: `true` and `false`. A bool is not a number: `true == 1` is false, and
  `true + 1` is an error. Use `to_int(true)`.
- **`null`**: a keyword. It equals only itself, so `null == 0` and `null == false` are false.
- **Lists, maps, functions, classes and objects**, below.

`type_of($x)` gives `int`, `float`, `string`, `bool`, `null`, `list`, `map`, `function`,
`class` or `object`.

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

class Token {
    const EOF = "EOF";
    const ENDS = [#EOF, Token.EOF .. "!"];
    fn is_eof($type) { return $type == #EOF; }     // #NAME inside the class
}
echo AREA .. " " .. Token.EOF;                       // Class.NAME outside it
```
```
12 EOF
```

`const NAME = value;` at the top level or in a class. The value is whatever needs nothing but
the source: literals, every operator, `?:`, lists and maps, and other constants. No variables, no
calls, no indexing. It is worked out before the program runs, so `const X = 1 / 0;` is an error
at that line whether or not `X` is ever used, and so is a constant that depends on itself.

A constant can be used before it is declared, like a function. A mistyped one is an error before
the program runs (`Undefined function or constant: WDITH`), which is the reason to give a string a name.
Nothing can change one: `X = 2` and `X[0] = 2` are syntax errors, and since lists and maps are
values, `$copy = KINDS; $copy[] = 1;` changes the copy.

A top level constant shares the namespace of functions and classes. A class's constant shares
the one its fields and methods are in, is inherited, and can't be declared again by a child. It
is reached by name, `Token.EOF` or `#EOF`, not through a value: `$class.EOF` and `$token.EOF`
are not constants.

## Strings

Double quotes interpolate; single quotes are raw (only `\'` and `\\` are escapes, every other
backslash is kept).

```gaz
echo "Hi $name";                  // a bare $name, greedily
echo "Hi $rows[0] and $m[key]";   // one PHP-style index after a bare name
echo "{$user.name} owes {@total + 1}";
```

Braces interpolate any expression that **starts with a sigil** — `{$…}`, `{@…}`, `{#…}`.
Anything else is literal, so `{round($n, 2)}` prints as written; assign it to a variable first,
or start the expression with a sigil and call from there (`{$o.shout() .. to_string($n)}`).
A lone `$`, `$5` and `me@example.com` are all literal too.

This is PHP's rule, and for PHP's reason: `{` has to stay literal so a string holding JSON, CSS
or braces needs no escaping. GazLang is the more permissive of the two, since `{$n + 1}` is a
parse error in PHP and works here.

Escapes in `"..."`: `\n \t \r \v \f \e \0 \\ \" \$ \{`, `\xHH` (exactly two hex digits) and
`\u{H…}` (1 to 6 hex digits, written out as UTF-8). Any other escape is an error.

`..` concatenates, converting each side the way `echo` does, so `"x" .. true` is `"xtrue"` and
`1 .. 2` is `"12"`. `..=` appends.

## Lists and maps

```gaz
$list = [1, 2, 3];                       // values at 0, 1, 2...
$map  = {"key" => 1, 5 => "five"};       // values by key, in insertion order
```

Keys are ints or strings, and `"1"` and `1` are different keys. Both types are **values**:
assigning or passing one copies it.

```gaz
$list[0]            $map["key"]          // read; missing is an error
$list[] = 4                              // append
$map["new"] = 1     $rows[0]["total"] = 5
delete $list[1];    delete $map["key"];
foreach ($map as $key => $value) { }
```

`...` spreads a list into a list literal, which is how lists are joined and prepended to:

```gaz
$all = [...$first, ...$rest];            // join
$list = [0, ...$list];                   // prepend
```

Only a list can be spread (anything else is an error at the `...`), and only into a list
literal: not into a map, a call's arguments or a pattern.

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
 `$a[] = v` appends and is only valid as an assignment
target; there is no `pop` — take the last element, then `delete` it.

Strings index the same way, by an int position, to a one character string, read only.

`==` compares lists element by element in order, and maps by the same keys with equal values in
any order. A list never equals a map, even `[] == {}`.

## Operators

By precedence, loosest first:

| Level | Operators |
| --- | --- |
| assignment | `=` `+=` `-=` `*=` `/=` `%=` `..=` `??=` `&=` `\|=` `^=` `<<=` `>>=` (right associative) |
| ternary | `$c ? $a : $b` (right associative) |
| coalesce | `??` |
| logical | `\|\|` then `&&` |
| equality | `==` `!=` `<=>` |
| relational | `<` `<=` `>` `>=` |
| concat | `..` |
| bitwise | `\|` then `^` then `&` |
| shift | `<<` `>>` |
| additive | `+` `-` |
| multiplicative | `*` `/` `%` |
| unary | `-` `!` `~` `++` `--` |
| postfix | `[index]` `(args)` `.name` |

- `+ - * /` are numbers only. `/` **always** gives a float (`6 / 2` is `3.0`); `intdiv()`
  divides ints. `%` is ints only, and its sign follows the left operand.
- `==` never converts between types. `"5" == 5` is false, `"1" != "01"`, `1 == 1.0` is true.
  There is no `===`. Ordering a string against a number is an error.
- `<=>` gives -1, 0 or 1, for comparison functions.
- `&& || !` short-circuit and return real booleans. Truthiness is C-like for numbers, and a
  string is true unless empty — so `"0"` is true.
- `& | ^ << >> ~` are ints only. They bind tighter than the comparisons, as in Rust and Python,
  so `$flags & MASK == 0` means `($flags & MASK) == 0`. A shift count must be 0 to 63.
- `$a ?? $b` gives `$a` unless it is null or missing; an undefined variable or a missing key on
  its left is `null` rather than an error. `0`, `false` and `""` are kept.

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
matching one are evaluated, and nothing matching with no `default` is an error. Written as a
**statement**, an arm's body may be a block:

```gaz
match ($c) {
    "\"" => { read_string(); }
    "\\" => { @pos += 2; }
    default => fail("bad character")
}
```

## Functions

```gaz
fn add($a, $b = 1) { return $a + $b; }
```

Top level only, and callable before they are declared, so mutual recursion works. Defaults come
after the required parameters and are evaluated on each call that leaves the argument out.
A bare name is a value, builtins included:

```gaz
$f = add;      $f(1, 2);
$g = len;      $h["save"]($doc);      pick()(1, 2);
```

Anonymous functions are `->`:

```gaz
$double = $x -> $x * 2;
$sum    = ($a, $b = 1) -> $a + $b;
$noop   = () -> { return 42; };          // a block body returns only through return
```

A closure **owns** the variables it captured: it copies them in when created, and its calls
read and write them there, so they persist between calls and the enclosing scope never sees the
changes. A plain `=` inside the body makes a variable local to each call instead. A lambda
assigned with `$f = ...` can call `$f` to recurse.

## Objects

```gaz
abstract class Shape {
    #name;
    fn _($name) { #name = $name; }
    abstract fn area();
    fn to_string() { return "{#name} with area {#area()}"; }
}

class Circle extends Shape {
    #radius;
    fn _($radius) {
        ##_("circle");                   // the parent's constructor
        #radius = $radius;
    }
    fn area() { return 3.14159 * #radius * #radius; }
}

$c = Circle(2);                          // constructing is a call; there is no new
echo $c;                                 // circle with area 12.56636
echo is_a($c, Shape) .. " " .. $c.radius;
```

- **Classes** are top level only, single inheritance, usable before they are declared. They are
  values: `type_of(Point)` is `"class"`, and `class_of($obj)` gives an object's own class, so
  `match (class_of($node)) { NumAST => ..., AddAST => ... }` dispatches from outside the classes.
- **Fields are declared** (`#x;` or `#x = default;`). A default is evaluated per object, so a
  `[]` default is never shared. Reading one never set is an error; `??` reads it as null.
- **`#` is this object**, `#name` a field or method, `##name` the parent's version of a method.
  All checked at parse time against the class.
- **Objects are handles**: `$b = $a; $b.x = 1` changes `$a`. `==` is identity. Lists and maps
  inside them are still values.
- **`.` reads and writes members**: `$user.name`, `$rows[0].total = 5`, `$obj.method(args)`.
  `$obj.method` on its own is a bound method.
- **`to_string()`** is the one protocol method: `echo`, `..`, interpolation and `join` use it.

## Errors

```gaz
class NotFound extends Error {
    #key;
    fn _($key) { ##_("Not found: {$key}"); #key = $key; }
}

try {
    error(NotFound("id"));
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
- **`Error` is a builtin class** with `#message`, `#file`, `#line` and `#trace`. Programs
  extend it; `catch (Type $e)` matches a class or a subclass, and an untyped `catch` must be
  last.
- **`error($value)` throws any value.** A string becomes an `Error`'s message; anything else is
  caught as it is.
- **`#trace`** is the calls that were running, innermost first, as a list of strings. An
  uncaught error prints it under the message.
- **`finally`** runs however the block is left, including on `return`, `break` and `continue`.
- **`exit($code)`** is not an error and `try` does not see it.

## Comments

`// to the end of the line`, and `/* ... */`, which **nest** — an inner `/*` opens another one
and the first `*/` closes only that, so commenting out a region that already holds a comment
works. An unterminated block comment is an error at the line it opened on.

## Names

Keywords are lowercase and matched exactly, so `class If`, `fn Return()` and `$while` are all
ordinary names. Writing a keyword in the wrong case says so. Sigils and member names have their
own namespaces, so `$default`, `@match` and `fn match()` were always fine.

Functions, classes, builtins and top level constants share one namespace, and so do all of a
class's fields, methods and constants across its hierarchy.

## Builtins

**Strings** — `len`, `slice($x, $start, $length)`, `lower`, `upper`, `trim`, `split($s, $sep)`,
`join($list, $sep)`, `replace($s, $search, $replacement)`, `contains`, `starts_with`,
`ends_with`, `index_of($s, $needle, $offset)`, `repeat($s, $count)`, `chr`, `ord`.

**Numbers** — `to_int`, `to_float`, `to_string`, `floor`, `ceil`, `round($x, $precision)`,
`abs`, `intdiv`, `min($a, $b)`, `max($a, $b)`.

**Lists and maps** — `len`, `slice`, `in_array($value, $list)`, `has_key($x, $key)`, `keys`,
`values`.

**Types** — `type_of`, `is_a($x, Class)`, `class_of($x)`, `fields($object)` (the fields that
are set, as a map by name, the parent's first; a never-set field is left out).

**Input and output** — `print`, `print_error`, `read_file($path)`,
`write_file($path, $string)`, `read_stdin()`, `args()`, `builtins()` (every builtin's name
mapped to its parameter count, or `[fewest, most]` when some are optional).

**Paths** — `cwd()` is the working directory, which relative paths are resolved from.
`real_path($path)` is the absolute path with every symlink, `.` and `..` resolved (a directory
too), and an error when there is nothing there; `file_exists($path)` is whether there is, so
`file_exists("a/../b")` is false when `a` is missing, as the system sees it.

**Control** — `error($value)`, `exit($code = 0)`.

## Libraries

`include "lib/json.gaz";` splices a file in at parse time, relative to the including file. Each
file is included once, which also breaks cycles. Everything in `lib/` is written in GazLang:

| File | What is in it |
| --- | --- |
| `functional.gaz` | `map`, `filter`, `reduce`, `sort` (stable merge sort, takes a comparator) |
| `sort.gaz` | `sort_values`, `sort_by` |
| `json.gaz` | `json_decode`, `json_encode` |
| `csv.gaz` | `csv_parse`, `csv_records` (RFC 4180) |
| `chars.gaz` | `char_at`, `is_digit`, `is_alpha`, `is_alnum`, `is_space`, `is_hex_digit` |
| `format.gaz` | `pad_left`, `pad_right` |
