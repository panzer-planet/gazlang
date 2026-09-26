# GazLang

[![CI](https://github.com/panzer-planet/gazlang/actions/workflows/ci.yml/badge.svg)](https://github.com/panzer-planet/gazlang/actions/workflows/ci.yml)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**A scripting language that would rather stop than guess.**

If you have written PHP, JavaScript or Python, you can read GazLang already. What makes it
different is what it refuses to do: it never quietly turns a string into a number, never
overflows an integer into a float, and never hands you a zero because a key was missing. When
something is wrong, it says so, with the line it happened on and how it got there.

```gaz
$sentence = "the cat sat on the mat";
$counts = {};
foreach (split($sentence, " ") as $word) {
    $counts[$word] ??= 0;
    $counts[$word]++;
}
echo $counts;
echo $counts["dog"] ?? "no dogs here";
```

```
{"the" => 2, "cat" => 1, "sat" => 1, "on" => 1, "mat" => 1}
no dogs here
```

## Why you might like it

- **Loud, precise errors.** `"5" + 5` is an error, not `10` or `"55"`. A missing key is an
  error unless you ask for a default with `??`. A runtime error names its file and line, with
  a stack trace.
- **Types when you want them.** Write `fn total(int $n): float` or `pub string #owner` and the
  types are checked as the program runs, with errors that name the parameter:
  `total() expects $n to be int, got string`. Leave them out and nothing changes. PHP's syntax,
  with no silent conversion behind it ([the tour](#types)).
- **Values that behave like values.** Lists and maps are copied when you assign them, like
  numbers are, so nothing changes behind your back. Objects are handles, shared on purpose.
- **It compiles itself.** The lexer, parser and compiler are about 5,800 lines of GazLang,
  running on a VM of about 7,900 lines of plain C. Its libraries (OpenSSL for HTTPS, SQLite and
  libpq for databases) are all optional: `make -C vm TLS=0 SQLITE=0 PG=0` needs nothing but a C
  compiler.
- **It is quick.** It beats Python 3.13 by 1.3 to 2.9 times, and stays within 1.5 times of
  PHP 8.5 with its JIT, beating it on calls, closures, maps and strings
  ([numbers below](#how-fast-is-it)).
- **It is checked to the byte.** What every test program, snippet and corpus file prints,
  error messages included, is recorded, and thousands of tests hold gaz to it under
  AddressSanitizer and a leak check, on Linux and on both kinds of Mac. The compiler has to
  compile itself to exactly itself.
- **Batteries included, and self-hosted.** JSON, CSV, an HTTP/1.1 client with TLS and a
  preforking web server, SQLite and PostgreSQL, dates, a terminal UI toolkit, and regular
  expressions with no ReDoS (a Thompson NFA, not backtracking). The libraries are all in `lib/`,
  written in GazLang; C does only what GazLang can't, like sockets and the terminal
  ([the list](#what-comes-with-it)).

It is a hobby language, not production software, and it would like company.

## Get it running

Download a release for Linux or macOS from the
[releases page](https://github.com/panzer-planet/gazlang/releases), unpack it, and put `gaz` on
your `PATH` (those builds have TLS and SQLite, not PostgreSQL). Or build it: you need a C
compiler, make and OpenSSL (`apt install libssl-dev` or `brew install openssl@3`; or build with
`make -C vm TLS=0` for no HTTPS). SQLite and PostgreSQL support is
built in when their libraries are found (`libsqlite3-dev`, `libpq-dev`; `brew install sqlite
libpq`), and left out quietly when they aren't.

```bash
git clone https://github.com/panzer-planet/gazlang.git
cd gazlang && make -C vm

echo 'echo "hello";' > hello.gaz
bin/gaz hello.gaz
```

The build uses profile-guided optimisation when your compiler supports it (gcc, or clang with
`llvm-profdata`, which Xcode's command line tools have): it runs gaz on the compiler and on
sample programs, then compiles it again knowing which code is hot, for a few to ten percent. Without
it you get a plain optimised build; `make -C vm PGO=0` asks for that.

Then kick the tyres:

```bash
bin/gaz examples/pathfinding.gaz            # the fewest steps and the least effort across a map
bin/gaz examples/brainfuck.gaz              # a Brainfuck interpreter
bin/gaz tests/programs/csv_report.gaz tests/programs/data/sales.csv region amount
bin/gaz tests/programs/cat_facts.gaz list 5 # from a web API, over HTTPS
```

Other ways to run it:

```bash
bin/gaz program.gaz arg1 arg2      # arguments, read with args()
cat program.gaz | bin/gaz - arg1   # the program from standard input (no file does the same)
bin/gaz -c program.gaz > x.gzb     # print the compiled VM code, which runs as it is
bin/gaz x.gzb
bin/gaz --tokens program.gaz       # print the tokens
bin/gaz --ast program.gaz          # print the tree
```

A script can run as a command of its own: put `bin/` on your `PATH`, start the file with
`#!/usr/bin/env gaz`, and `chmod +x` it.

The tests are PHPUnit, so running them needs PHP 8.5 or later and `composer install`;
GazLang itself needs neither.

## A ten minute tour

### Variables and text

A `$` variable is local — to the function it is in, or to the top level. An `@` variable is
global, the same variable everywhere. The sigil tells you the scope, so there is nothing to
declare and nothing to look up.

```gaz
$name = "Ada";
@count = 3;

echo "Hi $name, you have {@count} messages";
echo "Next year: {@count + 1}";
```

```
Hi Ada, you have 3 messages
Next year: 4
```

Inside `"..."`, a bare `$name` interpolates, and braces take any expression that *starts with a
sigil* — `{$x}`, `{@count + 1}`, `{$user.name}`. Anything else is literal, so `{round($n, 2)}`
prints as it is written; put it in a variable first. Use `'...'` for a raw string, and `..` to
join values.

### Numbers that do not lie

```gaz
echo 7 / 2;             // always a float
echo intdiv(7, 2);      // ask for int division explicitly
echo 1 == 1.0;          // true: numbers compare by value
echo "5" == 5;          // false: a string is never a number
try {
    echo "5" + 5;
} catch (Error $e) {
    echo $e.message;
}
```

```
3.5
3
true
false
Cannot use + on string
```

`+ - * /` are for numbers only. Integers never silently become floats: a result that will not
fit is an `Integer overflow` error, not a quiet loss of precision. There is no `===`, because
`==` never converted anything in the first place.

### Operators worth knowing about

```gaz
$user = null;
echo $user ?? "guest";                     // ?? reads the left unless null or missing
echo $user?.address.city;                  // ?. : null the whole way if $user is null
echo (5 > 3) ? "yes" : "no";               // ?:, right associative, only the taken branch runs

[$first, $second] = [10, 20];              // a list pattern: unpack in one line
echo $first + $second;

echo 0xF0 & 0x3C;                          // bitwise & | ^ << >> ~, ints only
echo -7 % 3;                               // %, ints only, sign follows the left operand

$front = [1, 2];
$back = [3, 4];
echo [...$front, ...$back];                // spread into a list literal
```

```
guest
null
yes
30
48
-1
[1, 2, 3, 4]
```

### Lists and maps

```gaz
$sizes = [3, 1, 2];                       // a list: values at 0, 1, 2...
$price = {"apple" => 1.5, "pear" => 2};   // a map: values by key, in order

$sizes[] = 4;                             // append
$price["fig"] = 3.0;                      // add a key

foreach ($price as $fruit => $cost) {
    echo "{$fruit} costs {$cost}";
}
```

```
apple costs 1.5
pear costs 2
fig costs 3.0
```

They are two different types, and both are **values**, so assigning one copies it:

```gaz
$original = [1, 2];
$copy = $original;
$copy[] = 3;
echo $original;
echo $copy;
```

```
[1, 2]
[1, 2, 3]
```

Reading a key that is not there is an error, not `null`. Write `$m["k"] ?? "default"` when
missing is a normal thing to happen.

### Control flow

The usual `if` / `else if` / `else`, `while`, `for`, `foreach`, `break` and `continue`. Plus
`match`, which comes in two shapes. With a subject it compares arms with `==`; without one, the
arms are conditions:

```gaz
fn classify($n) {
    return match {
        $n < 0  => "negative",
        $n == 0 => "zero",
        $n < 10 => "small",
        default => "big"
    };
}

foreach ([-4, 0, 7, 99] as $n) {
    $kind = classify($n);
    echo "{$n} is {$kind}";
}
```

```
-4 is negative
0 is zero
7 is small
99 is big
```

### Functions

Declared with `fn`, at the top level, and callable before they are declared. Parameters can
have defaults. A bare name is a value, so functions can be passed around.

```gaz
$double = $x -> $x * 2;                   // an anonymous function
echo $double(21);

fn counter() {                            // it keeps the variables it captured
    $n = 0;
    return () -> ++$n;
}
$next = counter();
$next();
echo $next();
```

```
42
2
```

Parameters, return values and fields can have types too: see [Types](#types).

### Objects

Fields are declared, single inheritance, and constructing is just a call — no `new`. `#` is
this object, `#name` one of its fields or methods. A member is the kind's own unless it says
`pub` or `kin` (visible to children too) — the same ladder that governs a namespace.

```gaz
kind Account {
    #balance = 0;                         // Account's own: nothing outside can read it

    fn _(pub #owner) {}                   // a promoted parameter: field + assignment, in one

    pub fn deposit($amount) {
        #balance += $amount;
        return #;                         // # is this object
    }

    pub fn to_string() {
        return "{#owner}: {#balance}";
    }
}

$account = Account("Ada");
$account.deposit(50).deposit(25);
echo $account;
```

```
Ada: 75
```

Single inheritance, with `##` reaching the parent's version of a method and a constructor
calling `##_(...)` to run the parent's:

```gaz
const PI = 3.14159;

kind Point {
    fn _(pub #x, pub #y) {}

    pub fn to_string() {
        return "({#x}, {#y})";
    }
}

kind Circle extends Point {
    fn _($x, $y, #radius) {
        ##_($x, $y);                      // the parent's constructor
    }

    pub fn area() {
        return PI * #radius * #radius;
    }

    pub fn to_string() {
        $area = round(#area(), 2);
        return "{##to_string()} r={#radius} area={$area}";
    }
}

$circle = Circle(1, 2, 3);
echo $circle;
echo $circle.x .. "," .. $circle.y;       // #x is pub, so it reaches outside the kind
```

```
(1, 2) r=3 area=28.27
1,2
```

Fields and methods can be `static`, shared by a kind and every child rather than per object:

```gaz
kind Counter {
    static #count = 0;

    static fn next() {
        return ++Counter::count;
    }
}

echo Counter::next();
echo Counter::next();
```

```
1
2
```

### Types

Types are optional: write them where they help, leave them out where they don't. They go where
PHP puts them, and they are checked as the program runs, at the edges: when a function is called,
when it returns, and whenever a field is written. Nothing is converted to fit, except that an int
is welcome where a float is asked, and arrives as one.

```gaz
kind Account {
    pub float #balance = 0;

    fn _(pub string #owner) {}

    pub fn deposit(int|float $amount): Account {
        #balance += $amount;
        return #;
    }
}

fn describe(Account $account, ?string $note = null): string {
    $text = "{$account.owner} has {$account.balance}";
    if ($note != null) {
        $text ..= " ($note)";
    }
    return $text;
}

fn attempt($action): null {
    try {
        $action();
    } catch (Error $e) {
        echo $e.message;
    }
}

$account = Account("Ada");
$account.deposit(50);
echo describe($account);
echo describe($account.deposit(25), "after payday");

attempt(() -> $account.deposit("lots"));
attempt(() -> Account(42));
attempt(() -> $account.balance = "a lot");
```

```
Ada has 50.0
Ada has 75.0 (after payday)
Account.deposit() expects $amount to be int|float, got string
Account() expects $owner to be string, got int
Account #balance must be float, got string
```

A type is a `type_of()` name (`int`, `string`, `list`, `map`...) or a kind, which its children
fit too. `?string` means a string or null, `int|float` either, and `: null` is a function that
returns nothing. Generics like `list<int>` aren't here yet: they are planned as a check made
before the program runs, since checking every element on every call would cost too much.

### Errors

Any runtime failure is catchable, and `Error` is a real kind you can extend.

```gaz
kind NotFound extends Error {
    fn _(pub #key) {
        ##_("No such fruit: {$key}");
    }
}

fn price($prices, $fruit) {
    if (!has_key($prices, $fruit)) {
        error(NotFound($fruit));
    }
    return $prices[$fruit];
}

$prices = {"apple" => 1.5};
try {
    echo price($prices, "apple");
    echo price($prices, "durian");
} catch (NotFound $e) {
    echo "{$e.message} (line {$e.line})";
}
```

```
1.5
No such fruit: durian (line 9)
```

Let one escape and you get the line it happened on and the calls that led there:

```gaz
fn inner() {
    $list = [1];
    return $list[5];
}

fn outer() {
    return inner();
}

echo outer();
```

```
Error: Index out of range: 5 at trace.gaz:3
  inner at trace.gaz:3
  outer at trace.gaz:7
  top level at trace.gaz:10
```

## Something real

The standard library is written in GazLang itself ([what is in it](#what-comes-with-it)). Here
is a sales report in thirty lines, on its CSV reader and number formatting:

```gaz
include "std/csv.gaz";
include "std/format.gaz";

// The sum of $column for each value of $group
fn totals_by($rows, $group, $column) {
    $totals = {};
    foreach ($rows as $row) {
        $key = $row[$group];
        $totals[$key] ??= 0.0;
        $totals[$key] += to_float($row[$column]);
    }
    return $totals;
}

fn largest_first($totals) {
    return sort(keys($totals), ($a, $b) -> $totals[$b] <=> $totals[$a]);
}

try {
    $file = args()[0] ?? "sales.csv";
    $rows = csv::records(csv::parse(read_file($file)));
    $totals = totals_by($rows, "region", "amount");

    foreach (largest_first($totals) as $region) {
        $total = round($totals[$region], 2);
        echo format::pad_right($region, 8) .. $total;
    }
} catch (Error $e) {
    print_error("{$e.message}\n");
    exit(1);
}
```

```
South   4560.49
East    3436.09
North   1524.0
West    899.95
```

## What comes with it

The standard library is written in GazLang and built into `gaz`, so any program anywhere
reaches it by name, and each file keeps its names in its own namespace:

```
include "std/json.gaz";
echo json::encode({"ok" => true});
```

| Library | What it gives you |
| --- | --- |
| `std/json.gaz` | `json::decode` and `json::encode`, matching PHP's `json_decode` on the JSON test suite; an object is written as its `to_json()` |
| `std/csv.gaz` | `csv::parse` (RFC 4180, quotes and line breaks in fields) and `csv::records`, rows as maps by header |
| Templates (`.gazml`) | HTML with `{{ $escaped }}`, `@if` and `@foreach`, compiled into a function when included: `include "views/page.gazml";` then `page($title)`. Output is escaped unless it is another template's |
| `std/cli.gaz` | Command line arguments: flags, options, arguments and subcommands declared once, with `--help` written for you and a usage mistake an exit 2 |
| `std/router.gaz` | `router::Router()`: `$app.get("/users/:id", $handler)`, 404s, 405s and redirects for a stray trailing slash handled, and middleware |
| `std/http.gaz` | An HTTP/1.1 client, `http::get`/`post`/`request` with https, redirects and chunked bodies; and a server, `http::serve($listener, $handler)`, which turns away malformed requests before your handler sees them, with `http::query` and `http::form` to decode what they carry |
| `std/db.gaz` | `db::open("sqlite:app.db")` or `postgres://...`, then `query`, `row`, `value`, `exec` and `transaction`, parameters always bound |
| `std/regex.gaz` | `regex::matches`, `search`, `find`, `groups` and `replace`: classes, ranges, anchors, alternation, capture groups, and matching in linear time |
| `std/date.gaz` | Calendar dates as day numbers: `date::days(2026, 8, 8)`, weekdays, adding months, `date::format` |
| `std/format.gaz` | `format::number(1234.5)` → `1,234.50`, `pad_left`, `pad_right`, and `format::sprintf("%-6s%5.1f", [$name, $score])` |
| `std/lists.gaz`, `std/sorting.gaz` | `flatten`, `unique`, `max_by`/`min_by`; `sorting::by($rows, "points", true)` |
| `std/random.gaz` | `shuffle`, `pick`, `chance`, `weighted` |
| `std/chars.gaz` | Character classes (`is_digit`, `is_alpha`, ...) and `span`, for writing scanners |
| `std/term.gaz`, `std/tui.gaz` | Colours, cursor and keys; a screen that redraws only what changed, boxes, tables, menus, text fields |

And built into the language, with no include:

| Builtins | |
| --- | --- |
| Strings, lists and maps | `len`, `slice`, `split`, `join`, `replace`, `index_of`, `starts_with`, `trim`, `upper`; `map`, `filter`, `reduce`, `sort`, `keys`, `values`, `in_array`, `sum`, `min`, `max` |
| Files and programs | `read_file`, `write_file`, `file_exists`, `list_dir`, `make_dir`, `delete_file`, `read_stdin`, `read_line`, `args`, `getenv`, and `run(["git", "status"])`, which starts a program with no shell in between |
| Sockets and servers | `socket_open` (TCP or TLS), `socket_listen`, `socket_accept`, and `workers($n)`, which runs a server as n processes, restarting any that crash and stopping gracefully |
| Databases | `db_open`, `db_run`, `db_close`, for SQLite and PostgreSQL |
| Time and chance | `time`, `monotonic_time`, `sleep`, `rand_int`, `rand_float`, `rand_seed` |
| The terminal | `term_raw`, `term_read`, `term_size`, `term_is_tty` |

[docs/language.md](docs/language.md) has every function and what it does at the edges.

## How fast is it?

Each program below does the same work in GazLang, PHP and Python (they are in
[`vm/bench/`](vm/bench)). The time is the whole process's CPU time, best of 7 runs,
interleaved, on an Intel i7-8700 running macOS.

| Program | GazLang | PHP 8.5 (JIT) | Python 3.13 |
| --- | ---: | ---: | ---: |
| `fib` — recursive calls, `fib(30)` | **0.067s** | 0.104s | 0.162s |
| `closures` — `map`, `filter`, `reduce` and `sort` with lambdas | **0.046s** | 0.112s | 0.088s |
| `loop` — ten million rounds of integer arithmetic | 0.359s | **0.239s** | 1.034s |
| `objects` — half a million small objects and method calls | 0.166s | **0.165s** | 0.345s |
| `lists` — a million elements, built, read and written | 0.174s | **0.131s** | 0.245s |
| `maps` — counting half a million words | **0.107s** | 0.123s | 0.187s |
| `strings` — building, splitting and joining 3MB of text | **0.117s** | 0.123s | 0.155s |

The times include starting up, which is roughly 0.08s for PHP with its JIT, 0.03s for Python
and under 0.01s for GazLang, so the shortest programs flatter GazLang against PHP. GazLang ran
compiled bytecode here; compiling from source adds about 10ms to a small program.

Run `php vm/bench.php` to measure on your own machine. It also times the self-hosted compiler
on real work: it compiles itself, all 5,800 lines, in under a third of a second.

## Where to go next

- **[docs/language.md](docs/language.md)** — the whole language, in reference form.
- **`examples/`** — runnable programs: [pathfinding](examples/pathfinding.gaz) with Dijkstra's
  algorithm, a [Brainfuck interpreter](examples/brainfuck.gaz), a
  [Markdown converter](examples/markdown.gaz), a [tokenizer](examples/tokenizer.gaz) and a
  [terminal dashboard](examples/dashboard.gaz). They are there to read and to run, and nothing
  tests them.
- **`games/`** — programs built on the language, in this repository so the language can improve as they ask:
  a [football manager](games/football/README.md) for the terminal: two divisions and a cup,
  tactics and substitutions, transfers and contracts, saving and seasons.
- **`tests/programs/`** — bigger programs that the tests do run, so they still work: a 1,400
  line [football league simulator](tests/programs/football.gaz) (the frozen one the game started
  from, and the benchmark workload), a
  [CSV report](tests/programs/csv_report.gaz), a
  [web API client](tests/programs/cat_facts.gaz) and a [web server](tests/programs/web_server.gaz).
- **`lib/`** — the standard library, all of it written in GazLang and built into `gaz`, so a
  program anywhere includes it by name: `include "std/json.gaz";`.
- **[docs/internals.md](docs/internals.md)** — how the compiler and VM fit
  together, and how to work on them.
- **[CLAUDE.md](CLAUDE.md)** — the rules, the reasons behind each design decision, and what
  is still open. The most interesting file here if you like language design.

## License

MIT, see [LICENSE.md](LICENSE.md).
