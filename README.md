# GazLang

[![CI](https://github.com/panzer-planet/gazlang/actions/workflows/ci.yml/badge.svg)](https://github.com/panzer-planet/gazlang/actions/workflows/ci.yml)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**A scripting language that would rather stop than guess.**

```gaz
kind NotFound extends Error {
    fn _(pub string #item) {
        ##_("no price for {$item}");
    }
}

fn price(map $prices, string $item): float {
    return $prices[$item] ?? throw NotFound($item);
}

fn label(float $amount): string {
    return match {
        $amount == 0.0 => "free",
        $amount < 5    => "cheap",
        default        => "$" .. $amount,
    };
}

$prices = {"coffee" => 4.5, "cake" => 6.0};

foreach (["coffee", "cake", "tea"] as $item) {
    try {
        echo $item .. ": " .. (price($prices, $item) |> label);
    } catch (NotFound $e) {
        echo $e.message;
    }
}
```

```
coffee: cheap
cake: $6.0
no price for tea
```

## SQL and HTML you can't get wrong

A name touching a string, `sql"..."` or `html"..."`, hands the function of that name the text and
the values *separately*. That's all a **tagged string** is, and it's what makes injection a
mistake the language can see: `db::sql"..."` sends every value to the database as a bound
parameter, `web::html"..."` escapes every value as HTML, and a plain string where a query is
expected is an error.

```gaz
include "std/db.gaz";
include "std/web.gaz";

$db = db::open("sqlite::memory:");
$db.run(db::sql"create table notes (id integer primary key, body text)");

$body = "<script>alert(1)</script> '); drop table notes; --";
$db.exec(db::sql"insert into notes (body) values ({$body})");

$items = map($db.query(db::sql"select body from notes"), $row -> web::html"<li>{$row["body"]}</li>");
echo web::html"<ul>{$items}</ul>";
echo $db.value(db::sql"select count(*) from notes") .. " note, table intact";

try {
    $db.query("select * from notes where body = '" .. $body .. "'");
} catch (Error $e) {
    echo $e.message;
}
```

```
<ul><li>&lt;script&gt;alert(1)&lt;/script&gt; &#39;); drop table notes; --</li></ul>
1 note, table intact
Db.query() takes db::sql"...", not a string: write db::sql"select ... where id = {$id}", or db::raw($text) for SQL built some other way
```

A value reaches the database as a value, never spliced into the SQL, and reaches the page
escaped, so there is nothing to remember at each use. Fragments nest (a query inside a query, an `Html` inside an `Html`), a list becomes
`in (?, ?)` or a row of `<li>`s, and `.gazml` templates give the same escaping to whole pages.
Both work on SQLite and PostgreSQL, and you can write your own tags: any function of two lists.

## Why you might like it

- **SQL and HTML that can't inject.** Tagged strings, `db::sql"... {$id}"` and
  `web::html"<li>{$name}</li>"`, bind or escape every value for you, and a plain string where a
  query is expected is an error ([above](#sql-and-html-you-cant-get-wrong)).
- **Loud, precise errors.** `"5" + 5` is an error, not `10` or `"55"`. A missing key is an
  error unless you ask for a default with `??`. A runtime error names its file and line, with a
  stack trace.
- **Types when you want them.** Write `fn total(int $n): float` or `pub string #owner` and the
  types are checked as the program runs, with errors that name the parameter:
  `total() expects $n to be int, got string`. Leave them out and nothing changes, and nothing
  is ever converted to fit.
- **Values that behave like values.** Lists and maps are copied when you assign them, like
  numbers are, so nothing changes behind your back. Objects are handles, shared on purpose.
- **A small set of operators that pull their weight.** `??` and `?.` for missing things, `|>`
  for pipelines, `match` with or without a subject, list patterns for unpacking, spread for
  combining lists and maps.
- **It is quick.** It beats Python 3.13 by 1.4 to 2.9 times, and stays within about 1.5 times of
  PHP 8.5 with its JIT, beating it on calls, closures, maps and strings
  ([numbers below](#how-fast-is-it)).
- **Batteries included, and self-hosted.** JSON, CSV, an HTTP/1.1 client with TLS and a
  preforking web server, SQLite and PostgreSQL, dates, a terminal UI toolkit, regular
  expressions with no ReDoS, and password hashing (Argon2id, scrypt, PBKDF2) — most of it
  written in GazLang itself ([the list](#what-comes-with-it)).
- **It is checked to the byte.** What every test program prints, error messages included, is
  recorded and held to under AddressSanitizer and a leak check. The compiler compiles itself to
  exactly itself. See [docs/internals.md](docs/internals.md) if that's the kind of thing you
  like to verify yourself.

It is a hobby language, not production software, and it would like company.

## Get it running

Download a release for Linux or macOS from the
[releases page](https://github.com/panzer-planet/gazlang/releases), unpack it, and put `gaz` on
your `PATH`. Building it from source needs nothing but a C compiler — see
[Building from source](docs/internals.md#building-from-source) for that and the optional build
flags.

```bash
echo 'echo "hello";' > hello.gaz
gaz hello.gaz
```

A script can run as a command of its own: start the file with `#!/usr/bin/env gaz` and
`chmod +x` it.

No program to write for a static site: `gaz -S localhost:8000` serves the current directory. And for a
one-liner, `gaz -e` runs the code you give it and leaves standard input as its data:
`ls | gaz -e 'while (($name = read_line()) != null) { echo upper($name); }'`.

Then kick the tyres — clone the repository for its `examples/` and `tests/`:

```bash
git clone https://github.com/panzer-planet/gazlang.git && cd gazlang
gaz examples/pathfinding.gaz            # the fewest steps and the least effort across a map
gaz examples/brainfuck.gaz              # a Brainfuck interpreter
gaz tests/programs/csv_report.gaz tests/programs/data/sales.csv region amount
gaz tests/programs/cat_facts.gaz list 5 # from a web API, over HTTPS
```

## Something real

GazLang is meant for web servers and CLI tools. Here's a small JSON API, routing and path
params included, ready to run:

```gaz
include "std/http.gaz";
include "std/json.gaz";

$books = {"1" => "Dune", "2" => "Foundation"};

$app = http::Router();

$app.get("/books/:id", $request -> {
    $id = $request["params"]["id"];
    if (!has_key($books, $id)) {
        return {"status" => 404, "body" => json::encode({"error" => "no such book"})};
    }
    return {"status" => 200, "body" => json::encode({"id" => $id, "title" => $books[$id]})};
});

$listener = socket_listen("localhost", 8080);
echo "Listening on http://localhost:8080";
http::serve($listener, $app.handler());
```

```bash
bin/gaz server.gaz &
curl http://localhost:8080/books/1
curl http://localhost:8080/books/9
```
```
{"id":"1","title":"Dune"}
{"error":"no such book"}
```

No frameworks, no build step, no `async`/`await`: `http::serve` forks worker processes
(`workers()`), restarts one if it crashes, and drains connections gracefully on Ctrl-C — all in
the standard library, all readable in [`lib/http.gaz`](lib/http.gaz).

## What comes with it

The standard library is built into `gaz`, so any program anywhere reaches it by name:

```gaz
include "std/json.gaz";
echo json::encode({"ok" => true});
```

JSON and CSV, HTML templates that escape by default, command line parsing with `--help` written
for you, an HTTP/1.1 client and a preforking server, a router, SQLite and PostgreSQL, regular
expressions, dates, number and string formatting, sorting and list helpers, cryptographically
sound randomness, password hashing and HMACs, and a terminal UI toolkit with boxes, tables,
menus and a screen that redraws only what changed. Sockets and worker processes, the database
drivers, the terminal and cryptography are the only parts written in C; everything else is
GazLang, reachable in [`lib/`](lib) and documented in [docs/language.md](docs/language.md#libraries).

## How fast is it?

Each program below does the same work in GazLang, PHP and Python (they are in
[`vm/bench/`](vm/bench)). The time is the whole process's CPU time, best of 7 runs, interleaved,
on an Intel i7-8700 running macOS.

| Program | GazLang | PHP 8.5 (JIT) | Python 3.13 |
| --- | ---: | ---: | ---: |
| `fib` — recursive calls, `fib(30)` | **0.064s** | 0.102s | 0.160s |
| `closures` — `map`, `filter`, `reduce` and `sort` with lambdas | **0.045s** | 0.111s | 0.086s |
| `loop` — ten million rounds of integer arithmetic | 0.356s | **0.235s** | 1.029s |
| `objects` — half a million small objects and method calls | 0.167s | **0.163s** | 0.343s |
| `lists` — a million elements, built, read and written | 0.133s | **0.127s** | 0.241s |
| `maps` — counting half a million words | **0.103s** | 0.121s | 0.187s |
| `strings` — building, splitting and joining 3MB of text | **0.107s** | 0.120s | 0.150s |

Run `php vm/bench.php 7` to measure on your own machine.

## Where to go next

- **[docs/language.md](docs/language.md)** — the whole language, in reference form.
- **`examples/`** — runnable programs: [pathfinding](examples/pathfinding.gaz) with Dijkstra's
  algorithm, a [Brainfuck interpreter](examples/brainfuck.gaz), a
  [Markdown converter](examples/markdown.gaz), a [tokenizer](examples/tokenizer.gaz) and a
  [terminal dashboard](examples/dashboard.gaz).
- **`games/`** — programs built on the language, in this repository so the language can improve
  as they ask: a [football manager](games/football/README.md) for the terminal.
- **`lsp/`** — a Language Server Protocol server for GazLang, `bin/gaz lsp/server.gaz`,
  diagnostics, hover, go-to-definition and completion.
- **[docs/internals.md](docs/internals.md)** — how the compiler and VM fit together, the build,
  and how to work on them.
- **[CLAUDE.md](CLAUDE.md)** — the rules, the reasons behind each design decision, and what is
  still open. The most interesting file here if you like language design.

## License

MIT, see [LICENSE.md](LICENSE.md).
