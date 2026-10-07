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
  ambiguous.
- Sockets (`net.c`): **the address text is `ipaddr.c`'s, not `inet_ntop()`'s**, whose IPv6
  spelling differs between systems; checked against Python's `ipaddress` on 295 addresses
  (`tests/IpTextTest.php`, on its own so no network is needed) and by
  `tests/gaz/sockets/peer_test.gaz`. `workers($n)` is `workers.c` (see [HTTP](http.md#serving-prefork-workers)); the fuzzer
  skips it with the socket builtins. `HttpTest` trusts `tests/fixtures/tls/` through
  `SSL_CERT_FILE`; an IP is checked as an address, with no SNI. SIGPIPE is ignored around each
  call and put back after, as curl does: per socket only macOS can turn it off, and ignoring it for
  good would change what a program writing to a closed pipe does. OpenSSL reports a socket timeout
  as wanting to read; `net.c` says `timed out`.
  `socket_wait()` exists for kept-alive connections: a worker idle on one must see a stop
  (`socket_read()` retries on `EINTR`, so a stop never interrupts it) and a client waiting on the
  shared listener, both one `poll()`, waited a second at a time as `socket_accept()` does. A
  hang-up or error counts as ready, since `socket_read()` won't wait on either, and so do bytes
  OpenSSL already decrypted. Tested by `tests/gaz/sockets/wait_test.gaz`, `inherited_test.gaz` and
  `HttpServerTest` (a SIGTERM during a 60-second wait ends it). `ponytail:` at most 16 sockets, a
  fixed array; a TLS record only partly arrived reads as ready and `socket_read()` then waits for
  the rest.

## Databases

Databases (`db.c`, drivers `sqlite.c` and `pg.c`): **three builtins whatever the drivers**, since
a builtin takes its name from every program: the URL's scheme picks a `DbDriver` (open, run, close)
in C, so a new database is a file and a table entry. **The builtin takes the database's own
placeholders**, not rewritten: rewriting means reading string literals in C.

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
  first, then the system's own search (`libpq.so.5`, `libpq.5.dylib`), then Homebrew's two prefixes
  on macOS. Missing, `db_open` raises a catchable error naming every file tried and how to install
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
  without), on temporary tables. `ponytail:` a bool parameter is 0/1 in SQLite, no blobs going in,
  and PostgreSQL's numeric, timestamps and json come back as text.

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
  `ponytail:` `run()` hands a child the terminal as raw mode left it.
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
  `vm/fuzz.php` skips programs naming it (what they print wouldn't follow from the seed) and the
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
    `date::format()`), not a format string to learn.
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
- `term.gaz`'s drawing functions return their sequence, so a program prints them and a test
  compares them; `tui.gaz` draws into a `Screen`, a grid that `render()` diffs against what it last
  drew, so a program redraws it all every frame and a test reads `lines()` without a terminal.
- Scan long strings with `index_of`, not a character at a time.
