# Security

## Reporting a vulnerability

Report it privately through GitHub: the repository's **Security** tab, then **Report a
vulnerability**. Please don't open a public issue for it.

## Supported versions

Only the latest release is supported: there is no compatibility promise between releases yet, so
a fix goes into the next one rather than back into an older one.

## Scope

- **The C VM** (`vm/`): memory safety, and the bytecode loader, which should refuse any file it
  can't run safely.
- **`web::html`** (`lib/web.gaz`): a value that changes a page's shape or makes a link run script.
- **`db::sql`** (`lib/db.gaz`): a value that reaches the SQL as anything but a value.
- **`lib/regex.gaz`**: a pattern or input that takes far longer than its size explains.
- **The crypto builtins** (`vm/crypto.c`): random bytes, hashes and password hashes.
- **The HTTP server** (`lib/http.gaz`, `vm/workers.c`).

## Known limits

These are open already, so they don't need a new report:

- [#67](https://github.com/panzer-planet/gazlang/issues/67): `web::html` takes the `content` of
  `<meta http-equiv="refresh">` as plain attribute text, not a URL.
- [#68](https://github.com/panzer-planet/gazlang/issues/68): a client sending its body slowly
  holds a worker. Under `workers()` the master reads every request's line and headers itself and
  hands a worker only a connection whose headers are all in, so slow headers and idle kept-open
  connections hold no worker (each costs an open connection, at most `max_connections` of them,
  and slow headers are answered 408 at `header_timeout`). A body is the worker's to read, so a
  client trickling one holds a worker for up to `request_timeout` (30 seconds), and as many such
  clients as there are workers can hold them all that long. A single process (`gaz -S`, or no
  `workers()`) reads its own requests, so there one slow client holds the server. In production,
  run behind a reverse proxy that buffers whole requests (nginx, Caddy), which keeps slow bodies
  off the workers.
