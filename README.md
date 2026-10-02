# GazLang

[![CI](https://github.com/panzer-planet/gazlang/actions/workflows/ci.yml/badge.svg)](https://github.com/panzer-planet/gazlang/actions/workflows/ci.yml)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**A scripting language that would rather stop than guess.**

**Hostile input welcome.**

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

## What's different

Three things you won't find together elsewhere.

### SQL and HTML you can't get wrong

A name touching a string, `sql"..."` or `html"..."`, hands the function of that name the text and
the values *separately*. That's all a **tagged string** is, and it's what makes injection a
mistake the language can see: `db::sql"..."` sends every value to the database as a bound
parameter, `web::html"..."` reads the markup around each value and writes it for the place it lands
(escaped in text and in quoted attributes, checked in a URL, refused where HTML escaping isn't
enough), and a plain string where a query is expected is an error.

```gaz
import "std/db.gaz";
import "std/web.gaz";

$db = db::open("sqlite::memory:");
$db.run(db::sql"create table notes (id integer primary key, body text)");

$body = "<script>alert(1)</script> '); drop table notes; --";
$db.exec(db::sql"insert into notes (body) values ({$body})");

$items = map($db.query(db::sql"select body from notes"), $row -> web::html"<li>{$row["body"]}</li>");
echo web::html"<ul>{$items}</ul>";
$profile = "javascript:alert(1)";
echo web::html"<a href=\"{$profile}\">profile</a>";
echo $db.value(db::sql"select count(*) from notes") .. " note, table intact";

try {
    $db.query("select * from notes where body = '" .. $body .. "'");
} catch (Error $e) {
    echo $e.message;
}
```

```
<ul><li>&lt;script&gt;alert(1)&lt;/script&gt; &#39;); drop table notes; --</li></ul>
<a href="about:invalid#blocked">profile</a>
1 note, table intact
Db.query() takes db::sql"...", not a string: write db::sql"select ... where id = {$id}", or db::raw($text) for SQL built some other way
```

A value reaches the database as a value, never spliced into the SQL, and reaches the page written
for where it lands, so there is nothing to remember at each use. Fragments nest (a query inside a
query, an `Html` inside an `Html`), a list becomes `in (?, ?)` or a row of `<li>`s, and `.gazml`
templates escape the text and quoted attributes of whole pages.
Both work on SQLite and PostgreSQL, and you can write your own tags: any function of two lists.

### Regular expressions that can't hang

`regex.gaz` runs a pattern as a Thompson NFA, one pass over the input however the pattern is
written, so there is no backtracking and no catastrophic case: a pattern from a user, a config
file or a request can't take your server down. This is the textbook one, on the input that makes
a backtracking engine run for longer than you'll wait:

```gaz
import "std/regex.gaz";

$input = repeat("a", 5000) .. "!";
echo regex::matches($input, "(a+)+$");
```

```
false
```

It answers at once, and the price is stated: no backreferences, and no `\d`/`\w` shorthands
(`std/chars.gaz` has those as functions). The match is the leftmost, repetition is greedy, and the
first alternative that matches wins.

### A terminal program you can run without a terminal

`gaz --tty app.gaz` runs a full-screen program on a pretend terminal: standard input is its keys,
and it prints the screen wherever the keys say `snap`. The same keys always print the same screens
(the clock is pretend too, and moves only on `wait`), so a script, a test or an AI agent can build
and check a terminal UI from plain shell calls:

```bash
gaz --tty=60x11 examples/dashboard.gaz <<< 'down + + snap'
```

```
=== after: down + + ===
           10        20        30        40        50        60
 1 ┌─ Tasks ──────────────────────────────────────────────────┐
 2 │                                                          │
 3 │   Write the lexer            ██████████████████████ 100% │
 4 │ > Write the parser           ██████████████████████ 100% │
 5 │   Write the code generator   ███████████░░░░░░░░░░░  50% │
 6 │   Write the VM               ███████░░░░░░░░░░░░░░░  30% │
 7 │   Write the docs             ░░░░░░░░░░░░░░░░░░░░░░   0% │
 8 │                                                          │
 9 │ up/down pick   +/- change   q quit                       │
10 └──────────────────────────────────────────────────────────┘
11
styles:
 4  3-30  bold  "> Write the parser"
 9  3-58  dim  "up/down pick   +/- change   q quit"
```

Every screen is the program's own grid, not a guess at its escape codes, and which cells are bold
or dim is listed under it. [The keys and clock are written out here](docs/language.md#without-a-terminal-gaz---tty).

## Why you might like it

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

A program of more than one file is a project: a `gaz.json` at its root, and files that import
each other by their path from it.

```
hello/
  gaz.json          {"name": "hello"}
  main.gaz          import "greet.gaz";  echo greet::hello("world");
  greet.gaz         namespace greet;  pub fn hello($who) { return "Hello, {$who}!"; }
```

`gaz hello/main.gaz` runs it from anywhere, as does `cd hello && gaz main.gaz`. A file sees what
it imports and no more, and a file that is imported only declares, so importing one never runs
anything: see [Modules](docs/language.md#modules). A single file needs no `gaz.json`.

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
import "std/http.gaz";
import "std/json.gaz";

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
workers(4);
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

No frameworks, no build step, no `async`/`await`. `workers(4)` is the whole concurrency story.

### How it serves: prefork

This is the model PHP-FPM and Puma's cluster mode (or Unicorn, or Gunicorn's sync workers) use: a
pool of processes started up front, all accepting connections on the one listening socket, each
answering one request at a time.

- **`workers(4)` forks the program into four processes** at that line, each carrying on with a
  copy of everything, and the kernel hands each new connection to whichever is free. A request
  that is slow, or crashes, holds up one worker and no one else.
- **A master supervises them, written in C and running no GazLang.** If a worker dies of an error
  or a signal it starts another, so the pool stays four wide. On SIGTERM it stops them all
  gracefully: each finishes the request it's in, and one still running after 10 seconds is
  killed. Ctrl-C ends them at once.
- **Workers share nothing.** Each has its own memory and, since lists and maps are values, there
  is nothing to lock and no data race to have. State that must outlive a request belongs in a
  database or a cookie, as it does behind FPM.
- **A worker can retire itself.** `http::serve($listener, $handler, {"max_requests" => 1000})`
  ends a worker after that many requests and the master starts a fresh one, which throws away
  whatever it built up over its life: FPM's `pm.max_requests`.

What it is not, today: there are no threads inside a worker (Puma's other half) and no event loop
(Node's), so the pool's size is how many requests run at once, and the pool is a fixed size
rather than growing under load (FPM's `pm = dynamic`). A connection is kept open for the client's
next request (HTTP/1.1's keep-alive), up to 5 seconds idle, and given up at once when a new client
would otherwise wait for a worker. There's no TLS on the server side, so
put nginx or Caddy in front for HTTPS, as you would in front of FPM. The same proxy is your
protection against slow clients: one that opens a connection and trickles bytes holds a worker
until the request's 30 second deadline, so a proxy that buffers whole requests before passing them
on keeps a handful of slow connections from using up the pool. If the proxy keeps its own
connections to gaz open, set gaz's `"idle_timeout"` above the proxy's upstream keep-alive timeout,
so gaz isn't the side that closes one just as the proxy sends on it, and keep the proxy's count of
idle upstream connections at most the number of workers. (Whether nginx retries a request on an
upstream connection gaz has just closed hasn't been checked.) All of it is in
[`lib/http.gaz`](lib/http.gaz) and the C of [`vm/workers.c`](vm/workers.c).

## What comes with it

The standard library is built into `gaz`, so any program anywhere reaches it by name:

```gaz
import "std/json.gaz";
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
- **`editors/`** — TextMate grammars for source (`.gaz`), bytecode (`.gzb`) and templates
  (`.gazml`), which VS Code, Sublime and most editors read.
- **`apps/todo/`** — a web app built to find what the language and library lack: registration,
  login and todos on PostgreSQL, with [a log of the friction](apps/todo/FRICTION.md).
- **[docs/internals.md](docs/internals.md)** — how the compiler and VM fit together, the build,
  and how to work on them.
- **[CLAUDE.md](CLAUDE.md)** — the rules, the reasons behind each design decision, and what is
  still open. The most interesting file here if you like language design.

## License

MIT, see [LICENSE.md](LICENSE.md).
