# GazLang

[![CI](https://github.com/panzer-planet/gazlang/actions/workflows/ci.yml/badge.svg)](https://github.com/panzer-planet/gazlang/actions/workflows/ci.yml)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**A scripting language that would rather stop than guess.**

If you have written PHP, JavaScript or Python, you can read GazLang already. What makes it
different is what it refuses to do: it never quietly turns a string into a number, never
overflows an integer into a float, and never hands you a zero because a key was missing. When
something is wrong, it says so, with the line it happened on and how it got there.

```gaz
$counts = {};
foreach (split("the cat sat on the mat", " ") as $word) {
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
- **Values that behave like values.** Lists and maps are copied when you assign them, like
  numbers are, so nothing changes behind your back. Objects are handles, shared on purpose.
- **It compiles itself.** The lexer, parser and compiler are about 4,400 lines of GazLang,
  running on a VM of about 5,700 lines of plain C, whose one dependency, OpenSSL for HTTPS,
  is optional: `make -C vm TLS=0` needs nothing but a C compiler.
- **It is quick.** It beats Python 3.12 by 1.4 to 2.9 times, and stays within 1.5 times of
  PHP 8.5 with its JIT, beating it on calls, closures, maps and strings
  ([numbers below](#how-fast-is-it)).
- **It is checked to the byte.** What every test program, snippet and corpus file prints,
  error messages included, is recorded, and thousands of tests hold gazlang to it under
  AddressSanitizer and a leak check, on Linux and on both kinds of Mac. The compiler has to
  compile itself to exactly itself.
- **Batteries included, and self-hosted.** JSON, CSV, an HTTP/1.1 client with TLS and a preforking server, and
  regular expressions with no ReDoS (a Thompson NFA, not backtracking) — all in `lib/`, all
  written in GazLang, none of it a C shortcut.

It is a hobby language, not production software, and it would like company.

## Get it running

You need a C compiler, make and OpenSSL (`apt install libssl-dev` or `brew install
openssl@3`; or build with `make -C vm TLS=0` for no HTTPS).

```bash
git clone https://github.com/panzer-planet/gazlang.git
cd gazlang && make -C vm

echo 'echo "hello";' > hello.gaz
bin/gazlang -f hello.gaz
```

The build uses profile-guided optimisation when your compiler supports it (gcc, or clang with
`llvm-profdata`, which Xcode's command line tools have): it runs gazlang on the compiler and on
sample programs, then compiles it again knowing which code is hot, for a few to ten percent. Without
it you get a plain optimised build; `make -C vm PGO=0` asks for that.

Then kick the tyres:

```bash
bin/gazlang -f examples/pathfinding.gaz      # the fewest steps and the least effort across a map
bin/gazlang -f examples/brainfuck.gaz        # a Brainfuck interpreter
bin/gazlang -f tests/programs/csv_report.gaz -- tests/programs/data/sales.csv region amount
bin/gazlang -f tests/programs/cat_facts.gaz -- list 5   # from a web API, over HTTPS
```

Other ways to run it:

```bash
bin/gazlang -f program.gaz -- arg1 arg2    # arguments, read with args()
cat program.gaz | bin/gazlang              # piped input runs as one program
bin/gazlang -c -f program.gaz > x.gzb      # print the compiled VM code, which runs as it is
bin/gazlang -f x.gzb
bin/gazlang --tokens -f program.gaz        # print the tokens
bin/gazlang --ast -f program.gaz           # print the tree
```

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
echo [...[1, 2], ...[3, 4]];               // spread into a list literal
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
$copy = $original = [1, 2];
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

    pub fn to_string() { return "{#owner}: {#balance}"; }
}

$a = Account("Ada");
$a.deposit(50).deposit(25);
echo $a;
```

```
Ada: 75
```

Single inheritance, with `##` reaching the parent's version of a method and a constructor
calling `##_(...)` to run the parent's:

```gaz
kind Point {
    fn _(pub #x, pub #y) {}
    pub fn to_string() { return "({#x}, {#y})"; }
}

kind Circle extends Point {
    fn _($x, $y, #radius) { ##_($x, $y); }

    pub fn area() { return 3.14159 * #radius * #radius; }
    pub fn to_string() {
        $area = round(#area(), 2);
        return "{##to_string()} r={#radius} area={$area}";
    }
}

$c = Circle(1, 2, 3);
echo $c;
echo $c.x .. "," .. $c.y;   // #x is pub, so it reaches outside the kind
```

```
(1, 2) r=3 area=28.27
1,2
```

Fields and methods can be `static`, shared by a kind and every child rather than per object:

```gaz
kind Counter {
    static #count = 0;
    static fn next() { return ++Counter::count; }
}

echo Counter::next();
echo Counter::next();
```

```
1
2
```

### Errors

Any runtime failure is catchable, and `Error` is a real kind you can extend.

```gaz
kind NotFound extends Error {
    #key;
    fn _($key) { ##_("No such fruit: {$key}"); #key = $key; }
}

fn price($prices, $fruit) {
    return $prices[$fruit] ?? error(NotFound($fruit));
}

try {
    echo price({"apple" => 1.5}, "apple");
    echo price({"apple" => 1.5}, "durian");
} catch (NotFound $e) {
    echo "{$e.message} (line {$e.line})";
}
```

```
1.5
No such fruit: durian (line 7)
```

Let one escape and you get the line it happened on and the calls that led there:

```gaz
fn inner() { return [1][5]; }
fn outer() { return inner(); }
echo outer();
```

```
Error: Index out of range: 5 at trace.gaz:1
  inner at trace.gaz:1
  outer at trace.gaz:2
  top level at trace.gaz:3
```

## Something real

`lib/` holds libraries written in GazLang itself — CSV, JSON, sorting by key, string
formatting. Here is a sales report in twenty lines:

```gaz
include "std/csv.gaz";
include "std/format.gaz";

fn totals_by($rows, $group, $column) {
    return reduce($rows, ($totals, $row) -> {
        $key = $row[$group];
        $totals[$key] = ($totals[$key] ?? 0.0) + to_float($row[$column]);
        return $totals;
    }, {});
}

try {
    $rows = csv::records(csv::parse(read_file(args()[0] ?? "sales.csv")));
    $totals = totals_by($rows, "region", "amount");

    foreach (sort(keys($totals), ($a, $b) -> $totals[$b] <=> $totals[$a]) as $region) {
        echo format::pad_right($region, 8) .. round($totals[$region], 2);
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

## How fast is it?

Each program below does the same work in GazLang, PHP and Python (they are in
[`vm/bench/`](vm/bench)). The time is the whole process's CPU time, best of 7 runs,
interleaved, on an Intel i7-8700 running macOS.

| Program | GazLang | PHP 8.5 (JIT) | Python 3.12 |
| --- | ---: | ---: | ---: |
| `fib` — recursive calls, `fib(30)` | **0.062s** | 0.102s | 0.145s |
| `closures` — `map`, `filter`, `reduce` and `sort` with lambdas | **0.040s** | 0.110s | 0.088s |
| `loop` — ten million rounds of integer arithmetic | 0.350s | **0.236s** | 0.997s |
| `objects` — half a million small objects and method calls | 0.164s | **0.163s** | 0.353s |
| `lists` — a million elements, built, read and written | 0.167s | **0.129s** | 0.240s |
| `maps` — counting half a million words | **0.101s** | 0.122s | 0.188s |
| `strings` — building, splitting and joining 3MB of text | **0.111s** | 0.122s | 0.166s |

The times include starting up, which is roughly 0.06s for PHP with its JIT, 0.02s for Python
and under 0.01s for GazLang, so the shortest programs flatter GazLang against PHP. GazLang ran
compiled bytecode here; compiling from source adds about 10ms to a small program.

Run `php vm/bench.php` to measure on your own machine. It also times the self-hosted compiler
on real work: it compiles itself, all 4,400 lines, in about a quarter of a second.

## Where to go next

- **[docs/language.md](docs/language.md)** — the whole language, in reference form.
- **`examples/`** — runnable programs: [pathfinding](examples/pathfinding.gaz) with Dijkstra's
  algorithm, a [Brainfuck interpreter](examples/brainfuck.gaz), a
  [Markdown converter](examples/markdown.gaz), a [tokenizer](examples/tokenizer.gaz) and a
  [terminal dashboard](examples/dashboard.gaz). They are there to read and to run, and nothing
  tests them.
- **`games/`** — programs built on the language, in this repository so the language can improve as they ask:
  a [football manager](games/football/README.md) for the terminal: two divisions, tactics and
  substitutions, transfers, saving and seasons.
- **`tests/programs/`** — bigger programs that the tests do run, so they still work: a 700 line
  [football league simulator](tests/programs/football.gaz), a
  [CSV report](tests/programs/csv_report.gaz) and a
  [web API client](tests/programs/cat_facts.gaz).
- **`lib/`** — the standard library, all of it written in GazLang and built into `gazlang`, so a
  program anywhere includes it by name: `include "std/json.gaz";`.
- **[docs/internals.md](docs/internals.md)** — how the compiler and VM fit
  together, and how to work on them.
- **[CLAUDE.md](CLAUDE.md)** — the rules, the reasons behind each design decision, and what
  is still open. The most interesting file here if you like language design.

## License

MIT, see [LICENSE.md](LICENSE.md).
