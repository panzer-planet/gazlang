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

- [#65](https://github.com/panzer-planet/gazlang/issues/65): a request handler has no time limit,
  so even a small regex over a large body can hold a worker for seconds.
- [#66](https://github.com/panzer-planet/gazlang/issues/66): UTF-8 isn't validated at the HTTP
  boundary; call `utf8_valid` on outside text.
- [#67](https://github.com/panzer-planet/gazlang/issues/67): `web::html` takes the `content` of
  `<meta http-equiv="refresh">` as plain attribute text, not a URL.
- [#68](https://github.com/panzer-planet/gazlang/issues/68): slow clients can stall a small worker
  pool; run behind a reverse proxy.
