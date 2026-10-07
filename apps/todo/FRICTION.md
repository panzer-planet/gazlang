# Friction found while building the todo app

Each entry says what hurt, what the app does about it today, and the options for fixing it in the
language or the library. Nothing here is decided: it is evidence. The app is registration, login and
logout, and per-user todos with optional due dates and an All/Open/Done filter, on PostgreSQL: 889
lines of GazLang, 225 of templates, 26 of SQL, and 617 of tests (222 checks). It was written by someone who knows the language, working from the library's own
documentation, and it has been changed since only by moving what it needed into the library.

Ordered by how much each would matter to a stranger writing their first web app.

**Status.** Resolved: 1 (middleware, now in `std/http.gaz`), 2 (a `namespace` line in a template), 3
(handles across `workers()`), 4 (UTF-8), 5 (`http::client_address()` and a per-client login limit), and
the redirect, the timestamps, the due dates, the CSRF field and static files in 10. Half done: 9 (decoding needs no
`try`; the request is still parsed twice). Open: 6, 7, 8, the rest of 9, and the rest of 10, each
linking the issue that tracks it (label `todo-app`).

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
  including both: a file naming what it doesn't include only failed when it was run alone. (Since
  `import`, a file sees only what it imports, so that mistake fails in the app too, naming the
  import to add.)
- **Breaking the tests on purpose caught every planted bug**: an unscoped query, no CSRF check, no
  Origin check, a missing UTF-8 check, a login that keeps the old session.

## 1. A web app writes about 100 lines of middleware that every web app will write  (resolved: `http::sessions()`, `http::csrf()`, `http::security_headers()`)

`middleware.gaz` was sessions (read the signed cookie, make sure a CSRF token is in it, write it back
only if it changed), flash messages, the CSRF check on POST, an `Origin` check, security headers,
"who is logged in", and "this route needs a user". The library has the pieces (`http::session`,
`http::session_cookie`, `http::csrf_token`, `http::verify_csrf`) but not the glue, so each app
decides how a handler asks for a session change (this one: add `"session"` to its response, which the
middleware strips, since a response with an unknown key is an error), and the obvious first version has
a trap: comparing the session with the one the token was just added to means a new visitor's token
is never written back. That is the kind of detail that is each app's own to get right or wrong.

- **Done**: the glue moved into `std/http.gaz` as middleware, with the convention documented:
  `http::security_headers($options)`, `http::sessions($secret, $options)` (`$request["session"]`,
  `$request["flash"]`, a response's `"session"`, the cookie written only on a change, measured
  against the cookie as it came, so the trap is the library's to avoid once), `http::csrf($options)`
  (the token and the `Origin`), and `http::with_session()`/`http::flash()` for handlers. The app's
  tests pass unchanged.
- **Left the app's**: `authentication()` and `authenticated()` (only the app knows what a user is) and
  `login_session()`, the new session a login builds with a fresh token, which is the session-fixation
  defence. `middleware.gaz` went from 98 lines to 23.

## 2. Templates can't be part of a namespaced program  (resolved: a `namespace` line and `import` lines in a template)

A `.gazml` file was read as a file with no namespace and no way to `include`. So:
(a) its function (`layout`, `login_page`) was **global**, not `todo::layout`, however the app was
organised; (b) its parameters **couldn't be typed with a kind of the app** (`?User $user` was
`Undefined type: User`, and `?todo::User` was `Namespace todo is not included here`), only with builtin
types; (c) it couldn't `include` anything. The app's templates took untyped `$user`/`$form` and had names
chosen not to collide.

- **Done**: a template may start with `namespace todo;` (and `@template pub name(...)` makes one
  `pub`), so the functions are `todo::layout` and the parameters are typed with `User`,
  `RegistrationForm`, `LoginForm` and `TodoForm`. The app's five templates use it; its tests pass
  unchanged.
- **Done, with `import`**: a template is a module, and imports what it uses on the lines before its
  `@template` line, as any file does at its top: the kinds its parameters name (`import
  "forms.gaz";`), other templates, and the library (`import "std/format.gaz";` for
  `{{ format::number($x, 2) }}`).

## 3. A database connection must not cross `workers()`, and nothing says so  (resolved)

A program that opened `db::open` before `workers()` shared one socket between the workers. Measured:
with one worker it worked (so it passed in development); with three, libpq reported `message type
0x31 arrived from server while idle` as the protocol stream was corrupted, and the workers hung,
ignoring the graceful stop. Worse, a worker that never touched the connection still killed it for
the master and its siblings just by ending or dropping it: closing it sent PostgreSQL's Terminate
on the shared socket.

- **Done**: a `db`, `socket` (not a listener) or `file` made before `workers()` belongs to the
  process that made it. Using one in a worker is an error (`db_run(): this db was opened before
  workers(), and workers can't share one: open one after workers()`), at the first query, in
  development too, and letting go of one in a worker abandons it without the goodbye, so the
  owner's session lives on. `main.gaz` opens each worker's connection after `workers()`, as before.
- **Left**: `http::serve` taking a function that makes the handler, once per worker, would make
  the right place to open a connection the natural one; nothing asks for it now that the wrong place
  fails loudly.
- **Checked, not a bug**: a worker blocked inside a PostgreSQL query does not ignore the graceful
  stop. SIGTERM waits for the request in hand: a 4 second `pg_sleep` was answered with a 200, the
  worker then left `http::serve()`, and the master was gone 3 seconds after the signal. Only a
  query still running when the 10 second grace ends is killed, which is what the grace is for.
  Still open: a master killed with SIGKILL leaves its workers running (macOS has no
  `PR_SET_PDEATHSIG`; [#61](https://github.com/panzer-planet/gazlang/issues/61)).

## 4. No UTF-8 validation, and `len()` counts bytes (resolved)

PostgreSQL refuses text that isn't well formed UTF-8, so an `%ff` in a form was a **500 with a stack
trace in the log**, found by the hostile-input pass, not by writing the validation. There was no
`is_utf8()`, so the app first carried a validator of its own in GazLang. It also gave "200
characters" its real meaning: `len()` counts bytes, so a title of 200 emoji was 800.

- **Resolved** by three builtins in C, `utf8_valid()`, `utf8_length()` and `utf8_chars()`, which
  `forms.gaz` calls; strings stay bytes. `json::encode` and `json::decode` became strict about
  UTF-8 at the same time, since the same bytes wrote invalid JSON.
- **Then made the default** ([#66](https://github.com/panzer-planet/gazlang/issues/66)):
  `http::query()`, `form()` and the router's params refuse a key or value that isn't UTF-8 once
  decoded, as they refuse a bad escape (and `cookies()` leaves such a cookie out), so a handler that forgets the check can't reach
  the database with the bytes. An `%ff` form is now refused by `http::csrf()` (403, its token
  unreadable); `forms.gaz` keeps its checks, since its kinds are also built from plain strings.

## 5. No rate limiting, and no client address  (resolved: `http::client_address()`, a per-client limit)

Argon2id makes a login cost 0.24s of a worker. Eight concurrent wrong logins on 2 workers made a
plain stylesheet wait 0.39s behind them, so on a fixed pool anyone can saturate the app with a few
requests a second. The app counts failures per email in a table and refuses the sixth without
hashing; that stops a guesser on one email and not one trying many, and lets anyone lock a known
email out for 15 minutes. A per-client limit needed the client's address, and behind a proxy the
connection's address is the proxy's.

- **Done**: handlers already had `$request["remote_address"]`, the connection's peer, which behind a
  proxy is the proxy. `http::client_address($request, $trusted_proxies = [])` gives the client's:
  the connection's address unless it is one of `$trusted_proxies`, and only then `X-Forwarded-For`,
  read from the right past the trusted hops, so a client's own header is never believed and the
  default is safe. `throttle.gaz` now counts per client: 5 failures for an email from one address, or
  20 from one address over any emails, refuse the login without hashing; a good login clears only its
  own email and address's failures, so a guesser can't reset its address's count by logging into an
  account of its own. The per-email limit is gone, and with it the lock-out a stranger could cause
  (`TRUSTED_PROXIES` names the proxies in front).
- **Left** ([#62](https://github.com/panzer-planet/gazlang/issues/62)): many addresses against one email are slowed only by Argon2id (a slower per-email limit
  or a captcha would close it); trusted proxies are exact addresses, no CIDR ranges.
  `http::rate_limit($key, $per_minute)` still needs shared state across workers, which means a
  table, a file or a store the library doesn't have, so the counting stays the app's. Lowering the
  Argon2id default for logins is the other lever (0.24s is the library's default, RFC 9106's second
  recommendation; on a pool this small it is a lever against the app).
- **Resolved, found writing `client_address()`'s tests**: `"{CLIENT}"` with a constant was the
  text `{CLIENT}`, and two checks of malformed `X-Forwarded-For` entries passed for the wrong reason.
  A constant's name alone in braces now interpolates it, and a name there that isn't a constant is
  an error when the program is read.

## 6. Calling a handler's helpers needs a test client that doesn't exist  (open: [#48](https://github.com/panzer-planet/gazlang/issues/48))

The tests drive the real router through a `Browser` kind (`tests/support.gaz`, 130 lines with the test database): it builds
request maps, keeps the cookies a response sets, form-encodes bodies, and finds the CSRF token in a
page. Every web app's tests will want this. (The request shape, `method`, `path`, `query`,
lower-cased `headers`, `body` and `remote_address`, is documented in `docs/language.md`.)

- **Today**: `tests/support.gaz`.
- **Options**: `http::client_for($handler)` giving something with `get`/`post`/cookies in the
  library (`std/test.gaz` is the neighbour), and the request map documented in `docs/language.md`.

## 7. Routes are wrapped one at a time  (open: [#49](https://github.com/panzer-planet/gazlang/issues/49))

`authenticated($handlers.create)` is written on 6 of the 11 routes. `Router.use` applies to every
route, so a group of routes with a requirement has no home.

- **Today**: a wrapper function applied at each route.
- **Options**: a second argument or a method on routes (`$app.get($path, $handler, [$middleware])`),
  or `$app.group($middleware, $routes)`. The wrapper is five words, so this is the least urgent.

## 8. Files are found from the working directory, and a program can't ask where it is  (open: [#50](https://github.com/panzer-planet/gazlang/issues/50))

An `import` is from the file's own directory or project root, but `read_file()`, `list_dir()` and `serve_static()` are
relative to where the program was started, and nothing says where the main file is
(`program_path()` is the interpreter). The app must be run from `apps/todo` (`migrations`,
`public`), and `main.gaz` says so.

- **Today**: a comment, and `MIGRATIONS` as an override.
- **Options**: `script_dir()`; or import-style resolution for a relative path given to those
  builtins; or accept it, since a deployed app has a working directory it chose.

## 9. The request is a plain map, so each helper parses it again

`http::form($request)` decodes the body each time: `http::csrf()` does, then the handler's form kind
does. It is cheap here (a few fields).

Decoding also needed a `try` (resolved: `http::form($request, $default)` and
`http::query($request, $default)`). `form()` raised a string for a body that wasn't a form or had a
bad percent-escape, and `query()` for `?show=%zz`, so the list page was a 500 for a link anyone can
write, found by the hostile-filter test. The app carried two `try`/`catch (Error)` wrappers,
`form_fields()` and `query_fields()`, which couldn't tell a hostile request from a bug and would also
have caught running out of call depth.

- **Resolved** by an optional `$default` on `http::query()`, `query_all()`, `form()`, `form_all()`,
  `url_decode()` and `cookies()`, as `to_int($x, $default)` and `date::parse($text, $default)` have
  one: given one, input the client controls that can't be decoded gives it, and a bug is still an
  error. Both wrappers are gone, and `http::csrf()` lost its own `try`. Checking the library for the
  same shape found `http::session()` decoding every cookie, so a stray `%` in any cookie on the
  domain made every request a 500; it now decodes only its own.
- **Today**: parse twice (open: [#51](https://github.com/panzer-planet/gazlang/issues/51)).
- **Options**: the middleware stores `$request["form"]` once.

## 10. Smaller things

- **Redirects** (resolved: `http::redirect($to, $status = 303)`): the router had a private one and
  every app would have written `{"status" => 303, "headers" => {"Location" => $to}, "body" => ""}` itself.
- **PostgreSQL NOTICEs** go to standard error by default: `create table if not exists`
  and `truncate ... cascade` print a line each, on every start and between test checks. A program
  can quiet them (`set client_min_messages = warning`, or `options=-c client_min_messages=warning`
  in the URL), but nothing tells it to. The driver could set `client_min_messages = warning` on connect ([#52](https://github.com/panzer-planet/gazlang/issues/52)).
- **Timestamps** (resolved: `date::parse()`, `date::zone()` and `Zone.at()`) arrive as text in the
  server's time zone (`"2026-10-01 14:04:27.78+02"`), which `date.gaz` couldn't read or show in
  another zone. The todo list now shows when each was added, in the zone `TIME_ZONE` names.
- **Due dates from a form** (resolved: `date::parse_reading()` and `Zone.occurrences()`): a
  `datetime-local` field sends `2026-10-05T14:30`, seconds optional and no offset, which
  `date::parse()` refuses, and `Zone.time()` could only reject a reading the clocks skip or show twice
  with a string, so telling the two apart meant matching its words. `date::parse_reading($text,
  $default)` gives a `date::Reading` (`#days`, `#seconds`) for `Zone.time()`, and `$zone.occurrences($days,
  $seconds)` every time a reading is (none, one or two), so the form resolves as an alarm clock would
  and says which happened. Still the app's: writing a time back as the field reads it
  (`date::iso($moment.days) .. "T" .. $moment.short_clock()`, with the seconds when there are some);
  a `Moment.reading()` writer would be the pair to `parse_reading()` if a second form wants one
  ([#53](https://github.com/panzer-planet/gazlang/issues/53)).
- **Timestamps go in and out of PostgreSQL by hand**: the driver has no time type, so a due time is
  written as `to_timestamp({$due_at})` and read as `floor(extract(epoch from due_at))::bigint`, where
  `created_at` is read as text and parsed. Either works; a column the driver turned into an int (or
  a `Moment`) would remove the casts ([#54](https://github.com/panzer-planet/gazlang/issues/54)).
- **A page that depends on the time is tested around the clock, not with it**: the list reads
  `time()` once per request and passes it to the template, so overdue marking is tested by rendering
  the template with a fixed time, and through the router only with due dates far in the past or the
  future. A clock in `Config` (a function, `time` by default) would let the router tests fix it too
  ([#55](https://github.com/panzer-planet/gazlang/issues/55)).
- **Database errors are strings**, so tests assert on their words
  (`postgres: duplicate key value violates unique constraint "users_email_key"`), which is fragile
  across PostgreSQL versions and has no SQLSTATE to match on ([#56](https://github.com/panzer-planet/gazlang/issues/56)).
- **`@csrf`-shaped repetition in templates** (resolved: `web::csrf_field($csrf)`):
  `<input type="hidden" name="_csrf" value="{{ $csrf }}">` was written by hand in 7 places, two of
  them inside the todo list. Each form now writes `{{ web::csrf_field($csrf) }}`, so the field's name
  and escaping are the library's, and the app's test still checks each form carries the token. Still
  open: the filter's `<input type="hidden" name="show" ...>` repeats in the same three forms, since a
  redirect after a POST has nothing else to go back to, and a page of 40 todos still carries 82
  tokens; a form that forgets the helper is still the template's mistake to make ([#57](https://github.com/panzer-planet/gazlang/issues/57)).
- **Every first visit gets a session cookie, even for the stylesheet or a 404.** `http::sessions()`
  creates the CSRF token as soon as a request has no cookie, and writes it back, so a visitor's
  first response always carries a `Set-Cookie` (checked: `/style.css`, `/login` and a 404 all do), and
  so does a bot's. A shared cache won't keep a response that sets a cookie. Options: make the token
  lazy, made only when a handler asks for it (so `$request["session"]` is read-only until something
  needs a token, and only then written); or leave sessions off the static routes ([#58](https://github.com/panzer-planet/gazlang/issues/58)).
- **An attribute can't be left out by a value**: `{{ }}` writes a value, so the selected filter link
  is `aria-current="{{ $selected ? "page" : "false" }}"` on every link, and an overdue todo's class
  is two `{{ }}` side by side. Both are valid HTML; a template directive for an optional attribute
  would read better, and templates are frozen until the app has used them more ([#59](https://github.com/panzer-planet/gazlang/issues/59)).
- **Static files** (resolved: `http::serve_static()` sends a weak `ETag`, `Last-Modified` and
  `Cache-Control: no-cache`, and answers a matching conditional GET with a 304) had no
  `Cache-Control`/`ETag`, so every page load refetched the stylesheet
  ([#60](https://github.com/panzer-planet/gazlang/issues/60)).
- **No access log**: `http::serve` prints errors and nothing else (it is on the roadmap as a line per
  request "when a program asks"). Checking the live server, the only record of what was asked
  and answered was the client's own; the server's side was silent unless something failed ([#21](https://github.com/panzer-planet/gazlang/issues/21)).
