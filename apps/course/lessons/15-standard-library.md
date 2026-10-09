---
unit: The standard library
title: A tour of the standard library
---
The standard library is built into `gaz`, so a program anywhere reaches it by name with an
`import "std/NAME.gaz";` line, and calls it through its namespace. Most of it is written in
GazLang itself.

```gaz
import "std/json.gaz";
import "std/format.gaz";
import "std/regex.gaz";
import "std/date.gaz";

$order = json::decode('{"item": "tea", "qty": 3, "price": 2.5}');
echo $order["item"];
echo json::encode({"total" => $order["qty"] * $order["price"]});

echo format::sprintf("%-6s|%6.2f|", ["tea", 7.5]);

echo regex::matches("2026-10-09", '\d\d\d\d-\d\d-\d\d');
echo regex::replace("call 555-1234", '\d', "#");

$day = date::days(2026, 10, 9);
echo date::format($day);
echo date::format(date::add_months($day, 1));
```

```output
tea
{"total":7.5}
tea   |  7.50|
true
call ###-####
Fri 9 Oct 2026
Mon 9 Nov 2026
```

Write a regular expression in single quotes, so `\d` reaches the pattern as written.

## What's in it

- `json`, `csv`: reading and writing data. `json::decode` gives maps and lists; an object is
  encoded through its own `to_json()` method.
- `text`, `format`, `chars`: lines, padding, `sprintf`, number formatting, character classes.
- `lists`, `sorting`, `random`: helpers over lists, sorting by keys, shuffling and picking.
- `regex`: regular expressions that run in one pass, so no pattern can hang on any input.
- `date`: days, times and time zones, read from the system's time zone database. Nothing in it
  reads the clock: you pass it `time()`, so date code is easy to test.
- `crypto`: password hashing, secure tokens and signed values.
- `fs`, `cli`: files and directories, and command line arguments with `--help` written for you.
- `http`, `web`, `db`: the client and server, HTML, and SQLite and PostgreSQL, which the web unit
  covers.
- `test`: what `gaz test` runs.
- `term`, `tui`: the terminal, and widgets for full-screen programs.

Each file's page on the GazLang website lists every name it has, from the documentation in its
source.

## Question
How does a program use the JSON library?

- [x] `import "std/json.gaz";`, then `json::encode()` and `json::decode()`: it's built into `gaz`
- [ ] Install it with a package manager first
- [ ] Nothing: `json_encode()` is a builtin
