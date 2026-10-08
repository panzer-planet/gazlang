/*
 * The terminal: term_raw(), term_read(), term_size() and term_is_tty(). Only what GazLang can't
 * do itself; lib/term.gaz has the rest (escape sequences to draw, and decoding what term_read
 * gives back into keys).
 *
 * Raw mode changes the terminal, not the program, so it outlives a crash unless something puts it
 * back. `exit()` and an uncaught error skip `finally` blocks, so the way out is here: an atexit
 * handler, and handlers for the signals that would end the program without running one. The
 * terminal is the user's shell's as much as the program's, and one left in raw mode types nothing
 * back until `reset`.
 *
 * `gaz --tty` gives the program a pretend terminal instead (`tty_cols` by `tty_rows`, 0 by 0 when
 * there is none), so a program can be driven and seen without one: every term_ builtin answers as
 * that terminal would, the clock (`tty_clock`) moves only when the program sleeps, and standard
 * input is a script of keys that term::Input reads, not bytes that term_read() gives.
 */
#include "gazvm.h"

#include <errno.h>
#include <math.h>
#include <poll.h>
#include <signal.h>
#include <stdlib.h>
#include <string.h>
#include <sys/ioctl.h>
#include <termios.h>
#include <unistd.h>

int tty_cols = 0, tty_rows = 0;
double tty_clock = 0;

static struct termios saved_mode;
static bool raw_on = false;
static bool exit_handler_set = false;

/* What the signals did before raw mode took them over */
static const int ending_signals[] = {SIGINT, SIGTERM, SIGHUP, SIGQUIT};
#define NSIGNALS ((int)(sizeof ending_signals / sizeof ending_signals[0]))
static struct sigaction saved_signals[NSIGNALS];

/* tcsetattr() is safe inside a signal handler, which is why this is all the handlers do. It is
   always TCSANOW: TCSADRAIN waits for the terminal to take the output, which never ends once the
   terminal is gone (a closed window, SIGHUP), and raw mode changes nothing about output. */
static void restore_mode(void) {
    if (raw_on) tcsetattr(STDIN_FILENO, TCSANOW, &saved_mode);
}

/* A signal that would end the program: put the terminal back, then let it end it as it would have */
static void restore_and_die(int sig) {
    restore_mode();
    signal(sig, SIG_DFL);
    raise(sig);
}

/* Raw mode, made from the mode the terminal was in before it */
static struct termios raw_mode(void) {
    struct termios raw = saved_mode;
    raw.c_iflag &= ~(tcflag_t)(BRKINT | ICRNL | INPCK | ISTRIP | IXON);
    raw.c_cflag |= CS8;
    raw.c_lflag &= ~(tcflag_t)(ECHO | ICANON | IEXTEN | ISIG);
    raw.c_cc[VMIN] = 1;
    raw.c_cc[VTIME] = 0;
    return raw;
}

/* term_raw($on): no echo, no line buffering, every key arrives as it is typed. Output processing
   stays on, so "\n" still starts a new line. Ctrl-C and Ctrl-Z arrive as the bytes 3 and 26 for
   the program to decide about, not as signals. */
bool term_raw(bool on) {
    if (on == raw_on || tty_rows > 0) return true;
    flush_output();
    if (!on) {
        tcsetattr(STDIN_FILENO, TCSANOW, &saved_mode);
        raw_on = false;
        for (int i = 0; i < NSIGNALS; i++) sigaction(ending_signals[i], &saved_signals[i], NULL);
        return true;
    }
    if (!isatty(STDIN_FILENO)) {
        return raisef("term_raw() needs a terminal on standard input; without one, pipe keys to "
                      "gaz --tty FILE (gaz --help says how)");
    }
    if (tcgetattr(STDIN_FILENO, &saved_mode) != 0) return raisef("term_raw() failed: %s", strerror(errno));
    struct termios raw = raw_mode();
    if (tcsetattr(STDIN_FILENO, TCSANOW, &raw) != 0) return raisef("term_raw() failed: %s", strerror(errno));
    raw_on = true;
    if (!exit_handler_set) {
        atexit(restore_mode);
        exit_handler_set = true;
    }
    struct sigaction handler = {.sa_handler = restore_and_die};
    sigemptyset(&handler.sa_mask);
    for (int i = 0; i < NSIGNALS; i++) sigaction(ending_signals[i], &handler, &saved_signals[i]);
    return true;
}

/* Whether raw mode is on, so run() can give a program the terminal as it was before */
bool term_is_raw(void) { return raw_on; }

/* For a program run() starts with the terminal: the mode it was in before raw mode, for the
   program's run, and raw mode again after. Raw mode stays on as far as this program knows, so
   saved_mode is never read again: what it puts back on the way out (term_raw(false), exit, a
   signal) is the mode it found, not one a program left behind (an editor killed at its time
   limit, an `stty -echo`). */
void term_lend(void) {
    tcsetattr(STDIN_FILENO, TCSANOW, &saved_mode);
}

bool term_take_back(void) {
    struct termios raw = raw_mode();
    if (tcsetattr(STDIN_FILENO, TCSANOW, &raw) != 0) return raisef("term_raw() failed: %s", strerror(errno));
    return true;
}

/* term_read($timeout): what has arrived on standard input, up to 4KB, waiting up to $timeout
   seconds for something (a negative $timeout waits for ever). null when the time ran out, "" at
   the end of the input. Output is flushed first, or a prompt would still be in its buffer while the
   program waited for the answer to it. Works on a pipe too, which is how it is tested. */
bool term_read(double timeout, Value *out) {
    if (tty_rows > 0) return raisef("term_read() under gaz --tty: its keys are read by term::Input");
    flush_output();
    /* poll() takes milliseconds in an int, so a long wait is cut to what fits (about 23 days) */
    double wait = ceil(timeout * 1000);
    int ms = timeout < 0 ? -1 : wait > 2000000000.0 ? 2000000000 : (int)wait;
    struct pollfd p = {.fd = STDIN_FILENO, .events = POLLIN};
    int ready;
    while ((ready = poll(&p, 1, ms)) < 0 && errno == EINTR) {}
    if (ready < 0) return raisef("term_read() failed: %s", strerror(errno));
    if (ready == 0) {
        *out = v_null();
        return true;
    }
    char chunk[4096];
    ssize_t n;
    while ((n = read(STDIN_FILENO, chunk, sizeof chunk)) < 0 && errno == EINTR) {}
    if (n < 0) return raisef("term_read() failed: %s", strerror(errno));
    *out = v_str(str_new(chunk, (size_t)n));
    return true;
}

/* term_size(): {"cols", "rows"} of the terminal standard output is, or 80 by 24 when it isn't
   one (a pipe, a file) or won't say */
bool term_size(Value *out) {
    struct winsize w;
    int cols = 80, rows = 24;
    if (tty_rows > 0) {
        cols = tty_cols;
        rows = tty_rows;
    } else if (ioctl(STDOUT_FILENO, TIOCGWINSZ, &w) == 0 && w.ws_col > 0 && w.ws_row > 0) {
        cols = w.ws_col;
        rows = w.ws_row;
    }
    Map *m = map_new();
    Value key = v_str(str_cstr("cols"));
    map_set(m, key, v_int(cols));
    decref(key);
    key = v_str(str_cstr("rows"));
    map_set(m, key, v_int(rows));
    decref(key);
    *out = v_map(m);
    return true;
}

/* term_is_tty($stream): 0, 1 or 2, standard input, output or error. Under gaz --tty, input and
   output are the pretend terminal's, and error is still what it is */
bool term_is_tty(int64_t stream, Value *out) {
    if (stream < 0 || stream > 2) return raisef("term_is_tty() expects 0, 1 or 2, got %lld", (long long)stream);
    *out = v_bool(isatty((int)stream) == 1 || (tty_rows > 0 && stream < 2));
    return true;
}

/* term_is_virtual(): whether this is gaz --tty's pretend terminal */
bool term_is_virtual(Value *out) {
    *out = v_bool(tty_rows > 0);
    return true;
}
