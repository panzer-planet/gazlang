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

## Dates and times

`std/date.gaz` keeps three numbers apart, all of them ints, so know which one you hold: a **time**
is seconds since 1970 began in UTC, as `time()` gives it, the one to store and compare; a **day
number** is a date, days since 1 January 1970; and **seconds after midnight** are a time of day. A
zone turns a time into a `Moment`, what that zone's clocks show, and a day and a time of day back
into a time:

```gaz
import "std/date.gaz";

$now = 1786197729;                        // what time() gave, fixed so this prints the same every day
$london = date::zone("Europe/London");
$moment = $london.at($now);
echo $moment.rfc3339();
echo $moment.format() .. ", " .. $moment.short_clock() .. " " .. $moment.abbreviation;
echo date::utc().at($now).short_clock() .. " in UTC";

$today = $london.date($now);
$deadline = date::days(2026, 8, 31);
echo ($deadline - $today) .. " days to go";
echo date::format(date::next_weekday($today, date::Weekday::Monday));

$meeting = $london.time(date::days(2026, 10, 25), date::time_of_day(9, 30));
echo $london.at($meeting).rfc3339();
echo date::parse("2026-08-08T14:02:09+02:00").time;
```

```output
2026-08-08T15:02:09+01:00
Sat 8 Aug 2026, 15:02 BST
14:02 in UTC
23 days to go
Mon 10 Aug 2026
2026-10-25T09:30:00Z
1786190529
```

- Store times, and turn one into a `Moment` only to show it, in the zone of whoever reads it.
  `date::zone()` reads the system's time zone database, summer time and all: by 25 October London
  is back on UTC, so the meeting's offset is `Z`.
- Nothing in the library reads the clock. A function that takes the time as an argument gives the
  same answer every run, which is what makes date code testable.
- `date::parse()` reads the RFC 3339 text APIs and databases send, and `date::parse_reading()` what
  an HTML `datetime-local` field sends.

## Question
How does a program use the JSON library?

- [x] `import "std/json.gaz";`, then `json::encode()` and `json::decode()`: it's built into `gaz`
- [ ] Install it with a package manager first
- [ ] Nothing: `json_encode()` is a builtin
