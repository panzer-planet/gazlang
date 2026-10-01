# Friction found while building the todo app

Each entry says what hurt, what the app does about it today, and the options for fixing it in the
language or the library. Nothing here is decided: it is evidence. The app is registration, login and
logout, and per-user todos, on PostgreSQL: 824 lines of GazLang, 115 of templates, and 395 of tests (152 checks). It was
written by someone who knows the language, working from the library's own documentation.

Ordered by how much each would matter to a stranger writing their first web app.

## What worked, so it is not lost

- **`db::sql"..."` is a pleasure.** Nested fragments (`{$columns}` is a `db::raw` column list shared
  by every query), `insert ... on conflict do nothing returning ...` and `update ... returning`
  all worked first time, ints and bools came back as ints and bools, and a plain-string query is
  an error. `on conflict` made the "email taken" race a non-problem.
- **Kinds with promoted constructor parameters** (`fn _(pub int #id, ...) {}`) made the models
  and forms about as short as they can be. `User::from_row($row)` was 3 lines each and in one place.
- **Bound methods as handlers** (`$app.get("/", $handlers.home)`) and `Router`'s params, 405 with
  `Allow`, middleware that wraps the 404s too: routing needed nothing added.
- **`getenv("X") ?? throw "X is not set"`** is exactly the right shape for configuration.
- **The sessions, CSRF tokens and `crypto::hash_password` were all there**, and the defaults are
  safe (`HttpOnly`, `SameSite=Lax`, Argon2id, constant-time compares).
- **It is fast enough not to think about**: a logged-in page (a query, 41 rows through a template)
  served about 2,000 requests a second on 4 workers, static files and pages without a query
  about 3,800 (the client was probably the limit).
- **The app built and ran at the first attempt**, and the live run through the forking server (a
  connection per worker, a graceful stop on SIGTERM) passed the whole flow. The one slip the unit
  tests found was a missing `include "todos.gaz"` in `forms.gaz`, which `app.gaz` had been hiding by
  including both: a file naming what it doesn't include only fails when it is run alone.
- **Breaking the tests on purpose caught every planted bug**: an unscoped query, no CSRF check, no
  Origin check, a missing UTF-8 check, a login that keeps the old session.

## 1. A web app writes about 100 lines of middleware that every web app will write

`middleware.gaz` is sessions (read the signed cookie, make sure a CSRF token is in it, write it back
only if it changed), flash messages, the CSRF check on POST, an `Origin` check, security headers,
"who is logged in", and "this route needs a user". The library has the pieces (`http::session`,
`http::session_cookie`, `http::csrf_token`, `http::verify_csrf`) but not the glue, so each app
decides how a handler asks for a session change (this one: add `"session"` to its response, which the
middleware strips, since a response with an unknown key is an error), and the obvious first version has
a trap: comparing the session with the one the token was just added to means a new visitor's token
is never written back. That is the kind of detail that is each app's own to get right or wrong.

- **Today**: `middleware.gaz`.
- **Options**: `http::sessions($secret, $options)` as a library middleware, with the convention
  documented (`$request["session"]`, a response's `"session"`, `$request["flash"]`); `http::csrf()` and
  `http::security_headers()` beside it. Or leave the library at the pieces and put the glue in the
  docs as a worked example that is copied. The risk of the glue being each app's own is that it
  is where the security bugs live.

## 2. Templates can't be part of a namespaced program

A `.gazml` file is read as a file with no namespace and no way to `include`. So:
(a) its function (`layout`, `login_page`) is **global**, not `todo::layout`, however the app is
organised; (b) its parameters **can't be typed with a kind of the app** (`?User $user` is
`Undefined type: User`, and `?todo::User` is `Namespace todo is not included here`), only with builtin
types; (c) it can't `include` anything. The app's templates therefore take untyped
`$user`/`$form` and have names chosen not to collide (`register_page`, `todos_page`).

- **Today**: untyped parameters, distinctive names.
- **Options**: a `namespace todo;` line allowed first in a template (it is then `todo::layout`, and
  resolves `User` as the including namespace does); or a template takes the namespace of the file
  that includes it; or `@include`/`@use` lines. The first is the smallest and matches how every
  other file works. This is the one that decides whether templates fit a real app.

## 3. A database connection must not cross `workers()`, and nothing says so

Each worker needs a connection of its own, opened after the fork; migrations run on one that is
closed first. A program that opens `db::open` before `workers()` shares one socket between the
workers. Measured: with one worker it **works perfectly** (400 queries, no errors, one backend), so it
passes in development; with three, libpq reports `message type 0x31 arrived from server while idle`
on standard error as the protocol stream is corrupted, and the workers did not finish their 400
queries in 8 seconds, where one worker needs under one. A program that fails only under concurrency
and says nothing about why is the worst kind. `main.gaz` does it right and explains in a comment,
because the language will not.

- **Today**: a comment and the order of lines in `main.gaz`.
- **Options**: `workers()` marks every `db` (and `socket`) handle the parent holds as inherited in
  each child, so using one is an error naming it ("opened before workers(); open it after"); this
  is the rule `parallel()` needs too (see the roadmap) and could share its code. Or have
  `http::serve` take a function that makes the handler, called once per worker, so the natural place
  to open a connection is inside it.

## 4. No UTF-8 validation, and `len()` counts bytes

PostgreSQL refuses text that isn't well formed UTF-8, so an `%ff` in a form was a **500 with a stack
trace in the log**, found by the hostile-input pass, not by writing the validation. There is no
`is_utf8()`, so `utf8.gaz` (60 lines, checked against Python's decoder on 418 byte strings) is the app's.
It also gave "200 characters" its real meaning: `len()` counts bytes, so a title of 200 emoji was 800.

- **Today**: `apps/todo/utf8.gaz` (`utf8_valid`, `utf8_length`).
- **Options**: a `utf8` library (`utf8::valid`, `utf8::length`, perhaps `utf8::slice` by characters)
  in `lib/`, written in GazLang from RFC 3629, or the two as builtins in C (they are loops over every
  byte of a form field). Every app that accepts text and stores it hits this on its first fuzz.

## 5. No rate limiting, and no client address

Argon2id makes a login cost 0.24s of a worker. Eight concurrent wrong logins on 2 workers made a
plain stylesheet wait 0.39s behind them, so on a fixed pool anyone can saturate the app with a few
requests a second. The app counts failures per email in a table and refuses the sixth without
hashing; that stops a guesser on one email and not one trying many, and lets anyone lock a known
email out for 15 minutes. A per-client limit needs the client's address, which a handler can't see
(`socket_peer()` is on the roadmap "when a program asks": this is asking).

- **Today**: `throttle.gaz`, per email, in the database so every worker agrees.
- **Options**: `$request["remote_address"]` from `socket_peer()`, with the proxy's
  `X-Forwarded-For` left to the app; a `http::rate_limit($key, $per_minute)` needs shared state
  across workers, which means a table, a file or a store the library doesn't have, so it should
  probably stay the app's. Lowering the Argon2id default for logins is the other lever (0.24s is the library's
  default, RFC 9106's second recommendation; on a pool this small it is a lever against the app).

## 6. Calling a handler's helpers needs a test client that doesn't exist

The tests drive the real router through a `Browser` kind (`tests/support.gaz`, 70 lines): it builds
request maps, keeps the cookies a response sets, form-encodes bodies, and finds the CSRF token in a
page. Every web app's tests will want this, and the request shape (`method`, `path`, `query`,
lower-cased `headers`, `body`) is only written down in `http.gaz`'s source.

- **Today**: `tests/support.gaz`.
- **Options**: `http::client_for($handler)` giving something with `get`/`post`/cookies in the
  library (`std/test.gaz` is the neighbour), and the request map documented in `docs/language.md`.

## 7. Routes are wrapped one at a time

`authenticated($handlers.create)` is written on 6 of the 11 routes. `Router.use` applies to every
route, so a group of routes with a requirement has no home.

- **Today**: a wrapper function applied at each route.
- **Options**: a second argument or a method on routes (`$app.get($path, $handler, [$middleware])`),
  or `$app.group($middleware, $routes)`. The wrapper is five words, so this is the least urgent.

## 8. Files are found from the working directory, and a program can't ask where it is

`include` is relative to the including file, but `read_file()`, `list_dir()` and `serve_static()` are
relative to where the program was started, and nothing says where the main file is
(`program_path()` is the interpreter). The app must be run from `apps/todo` (`migrations`,
`public`), and `main.gaz` says so.

- **Today**: a comment, and `MIGRATIONS` as an override.
- **Options**: `script_dir()`; or `include`-style resolution for a relative path given to those
  builtins; or accept it, since a deployed app has a working directory it chose.

## 9. The request is a plain map, so each helper parses it again

`http::form($request)` decodes the body each time: the CSRF check does, then the handler's form kind
does. It is cheap here (a few fields) and it is a wrapper (`form_fields()`) that turns the error a
non-form body raises into "no fields", since `form()` raises a string and the caller can't tell a
hostile request from a bug except by catching every `Error`.

- **Today**: parse twice; `try`/`catch (Error)` in `form_fields()`.
- **Options**: the middleware stores `$request["form"]` once; or `http::form($request, $default)`
  (as `to_int($x, $default)` does) so bad input needs no `try`.

## 10. Smaller things

- **Redirects** (resolved: `http::redirect($to, $status = 303)`): the router had a private one and
  every app would have written `{"status" => 303, "headers" => {"Location" => $to}, "body" => ""}` itself.
- **PostgreSQL NOTICEs** go to standard error and can't be quieted: `create table if not exists`
  and `truncate ... cascade` print a line each, on every start and between test checks.
  The driver could set `client_min_messages = warning` on connect.
- **Timestamps** arrive as text in the server's time zone (`"2026-10-01 14:04:27.78+02"`). The app
  never shows them; it would parse them, and `date.gaz` has no clock and no parser.
- **Database errors are strings**, so tests assert on their words
  (`postgres: duplicate key value violates unique constraint "users_email_key"`), which is fragile
  across PostgreSQL versions and has no SQLSTATE to match on.
- **`@csrf`-shaped repetition in templates**: `<input type="hidden" name="_csrf" value="{{ $csrf }}">`
  appears in 7 places in the templates, two of them inside the todo list, so a page of 40 todos
  carries 82 tokens. A `@csrf` directive, or a `form` helper, would remove it (and is a thing a
  template can get wrong; this app's test checks each form).
- **Static files** have no `Cache-Control`/`ETag`, so every page load refetches the stylesheet.
- **No access log**: `http::serve` prints errors and nothing else (it is on the roadmap as a line per
  request "when a program asks"). Checking the live server, the only record of what was asked
  and answered was the client's own; the server's side was silent unless something failed.
