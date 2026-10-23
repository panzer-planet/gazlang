# Builtins and the standard library

The rules behind the builtins (`builtin_info[]` in `vm/builtins.c`) and the standard library
(`lib/`), builtin by builtin and file by file: what each does where platforms disagree, how it
is tested, and what it leaves for later. For contributors changing either; what each one does
for a program is in [the language reference](language.md), and HTTP has [a page of its
own](http.md).

## Strings, lists and types

- **UTF-8**: **strings stay bytes**, since a text type would split every API in two. `utf8_valid`
  is RFC 3629 written out in C (`utf8_character()` in `builtins.c`, no locale), and the three
  share that one decoder so they can't disagree. **Builtins, not a library**: a check belongs on
  every request path, over every byte of a form field, which is C's work. `tests/Utf8Test.php`
  checks all three against PCRE's UTF-8, which shares no code with them.
- **Case and slicing by characters**: `utf8_upper`, `utf8_lower`, `utf8_fold` and `utf8_slice`,
  on well formed text only, as `utf8_length` is. `upper`/`lower` stay bytes, safe on any input,
  so no program changed meaning. The mappings are the full ones that hold in every language
  (`SpecialCasing.txt` without its conditions, so ß uppercases to SS; no Turkish or Lithuanian),
  plus the one condition that isn't a language's, the final sigma, read as every widely used
  implementation reads it: case-ignorable characters are skipped first, so one that is also cased
  (`ʰ`, U+0345) never counts as the cased letter before a sigma. Folding is `CaseFolding.txt`'s C
  and F lines. **The tables are the standard's own files, not the platform's**:
  `php tools/unicode.php` downloads a pinned version into `vm/build/unicode/` and writes
  `vm/unicode_tables.h`, which is checked in so a build needs no network; moving to a new
  Unicode is changing `VERSION` there and reviewing the diff. `vm/unicode.c` reads them (binary
  search; ASCII never reaches a table). `tests/Utf8CaseTest.php` checks every code point and
  random text heavy in the hard cases against both PHP's mbstring and ICU, and `utf8_slice`
  against `mb_substr`; the oracles map a line at a time, since mbstring given a whole text
  converts it in pieces and can miss what follows a sigma at the end of one. Title case waits for
  a program that wants it.
- **Width by columns**: `utf8_width`, the columns a terminal draws text in, for the terminal
  toolkit (`term::width()` counted characters, so CJK and emoji were two columns off and a
  combining mark one). **GazLang's own rule, never `wcwidth()`**, whose answer is the platform's and
  the locale's: a code point is 0 when it is a nonspacing or enclosing mark (`UnicodeData.txt`'s
  Mn and Me), a format character (Cf: the zero-width joiner and space, the soft hyphen, the byte
  order mark, the bidi controls) or a Hangul vowel or trailing consonant
  (`HangulSyllableType.txt`'s V and T, which join the leading consonant before them, as every
  `wcwidth` since Kuhn's counts them); 2 when `EastAsianWidth.txt` says W or F; and 1 otherwise,
  which takes in a control character (C0, DEL, C1) and a tab (the toolkit expands tabs and refuses
  control bytes itself), the ambiguous-width characters (as outside an East Asian locale), private
  use and unassigned code points (the file's own W for the unassigned parts of the CJK planes). A
  mark wins over wide, for the seven combining marks that are both (U+3099, the voiced sound mark).
  Emoji with default emoji presentation are every one Wide already except the 26 regional
  indicators, which are 1 each so that a flag, a pair of them, is 2; so `emoji-data.txt` isn't read,
  and the rule needs no emoji property. **Not refused**: a byte that isn't well formed counts 1 and
  reading goes on at the next byte, the one `utf8_` builtin after `utf8_valid` that takes bad text,
  since it is asked of text being drawn and a terminal draws something for every byte. The tables
  (`zero_width_ranges`, `wide_ranges`) come out of the same `php tools/unicode.php` run as the case
  tables, so they are one Unicode version; `column_width()` in `unicode.c` is two binary searches,
  and everything below U+00AD (the soft hyphen, the first zero-width code point) is 1 without one. A
  call on a one-character string costs what `utf8_length` does (a million calls on a plain build:
  about 26ns each for ASCII and 32ns for a wide character, where `len` costs 25ns, so the call is
  most of it), so the toolkit asks per cell and no per-character builtin
  is needed. `tests/Utf8WidthTest.php` checks every code point against Python's `unicodedata` (its
  categories and East Asian widths, its names for the jamo), which shares no code with the tables,
  wherever its Unicode knows the code point. `ponytail:` sequences aren't read: a text-presentation
  character followed by U+FE0F (`❤️`) counts 1 where many terminals draw 2, and a family joined by
  zero-width joiners counts each picture where some terminals draw one; terminals disagree among
  themselves there, so no rule can match them all.
- Lists and maps: `map`, `filter`, `reduce` and `sort` call back through `call_value()` in `vm.c`,
  checked as a call written in the program is, after every type is checked. A comparator-less
  `sort` is `binary_op(OP_CMP)`; `sort` is a defined merge sort (`merge_sort()` in `builtins.c`)
  because a comparator can see which comparisons are made. The index or key goes to a callback
  by what it *needs* (`callable_min_args()` in `vm.c`: its fewest, or -1 for a builtin, a kind or
  a non-function), not what it accepts, so a callback's meaning never depends on a default
  someone adds, and a builtin's extra parameters stay options (`to_int($x, $default)`).
- Types: `kind_name` gives the name with its namespace, since the bare name is
  `last(split(..., "::"))` and the other way would be impossible. `object_id` is a counter from 1
  in `object_new()`, reset by `run_program()`, not the address, which is reused and differs from
  run to run.

## Files and directories

- I/O: `real_path()` treats `""`, a NUL byte, `file/` and `file/..` as nothing, where platforms
  disagree.
- **File handles**: `T_FILE`, refcounted like a socket or a db. **Modes are one argument, not more
  names.** `file_read` reads into the heap, never `call_builtin()`'s frame (`FILE_READ_MAX`, 16
  MiB). No `file_tell`: `file_seek($f, 0, "current")` is the position.
  - **The end is where the file ends now**: C's end-of-file flag sticks, so every short read
    clears it (`finish_read()`). Tested with two handles on one file. `ponytail:` a line read
    while it is half written comes back as two lines (getline can't tell a last line from one
    still arriving); a reader that cares reads bytes.
  - **stdio wants a flush or a seek between a write and a read** on an `"r+"` handle, so each
    handle remembers what it did last (`File.last`) and seeks to where it is when that changes
    (`usable_file()`).
  - **`file_close()` reports a failed write** (`fflush` and `fclose`'s errors; the test provokes
    `EFBIG` with `ulimit -f` and SIGXFSZ ignored). **Releasing the last reference can't raise**
    (it happens in `decref`), so a file dropped unclosed loses such an error. `ponytail:` a
    `finally`-shaped release that could raise would lift it.
  - **`file_sync()` is GazLang's rule, not the platform's**: `fflush`, then `fcntl(F_FULLFSYNC)`
    where the system has it, `fsync()` where it hasn't or `F_FULLFSYNC` fails (`sync_fd()` in
    `builtins.c`). A reader is refused, being a mistake to name, not a no-op. Nothing outside the
    kernel can see the disk, so the tests check that it succeeds, writes the buffer out (a second
    handle reads it before any close), reports a failed write (`ulimit -f`) and refuses what it
    should; the `F_FULLFSYNC` branch is checked by reading the code.
  - **`file_truncate()`** is `fflush` then `ftruncate`; a negative length is an error of its own.
  - **Three builtins, not `read_line($f)`**: an optional handle would make `read_line`'s meaning
    depend on an argument's type. A directory is refused in every mode (`EISDIR` written out,
    after an `fstat()` of what `fopen()` opened, since it opens one for reading); a pipe is what
    streaming is for, where `read_file()` insists on a regular file. Close-on-exec, as a socket
    is. **`workers()` calls `fflush(NULL)` before it forks**, so a writer's buffer isn't copied
    into every worker to be written again by one that ends with `exit()`. Tested by
    `tests/gaz/workers/inherited_writer_test.gaz`. `ponytail:` a `foreach` can't be lazy, so a
    program loops on `file_read_line()`.
- `file_info()`: null for `ENOENT`, `ENOTDIR` and a NUL byte, any other failure an error; `"mtime"`
  whole seconds (nanoseconds wait for a program that needs them). **One builtin and a map, not
  `file_size`/`file_mtime`/`is_link`**: one name, one system call, and room for a field.
- `chmod()` follows a link (there is no `lchmod` on Linux, and a link's own bits mean nothing
  there); `check_mode()` is shared with `make_dir`.
- `set_mtime()`: `utimensat()`. `ponytail:` whole seconds, so a tool comparing times can't tell
  two writes in one second apart; nanoseconds in `file_info()` and here would lift it.
- `symlink()`/`readlink()`: an empty target is `ENOENT`, written out, since systems differ on it;
  `readlink` reads into a heap buffer that doubles until the text fits, and words "not a link" its
  own way, since `EINVAL`'s are "Invalid argument". Named as the system calls are, where
  `make_link`/`read_link` would be a fourth spelling to learn.
- `sync_dir()`: `open(O_DIRECTORY)` and `sync_fd()`. **A builtin of its own rather than
  `file_sync()` taking a path too**: an argument whose type changes what a builtin is would be the
  `read_line($f)` mistake again.
- `chdir()`: `cwd()` is `getcwd()`, never cached. Tested by `tests/gaz/workers/chdir_test.gaz` (a
  worker moves and recycles itself, and the next one the master forks is where the master is).
- `main_dir()`: `realpath()` of the main file with its last name cut off (`directory_of()` in
  `vm.c`), worked out by `main()` once, before the program runs, so a `chdir()` can't move it and
  a worker inherits it. **`null` with no main file** (piped source, `-e`, the `gaz test` and `-S`
  bootstraps), not the working directory, since pretending would hide the case where a program's
  files can't be found. **Bytecode is its own directory**, as its `@` locations resolve against
  it. **Not the main file's own path**: a program wants the files beside it, and the name would
  only invite parsing it. Tested by `tests/gaz/files/main_dir_test.gaz`, which runs `gaz` on files
  it writes: by relative and symlinked paths, after a `chdir()`, as bytecode, under `gaz test`,
  piped and with `-e`. The fuzzer needn't skip it: its value is the program's directory, which a
  replay runs in again.
- `rename_file()`: `EXDEV` written out, since its words differ between Linux and macOS. No
  copy-and-delete fallback: a move that can't be atomic shouldn't pretend to be.
- Directories: `list_dir()` sorts with `str_cmp`, since `readdir()`'s order is the file system's;
  `make_dirs()` works on a heap copy of the path it cuts up; `delete_file()` writes out `EISDIR`,
  since `unlink()` says EPERM on macOS; a NUL byte in a path is `ENOENT`, as no name holds one.
  `StdlibTest::test_directories` runs one snippet that makes, lists and clears `tests/.tmp/dirs`,
  clearing a failed run's leftovers first so it can be recorded; its names avoid differing only in
  case, which macOS's file system can't hold.
- `read_line()`: `getline()` on `stdin`, through the stdio buffer `read_stdin()` reads too, so the
  two share the input without losing a byte. Tested through `CliTest` rows with
  `tests/corpora/cli/lines.txt`.

## Processes and sockets

- `run()`: `posix_spawnp`. Standard input is `$input` in a temporary file unlinked before the
  program starts (a pipe would need writing while reading two, and SIGPIPE when the program stops
  reading, which Linux can't turn off per pipe), or `/dev/null` when empty, so a piped program's
  own input stays its own. Both outputs are read together with `poll()` so neither pipe fills and
  blocks the child. A signal's status is negative (Python's rule), where a shell's 128 + N is
  ambiguous. Its options are checked as the library's option maps are (`run() has no option "x":
  only output, timeout`), and tested by `tests/gaz/processes/run_options_test.gaz`.
  - **`"output" => "inherit"` is one setting for all three streams**, not one per stream: what
    asks for it (a build's progress, an editor) wants all of them, and a separate `"stdout"` or
    `"stderr"` key can come later without changing what this one means. Standard input is
    inherited too, since an editor needs the terminal; `$input` with it is an error, as giving the
    program a file there would make `"inherit"` mean two things. The VM's own output is flushed
    before the program starts, so what it prints comes after what was printed before. A GazLang
    program in raw mode lends the child the terminal as it was before (`term_lend()` in `term.c`)
    and takes raw mode back after (`term_take_back()`), since inheriting is where a child meets
    the terminal: a line-reading child would otherwise get no echo and no Ctrl-C. Raw mode stays
    on as far as the program knows, so the mode it saved is never read again: what it puts back on
    the way out is the mode it found, not one the child left (an editor killed at its limit, an
    `stty -echo`).
  - **`"timeout"`**: the deadline is on the monotonic clock (`monotonic_seconds()`, net.c's). Past
    it, SIGTERM, then SIGKILL after `RUN_GRACE_SECONDS` (2), and the program is always reaped, so
    no zombie is left; signalling before reaping is safe, since an ended child keeps its pid until
    it is waited for. Collected output is read by `poll()` with what is left of the deadline (in
    pieces, since `poll()` takes an int of ms); once it passes the pipes are closed rather than
    drained. An inheriting program is waited for by `waitpid(WNOHANG)` every `RUN_TICK_SECONDS`
    (10ms), since `waitpid()` can't wait with a limit: a SIGCHLD handler would be the whole
    process's, taking the signal from `workers.c`, and pidfds and kqueue are each one system's.
    Without a timeout it is a plain `waitpid()`. The error, not a status, so a stopped program
    can't pass for one that failed by itself. **The limit is the program's, not its output's**:
    with a timeout, a program that has ended by the deadline (looked at once with `WNOHANG` there,
    before any signal) gives its status and what was read by then, even if a child of its own
    still holds its output, and what that child writes later isn't collected; one that closed its
    outputs and runs on is stopped. Without a timeout `run()` waits for the outputs to close, as
    it always has.
  - **The program stays in this program's process group**, so Ctrl-C reaches both, as in a shell
    script, and an inheriting program can read the terminal (a background group reading it gets
    SIGTTIN). The price: a timeout signals the program alone, so a child it started (`sh -c "sleep
    100; ..."`) runs on, though closing the pipes means `run()` doesn't wait for it, and its next
    write to them ends it with SIGPIPE. A group of its own would end them all but leave the whole
    tree running when this program is interrupted. `ponytail:` an option to signal the group when a
    program needs it.
- Sockets (`net.c`): **the address text is `ipaddr.c`'s, not `inet_ntop()`'s**, whose IPv6
  spelling differs between systems; checked against Python's `ipaddress` on 295 addresses
  (`tests/IpTextTest.php`, on its own so no network is needed) and by
  `tests/gaz/sockets/peer_test.gaz`. `workers($n)` is `workers.c` (see [HTTP](http.md#serving-prefork-workers)); the fuzzer
  skips it with the socket builtins, and `worker_recycle()`, `worker_retire()`,
  `worker_deadline()`, `worker_accept()` and `worker_release()` too. `worker_accept()` and
  `worker_release()` are the worker's side of the master reading request heads (see
  [HTTP](http.md#the-master-reads-request-heads)): builtins, not a library over sockets, since the
  master is C that no GazLang runs in, and a connection reaches a worker as a descriptor passed over
  its channel, which `net_adopt()` in `net.c` makes a socket as `socket_accept()` would. Both give
  `false` outside a worker, as `worker_retire()` does, so `http::serve()` branches once, and both
  check their arguments there too (the rule's every key, a listener, a connection of this
  process's), tested by `tests/gaz/lib/http_master_test.gaz`; inside one by
  `tests/gaz/workers/handed_test.gaz`. `worker_deadline()` is the handler's deadline's (see [HTTP](http.md#the-handlers-deadline)):
  a SIGALRM timer whose handler writes what it was given and ends the worker, a no-op giving `false`
  outside a worker, since a single process has nobody to start another; it still checks its socket
  (open, a connection, this process's) and seconds there, and writes a TLS connection nothing
  (`SSL_write()` isn't async-signal-safe). **Server-side TLS** (`socket_tls_server()`,
  `socket_tls_accept()`, `socket_tls_protocol()`, see [HTTP](http.md#tls)) is OpenSSL's in `net.c`
  too: one context, made from the files when first asked and again when they change (device,
  inode, size and time, so a renewal by a moved symbolic link is seen), TLS 1.2 or later, no
  renegotiation, ALPN from the options' `"protocols"` (the server's order wins, and a client
  offering none of them goes on without ALPN), a session ticket key drawn before `workers()`. The
  handshake is non-blocking for its own call with `poll()` for what is left of `$seconds`, as
  `tls_read_within()` reads, so a stall ends at the deadline; a client speaking plain HTTP
  (`SSL_R_HTTP_REQUEST`) gives `false` with the connection left plain, and the errors are in gaz's
  words where someone looking needs them (`the client refused the certificate`, `the connection
  closed during the handshake`, OpenSSL 3's `unexpected eof` included), since OpenSSL's differ
  between versions. Each needs a TLS build; `make TLS=0` makes all three the usual error. `HttpTest` trusts `tests/fixtures/tls/` through
  `SSL_CERT_FILE`; an IP is checked as an address, with no SNI. SIGPIPE is ignored around each
  call and put back after, as curl does: per socket only macOS can turn it off, and ignoring it for
  good would change what a program writing to a closed pipe does. OpenSSL reports a socket timeout
  as wanting to read; `net.c` says `timed out`. `socket_read($socket, $seconds)` waits in a
  `poll()` first when `$seconds` is shorter than the socket's own timeout, and gives `null` rather
  than an error when it runs out, since running out is what the caller asked about (a deadline,
  `http::serve()`'s); the socket's own timeout is set once, by `setsockopt()`, and stays the error.
  Over TLS a readable socket is no promise of data (a TLS 1.3 session ticket after the handshake,
  part of a record), so there the descriptor is non-blocking for that one call and `SSL_read()`'s
  wants (to read, or to write for a key update) are waited for in `poll()` for the time left
  (`tls_read_within()`); tested against `tests/fixtures/http_server.php` by `HttpTest`.
  `socket_wait()` exists for kept-alive connections: a worker idle on one must see a stop
  (`socket_read()` retries on `EINTR`, so a stop never interrupts it) and a client waiting on the
  shared listener, both one `poll()`, waited a second at a time as `socket_accept()` does. A
  hang-up or error counts as ready, since `socket_read()` won't wait on either, and so do bytes
  OpenSSL already decrypted. Tested by `tests/gaz/sockets/wait_test.gaz`, `inherited_test.gaz` and
  `HttpServerTest` (a SIGTERM during a 60-second wait ends it). `ponytail:` at most 16 sockets, a
  fixed array; a TLS record only partly arrived reads as ready and `socket_read()` then waits for
  the rest.

## Databases

Databases (`db.c`, drivers `sqlite.c` and `pg.c`): **four builtins whatever the drivers**, since
a builtin takes its name from every program: the URL's scheme picks a `DbDriver` (open, run, close)
in C, so a new database is a file and a table entry. **The builtin takes the database's own
placeholders**, not rewritten: rewriting means reading string literals in C.

- **What the database refuses is a `db::Failure`, built in GazLang from what C hands over.** A
  driver's `run()` keeps the database's own account on the handle (`Db.error`, a map: SQLite's
  `sqlite3_extended_errcode()`; PostgreSQL's `PG_DIAG_SQLSTATE`, `PG_DIAG_CONSTRAINT_NAME`,
  `PG_DIAG_TABLE_NAME` and `PG_DIAG_COLUMN_NAME`), cleared by the next `db_run()`, and raises the
  message as it always did; `db_error($db)` reads it, and `Db.execute()` in `lib/db.gaz` catches the
  error, asks `db_error()` and throws a `Failure` when there is something, or the error unchanged
  when there isn't (a closed handle, a parameter no database can store: gaz's to find, not the
  database's). **Not raised from C as an object**: C raises messages, and making a library's kind
  in `caught()` would tie the VM to a name in `lib/` and put the classification in C; **not a
  second return from `db_run()`**, which would change what every caller of it gets. The cost is
  that an uncaught one prints as a thrown `Error` does, its trace from `Db.execute()`, without the
  `at <std>/db.gaz:N` a runtime error's first line had; the message is the same.
- **The classification is the library's, written out** (`sqlite_problem()` and `postgres_problem()`),
  so it is the same on every platform and a test can read it; C only names fields. **An enum**,
  since a problem is one of a closed set compared by identity, and a misspelt
  `db::Problem::Uniqe` is an error when the program is read, where a string would quietly never
  match. The table:

  | `db::Problem` | SQLite (extended code, or primary) | PostgreSQL (SQLSTATE) |
  | --- | --- | --- |
  | `Unique` | 2067 `SQLITE_CONSTRAINT_UNIQUE`, 1555 `_PRIMARYKEY`, 2579 `_ROWID` | 23505 `unique_violation` |
  | `ForeignKey` | 787 `SQLITE_CONSTRAINT_FOREIGNKEY` | 23503 `foreign_key_violation` |
  | `NotNull` | 1299 `SQLITE_CONSTRAINT_NOTNULL` | 23502 `not_null_violation` |
  | `Check` | 275 `SQLITE_CONSTRAINT_CHECK` | 23514 `check_violation` |
  | `Retry` | 517 `SQLITE_BUSY_SNAPSHOT` | 40001 `serialization_failure`, 40P01 `deadlock_detected` |
  | `Busy` | any other `SQLITE_BUSY` (5) or `SQLITE_LOCKED` (6) | 55P03 `lock_not_available` |
  | `Disconnected` | none | class 08, 57P01 `admin_shutdown`, 57P02 `crash_shutdown`, 57P03 `cannot_connect_now` |
  | `Other` | everything else | everything else, and no SQLSTATE |

  `Retry` is apart from `Busy` because what to run again differs: after `Retry` the transaction
  can't go on and must be rolled back (as `Db.transaction()` does) and run again from the start,
  while a `Busy` statement can simply be tried again. An exclusion
  constraint (23P01) is `Other`, not `Unique`, since no SQLite code matches it.
- **An error libpq makes itself has no SQLSTATE**; when the connection is gone `pg.c` gives it
  08006 (`connection_failure`), PostgreSQL's own code for that, so the library needs no second
  signal. `SQLITE_NAMES` is every result code `sqlite3.h` defines, by number, since SQLite has no
  call that names one; a code a newer SQLite adds has no name (null) and is classified by its
  primary code. `ponytail:` SQLite gives no constraint, table or column outside its message, and a
  failed `db::open()` is a plain `Error` (no handle to ask, and libpq gives no SQLSTATE for a
  connection refused).

- **`Sql` isn't `pub`**, so `sql()`, `raw()` and `Db` (one namespace) are what make and take one; a
  pub function may still declare `: Sql`, and `kind_of($fragment)([...], [])` stays as deliberate as
  `db::raw()`, its constructor still checking parts and values. `Sql.render($placeholder)` gives
  `[$text, $params]`, walking the parts with one counter; values are checked when the `Sql` is made,
  so the error is at the line that wrote it. An empty list is an error because rendering it `(null)`
  makes `not in` quietly match nothing.
- **The numbered-placeholder rule**: with PostgreSQL `db::sql"select $1, {$x}"` would render
  `select $1, $1` and bind `$x` twice, and SQLite reads `?1` as the first value however many `?`
  there are; a false alarm is accepted, loud being the safe side. The call-in-braces check is a
  linear scan (`check_no_call()`). `transaction()` uses the builtin for its own fixed statements.
- `tests/gaz/lib/sql_test.gaz` renders both styles with no database; `db_test.gaz` and
  `pg_check.gaz` run them. `ponytail:` no helper joins a list of fragments (a bulk insert of many
  rows is one `values {$row}` per row, or a loop in a transaction).
- **libpq is loaded, not linked** (`load_libpq()` in `pg.c`, `dlopen` on the first `postgres://`
  open), since linked it cost every start of gaz about 7ms (it brings OpenSSL 3 and Kerberos) for
  programs that never open a database. Building needs only `libpq-fe.h`, so "built in" means
  compiled against the header; the directory make found the library in is built in and tried
  first, then on Linux the system's own search (`libpq.so.5`), and on macOS Homebrew's two prefixes
  and `/usr/local/lib`, by whole paths only. Missing, `db_open` raises a catchable error naming every file tried and how to install
  it. **`workers()` loads it before it forks** (`pg_load_before_fork()`), when the program names
  `db_open` (`Program.opens_databases`, set by the loader from `CALL_BUILTIN` and `PUSH_FN`, the
  only ways a builtin is reached, so a server without a database never loads it): on macOS
  Homebrew's libpq brings in Kerberos.framework, whose Objective-C classes can't be set up in a
  child forked from a process with two threads, so a worker loading it itself was killed; elsewhere
  it saves each worker (and each recycled one) the load. **On macOS `open_pg()` in a worker sets
  `PGGSSENCMODE=disable` unless it is set** (a program that never forked keeps libpq's own
  default): libpq's default, `prefer`, asks Kerberos for
  credentials on every TCP connection, and Kerberos.framework sets up Objective-C classes to read
  its preferences, which the runtime refuses in a worker. As libpq's own variable it is the lowest
  setting, below a URL's `gssencmode`, a service file's and the user's environment, so GSS
  encryption is one `?gssencmode=prefer` away; `ponytail:` GSSAPI (that, or a server asking for
  `gss` authentication) still aborts a worker unless gaz was started with
  `OBJC_DISABLE_INITIALIZE_FORK_SAFETY=YES` (read when the process starts, so gaz can't set it
  itself), and run() passes the variable on; `PGGSSENCMODE` itself is seen by the worker's
  `getenv()` and inherited by what it starts with `run()` (`psql`, `pg_dump`). TLS, SCRAM and `.pgpass` set up no classes. Tested by
  `DbPgTest`'s `own` run of `tests/db/pg_workers.gaz` and its `refused` run, which needs no
  server: a worker reaching a closed port must get the catchable error. On macOS libpq is looked
  for by whole paths only, since dyld would find a name alone in the working directory.
- `make SQLITE=1`/`PG=1` make a missing library (libpq's header) an error, which CI asks for. SQLite is tested by
  `tests/gaz/lib/db_test.gaz` on `:memory:` (recorded, sanitized, leak-checked); PostgreSQL by
  `DbPgTest` running `tests/db/pg_check.gaz` against the server `GAZLANG_TEST_PG` names (skipped
  without), on temporary tables. `db_test.gaz` makes each `db::Problem` but `Disconnected` happen in
  SQLite (two connections to a temporary file for `Busy` and `Retry`) and checks the whole table
  through `db::Failure`'s constructor; `pg_check.gaz` has the server raise every SQLSTATE the table
  names (`raise exception ... using errcode`), and a real lock timeout and a connection it ends.
  `ponytail:` a bool parameter is 0/1 in SQLite, no blobs going in,
  and PostgreSQL's numeric, timestamp without a zone, date and json come back as text.
- **A timestamptz is a time** (an int, seconds since 1970 began in UTC), since it is an instant and
  every time in GazLang is that int (`time()`, `date.gaz`), and so a program storing ints in SQLite
  reads the same shape from both. `read_timestamptz()` in `pg.c` reads the text DateStyle ISO writes
  (initdb's default), step by step: the offset says only how the clocks read, so the session's
  TimeZone can't change the time; a fraction is dropped, as `date::parse()` drops it (so before 1970
  it rounds down); BC years count as `date.gaz` does (1 BC is year 0). **Refused, loudly**: another
  DateStyle, whose zone abbreviations don't say the offset, and `infinity`/`-infinity`, which no int
  is (the restrictive choice: null or the int limits could come later). **A timestamp without a
  zone stays text**: it is a clock reading, not a time, and only a zone the program knows makes it
  one (`date::parse_reading()`, `Zone.time()`). **Nothing is done going in**: an int parameter is
  text the server reads as the type the query wants, and a timestamptz doesn't read a number, so a
  time is written `to_timestamp({$time})`, the SQL that says what it is (null stays null). The
  driver can't know the parameter's type without a second round trip, and making an int one type
  would change every other int parameter. Tested by `pg_check.gaz` in four zones, with fractions,
  BC and both ends of PostgreSQL's range.

## Time, the terminal and random numbers

- `monotonic_time()` is `CLOCK_MONOTONIC`; a program that prints it can't be recorded, so tests
  check its type and that it never goes back, and its uses (`tui::Metronome`) take the time as an
  argument.
- `time()` was added for a server's `Date` header and logs; it is tested by type and range and by
  `HttpServerTest` against PHP's clock. `date.gaz` still keeps no clock, so a game keeps its own date.
- `sleep($seconds)` is `nanosleep()` a day at a time (any finite float fits), carrying on after a
  signal. `getenv()`'s NUL byte is an error rather than null, being a mistake. Both are tested by
  shape (`time_test.gaz`) and `getenv`'s value by `StdlibTest` setting one, since neither can be
  recorded.
- The terminal (`term.c`) is only what GazLang can't do itself, so the rules of drawing and decoding
  keys are written out in `lib/term.gaz` and testable from a pipe. **Raw mode outlives the program
  unless something puts it back**, and `exit()` and an uncaught error skip `finally`, so `term.c`
  restores it in an `atexit` handler and in signal handlers that then end the program as the signal
  would have, so its exit status is still the signal's. Always `TCSANOW`: `TCSADRAIN` waits for the
  terminal to take the output, which never ends once the terminal is gone. Raw mode turns off
  `ISIG`, so a program in a loop that never reads can't be interrupted from the keyboard. Resize is
  polled, not signalled, so nothing runs asynchronously. `tests/fixtures/pty_run.py` runs a program
  on a pty, since raw mode can't be seen from a pipe; `TermTest` skips those tests without python3.
  Its two limits (`PTY_START`, to enter raw mode, 60s; `PTY_DEADLINE`, to end, 30s) only guard
  against a hang and end with the program, so a loaded machine can't trip them; a test of a program
  meant to hang gives a short deadline.
  `ponytail:` `run()` hands a child whose output it collects the terminal as raw mode left it,
  which only a child opening `/dev/tty` meets; an inheriting one gets it as it was before (see
  [Processes and sockets](#processes-and-sockets)).
- **What the terminal reports beyond keys is asked for, never on by default** (`fullscreen()`'s
  `$options`; `term::enable()`/`disable()` and a pair of functions per feature are the sequences,
  as every drawing function returns its own), so a program that asks for nothing prints what it
  did. Each is turned on after the alternate screen is entered and off before it is left, in the
  other order, so the kitty flags are popped on the screen they were pushed on (the protocol keeps
  a stack per screen) and a terminal without the protocol ignores both. An unknown option is an
  error, the rule for every option map.
  - **Bracketed paste** gives one `paste` key with the text, its newlines made `"\n"`: a terminal
    sends a pasted newline as the enter key's `"\r"`, and an editor inserting lines wants one
    spelling. A paste cut across reads stays in `rest` until its end arrives, and `Input.read()`
    waits for it as it waits for a key, not the escape wait, since a big paste over a slow
    connection takes longer than 50ms and giving up would type its first half; one that never
    ends is its text when the input ends. `term::text()` of one is `null`, since it isn't one
    character: the program inserts `$key["text"]` itself.
  - **Mouse reports are read in the SGR form only** (`?1006`), which `fullscreen()` enables; the
    old X10 form stays `unknown`, since its three bytes can't be told from keys. The wheel's report
    holds no button, and the buttons past the third and the wheel sideways are `unknown`.
  - **The kitty keyboard protocol** is read with its first flag alone (every key as an escape code
    of its own): the keys it numbers by their control character and the keypad's in the private
    use area, the rest keeping their xterm forms, all decoded to the names the xterm forms give,
    so a program's `match` never knows which protocol the terminal spoke. A release event is
    `unknown`, since nothing here asks for the flag that sends them and a release taken for a
    press would type every key twice; a repeat is a press, as it is without the flag; the
    modifier's higher bits (super, caps lock) are passed over.
  - **Byte 8 stays `backspace`**, the restrictive choice: with the protocol on, backspace arrives
    as `"\e[127u"` and `"\e[104;5u"` is `ctrl+h`, so `decode()` needn't know whether the protocol
    is on, which would make it stateful. A program that wants `ctrl+h` asks for `"keys" => "kitty"`.
  - **`Input($escape_wait = 0.05)`** is Vim's `ttimeoutlen`, a parameter rather than the private
    constant it was, since a slow connection wants more and a modal editor less; `tui::interact()`
    passes nothing. **The ESC trap stays**: an ESC and a byte in one read are alt and the key, as
    every terminal program reads them, so an escape typed ahead of a `j` to a busy program is
    alt+j. The protocol closes it, since under it alt+j is `"\e[106;3u"` and the escape key
    `"\e[27u"`, so the trap is documented rather than guessed around.
  - **Cursor shapes** are DECSCUSR (`cursor_block()`, `cursor_underline()`, `cursor_bar()`, each
    with `$blink`, and `cursor_default()`). `fullscreen()` doesn't restore the shape, since it
    doesn't know whether the program changed it and the sequence would change what every program
    prints; `cursor_default()` is the terminal's own default, not a remembered one.
  - **`gaz --tty`'s script** gives a paste as `paste "text"` and a mouse report as `mouse left
    press 10 5`, since neither is a key a word could name, and `focus_in` and `focus_out` as keys.
    Tested in `term_test.gaz`, every shape with its cut-across-reads case, and by `CliTest`
    running `tests/corpora/cli/tty_events.gaz`.
- Random numbers: `xoshiro256**` through SplitMix64, as PHP's `Xoshiro256StarStar` does; the range
  mapping is `random_between()`, pinned for several seeds by `StdlibTest` against an independent
  port. The state is per program, reseeded by `run_program()`, since the compiler runs first.
  **Anything that prints random values calls `rand_seed()` first**, or what it prints can't be
  recorded as expected (snippets and corpus files included); unseeded behaviour is tested by type
  and range only.

## Cryptography

**Cryptography** (`crypto.c`): raw bytes in and out, hex and base64 being `lib/crypto.gaz`'s. Six
names, since a builtin takes its name from every program: one per algorithm rather than a
`kdf($name, $params)` map, so each argument is type-checked where it is and a misspelt parameter
can't be a key nobody reads; HMAC in C because PBKDF2 needs it there anyway, and at 600000
iterations only C is fast enough.

- **Our own C, not OpenSSL**: from FIPS 180-4, RFC 2104, 8018, 7914, 9106 and 7693 (BLAKE2b),
  so a `TLS=0` build has all of it, the bootstrap still needs only a C compiler, and every
  platform gives the same bytes. Bytes are loaded into words of a stated order one at a time, so
  the host's order never matters. The defaults take roughly 0.15s (Argon2id), 0.25s (scrypt) and
  0.3s (PBKDF2) on the development machine.
- **Checked against the specifications and against code that shares none with it**:
  `tests/gaz/lib/crypto_test.gaz` holds every vector FIPS 180-4, RFC 4231, RFC 7914 (PBKDF2 and
  scrypt) and RFC 9106 give (scrypt's fourth, 1 GiB, runs unsanitized in `CryptoTest`), plus
  lengths either side of each block and padding edge; `CryptoTest` compares with PHP's hash
  extension, libsodium, libargon2 (`password_hash()` in both directions), Python's
  `hashlib.scrypt` and `openssl kdf` (lanes, secret and data, which libsodium lacks), skipping
  what the machine doesn't have. Its inputs come from a small LCG written on both sides, so the
  programs print only digests and are recorded and sanitized like any snippet. A planted mistake
  fails both, except in Integerify's high word, which can't matter while scrypt's memory cap keeps
  N below 2^32.
- **The builtins' limits are what C can do safely, not what a login should cost**, each refused
  before anything is allocated; so is a size a 32-bit `size_t` can't hold, and a failed `malloc`
  of the big buffers is a catchable error, not `xmalloc`'s exit. A call within them can still take
  4 GiB and hours; bounding what a stored string may ask for is `verify_password`'s job. Memory is
  on the heap (4 GiB is past the thread's stack), and secrets are wiped by `wipe()`, a `memset`
  through a volatile pointer the compiler can't prove is one. Argon2's lanes run one after
  another: the result is a lane-parallel implementation's, and threads would only divide the wall
  time.
- **`random_bytes` is `getentropy()`**, 256 bytes a call, as `rand_seed()` without a seed is.
  `tools/fuzz.php` skips programs naming it (what they print wouldn't follow from the seed) and the
  three password hashes (their cost is their arguments, so a slow one isn't a bug).
- **`lib/crypto.gaz`**: the PHC format is what PHP, libsodium and libargon2 write, so Argon2id
  hashes move between them. `verify_password`'s refusals are canonical-only on purpose (each
  parameter a plain decimal, in order); it derives as many bytes as the stored hash has.
  `needs_rehash` compares the header `hash_password` would write.
- **`crypto::equals` compares HMACs, not bytes in a loop**: a loop would index one-byte strings,
  each made the first time its byte value is seen, which is a timing difference that depends on
  the secret, while the MACs' first difference is at a place nobody can predict or steer. It costs
  two HMACs and a `getentropy()`, and its time still grows with the lengths (a `ponytail:` there).
- Hex and base64 are GazLang (a digest is 32 bytes; speed would matter only for bulk data, which
  nothing encodes yet). They go through one-byte strings and `index_of`, which leak timing about
  the bytes; `ponytail:` comments mark each, and an encoder and decoder in C would lift them.

## The standard library

- `builtins()` is the builtins of the runtime running the program, which the self-hosted parser
  checks calls against. That is right because the compiler always runs on the runtime that will
  run its output, and a loader refuses bytecode naming a builtin it lacks.
- **The standard library is built into the VM** (`vm/build/std.c`, made from `lib/*.gaz` by the
  Makefile with `od`, as the compiler's bytecode is). The parser resolves `std/` (and a `./` or root
  path inside a library file, whose directory and root are `<std>`) through `std_source()`. The VM,
  the bytecode and the collector learn nothing: the module is read as any other, and a name in angle
  brackets is what bytecode never rewrites, so bytecode runs from anywhere. **Embedding, not a search
  path** (Python's `sys.path`, Lua's `package.path`): one file, `bin/gaz`, works from any directory
  with no install layout to get wrong and no skew between a binary and the library it runs; the
  cost, a rebuild after editing `lib/`, is met by `GAZLIB=lib`. Everything in the repository loads
  the library by `std/`, as any other program would: the tests (`tests/gaz/lib` tests the built-in
  copy, which the tests build from `lib/`), the games, the website, the language server and
  `compiler/`, whose `std/chars.gaz` is the built-in copy of the `bin/gaz` that compiles it. A
  library file imported by path as well is another module of the standard library's namespace, the
  ownership error, unless `GAZLIB` names its directory, which makes it the same module. A library
  file therefore names its siblings `std/x.gaz`, never `./x.gaz`: one loaded by its path (a test
  that joins a namespace to reach what is private, `tests/gaz/lib/http_turns_test.gaz`) then shares
  its imports with the built-in library and not a second copy of them. `StdLibraryTest` checks that
  what is built in equals `lib/`.

## The library files

The library files, each its own namespace (`docs/language.md` lists what each has); the reasons
behind them:

- `lists.gaz`: plain functions over plain lists, not a wrapping object: a `Collection` kind would
  have to be a handle, since every `kind` is, reintroducing the "did I get a copy?" ambiguity
  value semantics exist to remove. `max_by`/`min_by` take the first on a tie so they replace a
  stable `sort(...)[0]` exactly; `take`/`drop` are named wrappers over `slice()` for how they read
  mid-chain; `pluck` takes only maps until `$obj.$name` exists; `find` gives `null` since not-found
  is an ordinary outcome. A list helper goes here rather than into the builtins, since a builtin
  takes its name from every program and a namespace only from those that import it.
- `text.gaz`: `text::lines` strips `"\r\n"` only where a `"\n"` follows, as `read_line()` and
  `file_read_line()` do; `text::indentation`/`unindented` are what the website's Markdown and
  highlighter read; `text::trim_start`/`trim_end` are in GazLang rather than two more builtins, since a path's trailing slash is the one place
  they are needed.
- `fs.gaz`: `fs::copy` goes in 64KB pieces; onto itself is an error, since opening the target
  would empty it; the mode is set before a byte is written, so a private file's copy is never
  readable by others, and once more after writing, since a write clears setuid on Linux.
  `fs::copy_tree` sets directory modes deepest first once everything is in, so a read-only one is
  filled first, and gives what it left out rather than failing or quietly dropping it. `fs::glob`:
  a hidden name only by a pattern name starting with a dot; a link the pattern names is followed,
  one `**` finds never is, so no loop; each `*` matched by the two-pointer method, backing up only
  to the last star, so never exponential. `fs::write_atomic` gives the temporary the replaced
  file's mode and removes it if anything fails; `fs::touch` opens with `"a"`, so never empties.
  **Paths only, no path kind**: strings joined with `"/"`, as every builtin takes them. **No
  kinds-with-the-walk**: `fs::walk` gives paths, and a caller wanting sizes or times asks
  `file_info()` again, a second `lstat` per entry, which the examples didn't notice next to what
  the walk costs. **No `fs::mode_string`** (`rwxr-xr-x`): no example lists modes, and
  `format::sprintf("%o", $mode)` shows one. `ponytail:` a copy onto a read-only file is an error
  (`fopen` can't write it) where `cp -f` would remove it first. Tested by
  `tests/gaz/lib/fs_test.gaz` on a tree it plants with links in it.
- `format.gaz`: display helpers take any value, string functions stay strict; `%x` or `%o` of a
  negative is an error, not two's complement; `%f` goes through `format::number()` so it rounds as
  `round()` does and never by the platform's printf, which caps it at an int's worth of digits and
  18 decimals (`ponytail:`); every placeholder is read and the count checked before anything is
  formatted.
- `regex.gaz`: **the shorthands are `chars.gaz`'s predicates**, called by the class that holds
  them, so `\d`, `\w` and `\s` can't disagree with `chars::is_digit`, `is_alnum` and `is_space`;
  ASCII bytes, so `\s` is space, tab, newline and carriage return, not a form feed. `\w` takes
  `_` too, as every dialect's does and a name is made of. A shorthand can't end a range
  (`[\d-z]`), being a set. **An escaped letter or digit is a shorthand, `\t`/`\n`/`\r`, or an
  error**, so `\q` (or a future `\b`) can't quietly mean a letter; any other byte escaped is
  itself. **Braces are literal**, not counted repetition, so `a{3}` matches the text `a{3}` and
  not `aaa`. **`$N` in a replacement reads every digit that follows** (`$10` is group 10), and a
  group the pattern hasn't got is an error, so `$1` and then a `0` is the function form's; a `$`
  before anything but a digit or `$` is an error, never a literal. The replacement is read and
  checked once, before the first match. **`$with` may be a function** of the match's groups (as
  `regex::groups` gives them) that returns a string, for a replacement computed from the match;
  its arity is the call's own error. **A pattern compiles to at most 2000 instructions and has at
  most 100 groups** (`MAX_INSTRUCTIONS`, each entry of a `[...]` counting one more, since a class
  tests them one by one; `MAX_GROUPS`, since every thread copies its two slots a group each time
  it passes a `(` or `)`, so groups cost by their number squared), each an error before anything
  is matched, since the work for each byte of the input grows with both and a pattern may come
  from a request. At both limits a byte costs about what it does at 2000 instructions without
  groups; ordinary patterns compile to tens of instructions and a few groups, so the limits only
  meet one built to be expensive. The count stops the compiler as soon as it is passed, and a
  pattern longer than five bytes an instruction is refused unread, so refusing reads at most
  10000 bytes of the pattern (about a tenth of a second). `ponytail:` a trusted pattern can't
  raise them; that needs an options argument or a compiled pattern value. Tested by `tests/gaz/lib/regex_test.gaz`, every shorthand in each form against
  all 256 bytes.
- `http.gaz`: the client and the server have [a page of their own](http.md).
- `date.gaz` has no clock, since a program that asked one what day it is could not be recorded:
  every function is given the time.
  - **A time is an int**, seconds since 1970 in UTC without leap seconds, as `time()` and
    `file_info()` give it, so it compares, sorts and is stored as a number; a `Moment` is one
    read in a zone, for its fields and formatting, made when shown rather than passed around,
    since a kind is a handle. Formatting is named pieces (`rfc3339()`, `clock()`,
    `date::format()`), not a format string to learn. A day number and seconds after midnight
    are ints too, so the overview docblock opens with a table of the five values (the three
    ints, `Moment`, `Reading`), how each is made and what it turns into; the same table is in
    `docs/language.md`. Every day-number function is typed `int`, so a `Moment` or a string
    where a day number goes is an error naming the parameter.
  - **A weekday is an enum** (`date::Weekday`, `Monday` = 0 to `Sunday` = 6), so a program
    names the day it means rather than a magic number, and a number is an error; `.value` keeps
    the index for a table of names (`format()`'s short names, `http_date()`'s). A `Moment`
    formats its own date as a day number's `format()` and `short()` do, but has no `iso()`:
    `Reading.iso()` is the date and the clock, and one name means one shape. It gives its
    `reading()` (date and seconds after midnight, the offset left behind), and `Reading.iso()`
    writes what `parse_reading()` reads, so a stored time goes back into a
    `datetime-local` field as `$zone.at($time).reading().iso()`. `Zone.date($time)` is the day
    number the clocks show, the inverse of `Zone.time($days, $seconds)`.
  - **Named zones are TZif files read in GazLang** (RFC 8536), the system's
    `/usr/share/zoneinfo` unless a program names a directory, never libc's `localtime()`, whose
    answer depends on `TZ` and the platform. The POSIX TZ rule at a file's end is written out
    (`Rule`, `Change`), with RFC 8536's hours from -167 to 167; files counting leap seconds are
    refused. Tests read `tests/fixtures/zoneinfo`, never the machine's; `DateTest` compares with
    PHP's own database, on zones whose rules the two copies agree on.
  - **A clock reading that happens twice or never is an error unless the call says**
    (`date::Resolution`, an enum, so a misspelt choice is an error when the program is read): the
    two candidates are the reading at the offsets a day before and after it. `ponytail:` two changes within two days would hide the first.
  - **`parse()` is RFC 3339 and what PostgreSQL writes** (a space for the `T`, `+02`); a leap
    second is refused and a fraction dropped (`ponytail:`, until times have nanoseconds). Its
    `$default` is told from none by a private kind (`NoDefault`), as a parameter can't say
    whether it was passed, and only `Unreadable` is caught, so running out of call depth isn't.
- `syntax.gaz`: **the compiler's own lexer, in the library** so a program outside the
  repository's project (an app showing GazLang, a linter) reads source exactly as the compiler
  does: a namespace belongs to one project, and the library is the one every project can import.
  The compiler imports it as `std/syntax.gaz` and so holds the copy built into the `bin/gaz` that
  compiled it, which is why a change to it needs `make -C vm compiler`. Its public surface is
  what its callers need (`Lexer`, `Token`, `LexError`, `Lexer::KEYWORDS`, and `TEMPLATE_BUFFER`
  for the compiler's templates), the rest private, so the lexer's insides can change without
  breaking a program. Its tokens are checked by the lexer corpus (`SelfHostedLexerTest`), its
  API by `tests/gaz/lib/syntax_test.gaz`.
- `http2.gaz`: **HTTP/2's arithmetic apart from the server** (see [HTTP](http.md#http2)): frames, and
  HPACK's decoder (a connection's dynamic table, evicted as RFC 7541 §4 says; the Huffman code
  decoded four bits at a time through a table made once from RFC 7541's codes, generated from Go's
  copy of them; strings ending only at a code's end or in under 8 bits of 1s; integers past 2^31
  refused) and an encoder that uses no table (literals, a name the static table has by its index),
  so no setting of the peer's can make a block it writes wrong. Tested by
  `tests/gaz/lib/http2_test.gaz` on RFC 7541's Appendix C, which exercises the table and the
  Huffman code (a wrong code, or an entry's cost without its 32 bytes, fails it), and while it was
  written by thousands of random blocks against Go's own encoder and decoder both ways.
- `highlight.gaz`: **on `syntax.gaz`, never a lexer of its own**, so a colour can't disagree with
  the language, and every piece is cut from the source by `Lexer.span()` rather than written back
  from a token, so joining the pieces gives the source byte for byte
  (`tests/gaz/site/round_trip_test.gaz`, on every docs block and every file of `lib/` and
  `examples/`). Source the lexer refuses is one plain piece, so a broken example is shown rather
  than fatal. **`to_html()` and `render()` give an `Html`**, not a string: every piece is escaped
  inside, so a caller can't escape it again or forget to, and it nests into `web::html` or a
  template as it is. The class names are the website's (`kw`, `str`, ...), so an app's stylesheet
  can colour code as the site does.
- `term.gaz`'s drawing functions return their sequence, so a program prints them and a test
  compares them; `tui.gaz` draws into a `Screen`, a grid that `render()` diffs against what it last
  drew, so a program redraws it all every frame and a test reads `lines()` without a terminal.
- Scan long strings with `index_of`, not a character at a time.
