# The C VM

How the virtual machine in `vm/` runs a program, and the rules it keeps: the command line
(with `-e`, `--watch` and `--tty`), values, frames, the cycle collector, superinstructions and
what made it fast, and one thing it could grow (a REPL). For contributors changing
the C; `vm/gazvm.h` says which file does what.

## The command line

**The CLI** parses options as PHP's `getopt` does, plus the check for unknown ones: options
end at `--` or the first non-option; `-f` takes the next argument whatever it is. Then, unless
`--` ended them or `-f` gave a file, **the first argument is the file** (`gaz main.gaz a b`, as
Python, PHP and Node take it) or `-` for the program on standard input, and the rest are the
program's. Standard input is the program's whenever a file is given; piped source with bare
arguments is `gaz - a b` or `gaz -- a b`. The lexer skips a `#!` first line, so
`#!/usr/bin/env gaz` scripts run. Bytecode is recognised by its first line or a `.gzb` name. `-c`, `-t` and `--ast` run the
built-in front end in that mode; running source runs it in `code` mode first. With no file and
a terminal on stdin it prints the help to stderr and exits 1: there is no REPL (running each
line as its own program wouldn't be one).

- **`gaz -e CODE`** (`--eval`, `-eCODE`; more than once, each a line joined by `"\n"`) runs text
  given on the command line: the same path `-S` and `gaz test` take (`job.text` set, no path, so
  the front end reads it as piped source, which it consumes before the program starts), and
  **`main()` never reads standard input for it**, which is the whole point: `read_stdin()` and
  `read_line()` find the data that was piped in. Nothing in `compiler/` knows about it. Every
  argument left after the options is the program's `args()` (never a file), refused with a file,
  `-`-as-file (`-f`), `--watch`, `-S` and `--tty`, and `-e` with no code. Errors are "on line N",
  as piped source's are, not a made-up file name, which would break resolving imports from the
  working directory and its project. **Plain program semantics, on purpose**: no implicit `echo` of a last
  expression (a rule that changes what a statement does by where it sits), no `-n`/`-p` line loop
  (C would wrap the text, shifting line numbers and inventing `$line`), and no prelude of
  libraries (implicit, and about 70ms a run): `import "std/lists.gaz";` is the price, and a
  `lists::x` without it says so (`library_hint()` in `parser.gaz`). `ponytail:` code shows in
  `ps`; no columns in an error.

## Watching for changes

**`gaz --watch app.gaz ARGS`** (`watch.c`) runs the program and runs it again whenever a file it
is made of changes. A supervisor in C that runs no GazLang: it starts `argv[0]` again (spawnp,
so a bare `gaz` is found on the PATH as the shell found it; portable, where `/proc/self/exe` and
`_NSGetExecutablePath` are one system each) as `gaz -f FILE -- ARGS`, in a process group of its
own so a `workers()` master and its workers stop together. A file, never piped source, and not
with `-c`, `--tokens` or `--ast`.

- **What is watched is the files the program is made of**, not a directory: the main file and
  the distinct paths of the `@` lines of a `gaz -c` of it (`source_files()` in `load.c`,
  resolved and shown as the loader shows them, `<builtin>` and `<std>` left out), so templates
  are watched and the built-in library isn't. Worked out again before each start, so a new
  import is watched from its first run; a compile that fails keeps the last list. Compiling
  twice costs the restart one compile, but needs no change to the front end. `ponytail:` a file
  that leaves no instruction (only constants) isn't watched.
- **Polled every 0.25s**, modification time (nanoseconds too), size and inode, portable where
  inotify and kqueue are one system each. A change or a file gone restarts: SIGTERM to the
  group (graceful for `workers()`), SIGKILL after 1s, where production's grace is 10s, so a
  save feels instant. A compile error (the child prints it) or a program that ends by itself
  waits for the next change.
- **Messages on stderr**: `gaz: watching app.gaz and 6 files it imports` (again whenever the
  list changes), `gaz: views/page.gazml changed, restarting`, `gaz: waiting for a change`.
- **SIGINT, SIGTERM, SIGHUP stop the group the same way, then end the supervisor as the signal
  would**; one it was started ignoring stays ignored (so `sh -c '... &'` background jobs, which
  ignore SIGINT, need SIGTERM), as in `workers()`. Ctrl-C reaches only the supervisor, since the
  child's group isn't the terminal's. `ponytail:` so the program can't read the terminal or turn
  on raw mode (SIGTTIN, SIGTTOU); the supervisor says so and ends it rather than leave it stopped.
  Handing the child's group the terminal (`tcsetpgrp`) would lift that, with Ctrl-C then the
  program's. `WatchTest` edits files under a running supervisor.

## A pretend terminal

**`gaz --tty[=COLSxROWS] app.gaz`** runs a program on a pretend terminal (120x40 by default), so
a TUI can be developed with no terminal: by an AI agent above all, which is who it is for, out of
the box, and by scripts and tests. Standard input is its keys, as a terminal's keyboard is its
program's stdin, which is why there is no `--keys`: `gaz --tty app.gaz <<< "2 j snap enter"`, or
`< keys.txt`. Stateless on purpose: the same keys always print the same screens, so an agent's
shell calls are its session and a sequence showing a bug is already a test case.

- **The VM pretends, the library shows** (`tty_cols`, `tty_rows`, `tty_clock` in `term.c`):
  `term_is_tty()` is true for 0 and 1, `term_size()` the pretend size, `term_raw()` a no-op,
  `term_is_virtual()` true, and the clock virtual: `monotonic_time()` starts at 0.0 and only
  `sleep()` moves it, at once, so a screen run by a beat is deterministic. Reading the script
  is GazLang's (`term::Script`, shared by every `term::Input`, parsed from `read_stdin()` on
  first use), as turning bytes into keys is, and `term_read()` is an error there, since the
  script isn't bytes. The screen is printed by `tui::interact()` as `Screen.snapshot()`, from
  the grid it already has, rather than by a VT emulator in C decoding the escape bytes back:
  the grid is the truth. `ponytail:` so a bug in `render()`'s bytes doesn't show, and a
  program drawing its own escapes gets keys and clock but no screens.
- **The script's words are `term::name()`'s** (`j`, `enter`, `ctrl+c`, `page_up`), one
  vocabulary; `"text"` types characters; `wait N` moves the clock; `snap` prints the screen. A
  snap reaches `interact()` as the key `"snap"` through `Input.read_or_snap()`, never through
  `read()`, which passes it over, since only `interact()` has a screen to show; a snap that
  follows a `wait` inside one read is why it can't be peeked for before reading instead.
  An unknown word is the user's mistake, not the program's: one line and exit 2, no trace.
- **The dead end points the way**: `term::fullscreen()` refuses a program without a terminal on
  stdin and stdout, naming `gaz --tty`, and `term_raw()` does too, so programs don't carry
  checks of their own and an agent's first `gaz app.gaz` tells it what to do next.

## How a program runs

- **Running source**: the compiler runs as a program of its own with its output captured in an
  `open_memstream()` buffer, which is then loaded as bytecode saved next to the source. Each run
  starts with fresh stacks and globals and the compile's leftovers are dropped first, so the leak
  check sees only the program. It costs about 10ms before the first instruction.
- **Reading bytecode is most of starting**, since every run of source loads the compiler (about
  50000 lines) first, so the reader does per line only what it must: words go into one buffer
  every line reuses (`split_words()`), an instruction's name is found by a hash table built once
  (`op_find()`), an `@` line's literal written as the last one was isn't read again
  (`read_location()`), and an instruction keeps a pointer to its line rather than its words, which
  only the "stack is N deep at ..." message needs, split again then. Each was a malloc, a binary
  search or an intern per line, and together they were half of loading the compiler.
- **Errors are return values**: a function that can fail returns `bool` with the error in
  `vm_error`, passed up to the dispatch loop, which locates it and unwinds. No
  `setjmp`/`longjmp`, so reference counts stay right on the way out. The loader is the
  exception: it gives up at the first problem.
- **Values** are a 16-byte tag and payload. Strings, lists, maps, functions, objects and raised
  errors are reference counted, and lists and maps copied on write, which is PHP's value
  semantics exactly. A map is PHP's design: entries in insertion order with holes, and an
  open-addressed index rebuilt as it grows. Names are interned, so member lookups compare
  pointers; one-byte strings are 256 shared values. A list's first `LIST_INLINE` (4) items
  live in its header, and move to an array of their own when it outgrows that, never back, so
  most lists are one malloc: 4 is what the first push allocated anyway, so a list of one to four
  costs the memory it did, and 6 or 8 saved a point or two more allocations for 32 or 64 more
  bytes on every list. A `List` is never copied as a struct, since `items` points into it.
  - **Keys are hashed under a key drawn for each process** (`siphash.c`, SipHash-1-3 for strings,
    splitmix64's finaliser after an xor with the key for ints), since a map's keys are often
    someone else's (a JSON object, a query string, a form) and an unkeyed hash lets them choose
    ones that all share a bucket: 20000 such keys took nine times as long to decode as ordinary
    ones, and the cost grows with the square. Nothing a program can see depends on it (a map keeps
    insertion order, so output is the same whatever the key), which is why it may differ between
    runs where the rest of the language must not. **Drawn once and kept through a `fork()`**:
    a string or map made before one holds hashes worked out under the key, so `workers()` must
    not draw again, as it does reseed `rand_*`. `tests/SipHashTest.php` checks the function
    against CPython's, which is SipHash-1-3 too; it costs about 6% on a loop that does nothing
    but make and look up new string keys and nothing measurable elsewhere. `ponytail:` not
    constant-time and not for secrets; a program that must cap what a client can send still
    wants `max_body`.
- **Frames live on one value stack**: a call's pushed arguments become the callee's first
  locals, and its stack is sized by the loader's walk. The one use of the C stack is a method
  or a builtin's callback run from inside an instruction (`call_method()`, `call_value()`), which
  can nest as deep as the call limit, so the program runs on a thread with a 1GB stack (address
  space, backed only as used). `call_value()` checks its callee as `CALL_VALUE` does, with its
  own copy of the checks (`enter_value()`): sharing them cost lambda calls 8%.
- **An interface is a `Kind` to the VM** (`Kind.interface`): a value of one is a `T_KIND`, so
  `is_a`, `==`, `echo` and a type's alternatives take one with no tag of its own, and it is
  abstract, so nothing constructs it. The loader works out once which interfaces each kind
  implements, its ancestors' included (`Kind.interfaces`), and `kind_is_a()` reads that list only
  after the walk up the parents has failed and the kind asked about is an interface, so a check
  against a kind, and code without types, cost what they did.
- **An enum is a `Kind` too** (`Kind.is_enum`), so types, `is_a`, methods and interfaces take one
  with nothing of their own. Its cases are made by `make_cases()` in `vm.c` when a run starts,
  after the run's object ids and static slots exist, one object each in the static slot the
  loader found for it, so `Filter::Open` is a `LOAD_STATIC` and `==` compares pointers.
  `Object.case_number` (in the padding before the fields, so no object grows) says which case one
  is, for `echo` and errors (`append_case()` in `value.c`), which never run program code. A backed
  enum's one field, `value`, is typed, so every write into a case goes through
  `check_field_type()`, which refuses it; the loader refuses `NEW`, `CALL_CONSTRUCTOR` and a store
  into a case's slot, and calling the enum as a value is refused with an abstract kind. The case
  values are the record's, dropped with the code's constants at the end, so the leak check counts
  them as it counts a `PUSH`'s.
- **The cycle collector** (`gc.c`) is CPython's trial deletion over every list, map, object and
  function, needing no roots, run at a backward jump or call once as many containers have been
  made as were alive after the last collection. There are no destructors, so freeing runs no
  program code. The tested build lowers the threshold's floor to 64 containers (`GC_MINIMUM`,
  10000 otherwise) so the harness exercises it.
- **Superinstructions** (`fuse()` in `load.c`): the loader puts one in place of the first
  instruction of a common sequence (`LOAD; PUSH; LT; JZ`, `LOAD x; INC; STORE x`, eight in all,
  after `OP_COUNT` so no file can name one) and leaves the sequence where it was, so a jump into
  it still lands on real instructions. A superinstruction's quick path must be one that can't
  fail or run program code; otherwise it runs its first instruction alone and the rest follow,
  so errors and their lines are the sequence's own. `tests/corpora/vm/superinstructions.gaz`
  takes every fallback; `tests/corpora/bytecode/superinstruction_lookalikes.gzb` holds the shapes only
  hand-written bytecode has. `LOAD; LOAD_STATIC; op` is for a `match` on an enum's cases: without
  it each arm took two more dispatches than a string constant's, which `LOAD; PUSH; op` fuses.

## Speed

- **Speed**: what paid was an int fast path for `%`, the `STORE; LOAD; POP` peephole, shared
  one-byte strings, not interning names on the hot path, inline caches on member instructions,
  and the superinstructions. What didn't: computed-goto dispatch (the CPU predicts the switch
  well), a fast path for `==`, and fusing a comparison with `JZ` on its own. A call into another
  file on the hot path costs twice, so `arity_fits()` is `static inline` in the header. On the
  development machine moving code a few bytes swings a hot loop by 5%, so judge a change with the
  same binary both ways, or on two builds, and keep what wins on both. What is left in a profile
  is the dispatch loop, malloc/free and the collector.
- **PGO is the default where the toolchain has it** (gcc, or clang with `llvm-profdata`), and
  plain `-O2` where it doesn't, so the bootstrap still needs only a C compiler: it makes every
  benchmark faster for about 5s more per build. It trains on the compiler, `examples/` and `tests/programs/`, never `vm/bench`, so the benchmarks stay an
  honest test; `bench.php` times whichever build `bin/gaz` is, so compare a change with
  both builds PGO (or both `PGO=0`). `-O3` was a wash and `-flto` slower.
- `ponytail:` in the C: float printing tries up to 34 `printf`/`strtod` pairs per float.

## Why C

**Why C**: over Rust, Zig and Go, since the heap (refcounts plus a cycle collector) is unsafe
code in every one of them, Go has no refcounts for cheap copy-on-write, and Zig moves under a
pinned toolchain; C bootstraps with nothing but a C compiler (`TLS=0`), and the differential harness
under ASan and UBSan is the safety net C usually lacks. A separate program rather than PHP
FFI, since converting values per call costs more than an instruction.

## A real REPL, not built

**A real REPL is possible, not built** ([#33](https://github.com/panzer-planet/gazlang/issues/33)), and nothing decided rules it out; what stands in the way
is that everything assumes a whole program. It would take: a session mode in the compiler (the
parser keeps its function, kind, constant and namespace tables between entries, the code
generator keeps the top level's slot map and the global slots, and each entry compiles as a
continuation of the top block plus any new blocks); a loader that appends blocks to a running
program and grows its globals, statics and top frame instead of `run_program()` starting afresh;
and a loop that reads with `term.c`/`lib/term.gaz`, asks for more when the parser runs out of
input mid-construct, and prints a bare expression's value as a literal. Decisions it forces:
whether a function or kind can be redefined (a kind can't be safely, since objects keep its
layout), that an entry failing halfway keeps its side effects (as Python's does), and that a
call to a function not yet defined is an error for that entry.
