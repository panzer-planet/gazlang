/*
 * The terminal: term_raw(), term_read(), term_size(), term_is_tty(), term_resized() and
 * term_suspend(). Only what GazLang can't do itself; lib/term.gaz has the rest (escape sequences
 * to draw, and decoding what term_read gives back into keys).
 *
 * Raw mode changes the terminal, not the program, so it outlives a crash unless something puts it
 * back. `exit()` and an uncaught error skip `finally` blocks, so the way out is here: an atexit
 * handler, and handlers for the signals that would end the program without running one. The
 * terminal is the user's shell's as much as the program's, and one left in raw mode types nothing
 * back until `reset`. A stop (SIGTSTP from outside; Ctrl-Z is a byte in raw mode) puts it back
 * for the shell and takes it again when the program goes on, which is also what term_suspend()
 * does for a program stopping itself.
 *
 * The window changing (SIGWINCH) is the one signal a program wants to hear about: the handler
 * writes a byte to a pipe of its own, which term_read() polls beside standard input, so a program
 * waiting for a key wakes to draw again, and term_resized() says whether it happened. Nothing else
 * runs asynchronously.
 *
 * `gaz --tty` gives the program a pretend terminal instead (`tty_cols` by `tty_rows`, 0 by 0 when
 * there is none), so a program can be driven and seen without one: every term_ builtin answers as
 * that terminal would, the clock (`tty_clock`) moves only when the program sleeps, and standard
 * input is a script of keys that term::Input reads, not bytes that term_read() gives; its
 * `resize` word is term_resize(), which only that terminal takes.
 */
#include "gazvm.h"

#include <errno.h>
#include <fcntl.h>
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
static struct sigaction saved_stop;

/* The resize wake: a pipe the SIGWINCH handler writes a byte to, so a byte in it is a resize not
   yet taken. term_read() takes one by returning null early and remembers it for term_resized(),
   which takes what is left; the two move together, so a program asking either sees each resize
   once and term_read() never wakes for one already answered. */
static int wake_pipe[2] = {-1, -1};
static bool resize_taken = false;

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

/* Stop as Ctrl-Z would have, with the terminal as the shell left it, and go on in raw mode again
   once the shell continues the program (fg). Everything here is safe in a signal handler, since
   the SIGTSTP handler is one caller; the other is term_suspend(). The whole process group is
   stopped, as the terminal's Ctrl-Z stops it, since that is the job the shell is waiting on: a
   program stopped alone in a pipeline would leave the shell waiting for the rest. SIGTSTP is set
   to its default and unblocked for the kill (a handler runs with its own signal blocked), and
   sent to this process among the group, so the stop happens inside the kill() and whatever was
   before (the handler, the mask) is put back after it. A group the shell has given up on (no
   process of it has a parent in the shell's session) discards a stop, so the program comes
   straight back, as it should: there is nobody to go back to. */
static void stop_and_come_back(void) {
    restore_mode();
    struct sigaction before;
    struct sigaction dfl = {.sa_handler = SIG_DFL};
    sigemptyset(&dfl.sa_mask);
    sigaction(SIGTSTP, &dfl, &before);
    sigset_t stop, was;
    sigemptyset(&stop);
    sigaddset(&stop, SIGTSTP);
    sigprocmask(SIG_UNBLOCK, &stop, &was);
    kill(0, SIGTSTP);
    /* stopped here, until SIGCONT */
    sigprocmask(SIG_SETMASK, &was, NULL);
    sigaction(SIGTSTP, &before, NULL);
    if (raw_on) {
        struct termios raw = raw_mode();
        tcsetattr(STDIN_FILENO, TCSANOW, &raw);
    }
}

/* SIGTSTP from outside (kill -TSTP; the keyboard's is a byte in raw mode): the terminal goes back
   to the shell for the stop and raw mode is on again after it, as for term_suspend() */
static void stop_and_restore(int sig) {
    (void)sig;
    int saved = errno;
    stop_and_come_back();
    errno = saved;
}

/* The window changed: a byte in the pipe, which wakes term_read() and is what term_resized()
   reads. A full pipe drops it, since one byte already says what every one would. */
static void note_resize(int sig) {
    (void)sig;
    int saved = errno;
    char byte = 0;
    (void)!write(wake_pipe[1], &byte, 1);
    errno = saved;
}

static void close_wake_pipe(void) {
    close(wake_pipe[0]);
    close(wake_pipe[1]);
}

/* Both ends never block (the handler must not, and draining reads until there is nothing) and
   close on exec, so a program run() starts doesn't hold them */
static bool unblocked(int fd) {
    int flags = fcntl(fd, F_GETFL);
    return flags >= 0 && fcntl(fd, F_SETFL, flags | O_NONBLOCK) == 0 && fcntl(fd, F_SETFD, FD_CLOEXEC) == 0;
}

/* The resize wake, made the first time raw mode goes on or term_resized() is asked and kept from
   then on: SIGWINCH is ignored by default, so a handler that stays costs nothing, and SA_RESTART
   keeps it from cutting a write short (poll() returns EINTR whatever the flag says, and the pipe
   makes it ready again at once) */
static bool watch_resize(void) {
    if (wake_pipe[0] >= 0) return true;
    if (pipe(wake_pipe) != 0) return raisef("term: the resize wake failed: %s", strerror(errno));
    if (!unblocked(wake_pipe[0]) || !unblocked(wake_pipe[1])) {
        return raisef("term: the resize wake failed: %s", strerror(errno));
    }
    atexit(close_wake_pipe);
    struct sigaction handler = {.sa_handler = note_resize, .sa_flags = SA_RESTART};
    sigemptyset(&handler.sa_mask);
    sigaction(SIGWINCH, &handler, NULL);
    return true;
}

/* Whether the wake pipe had anything, taking all of it */
static bool drain_wake(void) {
    if (wake_pipe[0] < 0) return false;
    char bytes[64];
    bool had = false;
    while (read(wake_pipe[0], bytes, sizeof bytes) > 0) had = true;
    return had;
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
        sigaction(SIGTSTP, &saved_stop, NULL);
        return true;
    }
    if (!isatty(STDIN_FILENO)) {
        return raisef("term_raw() needs a terminal on standard input; without one, pipe keys to "
                      "gaz --tty FILE (gaz --help says how)");
    }
    if (tcgetattr(STDIN_FILENO, &saved_mode) != 0) return raisef("term_raw() failed: %s", strerror(errno));
    if (!watch_resize()) return false;
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
    struct sigaction stop = {.sa_handler = stop_and_restore, .sa_flags = SA_RESTART};
    sigemptyset(&stop.sa_mask);
    sigaction(SIGTSTP, &stop, &saved_stop);
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
   seconds for something (a negative $timeout waits for ever). null when the time ran out, or
   when the window changed first (term_resized() tells the two apart), "" at the end of the input.
   Output is flushed first, or a prompt would still be in its buffer while the program waited for
   the answer to it. Works on a pipe too, which is how it is tested. */
bool term_read(double timeout, Value *out) {
    if (tty_rows > 0) return raisef("term_read() under gaz --tty: its keys are read by term::Input");
    flush_output();
    /* poll() takes milliseconds in an int, so a long wait is cut to what fits (about 23 days) */
    double wait = ceil(timeout * 1000);
    int ms = timeout < 0 ? -1 : wait > 2000000000.0 ? 2000000000 : (int)wait;
    struct pollfd p[2] = {{.fd = STDIN_FILENO, .events = POLLIN}, {.fd = wake_pipe[0], .events = POLLIN}};
    nfds_t watched = wake_pipe[0] >= 0 ? 2 : 1;
    int ready;
    while ((ready = poll(p, watched, ms)) < 0 && errno == EINTR) {}
    if (ready < 0) return raisef("term_read() failed: %s", strerror(errno));
    if (ready == 0) {
        *out = v_null();
        return true;
    }
    if (watched == 2 && p[1].revents != 0) {
        /* the window changed: said before any key, which the next read gives at once, so a
           program waiting with no timeout hears of it now rather than at its next null */
        drain_wake();
        resize_taken = true;
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

/* term_resized(): whether the window changed since this was last asked (or the program started),
   true once for each change. Nothing under gaz --tty, where the script's resize is a key. */
bool term_resized(Value *out) {
    if (tty_rows > 0) {
        *out = v_bool(false);
        return true;
    }
    if (!watch_resize()) return false;
    bool had = drain_wake();
    *out = v_bool(resize_taken || had);
    resize_taken = false;
    return true;
}

/* term_resize($cols, $rows): the pretend terminal is that big from now on, as the user dragging
   the window would make it; the script's `resize COLSxROWS` word. A real terminal is its user's
   to resize. */
bool term_resize(int64_t cols, int64_t rows) {
    if (tty_rows == 0) {
        return raisef("term_resize() is gaz --tty's: a real terminal is resized by its user, which "
                      "term_resized() and term::Input say");
    }
    if (cols < 1 || cols > 1000 || rows < 1 || rows > 1000) {
        return raisef("term_resize() expects columns and rows of 1 to 1000, got %lld and %lld",
                      (long long)cols, (long long)rows);
    }
    tty_cols = (int)cols;
    tty_rows = (int)rows;
    return true;
}

/* term_suspend(): the program stops itself as Ctrl-Z would have stopped it (raw mode turns Ctrl-Z
   into the byte 26, so the program decides), with the terminal put back for the shell, and goes
   on in raw mode again when the shell continues it, so it has only to draw its screen again.
   Under gaz --tty the pretend shell gives it straight back. Without a terminal there is no shell
   to stop for, so that is an error, as term_raw() is. */
bool term_suspend(void) {
    if (tty_rows > 0) return true;
    if (!isatty(STDIN_FILENO)) {
        return raisef("term_suspend() needs a terminal on standard input; without one, there is no "
                      "shell to go back to");
    }
    flush_output();
    stop_and_come_back();
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
