# HTTP

How `lib/http.gaz` speaks HTTP, as a client and as a server in `workers()` processes
(`vm/workers.c`), and why: keep-alive in a prefork pool, decoding, routing, sessions and the
middleware a web app is built on. For contributors changing the library's HTTP or the worker
pool.

## The client

**HTTP is HTTP/1.1 in GazLang (`lib/http.gaz`) on socket builtins, TLS through OpenSSL**,
linked by default and optional (`make TLS=0`), so the bootstrap still needs only a C compiler.
Not curl through `run()`: a process per request, the headers visible in `ps`, and curl as a
runtime dependency; not TLS of our own, which would be thousands of lines of crypto whose bugs
no output shows. Client keep-alive, proxies, compression and HTTP/2 wait for a program that
needs them ([#19](https://github.com/panzer-planet/gazlang/issues/19)).

- `http.gaz`'s client: `HttpTest` runs it against `tests/fixtures/http_server.php`, over TCP and
  TLS, which writes framing out by hand so it can get it wrong on purpose; ports vary, so what it
  prints is checked by shape, not recorded. The server and `http::Router` are below.

## Serving: prefork workers

**Serving HTTP is `http::serve()` in the same file, in `workers()` processes**, the PHP-FPM
model rather than Node's. **Prefork, not an event loop**: share-nothing processes suit value
semantics and refcounting, need no locks, and a crash takes one request, where an event loop
needs non-blocking sockets and callbacks or coroutines the language doesn't have.

### Workers

- `workers($n)` is one builtin, not `fork()`/`wait()`/`kill()`, so no program can leave zombies or
  orphans; the master stays in C (`workers.c`) for good. Workers report in a page of shared memory
  (`mmap`, a `Slot` per number), not a pipe, since the master only reads it when one dies.
- **A handle made before `workers()` is the process's that made it**: every `Db`, `Socket` and
  `File` records `vm_process` (`workers.c`: 0 at start, raised in each worker's child by
  `fork_worker()`; a counter, since `getpid()` would be a system call on every
  `file_read_line()`) as its owner; listeners are exempt. **Releasing one is an abandon, not a
  close**, in the paths every release takes (`db_close()`, `net_close()`, `file_close()`: decref,
  the collector, the end of the program and an explicit close): a close says goodbye on the shared
  connection and ends it for its owner too. PostgreSQL's socket is pointed at `/dev/null`
  (`abandon_fd()`) before `PQfinish()`, so its Terminate goes nowhere; TLS skips `SSL_shutdown()`'s
  close_notify; a file's descriptor is pointed at `/dev/null` before `fclose()`, whose seek back
  would move the shared offset (nothing can read it after the fork today, but a `parallel()`
  parent would); SQLite's is never closed in a worker (a close can roll back the owner's
  journal), only kept reachable, a `ponytail:` in `sqlite.c`. The per-driver part is
  `DbDriver.abandon`. **On macOS `open_sqlite()` sets `OS_ACTIVITY_MODE=disable`** first: Apple's
  libsqlite3 makes an `os_signpost` on every open, and libtrace's state doesn't survive a fork, so a
  worker's open after the master had opened one crashes in `os_signpost_enabled`. **On macOS
  `open_pg()` in a worker sets `PGGSSENCMODE=disable`** unless something set it: libpq would otherwise ask
  Kerberos.framework for credentials, which sets up Objective-C classes in the worker, and the
  Objective-C runtime aborts a forked child that does (see [Databases](library.md#databases)). Tested by `tests/gaz/workers/inherited_test.gaz` (under the
  sanitizers, each worker's `GAZVM_STATS` line checked by `CVM::leaks()`) and `DbPgTest`.
- **`worker_recycle()` is a third case**, neither a graceful exit nor a crash: the master tells
  `WIFSIGNALED(status) && WTERMSIG(status) == SIGUSR2` apart before the generic failure path,
  restarts at once without the start-up check, and logs `worker N recycled; starting another`.
- **`worker_retire()`** sets `retiring` in its `Slot` (`accepted`, `listening`, `retiring`); the
  master starts the replacement under the same number at its next poll, keeping the retiring pid
  in a second half of its pid table, and sends the retiring one SIGTERM once the replacement has
  set `listening` (on entering `socket_accept()`), or once the number has ended for good. A
  builtin of its own because the retiring worker must go on running the handler while it waits,
  which `worker_recycle()`, never returning, can't. After asking, the worker writes nothing more
  in its slot (`slot = NULL`), which is the replacement's from the fork on. The retiring one is
  reaped, never replaced (an end other than a recycle or a clean exit is a line, `worker N exited
  with code 3 while retiring`); a second hand-over of one number waits until the first one's
  leaver has gone; one still there `STOP_GRACE` after it was asked is killed. Tested by
  `tests/gaz/workers/retire_test.gaz` (the order, the lines, a slow replacement),
  `retire_grace_test.gaz` (the kill) and `HttpServerTest` (a worker slow to start and
  `max_requests` 1: no request waits for a start). **The master polls every 5ms while a hand-over
  is under way** (`handing_over()`), 50ms otherwise, because the retiring worker serves a request
  per connection until relieved and every reconnect costs.
  `ponytail:` noticing the hand-over still waits for a 50ms poll, since the master can't be woken
  (see the program's own thread below). A replacement that never reaches `socket_accept()` (a
  start-up that hangs) leaves the retiring worker serving a request per connection with nothing
  bounding it, so `max_requests` stops limiting its life; a deadline after which the master
  relieves it anyway would lift that. A replacement that dies within a second of starting without
  accepting stops the whole pool, as a recycled worker's always did, though the retiring worker
  is healthy; keeping it and retrying would lift that. `Slot.listening` is set on entering any
  `socket_accept()`, so a worker that waits on a second listener (an admin port) before the
  shared one relieves the retiring worker early.
- **Stopping**: a worker catches SIGTERM without `SA_RESTART`, so a waiting `accept()` wakes;
  `socket_accept()` waits in `poll()` a second at a time, so a stop that lands between its check
  and the wait is still seen. The grace is `STOP_GRACE`.
- **The program runs on its own thread** (see [the C VM](vm.md#how-a-program-runs)), so a fork is that thread alone, with
  no `main()` to end the process: `run()` in `vm.c` exits a worker itself. And a signal to the
  master may land on `main()`'s thread, which is why the master polls every 50ms instead of
  sleeping until one; the stop signals are blocked across each `fork()`, or one sent before a new
  worker has put its own handlers back would only set its copy of the master's flag.

### Requests and connections

- `http::serve`'s refusals: both `Content-Length` and `Transfer-Encoding` are refused as the way
  requests are smuggled past a proxy, and so is a chunk size line or trailer with a lone CR or LF
  (a line end to some proxies, so the same request would be two). `HttpServerTest` runs
  `tests/programs/web_server.gaz` and speaks to it over raw sockets, so requests no client would
  send can be sent.
- **`"request_timeout"`** exists because the per-read `"timeout"` alone let a client trickling a
  byte every few seconds hold a worker for hours; `Reader` checks it before each read, so it can
  overrun by one read's timeout.
- **`"max_requests"` is opt-in**, since forcing it by default would be gaz second-guessing an app
  that has no accumulating state to worry about.
- **Keep-alive is on by default**, since every browser and proxy expects it and a TCP handshake
  per request is the cost it saves; `"requests_per_connection"` 1 is the off switch, so there is
  no flag.
  - **No HTTP/1.0 keep-alive**: the rarer the path the fewer its bugs (`ab -k` gets a connection
    per request). A closing response's `Connection: close` is written by `write_response()` alone.
  - A quiet connection gets no 400 or 408, which nobody would read (`Reader.heard_anything()`).
  - **The idle wait is `socket_wait()`**, which sees a stop within a second. `ponytail:` a request
    already read in part (pipelined) is answered even after a stop (bounded by
    `requests_per_connection` and the master's grace), and the response written after a stop can't
    say `Connection: close`; a `worker_stopping()` builtin would let it.
  - **An idle connection yields to a waiting client**, which is what makes keep-alive safe on by
    default in a prefork pool, where each idle connection would otherwise hold a whole worker for
    `idle_timeout` (a browser opens up to six). Between requests (never before a connection's
    first) a worker waits on its socket and its listener together; when the listener is ready it
    gives its client one to two `YIELD_GRACE`s (10ms), then looks again: a client still queued
    means no worker is free, so it closes; one gone means a free worker took it, and it waits out
    the rest of its idle time (a deadline, not a fresh wait each time round). The grace is drawn
    by each worker because idle workers that look again together all see the client still queued
    and all give up their connections, where one is enough.
    `http::handle()` has no listener and waits on its socket alone.
  - **A connection is handed over at a response where it can be** (`Turns` in `http.gaz`, one per
    worker), since closing an idle one races with a request its client sends at that moment (a
    browser resends; `wrk` counts a read error, a proxy may not resend a POST), and HTTP/1.1 has
    no way to tell an idle client not to send, while a response saying `Connection: close` races
    with nothing. Hence `TURN` (50ms; `requests_per_connection` alone lets a slow handler's 100
    requests keep a waiting client out for seconds), `CROWDED` (5s, since a crowded worker's
    clients pause and a pause is when the next idle close would come) and `HOT_GAP` (5ms: a
    connection per request costs such clients a good part of their throughput, and one that is
    late is late, not gone). The last look at the client is just before the close. Tested by
    `http_turns_test.gaz` (the rules) and `HttpServerTest` (two clients taking turns on one worker
    see one idle close in twenty turns; a busy connection on a 30ms handler gives way within a
    second). `ponytail:` the first idle close of each crowded spell still races, as the
    `idle_timeout` close always does; nothing short of the client saying when it will send next
    would remove it. A proxy avoids both by keeping no more idle upstream connections than there
    are workers and closing them before `idle_timeout` (the README says so). Whether a client is
    waiting is a readable listener, which also holds for the instants before an idle sibling
    accepts it, so a connection past its `TURN` can be closed for a client no worker lacked;
    that costs one reconnect. A connection is closed after its `Connection: close` response
    without draining, so a client that pipelined behind it gets a reset, not an orderly end; a
    lingering close (a half-close and a short read) would lift that, and needs a builtin to
    half-close.
  - The empty lines skipped before a request line are bounded (`MAX_EMPTY_LINES`) so a client
    can't hold a worker with them.
  - Tested by `tests/gaz/lib/http_connection_test.gaz` (`http::handle()` over a socket pair,
    responses compared byte for byte without the `Date` line) and `HttpServerTest` (idle close,
    stop while idle, `max_requests` on one connection, a 500 closing, the yield both ways).

### The access log

- **A line per request answered, on standard error, on by default** (`access_line()` in
  `http.gaz`): `2026-10-07T14:02:09Z 192.0.2.7 GET /search?q=gaz 200 1234 0.412ms`, the time,
  peer address, method, target, status, body bytes sent and milliseconds. Standard error because
  that is where the 500s' lines already go and where a process manager collects a server's
  output; on by default because a server that is silent until something fails was the todo app's
  friction; `"access_log" => false` turns it off, with no format option until a program asks for
  one.
- **Written in `connection()` after the response is sent**, timed from the request's first byte
  (`Reader.first_byte_at()`) to its response written, so a connection's first request doesn't
  count the wait before its client spoke when a kept-open one doesn't count its idle wait; every
  answer gets one line: a
  handler's, the server's refusals (400, 408, 413, ...), a 500 (after its error line, which
  stays) and each request on a kept-open connection; a response the client didn't stay for is
  logged as answered. A connection that closes, or goes quiet, before a byte of a request has no
  line, as it gets no response. `http::TestClient` calls `answer()` and so writes none: a test's
  output stays its own. A refusal after the request line is logged with its method and target
  (`RequestLine`, which `read_request()` fills as soon as it has them), one before it with `-`.
- **ISO 8601 UTC time, not `http_date()`**: it sorts as text, has no spaces, and says its zone.
  **The peer's address, not `client_address()`'s**: X-Forwarded-For is the client's to write and
  only the program knows which proxies to trust; behind one, the proxy's own log has the client.
- **Escaping is one rule**: every byte of the method and target outside printable ASCII (`!` to
  `~`), and a backslash, is `\xHH`, so a line break, a terminal escape or bytes that aren't UTF-8
  can't reach the log as they are, and an escape always means one (a backslash in the text is
  `\x5C`). Not UTF-8 passed through: a bidirectional override is valid UTF-8 too. Fields are split
  by single spaces and none holds one; `-` for what is unknown. A 500's error line shows the
  method and path the same way (`error_prefix()`), since 0x9B and U+0085 are C1 controls a
  terminal acts on.
- **Cheap where it can be**: the time is written out once a second (`LogClock`), text with
  nothing to escape is found by one `trim()` against the bytes that stay (`LOGGED_AS_IS`) rather
  than a loop over its bytes, and the milliseconds are made from whole microseconds, since writing
  out a float cost more than the rest of the line.
- **A closed standard error ends nothing**: `workers()` ignores SIGPIPE in the master and its
  workers for good, so a write to a pipe whose reader has gone (`gaz server.gaz 2>&1 | head`, a
  log shipper restarting) fails with EPIPE and the line is lost, where the default would end a
  worker at its next line and the master, with the pool, at its "starting another". For good,
  since neither goes back to code that wants the default; `run()` gives what it starts the
  default back (`POSIX_SPAWN_SETSIGDEF`), as an ignored signal stays ignored through exec; and a
  program that never calls `workers()` keeps it, so `gaz tool | head` still ends quietly.
  `ponytail:` `http::serve()` without `workers()` (`gaz -S`) still ends on a closed standard
  error; ignoring SIGPIPE around each `print_error()` would lift it. Tested by `HttpServerTest`
  (started through perl, since PHP ignores SIGPIPE and what it starts inherits that) and
  `tests/gaz/workers/sigpipe_test.gaz`.
- **One line is one `write()`**: `print_error()` writes its whole text in one system call
  (`write_whole()` in `builtins.c`), since macOS's stdio writes an unbuffered stream 1024 bytes at
  a time and workers share standard error. A pipe takes only `PIPE_BUF` bytes (512 at the least)
  whole, so the method is cut at 20 bytes and the target at 360, with `\...`, keeping a line under
  512. Tested by `HttpServerTest` (the shape, a forged line and terminal escape in a target, a cut
  target, a line per kept-open request, refusals, the off switch).

### Not built: jitter and a pool that grows

- **`"max_requests"` jitter, not built** ([#23](https://github.com/panzer-planet/gazlang/issues/23)): workers under an evenly spread load retire together; the
  hand-over means that no longer leaves the pool short, only their start-up work lands at once,
  which matters only to an app whose start-up is heavy. If wanted, it is library code:
  `"max_requests"` taking `[$min, $max]`, each worker drawing `rand_int($min, $max)` once (each is
  already reseeded from OS entropy by `fork_worker()`).
- **`workers($n)` is a fixed pool, not built: dynamic sizing (PHP-FPM's `pm = dynamic`/`ondemand`)** ([#24](https://github.com/panzer-planet/gazlang/issues/24)),
  the one real capability gap against FPM's process manager. The master knows only whether a
  worker is alive, has started or retires, not whether it is idle in `socket_accept()` or busy,
  since workers race to accept with no coordination through it. Scaling needs the `Slot` to hold
  a state (idle/busy, with a last-transition time) written on each transition and read by the
  poll loop, spawning more when nothing has been idle for a stretch and sending one worker the
  stop's SIGTERM when it has been idle past a timeout with others to spare, which needs a *target*
  pool size apart from the live count so a scale-down isn't respawned as a crash (the hand-over's
  reaped-not-replaced pid is a start on that). `workers($min, $max)` is the likely shape;
  `ponytail:` no jitter on synchronized scale-down either, matching FPM's own lack of one.

## Decoding, routing and responses

- **Decoding a request is asked for, not done for every request**: `query()`/`form()` give maps of
  strings so a handler never checks a value's type, and `query_all()`/`form_all()` lists (Go's
  `Get` against the full list, not PHP's `tag[]` convention, where a key's type would depend on
  what the client sent). A bad escape is an error, not passed through as PHP and browsers do, so a
  mangled value can't arrive looking valid. `form()` refuses another Content-Type rather than
  giving `{}`. **A `$default` (`form($request, {})`) is given back instead** for what the client
  sent that can't be decoded, as `to_int($x, $default)` does, so a handler needs no `try`, whose
  `catch (Error)` would also catch a bug and running out of call depth: only the private
  `Undecodable` kind turns into it. With no default the `Undecodable` itself carries on, and
  `answer()` turns one the handler left uncaught into a 400 with nothing logged, since what the
  client sent wrong is not the server's failure. It does so whoever built the request map, since
  it can't tell; so only the request decoders let one out, and `url_decode()`, whose text may be
  the program's own, throws its message as a plain error, a logged 500. Pieces split as the WHATWG parser splits them
  (empty ones skipped, no `=` is a value of `""`). Tested by `tests/gaz/lib/http_decode_test.gaz`,
  and end to end by `HttpServerTest`.
- **What a decoder gives a handler is UTF-8, by default**: every key and value of `query()` and
  `form()`, and every path segment the router and `serve_static()` match, is checked with
  `utf8_valid` after percent-decoding (`%ff` is how the bytes get in) and refused as `Undecodable`
  otherwise, one more way input is malformed rather than a mechanism of its own, so a handler
  can't forget it and a database never sees the bytes.
- **`cookies()` leaves out a cookie it can't decode** (a bad escape, or a name or value that isn't
  UTF-8) rather than refusing: the Cookie header is the browser's shared state for the domain, so
  one cookie another app set must not refuse every request; the rest still arrive. It has no
  `$default`, having nothing left to refuse. `session()` reads its own cookie from
  `sent_cookies()`, never through `cookies()`. Tested by `tests/gaz/lib/http_decode_test.gaz` and,
  for path segments, `router_test.gaz` and `http_serve_static_test.gaz`. `url_decode()` alone gives bytes
  back as they are, since `url_decode(url_encode($bytes))` must be `$bytes`; it is the way to raw
  bytes, on a piece of `$request["query"]` or `$request["body"]`. `ponytail:` no option on the
  decoders themselves for raw bytes: `$default` takes the only optional slot, and a raw-bytes
  form decoder waits for a program that needs one.
- **Uploads are `http::multipart()` and `http::multipart_all()`**, the `form()`/`form_all()` split
  over `{"fields" => ..., "files" => ...}`, with the same `$default` and the same `Undecodable`,
  so a malformed body is a 400 and a body of another type is refused rather than read as empty.
  Fields and files are kept apart because a file is a map and a field a string, and a handler
  should know which it holds without asking. RFC 7578 on RFC 2046's framing, and every rule loud:
  - **Held in memory, bounded by `"max_body"`**, which an app raises for a server that takes
    uploads: one limit that already exists, and no temporary files for a worker to leave behind.
    `ponytail:` an upload bigger than memory wants streaming to disk, when a program needs one.
  - **Linear in the body**: each boundary is found with `index_of()`, and a part's head is bounded
    (`MAX_PART_HEAD`) before its parameters are read; a quoted string is found with one
    `index_of()`. `MAX_PARTS` (1000) bounds the maps a body of tiny parts makes.
  - **The framing is strict**: the boundary from the Content-Type's parameter, 1 to 70 bytes; CRLF
    line ends only, since a lone CR or LF in a part's head is how one request is read two ways; the
    close delimiter required, so a body cut off is refused rather than giving the parts before the
    cut. A boundary line with more than padding after the boundary means the boundary was in the
    content, which RFC 2046 forbids, so it is refused rather than guessed at.
  - **A part is `form-data` with a `name`**; one header given twice, a header line without a colon
    or with a space in its name (a folded line), a nested multipart body and a
    `Content-Transfer-Encoding` other than `7bit`, `8bit` or `binary` are refused, since each would
    otherwise give the handler something other than what was sent.
  - **A parameter's name is a token and its value a token or a quoted string**, so a stray `=x`,
    `name x=y`, `name=` or `name=a b` is refused rather than read some way; an empty `name` is a
    part without one.
  - **`filename*` is refused, and any name with a `*`** (RFC 2231's `filename*0`, `filename*1`):
    RFC 7578 forbids them, honouring one would be a second decoding, and ignoring it would read a
    file whose only filename is there as a text field.
  - **A filename is given exactly as sent**, so it can be `../../etc/passwd`: the docs say never to
    use it as a path. **A quoted string has no backslash escapes**: it runs from its quote to the
    next, every byte kept, since browsers send `"` as `%22` and backslashes raw (WHATWG), so reading
    escapes would change or refuse real filenames (`x\\y`, `dir\`). `filename=""`, an empty file
    input, is a file named `""`.
  - **Text is UTF-8, content is bytes**: a part's head (names, filenames, its Content-Type) and a
    field's value are refused otherwise, as every decoded request text is; a file's content is never
    checked. A file's `"content_type"` is its part's, `application/octet-stream` when it has none.
  - Tested by `tests/gaz/lib/http_multipart_test.gaz`, end to end through `http::TestClient`.
- **Routing is `http::Router()`, in `http.gaz` itself**, not a `router.gaz` read as
  `router::Router()`, one namespace naming the other redundantly, for a program already reaching
  for `http::serve`. `$app.handler()` is an ordinary handler, so the server learns nothing. No
  regex in patterns; the path is split before it is percent-decoded. Params go into
  `$request["params"]`, so a handler keeps one argument and tests with a plain map. First match
  wins (Express's rule: nothing to rank). The 308 is so a link works either way and a page has one
  URL. Tested by `tests/gaz/lib/router_test.gaz`.
- **`http::client_address()` reads `X-Forwarded-For` only when `remote_address` is a trusted
  proxy**, so the default (none) can't be spoofed by a client's own header, and it walks the header
  from the right, since every entry left of the last trusted hop is the client's to write; an entry
  that isn't an address ends the walk at the last vouched-for hop rather than guessing. Trusted
  proxies are exact strings (`ponytail:` no CIDR, no IPv6 canonicalisation). Tested by
  `tests/gaz/lib/http_client_address_test.gaz`.
- **`http::redirect()`** is public because every handler that answers a form needs one (the
  router's own 308 is a separate private function). Tested by
  `tests/gaz/lib/http_redirect_test.gaz`.
- **`http::serve_static($dir)`**: a segment that decodes to hold a `/` is refused too (`..%2f` is a
  `..` the segment check never sees; `%2F` is never a separator here). The regression tests aim at
  a file that exists above the served directory, since a 404 alone can't tell a refusal from a
  miss. `gaz -S` (`std/devserver.gaz`, run by `vm.c`'s `-S`) takes no router script as `php -S`
  can, since `import` takes a string literal resolved at parse time, never a runtime-named file
  (the same reason there is no type-tag deserialization). Tested by
  `tests/gaz/lib/http_serve_static_test.gaz` and, end to end, `DevServerTest`.
  - **The ETag is `W/"size-mtime"`, weak**, since a size and a time in whole seconds are one
    `file_info()` call where hashing would read the whole file on every request, and they can't
    promise the bytes are the same: a rewrite of the same size within one second keeps its tag.
    `ponytail:` nanoseconds in `file_info()` would narrow that window.
  - **`Cache-Control: no-cache` by default**, the one value that is right for any file: the browser
    keeps it and revalidates each time, which the tag makes a 304. A long `max-age` is right only
    when a file's name changes with its contents, which only the program knows, so it is the
    `"cache_control"` option (refused names as `with_defaults()` refuses them elsewhere).
  - **Conditional requests follow RFC 9110**: only GET and HEAD; `If-None-Match` (a list, or `*`)
    compared weakly, as a GET may, and when it is present `If-Modified-Since` is ignored. A 304
    carries the three validator headers and no body (the writer already sends no
    `Content-Length` for one). `ponytail:` `If-Modified-Since` matches only `Last-Modified`'s own
    text, as browsers send it back, so a later date gets a 200, never a wrong 304; parsing HTTP
    dates would lift it.
  - A path that is neither a file nor a directory (a pipe, a device) is a 404, as `file_info()`'s
    `"kind"` says, rather than the error `read_file()` gives.

## Cookies, sessions and middleware

- **Cookies and signed sessions**: a session is a map, `json::encode`d and signed as one value with
  `crypto::sign`/`crypto::unsign` (HMAC-SHA256, checked with `crypto::equals`, never `==`).
  `write_response()` writes any header's list value as one line each, not only `Set-Cookie`'s.
  Tested by `tests/gaz/lib/http_session_test.gaz` and end to end by `HttpServerTest`.
- **Middleware for a web app** is the glue the todo app proved (`apps/todo`, whose tests passed
  unchanged on it): library middleware because the glue is where the security bugs live, and each
  app writing its own gets them its own way. Headers outermost so a 403 and a redirect get them
  too; sessions before csrf, which reads the token. Tested by
  `tests/gaz/lib/http_sessions_test.gaz`.
  - **A handler asks for a session change by adding `"session"` to its response**, since the
    response is the one thing a handler returns: no global, mutable request or second return
    value. The flash is kept out of the session so a handler that passes the session on doesn't
    show it again.
  - **The cookie is compared against the session as it came, *before* the token is added**:
    compared with the session after, a new visitor's token is never written and every form of
    theirs is a 403. A test plants exactly that mutation.
  - **`http::csrf()` checks the `Origin` as well as the token**, a second, independent line that
    holds if a token leaks; a hostile non-form POST is a 403, not a 500. Without `http::sessions()`
    before it every request is an error, since a wiring mistake that answered 403 for ever would
    look like a user's problem.
  - **The secret must be 32 bytes or more**: a short signing secret is the program's mistake, and
    one that can be guessed forges every session.
  - **Who the user is stays the app's** (and the login that builds a *new* session, the
    session-fixation defence): the library doesn't know what a user is.

## Testing a handler

- **`http::TestClient` goes through `answer()`, the server's own path**: the
  request is written out as bytes and read by `read_request()`, the handler's response written by
  `write_response()` and read back by the client's `read_response()`, over a `Reader` holding the
  bytes instead of a socket (`Reader(null, $bytes)`). So a test sees what a browser would, refusals
  and 500s included, and nothing about a request or a response is described twice. Its header
  names and values go through `checked_header()`, `http::request()`'s own check, so a test can't
  smuggle a header in; a `Reader` with no socket writes no interim `100 Continue`, and a
  `Transfer-Encoding` the test gives gets no `Content-Length` beside it. The serve() options the
  request's reading takes (`"max_body"`) are the client's, by the same names.
- **In `http.gaz` itself**, as the router is: it needs the server's private functions, and a
  second file declaring `namespace http` couldn't be compiled by its path, being then another
  project's namespace (see [the library](library.md)).
- **A handler that raises is a 500, not an error in the test**, since that is what a client sees;
  the log line on standard error says why, as the server's does.
- **Redirects are followed only by `follow()`**, since where a response sends the browser is
  usually what the test checks. The `Location` is resolved against the last request's target by
  `resolve()`, as `http::request()` resolves one, and only this host is followed, `//` included.
  `ponytail:` a 307 or 308 after a POST is followed with a GET; keeping the method needs the
  request kept.
- **Cookies are one site's**: Path, Domain, Secure and Expires are not read, `Max-Age` of 0 or less
  forgets one, and one with no name (no `=`, or nothing before it) is skipped, since no Cookie
  header could send it back by name. A `Cookie` header a test gives wins over the jar, so a forged cookie can be sent.
- **`http::form_token()` reads exactly what `web::csrf_field()` writes**, so finding the token needs
  no app-specific pattern; `session($secret)` reads the cookie as `http::sessions()` does, for what
  a page doesn't show.
- Tested by `tests/gaz/lib/http_test_client_test.gaz`, and by the todo app's tests, which drive its router
  through it.

## Known limits

- `ponytail:` writing a response has only the per-write timeout; the stop grace
  is fixed; while workers drain, new connections queue in the listener's backlog (the master
  holds it too) and are reset when the program ends, where closing the listeners first would
  need `workers()` to know which sockets are listeners; a master killed with SIGKILL leaves its
  workers running (Linux's `PR_SET_PDEATHSIG` would end them, macOS has nothing like it;
  [#61](https://github.com/panzer-planet/gazlang/issues/61)).
