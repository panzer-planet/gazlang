# The GazLang language

A reference. [The README](../README.md) is the tour; this is the whole thing, briefly. Why any
of it is the way it is lives in [CLAUDE.md](../CLAUDE.md), which is longer and more
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
- **Lists, maps, functions, kinds and objects**, below.

`type_of($x)` gives `int`, `float`, `string`, `bool`, `null`, `list`, `map`, `function`,
`kind`, `object` or `socket`.

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
    const EOF = "EOF";
    const ENDS = [#EOF, Token::EOF .. "!"];
    fn is_eof($type) { return $type == #EOF; }     // #NAME inside the kind
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
the one its fields and methods are in, is inherited, and can't be declared again by a child. It
is reached by name, `Token::EOF` or `#EOF`, not through a value: `$kind.EOF` and `$token.EOF`
are not constants. `::` resolves a name and `.` goes through a value, so `Token.EOF` is an error
that says to write `Token::EOF`. A `:` followed by a `:` is always `::`, so a ternary needs a
space: `$c ? Token::EOF : $x`.

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

A map spreads into a map literal the same way, later entries winning, so options over
defaults, or a copy with a change, is one literal:

```gaz
$settings = {...$defaults, ...$options};
$moved = {...$player, "club" => "Harbour City"};
```

A key already there keeps its place and takes the later value. Only a list spreads into a
list and only a map into a map (anything else is an error at the `...`), and nowhere else:
not into a call's arguments or a pattern.

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
target; there is no `pop` — take `last($list)`, then `delete` it.

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
| postfix | `[index]` `(args)` `.name` `::name` |

- `+ - * /` are numbers only. `/` **always** gives a float (`6 / 2` is `3.0`); `intdiv()`
  divides ints. `%` is ints only, and its sign follows the left operand.
- `==` never converts between types. `"5" == 5` is false, `"1" != "01"`, `1 == 1.0` is true.
  There is no `===`. Ordering a string against a number is an error.
- `<=>` gives -1, 0 or 1, for comparison functions.
- Lists order element by element: the first pair that differs decides, and a shorter list the
  other starts with comes first (`[1, 2] < [1, 3]`, `[1] < [1, 0]`). That makes a sort by
  several keys one comparison, with `$a` and `$b` swapped in an element to sort it the other
  way: `sort($teams, ($a, $b) -> [$b.points, $a.name] <=> [$a.points, $b.name])` is most points
  first, then by name. Maps can't be ordered.
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

A parameter can be a list pattern, which takes its argument apart as `[$a, $b] = $x` would
(exactly that many elements, or an error), in a lambda, a function or a method:

```gaz
echo map($scores, ([$name, $points]) -> "{$name}: {$points}");
fn distance([$x1, $y1], [$x2, $y2] = [0, 0]) { return abs($x2 - $x1) + abs($y2 - $y1); }
```

A closure **owns** the variables it captured: it copies them in when created, and its calls
read and write them there, so they persist between calls and the enclosing scope never sees the
changes. A plain `=` inside the body makes a variable local to each call instead. A lambda
assigned with `$f = ...` can call `$f` to recurse.

## Objects

```gaz
abstract kind Shape {
    kin #name;                           // Shape's and its children's, not anyone else's
    fn _($name) { #name = $name; }
    pub abstract fn area();
    pub fn to_string() { return "{#name} with area {#area()}"; }
}

kind Circle extends Shape {
    fn _(pub #radius) {                  // pub #radius: a field, set from the parameter
        ##_("circle");                   // the parent's constructor
    }
    pub fn area() { return 3.14159 * #radius * #radius; }
}

$c = Circle(2);                          // constructing is a call; there is no new
echo $c;                                 // circle with area 12.56636
echo is_a($c, Shape) .. " " .. $c.radius;
```

- **Kinds** are top level only, single inheritance, usable before they are declared. They are
  values: `type_of(Point)` is `"kind"`, and `kind_of($obj)` gives an object's own kind, so
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
- **A member is private unless `pub`**, the same word and the same meaning as a namespace's:
  this name escapes the thing it is written in. The ladder is unmarked (mine) → `kin` (mine and
  my children's) → `pub` (anyone's), and it applies to a field, method, constant or static
  alike: `kin #energy = 100;`, `pub static #tally = 0;`. `#name` and `##name` are checked at
  parse time, `$obj.name` when it runs, both against the kind the code asking is written in —
  a lambda's and a static method's is the kind they sit in.
- **`kin` is for what a kind declares on its children's behalf**: a field they set, a method
  they call, a hook they define. `protected` earns a rename where `extends` does not, since it
  famously protects less than the default does, and a level is better named after who can see
  it. `public` and `protected` stay reserved and say to write `pub` and `kin`; `private` says a
  member needs no marker to be its kind's own.
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
- **`fields()` and `echo` are not member access** and show every field that is set, whatever it
  escapes: reflection exists so a pass can walk an object without knowing its kind.
- **`to_string()`** is the one protocol method: `echo`, `..`, interpolation and `join` use it.

## Errors

```gaz
kind NotFound extends Error {
    pub #key;
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
- **`Error` is a builtin kind** with `#message`, `#file`, `#line` and `#trace`. Programs
  extend it; `catch (Type $e)` matches a kind or a child kind, and an untyped `catch` must be
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

Keywords are lowercase and matched exactly, so `kind If`, `fn Return()` and `$while` are all
ordinary names. Writing a keyword in the wrong case says so. Sigils and member names have their
own namespaces, so `$default`, `@match` and `fn match()` were always fine.

Functions, kinds, builtins and top level constants share one namespace, and so do all of a
kind's fields, methods and constants across its hierarchy.

## Builtins

**Strings** — `len`, `slice($x, $start, $length)`, `lower`, `upper`, `trim`, `split($s, $sep)`,
`join($list, $sep)`, `replace($s, $search, $replacement)`, `contains`, `starts_with`,
`ends_with`, `index_of($s, $needle, $offset)`, `repeat($s, $count)`, `chr`, `ord`.

**Numbers** — `to_int($x, $default)`, `to_float($x, $default)` (without a default, a string
that isn't a number is an error; with one, it gives the default: `to_int($arg, null) ?? 1`; a
null, list or map is an error either way), `to_string`, `floor`, `ceil`, `round($x, $precision)`,
`abs`, `intdiv`, `min($a, $b)`, `max($a, $b)`, and `min($list)`, `max($list)` and `sum($list)`
over a list's or map's values (`sum([])` is 0; `min` and `max` of nothing is an error; `sum`
adds with `+`, so an int overflowing or a string in the list is `+`'s error).

**Lists and maps** — `len`, `slice`, `in_array($value, $list)`, `has_key($x, $key)`, `keys`,
`values`, `last($list)` (the last element; an empty list is an error), `reverse($x)` (a list or
a string backwards, a string byte by byte, or a map's entries in the other order, keeping their
keys), and four that call a function, lambda, bound method, builtin or kind for each
element, in order:

- `map($x, $f)` — `$f($value)` for each; a list gives a list, a map a map with the same keys.
- `filter($x, $keep)` — the elements for which `$keep($value)` is true, as `if` reads it; a
  list gives a list, a map a map with the kept keys.
- `reduce($x, $f, $initial)` — folds the values left: `$carry = $f($carry, $value)`.
- `sort($x, $compare)` — the values in a new list, ordered by `$compare($a, $b)`, which returns
  an int below zero when `$a` comes first, as `$a <=> $b` does; anything but an int is an
  error. Stable: a merge sort that splits in the middle and asks `$compare(right, left)`,
  taking from the right only when that is below zero, so a comparator that prints shows the
  same calls on every runtime.

An error in the function comes out of the builtin, and its trace goes from the function
straight to where the builtin was called.

**Types** — `type_of`, `is_a($x, Kind)`, `kind_of($x)`, `fields($object)` (the fields that
are set, as a map by name, the parent's first; a never-set field is left out), `object_id($object)`
(an int that no other object in the program has or had, the same every run: keep a set of
objects as `$seen[object_id($x)] = true`).

**Input and output** — `print`, `print_error`, `read_file($path)`,
`write_file($path, $string)`, `read_stdin()`, `args()`, `builtins()` (every builtin's name
mapped to its parameter count, or `[fewest, most]` when some are optional).

**Paths** — `cwd()` is the working directory, which relative paths are resolved from.
`real_path($path)` is the absolute path with every symlink, `.` and `..` resolved (a directory
too), and an error when there is nothing there; `file_exists($path)` is whether there is, so
`file_exists("a/../b")` is false when `a` is missing, as the system sees it.

**Programs** — `run($argv, $input = "")` starts a program and waits for it: `$argv` is a list
of strings, the program (found on `PATH` unless it has a `/`) and then its arguments, passed as
they are, with no shell to read `;`, `$` or `*` in them. It inherits the environment and the
working directory, reads `$input` as its standard input (any bytes, any size), and gives
`{"status" => 0, "stdout" => "...", "stderr" => "..."}`, both outputs whole. The status is its
exit code, or minus the signal's number when a signal killed it (`-9`). A program that can't be
started is an error (`Cannot run "nope": No such file or directory`), as are an empty list and
an argument that isn't a string or holds a NUL byte.

```
$r = run(["git", "log", "-1", "--format=%s"]);
if ($r["status"] != 0) { error($r["stderr"]); }
```

**Sockets** — a connection over TCP, or TLS for `$tls = true`:

- `socket_open($host, $port, $tls = false, $timeout = 30)` — connects, trying each address the
  name has, and gives a `socket`. With TLS the server's certificate must be one the system
  trusts (or that the file `SSL_CERT_FILE` names) and must name `$host`; TLS 1.2 or later.
  `$timeout` (seconds, an int or a float) bounds connecting and each read and write.
- `socket_read($socket)` — what has arrived, up to 64KB, waiting for something to; `""` once
  the other end has closed.
- `socket_write($socket, $string)` — sends all of it.
- `socket_close($socket)` — closes it; closing again does nothing. A socket no variable holds
  any more is closed too.

A socket is a handle: copies share the connection, `==` is identity, and it prints as `socket`
(`socket (closed)`). Failing to find the host or connect, a certificate that doesn't check out,
a timeout, and reading or writing a closed socket are errors. A gazlang built with `make TLS=0`
has no TLS, and `$tls = true` is an error.

**The terminal** — what a program needs to be interactive and GazLang can't do itself. Drawing
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

`term_read` reads the descriptor, not the buffer `read_stdin()` fills, so use one or the other.

**Control** — `error($value)`, `exit($code = 0)`.

**Random numbers** — not cryptographically secure: for games, simulations and sampling, never
for passwords, tokens or keys.

- `rand_int($min, $max)` — an int from `$min` to `$max`, both included; `$max < $min` is an
  error.
- `rand_float()` — a float from `0.0` up to but not including `1.0`.
- `rand_seed($seed = null)` — restarts the sequence from an int seed, so a program draws the
  same numbers on every run and on both runtimes; with no seed, from an unpredictable one taken
  from the operating system. Every program starts as if it had called `rand_seed()`.

The generator is xoshiro256\*\*, seeded from the int through SplitMix64 (as PHP's
`Random\Engine\Xoshiro256StarStar` does). How its 64-bit outputs become numbers is GazLang's
own rule: `rand_float()` is the top 53 bits divided by 2^53; `rand_int()` takes the span
`$max - $min` as an unsigned 64-bit number, masks each output down to the bits the span uses,
and draws again until the result is at most the span, then adds it to `$min`, so every int in
the range is equally likely. `lib/random.gaz` builds shuffling and picking on these.

## Statics

A static belongs to the kind rather than to an object: a field is one slot the kind owns, and
a method a function that needs no object. Both are reached by name, with `::`.

```gaz
kind Counter {
    static #count = 0;                // one slot, not one per object
    static #limit = 2 * 5;            // its value is a constant expression
    #id;                              // an ordinary field, one per object

    fn _() { #count++; #id = #count; }
    static fn next() { #count++; return #count; }
    fn mine() { return "{#id} of {#count}"; }
}

kind Tally extends Counter {}        // shares the same slot

Counter(); Counter();
echo Counter::count .. " " .. Tally::count;
echo Counter::next();
Counter::count = 100;                 // a slot, so it can be written from anywhere
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
- **Assigned from anywhere the static escapes to**: `Counter::count = 1`, `Counter::count++`,
  `Counter::rows[] = $r` and `delete Counter::rows[0]` all work on a `pub static`, and inside
  `Counter` on one that says nothing. A kind constant is still not a slot, so
  `Counter::LIMIT = 1` is an error.
- **A child shares its parent's static** and can't declare one again, as with a constant: every
  member shares one namespace across the hierarchy, statics included.
- **Reached by name only**: `$counter.count` is not a static, and `$counter::next()` puts a
  value on the left of a parse-time operator, which is an error. `#next` without calling it is
  an error too; write `Counter::next` for the function.

## Namespaces

`namespace json;` first in a file, at most one, optional. A file without one declares its names
globally, which is what every file did before namespaces and what a small program still wants.

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
or a kind. Privacy is per namespace rather than per file, so several files can declare the same
namespace and go on seeing everything of each other's.

```gaz
include "lib/json.gaz";                              // json:: becomes reachable
include "lib/chars.gaz" use is_digit, char_at as at; // and these two, unqualified

echo json::decode("1");
echo is_digit("4") .. at("abc", 0);
echo json::scan("1");                               // Error: json::scan is not pub
```

Including a file always makes its namespace reachable qualified. A `use` clause only adds
aliases, and its names are bare, since the string already said which file they come from. There
is no standalone `use` and no `use ns::*`, so a file can only name what it includes itself:
naming a namespace that a file it includes happens to include is an error.

**Resolution** is the current namespace, then this file's aliases, then the global namespace,
where the builtins are. There is no fallback into another namespace. Only a name's first part is
resolved, since a namespace holds no namespace: inside `namespace gazlang`, `Token::EOF` is
`gazlang::Token::EOF`, while `json::decode` is already what it means.

A namespace's own name wins over a builtin of that name inside it, so declaring
`pub fn values()` in `namespace sorting` makes `values($x)` mean `sorting::values($x)` in that
file; write `sorting.gaz`'s own calls to the builtin as they are meant, or pick another name.

`::` resolves a name and `.` goes through a value, so `json::decode` and `Token::EOF` are names
the parser works out, and `$reader.decode` is a member of whatever `$reader` holds. A `:`
followed by a `:` is always `::`, so a ternary needs a space: `$c ? Token::EOF : $x`.

Namespaces are resolved by the parser, so the VM never learns the word: bytecode only sees
longer names.

## Libraries

`include "lib/json.gaz";` splices a file in at parse time, relative to the including file. Each
file is included once, which also breaks cycles. Every file in `lib/` declares a namespace, so
its names are reached with `::`; everything in `lib/` is written in GazLang:

| File | What is in it |
| --- | --- |
| `sorting.gaz` | `sorting::values`, `sorting::by` |
| `lists.gaz` | `lists::flatten` (a list of lists as one list, one level deep), `lists::max_by($xs, $key)` and `lists::min_by` (the element whose `$key($x)` is largest or smallest, the first on a tie; a list of keys breaks ties in order) |
| `json.gaz` | `json::decode`, `json::encode` |
| `csv.gaz` | `csv::parse`, `csv::records` (RFC 4180) |
| `chars.gaz` | `chars::char_at`, `chars::is_digit`, `chars::is_alpha`, `chars::is_alnum`, `chars::is_space`, `chars::is_hex_digit` |
| `format.gaz` | `format::number`, `format::pad_left`, `format::pad_right` |
| `http.gaz` | `http::get($url, $headers = {})`, `http::post($url, $body, $headers = {})`, `http::request($method, $url, $headers = {}, $body = null)`, HTTP/1.1 on the socket builtins; see below |
| `random.gaz` | `random::shuffle` (a shuffled copy of a list or string), `random::pick` (an element of a list or value of a map), `random::key`, `random::chance($p)`, `random::weighted` (from `[item, weight]` pairs) |
| `regex.gaz` | `regex::matches($s, $pattern)` (full match), `regex::search($s, $pattern)` (found anywhere), `regex::find($s, $pattern)` (the start index, or null); literals, `.`, `* + ?`, `\|`, `(...)`, `[...]`/`[^...]` with ranges, `^ $`, `\` escapes; no captures, no backreferences, no backtracking |
| `term.gaz` | `term::style`, cursor and screen sequences, `term::decode`, `term::Input`, `term::fullscreen`, on the terminal builtins; see below |
| `tui.gaz` | `tui::Screen` (a grid of cells that renders only what changed), `tui::box`, `tui::label`, `tui::progress`, `tui::table`, `tui::Menu`, `tui::TextField`, `tui::choose`, `tui::ask`; see below |

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

```
include "lib/http.gaz";

$r = http::post("https://example.com/api", "{\"n\": 1}", {"Content-Type" => "application/json"});
echo $r["status"] .. " " .. $r["headers"]["content-type"];
```

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

```
include "lib/term.gaz";

term::fullscreen(() -> {
    $input = term::Input();
    print("press a key, q to quit");
    while (true) {
        $key = term::name($input.read());
        if ($key == "q" || $key == "ctrl+c" || $key == "eof") { break; }
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
- **`tui::Menu($items, $title = "")`** and **`tui::TextField($value = "")`** take keys
  (`handle($key)`) and draw themselves (`draw(...)`). `handle` gives `"select"` or `"cancel"` for
  a menu and `"submit"` or `"cancel"` for a field, and `null` for a key it dealt with itself: the
  arrows, `j`/`k`, home and end, page up and down; typing, backspace, delete, the arrows, home and
  end or ctrl+a and ctrl+e, ctrl+u and ctrl+k. `$menu.index()` and `$menu.item()` say what is
  selected and `$field.value()` what was typed.
- **`tui::interact($draw, $handle, $input = null)`** runs `$draw($screen)` and `$handle($key)` on a screen of its
  own until `$handle` gives something other than `null`, which is what it gives (`null` too
  when the input ends). Keys come from `$input`, a `term::Input`, or a new one; a program that
  shows one screen after another passes the same one to each, since keys typed ahead are in it and
  a new one for each screen would lose them. Two lambdas can't share a variable they change, so the state lives in an
  object; `examples/dashboard.gaz` is one. **`tui::choose($items, $title = "", $input = null)`**
  (the index picked, or `null` if cancelled or there is nothing to pick) and
  **`tui::ask($question, $initial = "", $input = null)`** (the answer, or `null`) are a
  `Menu` and a `TextField` run that way, in the middle of the screen. All three need a terminal.
