# GazLang

A small scripting language that would rather stop than guess.

If you have written PHP, JavaScript or Python you can read GazLang already. The difference is
in what it refuses to do: it never quietly turns a string into a number, never overflows an
integer into a float, and never hands you a zero because a key was missing. When something is
wrong it says so, with a line number and a stack trace.

```gaz
fn greet($name) {
    return "Hello, {$name}!";
}

echo greet("world");
```

```
Hello, world!
```

It is a hobby language that compiles itself: the lexer, parser and compiler are written in
GazLang, and run on a small VM in C. The first implementation, in PHP, with a tree-walking
interpreter and a stack VM that must agree on every single test, stays as the reference the C
side is checked against. It is not production software, and it would like company.

## Get it running

You need a C compiler and make.

```bash
git clone https://github.com/panzer-planet/gazlang.git
cd gazlang && make -C vm

echo 'echo "hello";' > hello.gaz
bin/gazlang -f hello.gaz
```

Then try one of the sample programs:

```bash
bin/gazlang -f examples/csv_report.gaz -- examples/data/sales.csv region amount
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

The PHP implementation, `bin/gazlang-php`, takes the same options (it needs PHP 8.5 or later and
`composer install`), and one more: `--interpreter`, the tree-walking interpreter instead of the VM.
The tests compare the two, so they need both.

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
this object, `#name` one of its fields or methods.

```gaz
class Account {
    #owner;
    #balance = 0;

    fn _($owner) { #owner = $owner; }     // the constructor

    fn deposit($amount) {
        #balance += $amount;
        return #;                         // # is this object
    }

    fn to_string() { return "{#owner}: {#balance}"; }
}

$a = Account("Ada");
$a.deposit(50).deposit(25);
echo $a;
```

```
Ada: 75
```

### Errors

Any runtime failure is catchable, and `Error` is a real class you can extend.

```gaz
class NotFound extends Error {
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
include "lib/csv.gaz";
include "lib/format.gaz";

fn totals_by($rows, $group, $column) {
    return reduce($rows, ($totals, $row) -> {
        $key = $row[$group];
        $totals[$key] = ($totals[$key] ?? 0.0) + to_float($row[$column]);
        return $totals;
    }, {});
}

try {
    $rows = csv_records(csv_parse(read_file(args()[0] ?? "sales.csv")));
    $totals = totals_by($rows, "region", "amount");

    foreach (sort(keys($totals), ($a, $b) -> $totals[$b] <=> $totals[$a]) as $region) {
        echo pad_right($region, 8) .. round($totals[$region], 2);
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

## Where to go next

- **[docs/language.md](docs/language.md)** — the whole language, in reference form.
- **`examples/`** — runnable programs, from [`strings.gaz`](examples/strings.gaz) up to a
  699 line [football league simulator](examples/football.gaz) and a
  [tokenizer](examples/tokenizer.gaz).
- **`lib/`** — the standard library, all of it written in GazLang.
- **[docs/internals.md](docs/internals.md)** — how the interpreter, compiler and VM fit
  together, and how to work on them.
- **[CLAUDE.md](CLAUDE.md)** — the rules, the reasons behind each design decision, and what
  is still open. The most interesting file here if you like language design.

## License

MIT
