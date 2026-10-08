/*
 * workers($count): the program as several processes from here on, which is how a server
 * answers more than one request at a time (prefork, as PHP-FPM and Apache do). Each worker is a
 * fork(): it carries on from the call with a copy of everything, a socket_listen() listener
 * included. Under http::serve() the master accepts the connections and hands each to a worker once
 * its request's head is in (see "The master reads request heads" below); a program that accepts
 * its own has every worker accept on the one port, the system handing each connection to one of
 * them. Nothing is shared after that, so nothing needs a lock, and a worker that crashes takes
 * only its own request with it.
 *
 * The call returns the worker's number, 1 to $count, in each worker. The process that called it,
 * the master, never returns: it waits here, starts a worker again when one dies of an error or a
 * signal, and ends once every worker has ended with code 0. A worker that dies within a second of
 * starting without having taken a connection is a program that can't start, not bad luck, so rather
 * than start it again and again the master stops the others and exits with its code; one that took
 * a connection first died of a request, and is started again like any other.
 *
 * worker_recycle() is a third case: a worker retiring itself on purpose (http::serve's
 * max_requests, PHP-FPM's pm.max_requests), which must be replaced like a crash (the pool stays
 * $count wide) but logged and restarted like neither a crash nor a graceful stop. It raises
 * SIGUSR2 on itself, which the master below tells apart before the generic paths: it is started
 * again at once, skipping the "died within a second of starting" check (a low max_requests
 * recycling fast on purpose is not a startup failure), with its own log line rather than the
 * failure phrasing.
 *
 * worker_retire() hands over instead of leaving a gap (http::serve's max_requests uses it): the
 * worker says so in the page it shares with the master and carries on serving; the master starts
 * the replacement at once, under the same number, and once that one waits for connections asks the
 * retiring one to stop as a stop asks every worker (SIGTERM), so it leaves after the request in hand
 * and the pool never has fewer than $count workers taking connections. Leaving first would leave it
 * short for as long as the program takes to start after workers() (a database connection, an app
 * built), and empty when they all retire together, which an evenly spread load makes them do. The
 * retiring one is the master's to reap, not to replace, however it ends; for that moment two
 * processes have one number.
 *
 * SIGINT, SIGTERM and SIGHUP to the master stop the workers, and then end the master as that signal
 * would have; one the program was started ignoring (nohup's SIGHUP) stays ignored. Stopping is
 * graceful: the master stops listening and closes the connections it holds (close_front()), and a
 * worker is sent SIGTERM, which makes its next worker_accept() or socket_accept() (or the one it
 * waits in) give null, so http::serve() returns after the request in hand and the worker's program
 * ends; one still running after STOP_GRACE seconds is killed. Ctrl-C reaches the workers themselves too, and
 * ends them at once, which is what it is for.
 *
 * A master that ends without stopping them (SIGKILL, a crash) can't ask its workers to stop, so
 * each worker notices for itself: every wait that already wakes each second (worker_accept(),
 * socket_accept(), socket_wait()) asks workers_stopping(), which takes a parent other than the
 * master for a stop. Under http::serve() the master's end of the channel closing wakes an idle
 * worker at once. getppid() rather than Linux's PR_SET_PDEATHSIG, which macOS hasn't got, so both
 * behave alike.
 *
 * ponytail: the grace is fixed.
 */
/* memmem() is declared by glibc before 2.38 only under _GNU_SOURCE (see builtins.c) */
#define _GNU_SOURCE
#include "gazvm.h"

#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <poll.h>
#include <pthread.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <sys/resource.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#define MAX_WORKERS 1024
#define STOP_GRACE 10.0     /* seconds a worker has to finish its request once asked to stop */

bool vm_worker;

/*
 * Which process this is, as a generation: 0 in the one the program started in, raised in each
 * worker as it is forked (and in the child of anything else that forks a running program, such as
 * a parallel() if one is ever built). A db, socket or file records it as its owner when it is
 * made, and a fork copies the handle with the old number in it, so a worker can tell a handle it
 * inherited from one it made itself. A counter rather than getpid(), which would be a system call
 * on every file_read_line().
 *
 * Another process's handle can't be used: the connection or file offset under it is shared with
 * that process, so two of them talking on one PostgreSQL connection garble each other's replies,
 * and SQLite forbids carrying a connection across a fork at all. Releasing one abandons it rather
 * than closing it (db_close(), net_close(), file_close()), since a close says goodbye on the
 * shared connection, or moves the shared offset, for the process that still uses it. Listeners
 * are the exception: sharing one is what a worker pool is.
 */
int vm_process;

bool refuse_inherited(const char *builtin, const char *type) {
    return raisef("%s(): this %s was opened before workers(), and workers can't share one: open one after workers()", builtin, type);
}

/* dup2() swaps what fd refers to for /dev/null, keeping the number, so a library that writes its
   goodbye to fd as it closes writes it nowhere, and the connection or file it was is left alone
   for the process that still has it. Only this process's descriptor changes: the other process's
   copy of the descriptor is its own. */
void abandon_fd(int fd) {
    if (fd < 0) return;
    int null = open("/dev/null", O_RDWR | O_CLOEXEC);
    if (null < 0) return;
    dup2(null, fd);
    close(null);
}

static const int STOP_SIGNALS[] = {SIGINT, SIGTERM, SIGHUP};
#define NSTOP (int)(sizeof STOP_SIGNALS / sizeof STOP_SIGNALS[0])

/* Set by the handler, on whichever thread the signal lands: main()'s thread, which waits for the
   program's, doesn't block it, so the master can't sleep until a signal and polls instead */
static volatile sig_atomic_t stop_signal;

static void on_stop(int sig) { stop_signal = sig; }

static double now(void) {
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);
    return (double)t.tv_sec + (double)t.tv_nsec / 1e9;
}

/* What a worker tells the master, in a page they share, one for each worker number */
typedef struct {
    unsigned char accepted;    /* it has taken a connection: a death now is a request's, not start-up's */
    unsigned char listening;   /* it has waited for one: it can take over from a worker retiring */
    unsigned char retiring;    /* it asked for a replacement (worker_retire()) and serves on until then */
} Slot;

/* In a worker: SIGTERM asks it to stop, and its slot, until it retires and the slot is its
   replacement's */
static volatile sig_atomic_t stopping;
static volatile Slot *slot;

/* In a worker serving through the master (worker_accept()): the listener's descriptor, pointed at
   /dev/null (null_fd) once the worker is asked to stop, so that when the master has closed its own
   copies nothing listens and a new connection is refused rather than queued; -1 until then */
static volatile sig_atomic_t listener_to_drop = -1;
static int null_fd = -1;

static void on_worker_stop(int sig) {
    (void)sig;
    stopping = 1;
    /* dup2() is async-signal-safe, and keeps the number, which the program's socket still holds */
    if (listener_to_drop >= 0 && null_fd >= 0) dup2(null_fd, listener_to_drop);
}

/* The master's pid, which a worker's getppid() gives for as long as the master runs: once it has
   died the worker has been given to another parent (init, or a subreaper such as a container's) */
static pid_t master_pid;

/* Whether this worker should stop: asked to (SIGTERM), or orphaned, which it takes as being asked,
   so the request in hand is finished and the next wait gives null, as after a graceful stop */
bool workers_stopping(void) {
    if (!stopping && vm_worker && getppid() != master_pid) on_worker_stop(SIGTERM);
    return stopping;
}

void worker_accepted(void) {
    if (slot) slot->accepted = 1;
}

void worker_listening(void) {
    if (slot) slot->listening = 1;
}

bool worker_retire(void) {
    if (!slot) return false;
    slot->retiring = 1;
    slot = NULL;
    return true;
}

/*
 * worker_deadline(): a timer on the request in hand (http::serve's handler_timeout). When it fires
 * the handler is still running, and the worker writes the answer it was given (a 503) to the
 * client, the line it was given to standard error, and ends by SIGALRM, which the master reports
 * and replaces it for. Only a worker has one: in a single process nobody would start another, so
 * ending it would end the server.
 *
 * The signal handler may only make async-signal-safe calls, so everything it writes was copied
 * here when the timer was set, and it writes with write()/send(), no stdio, no malloc; it never
 * returns, so whatever it interrupted (a sleep(), a query) is never resumed. A worker is one thread
 * (a fork copies only the thread that forked), so the signal lands on the program's thread and
 * can't run alongside the code that clears the timer. Clearing sets `armed` to 0 before cancelling,
 * so a signal that lands between the two finds nothing to do and returns.
 */
static volatile sig_atomic_t deadline_armed;
static volatile sig_atomic_t deadline_fd = -1;
static char *deadline_answer, *deadline_line;
static size_t deadline_answer_len, deadline_line_len;

/* All of `len` bytes to fd, as far as it takes them: a send that fails or times out (the socket's
   SO_SNDTIMEO) ends it, since the worker is about to end anyway */
static void write_out(int fd, const char *p, size_t len, bool to_socket) {
    while (len > 0) {
        ssize_t n;
#ifdef MSG_NOSIGNAL
        n = to_socket ? send(fd, p, len, MSG_NOSIGNAL) : write(fd, p, len);
#else
        (void)to_socket;   /* a system without the flag (Linux and macOS both have it): a worker ignores SIGPIPE */
        n = write(fd, p, len);
#endif
        if (n < 0 && errno == EINTR) continue;
        if (n <= 0) return;
        p += n;
        len -= (size_t)n;
    }
}

static void on_deadline(int sig) {
    if (!deadline_armed) return;
    write_out(deadline_fd, deadline_answer, deadline_answer_len, true);
    write_out(2, deadline_line, deadline_line_len, false);
    /* End as SIGALRM's default does, so the master can tell why */
    struct sigaction dfl = {.sa_handler = SIG_DFL};
    sigemptyset(&dfl.sa_mask);
    sigaction(sig, &dfl, NULL);
    raise(sig);
}

/* A copy of s (NULL for nothing) in *to, grown as needed: only while the timer isn't set, so the
   handler never sees one half made */
static void keep_copy(char **to, size_t *len, Str *s) {
    *len = s ? s->len : 0;
    *to = xrealloc(*to, *len + 1);
    if (s) memcpy(*to, s->data, s->len);
}

static void set_timer(double seconds) {
    struct itimerval t = {0};
    t.it_value.tv_sec = (time_t)seconds;
    t.it_value.tv_usec = (suseconds_t)((seconds - (double)t.it_value.tv_sec) * 1e6);
    setitimer(ITIMER_REAL, &t, NULL);
}

/* setitimer() refuses more than 100000000 seconds on macOS, three years, which is no limit anyway */
#define LONGEST_DEADLINE 100000000.0

bool worker_deadline(int fd, double seconds, Str *answer, Str *line) {
    if (!vm_worker) return false;
    deadline_armed = 0;
    set_timer(0);
    if (seconds <= 0) return true;
    static bool handled;
    if (!handled) {
        /* SA_RESTART, for the signal that finds nothing to do and returns */
        struct sigaction on = {.sa_handler = on_deadline, .sa_flags = SA_RESTART};
        sigemptyset(&on.sa_mask);
        sigaction(SIGALRM, &on, NULL);
        handled = true;
    }
    keep_copy(&deadline_answer, &deadline_answer_len, answer);
    keep_copy(&deadline_line, &deadline_line_len, line);
    deadline_fd = fd;
    deadline_armed = 1;
    /* At least a microsecond, as a zero would cancel the timer instead */
    if (seconds < 1e-6) seconds = 1e-6;
    if (seconds > LONGEST_DEADLINE) seconds = LONGEST_DEADLINE;
    set_timer(seconds);
    return true;
}

/*
 * The master reads request heads (http::serve() under workers()). It accepts every connection
 * itself, waits in poll() for each to send the head of a request (up to a delimiter, under a byte
 * cap and a deadline), and only then hands the connection, with every byte it read, to a worker
 * that has asked for one. So a client sending slowly, or a kept-open connection with nothing to
 * say, costs the master an entry in a table and a descriptor, never a worker. After its response
 * a worker answers the requests already whole in what it has read (pipelined), then gives a
 * kept-open connection back, with any bytes it read past them, and the master waits for the next.
 *
 * The master learns no HTTP: a worker tells it a rule (worker_accept()'s map: the delimiter, what
 * may be skipped before a head and how often, the byte cap and the timeouts), and every byte a client receives is still written by GazLang in a worker,
 * a refusal included: a head over the cap, one the deadline cut off and one its client closed in
 * the middle of are handed over too, tagged, for the worker's reader to refuse (431, 408, 400).
 * A connection that sent nothing is closed without a word, as a worker closes one.
 *
 * Each worker has a channel to the master, a socketpair(AF_UNIX, SOCK_STREAM) made before its
 * fork. A message either way is a header of HEADER_SIZE bytes, [type][flags][length, 4 bytes]
 * [the head's first byte's time, 8 bytes, CLOCK_MONOTONIC seconds][requests served on the
 * connection, 4 bytes], then `length` bytes; a connection travels as a descriptor passed with SCM_RIGHTS on its message, so the master never
 * relays a byte of a request. Both ends are one program on one machine, so numbers are in its own
 * byte order.
 *
 *   worker -> master   R  the rule (a Rule), with the listener's descriptor: the first worker_accept()
 *                      W  it waits for a connection: a worker_accept() that hasn't said so already
 *                      I  a kept-open connection back, with the bytes read past its last request
 *                         and the count of requests served on it; with FLAG_WAITS, as it always
 *                         has (a W folded in, saving a message each way)
 *   master -> worker   C  a complete head          L  over the cap, with no end within it
 *                      T  the deadline passed      E  closed, or failed, in the middle of a head
 *                      with the count of requests served on it, for requests_per_connection,
 *                      which the worker keeps, as it answers pipelined requests itself
 *
 * The master keeps its copy of a connection it handed over until that worker's next message shows
 * the descriptor arrived (Channel.sent; worker_release() asks at once when it closes one, so the
 * client sees its end then). On macOS, with the master closing its copy straight after sendmsg(),
 * a connection was now and then shut down under its client after the hand-over: in bursts a few
 * seconds apart, about one request in 30000 under load (wrk's "read" errors), and none in over a
 * million with the copy kept. The worker closing its own copy after giving one back made no
 * difference measured, and keeping that one would hold a connection open after the master had
 * closed it, until the worker's next request.
 *
 * Every byte the master has read goes to one worker, never split, so two readers never disagree
 * about where a request ends (how requests are smuggled); the master stops at the end of the head,
 * and a body is the worker's to read. ponytail: a trickled body still holds a worker for up to
 * request_timeout; the master buffering bodies up to max_body would lift that, and a buffering
 * proxy covers it today. ponytail: a hot keep-alive connection goes back to the master after every
 * response and comes back with its next request, which wrk measured at about 35us a request (four
 * connections kept busy on four workers lost a quarter of their throughput), and the master is one
 * process, near a core at 35000 requests a second; a worker peeking for the next request a moment
 * before giving the connection back would save the hand-overs, but a peek costs every response to
 * an idle client its length, so it would want to be only for connections whose last gap was short.
 */
#define HEADER_SIZE 18
#define AT_FLAGS 1
#define AT_LENGTH 2
#define AT_TIME 6
#define AT_SERVED 14
#define FLAG_WAITS 1                  /* on an I: and the worker waits for another connection, as a W would say */
#define MSG_REGISTER 'R'
#define MSG_WANT 'W'
#define MSG_IDLE 'I'
#define HAND_COMPLETE 'C'
#define HAND_TOO_LARGE 'L'
#define HAND_TIMED_OUT 'T'
#define HAND_CLOSED 'E'
#define CHANNEL_BUFFER (256 * 1024)   /* bytes each end of a channel holds: more than a message, so a send doesn't wait */
#define CHANNEL_CHUNK 65536           /* bytes the master reads from a channel at a time */
#define TAKE_FIRST 65536              /* bytes a worker asks for in its first read of a message, which a head usually fits */
#define MOST_FDS_A_READ 4             /* descriptors one recvmsg() can bring */
#define RULE_TEXT_MOST 16             /* bytes of the rule's delimiter, and of what it skips */
#define MOST_SKIPS 1000               /* the most times the rule's skip may come */
/* The most a rule's byte cap may be. With what may be skipped before a head (MOST_SKIPS of
   RULE_TEXT_MOST) it bounds a message (at most a head's cap, head_cap(), and a header) to under
   half of CHANNEL_BUFFER, which Linux may halve again (net.core.wmem_max), so a blocking send
   never waits on a worker that isn't reading yet. */
#define MOST_CAP 65536
#define MOST_CONNECTIONS 1000000      /* the most max_connections may ask for, before the open file limit lowers it */

/* What a worker tells the master about the requests on its listener (worker_accept()'s map) */
typedef struct {
    char ready[RULE_TEXT_MOST];   /* a head ends with this ("ready") */
    size_t ready_len;
    char skip[RULE_TEXT_MOST];    /* and may come after this ("skip"), up to skip_most times */
    size_t skip_len;
    int64_t skip_most;
    int64_t most;                 /* bytes a head may be, without its end and what was skipped */
    double within;                /* seconds a head may take: from its connection's accept, or after
                                     an idle wait from its first byte */
    double idle;                  /* seconds a kept-open connection may wait for its next request */
    double timeout;               /* seconds each read and write on a connection may wait, set at accept */
    int64_t max_connections;      /* open at once, in the master's table or held by workers */
    int listener_number;          /* the listener's descriptor in the worker (see close_front()) */
} Rule;

/* Whether a rule is one worker_accept() would have sent: the master checks what it is given rather
   than read past the rule's texts on the word of a length in it */
static bool rule_sound(const Rule *r) {
    return r->ready_len >= 1 && r->ready_len <= RULE_TEXT_MOST && r->skip_len >= 1 && r->skip_len <= RULE_TEXT_MOST
        && r->skip_most >= 0 && r->skip_most <= MOST_SKIPS && r->most >= 1 && r->most <= MOST_CAP
        && r->within > 0 && r->idle > 0 && r->timeout > 0
        && r->max_connections >= 1 && r->max_connections <= MOST_CONNECTIONS;
}

/* The bytes that settle a head either way, whatever is skipped before it: the master reads no more,
   and a worker gives back no more */
static size_t head_cap(const Rule *rule) {
    return rule->skip_len * (size_t)rule->skip_most + (size_t)rule->most + 1;
}

/*
 * Whether bytes hold a whole head by the rule: HAND_COMPLETE, HAND_TOO_LARGE when they run past the
 * cap without one, or 0 while more must come. *searched says where no end starts before, for the
 * next look at the same bytes grown. A worker's reader (read_request() in http.gaz) skips and
 * searches exactly so, which is what makes a C a head it reads without waiting and an L one it
 * refuses (431) without reading more. Reader.holds_head() in http.gaz, which decides what a worker
 * keeps and what it gives back, must agree with it.
 */
static char head_state(const Rule *rule, const char *data, size_t len, size_t *searched) {
    size_t at = 0;
    for (int64_t skipped = 0; skipped < rule->skip_most; skipped++) {
        if (len - at < rule->skip_len) return 0;
        if (memcmp(data + at, rule->skip, rule->skip_len) != 0) break;
        at += rule->skip_len;
    }
    size_t from = *searched > at ? *searched : at;
    const char *end = memmem(data + from, len - from, rule->ready, rule->ready_len);
    if (end && (size_t)(end - (data + at)) <= (size_t)rule->most) return HAND_COMPLETE;
    if (len - at > (size_t)rule->most) return HAND_TOO_LARGE;
    /* An end that starts in what came last may finish in what comes next */
    *searched = len >= rule->ready_len ? len - rule->ready_len + 1 : 0;
    return 0;
}

static void put_header(unsigned char *header, char type, int flags, uint32_t len, double at, uint32_t served) {
    header[0] = (unsigned char)type;
    header[AT_FLAGS] = (unsigned char)flags;
    memcpy(header + AT_LENGTH, &len, sizeof len);
    memcpy(header + AT_TIME, &at, sizeof at);
    memcpy(header + AT_SERVED, &served, sizeof served);
}

/*
 * A message on a channel, with fd passed on it unless it is -1; false if the other end has gone.
 * The whole of it: a stream socket may take part of a send, and the descriptor goes with the first
 * part. Blocking, since a channel's buffer holds more than one message and each end has at most
 * one message unread by the other (a worker sends only after asking or being given, the master
 * only to a worker that asked), so it never waits long.
 */
static bool send_message(int chan, char type, int flags, double at, uint32_t served, const char *data, size_t len, int fd) {
    unsigned char header[HEADER_SIZE];
    put_header(header, type, flags, (uint32_t)len, at, served);
    struct iovec parts[2] = {{header, HEADER_SIZE}, {(void *)data, len}};
    /* Room for one descriptor, aligned as a cmsghdr must be */
    union {
        struct cmsghdr align;
        char bytes[CMSG_SPACE(sizeof(int))];
    } control;
    struct msghdr m = {.msg_iov = parts, .msg_iovlen = len > 0 ? 2 : 1};
    if (fd >= 0) {
        memset(&control, 0, sizeof control);
        m.msg_control = control.bytes;
        m.msg_controllen = sizeof control.bytes;
        struct cmsghdr *c = CMSG_FIRSTHDR(&m);
        c->cmsg_level = SOL_SOCKET;
        c->cmsg_type = SCM_RIGHTS;
        c->cmsg_len = CMSG_LEN(sizeof(int));
        memcpy(CMSG_DATA(c), &fd, sizeof fd);
    }
    size_t left = HEADER_SIZE + len;
    while (left > 0) {
        ssize_t n = sendmsg(chan, &m, 0);
        if (n < 0 && errno == EINTR) continue;
        if (n <= 0) return false;
        left -= (size_t)n;
        /* What is left of the parts, without the descriptor, which went with the first */
        m.msg_control = NULL;
        m.msg_controllen = 0;
        size_t done = (size_t)n;
        while (done > 0 && m.msg_iovlen > 0) {
            if (done >= m.msg_iov[0].iov_len) {
                done -= m.msg_iov[0].iov_len;
                m.msg_iov++;
                m.msg_iovlen--;
            } else {
                m.msg_iov[0].iov_base = (char *)m.msg_iov[0].iov_base + done;
                m.msg_iov[0].iov_len -= done;
                done = 0;
            }
        }
    }
    return true;
}

/* Linux can make a received descriptor close-on-exec as it arrives; macOS can't, so it is fcntl()'s */
#ifdef MSG_CMSG_CLOEXEC
#define RECEIVE_FLAGS MSG_CMSG_CLOEXEC
#else
#define RECEIVE_FLAGS 0
#endif

/* recvmsg() of up to size bytes into buf; descriptors that came with them are added to fds, of
   which there are *nfds (room for MOST_FDS_A_READ: one more is closed), each close-on-exec */
static ssize_t receive(int chan, char *buf, size_t size, int flags, int *fds, int *nfds) {
    struct iovec part = {buf, size};
    union {
        struct cmsghdr align;
        char bytes[CMSG_SPACE(MOST_FDS_A_READ * sizeof(int))];
    } control;
    struct msghdr m = {.msg_iov = &part, .msg_iovlen = 1, .msg_control = control.bytes, .msg_controllen = sizeof control.bytes};
    ssize_t n = recvmsg(chan, &m, flags | RECEIVE_FLAGS);
    if (n < 0) return n;
    for (struct cmsghdr *c = CMSG_FIRSTHDR(&m); c; c = CMSG_NXTHDR(&m, c)) {
        if (c->cmsg_level != SOL_SOCKET || c->cmsg_type != SCM_RIGHTS) continue;
        size_t count = (c->cmsg_len - CMSG_LEN(0)) / sizeof(int);
        for (size_t k = 0; k < count; k++) {
            int fd;
            memcpy(&fd, CMSG_DATA(c) + k * sizeof(int), sizeof fd);
            if (RECEIVE_FLAGS == 0) fcntl(fd, F_SETFD, FD_CLOEXEC);
            if (*nfds < MOST_FDS_A_READ) fds[(*nfds)++] = fd;
            else close(fd);
        }
    }
    return n;
}

/* At least `fewest` bytes from a channel, and at most size, waiting for them; how many came, or
   -1 if the channel ends first */
static ssize_t receive_at_least(int chan, char *buf, size_t fewest, size_t size, int *fds, int *nfds) {
    size_t got = 0;
    while (got < fewest) {
        ssize_t n = receive(chan, buf + got, size - got, 0, fds, nfds);
        if (n < 0 && errno == EINTR) continue;
        if (n <= 0) return -1;
        got += (size_t)n;
    }
    return (ssize_t)got;
}

/* ---- A worker's side: worker_accept() and worker_release() ---- */

/* In a worker: its end of its channel to the master, -1 elsewhere */
static int channel = -1;
/* The listener whose rule the master has, -1 before the first worker_accept(), and the rule */
static int registered_listener = -1;
static Rule registered_rule;
/* The connection the master last handed this worker, until worker_release() */
static int held = -1;
/* The master counts this worker as waiting for a connection: it said so (W, or an I that waits) */
static bool asked;

static const char *const RULE_KEYS[] = {
    "ready", "skip", "skip_most", "most", "within", "idle", "max_connections", "timeout",
};
#define NRULE_KEYS (int)(sizeof RULE_KEYS / sizeof RULE_KEYS[0])

static Value *rule_field(Map *rule, const char *name) {
    Value key = v_str(str_cstr(name));
    Value *v = map_find(rule, key);
    decref(key);
    if (!v) raisef("worker_accept()'s rule has no \"%s\"", name);
    return v;
}

static bool rule_text(Map *rule, const char *name, char *to, size_t *len) {
    Value *v = rule_field(rule, name);
    if (!v) return false;
    if (v->type != T_STRING || v->s->len < 1 || v->s->len > RULE_TEXT_MOST) {
        return raisef("worker_accept() expects the rule's \"%s\" to be a string of 1 to %d bytes", name, RULE_TEXT_MOST);
    }
    memcpy(to, v->s->data, v->s->len);
    *len = v->s->len;
    return true;
}

static bool rule_int(Map *rule, const char *name, int64_t fewest, int64_t most, int64_t *to) {
    Value *v = rule_field(rule, name);
    if (!v) return false;
    if (v->type != T_INT || v->i < fewest || v->i > most) {
        return raisef("worker_accept() expects the rule's \"%s\" to be an int from %lld to %lld", name, (long long)fewest, (long long)most);
    }
    *to = v->i;
    return true;
}

static bool rule_seconds(Map *rule, const char *name, double *to) {
    Value *v = rule_field(rule, name);
    if (!v) return false;
    double seconds = v->type == T_INT ? (double)v->i : v->type == T_FLOAT ? v->f : 0;
    if ((v->type != T_INT && v->type != T_FLOAT) || !(seconds > 0)) {
        return raisef("worker_accept() expects the rule's \"%s\" to be seconds above 0", name);
    }
    *to = seconds;
    return true;
}

/* worker_accept()'s map as a Rule, every key checked */
static bool read_rule(Map *map, Rule *rule) {
    for (size_t i = 0; map_next(map, &i); i++) {
        Value key = map->entries[i].key;
        bool known = false;
        for (int k = 0; k < NRULE_KEYS && key.type == T_STRING; k++) {
            known = known || (key.s->len == strlen(RULE_KEYS[k]) && memcmp(key.s->data, RULE_KEYS[k], key.s->len) == 0);
        }
        if (!known) {
            Buf name = {0};
            if (key.type == T_STRING) quote(key.s, &name);
            else append_string(key, &name);
            raisef("worker_accept()'s rule has no use for %s", name.data);
            free(name.data);
            return false;
        }
    }
    return rule_text(map, "ready", rule->ready, &rule->ready_len)
        && rule_text(map, "skip", rule->skip, &rule->skip_len)
        && rule_int(map, "skip_most", 0, MOST_SKIPS, &rule->skip_most)
        && rule_int(map, "most", 1, MOST_CAP, &rule->most)
        && rule_seconds(map, "within", &rule->within)
        && rule_seconds(map, "idle", &rule->idle)
        && rule_int(map, "max_connections", 1, MOST_CONNECTIONS, &rule->max_connections)
        && rule_seconds(map, "timeout", &rule->timeout);
}

static const char *hand_name(char type) {
    switch (type) {
    case HAND_COMPLETE: return "complete";
    case HAND_TOO_LARGE: return "too_large";
    case HAND_TIMED_OUT: return "timed_out";
    case HAND_CLOSED: return "closed";
    default: return NULL;
    }
}

/* The message the master sent, which a poll() said has come: [socket, bytes, state, served,
   first byte's time], or null if the master has gone. One read, when the message fits TAKE_FIRST. */
static bool take_handed(Value *out) {
    int fds[MOST_FDS_A_READ], nfds = 0;
    size_t size = HEADER_SIZE + TAKE_FIRST;
    char *message = xmalloc(size);
    ssize_t got = receive_at_least(channel, message, HEADER_SIZE, size, fds, &nfds);
    uint32_t len = 0;
    if (got >= 0) {
        memcpy(&len, message + AT_LENGTH, sizeof len);
        size_t whole = HEADER_SIZE + (size_t)len;
        if (whole > size) message = xrealloc(message, whole);
        /* Never past this message: the master sends a worker one message per request for one */
        if ((size_t)got < whole && receive_at_least(channel, message + got, whole - (size_t)got, whole - (size_t)got, fds, &nfds) < 0) got = -1;
    }
    const char *state = got >= 0 ? hand_name(message[0]) : NULL;
    if (!state || nfds != 1) {
        for (int k = 0; k < nfds; k++) close(fds[k]);
        /* Whatever it was answered this worker's asking, so the next worker_accept() asks again */
        asked = false;
        free(message);
        if (got < 0) {
            *out = v_null();
            return true;
        }
        return raisef("worker_accept(): the master sent what it never sends");
    }
    double at;
    uint32_t served;
    memcpy(&at, message + AT_TIME, sizeof at);
    memcpy(&served, message + AT_SERVED, sizeof served);
    held = fds[0];
    asked = false;
    worker_accepted();
    List *taken = list_new(5);
    list_push(taken, net_adopt(fds[0], registered_rule.timeout));
    list_push(taken, v_str(str_new(message + HEADER_SIZE, len)));
    list_push(taken, v_str(str_cstr(state)));
    list_push(taken, v_int(served));
    list_push(taken, v_float(at));
    free(message);
    *out = v_list(taken);
    return true;
}

/*
 * worker_accept($listener, $rule): the next connection the master hands this worker, as
 * [$socket, $bytes, $state, $served, $first_byte_at], or null once the worker is asked to stop (or
 * the master has gone); false outside a worker, where nobody reads heads for it. The first call
 * gives the master the rule and the listener. A message already sent is taken even after a stop,
 * since the master hands a connection only to a worker that asked, and it is the request in hand.
 */
bool worker_accept(Socket *listener, Map *map, Value *out) {
    if (listener->fd < 0) return raisef("worker_accept() on a closed socket");
    if (!listener->listening) return raisef("worker_accept() expects a listening socket, got a connection");
    Rule rule = {0};
    if (!read_rule(map, &rule)) return false;
    if (!vm_worker) {
        *out = v_bool(false);
        return true;
    }
    if (registered_listener >= 0 && listener->fd != registered_listener) {
        return raisef("worker_accept() on a second listener: the master reads one listener's connections");
    }
    worker_listening();
    if (registered_listener < 0) {
        rule.listener_number = listener->fd;
        if (!send_message(channel, MSG_REGISTER, 0, 0, 0, (const char *)&rule, sizeof rule, listener->fd)) {
            *out = v_null();
            return true;
        }
        registered_listener = listener->fd;
        registered_rule = rule;
        null_fd = open("/dev/null", O_RDWR | O_CLOEXEC);
        listener_to_drop = listener->fd;
        if (stopping && null_fd >= 0) dup2(null_fd, listener->fd);
    }
    if (!stopping && !asked) {
        if (!send_message(channel, MSG_WANT, 0, 0, 0, NULL, 0, -1)) {
            *out = v_null();
            return true;
        }
        asked = true;
    }
    for (;;) {
        /* A second at a time, so a stop that comes just before the wait is still seen */
        struct pollfd p = {.fd = channel, .events = POLLIN};
        bool stop = workers_stopping();
        int ready = poll(&p, 1, stop ? 0 : 1000);
        if (ready < 0 && errno != EINTR) return raisef("worker_accept() failed: %s", strerror(errno));
        if (ready > 0) return take_handed(out);
        if (stop) {
            *out = v_null();
            return true;
        }
    }
}

/*
 * worker_release($socket, $bytes, $served = 0): the connection worker_accept() gave, given back to
 * the master to wait for its next request, with $bytes, what was read past the last one, and
 * $served, how many requests it has had answered (for requests_per_connection); or, with null,
 * closed. Either way this worker's copy is closed. Either way it also asks for the next connection
 * (FLAG_WAITS on the I, or a W), as the worker_accept() that follows would, saving a message, so a
 * worker calls worker_accept() next (as http::serve() does) unless it is stopping. Closed too once
 * the worker is asked to stop, since the master is closing its own connections. $bytes holds
 * no whole head and is at most the rule's cap (a longer one is the reader's to refuse, which it can
 * without reading more), so a message always fits the channel. False outside a worker.
 */
bool worker_release(Socket *s, Value bytes, int64_t served, Value *out) {
    if (s->fd < 0) return raisef("worker_release() on a closed socket");
    if (s->listening) return raisef("worker_release() on a listening socket: it takes the connection worker_accept() gave");
    if (s->owner != vm_process) return refuse_inherited("worker_release", "socket");
    if (served < 0 || served > UINT32_MAX) return raisef("worker_release() expects 0 to %lu requests served, got %lld", (unsigned long)UINT32_MAX, (long long)served);
    if (!vm_worker) {
        *out = v_bool(false);
        return true;
    }
    if (s->fd != held) return raisef("worker_release() takes the connection worker_accept() gave, not another");
    size_t cap = head_cap(&registered_rule);
    if (bytes.type == T_STRING && bytes.s->len > cap) {
        return raisef("worker_release() expects at most %zu bytes, the rule's cap: a longer head is the reader's to refuse", cap);
    }
    held = -1;
    /* A master that has gone takes the connection with it */
    if (bytes.type == T_STRING && !stopping) {
        asked = send_message(channel, MSG_IDLE, FLAG_WAITS, 0, (uint32_t)served, bytes.s->data, bytes.s->len, s->fd);
    } else if (!stopping) {
        /* Asking now tells the master this worker has the connection, so it lets go of its copy
           (Channel.sent) and the client sees the end now, not at the worker's next request */
        asked = send_message(channel, MSG_WANT, 0, 0, 0, NULL, 0, -1);
    }
    net_close(s);
    *out = v_bool(true);
    return true;
}

/* What the master had before it took the signals over, which a worker gets back */
static struct sigaction saved_stop[NSTOP];

static void restore_signals(void) {
    for (int i = 0; i < NSTOP; i++) sigaction(STOP_SIGNALS[i], &saved_stop[i], NULL);
}

/* How a worker ended, for the master's message, and the exit code that stands for it */
static int describe(int status, char *text, size_t size) {
    if (WIFSIGNALED(status) && WTERMSIG(status) == SIGALRM) {
        /* worker_deadline()'s end: its answer and its line are written already */
        snprintf(text, size, "timed out on a request");
        return 128 + SIGALRM;
    }
    if (WIFSIGNALED(status)) {
        snprintf(text, size, "died of signal %d", WTERMSIG(status));
        return 128 + WTERMSIG(status);
    }
    snprintf(text, size, "exited with code %d", WEXITSTATUS(status));
    return WEXITSTATUS(status);
}

static void pause_briefly(void) { nanosleep(&(struct timespec){.tv_nsec = 50000000}, NULL); }

/* ---- The master's side: its table of connections ---- */

#define POLL_MS 50              /* the longest the master waits before looking at its workers again */
#define HAND_OVER_POLL_MS 5     /* the same while a hand-over (worker_retire()) is under way */
#define READ_CHUNK 16384        /* bytes the master reads from a connection at a time */
#define ACCEPT_BURST 16         /* connections accepted in a row before looking at the rest again */
#define LISTENER_REST 0.05      /* seconds the listener is left alone when there are no descriptors to accept with */
#define SPARE_FDS 64            /* descriptors left for everything but the table: the program's own, the channels */
#define MOST_POLLED 10000       /* poll() on macOS refuses more than OPEN_MAX (10240) descriptors at once */
#define LISTENER_TAG -1         /* a watched descriptor's tag: a connection's is its entry, a channel's -2 - its index */

/* A worker's channel, as the master has it */
typedef struct {
    int fd;                     /* the master's end, -1 when there is none */
    bool wants;                 /* it asked for a connection (W) and hasn't been given one */
    bool holding;               /* it was given one and hasn't asked again, so that one is still open */
    uint64_t asked;             /* when it asked, as a count: the latest asker gets the next connection */
    char *in;                   /* bytes read that aren't a whole message yet */
    size_t in_len, in_cap;
    int fds[MOST_FDS_A_READ];   /* descriptors that came with them, oldest first */
    int nfds;
    int sent;                   /* the connection last handed to it, kept until its next message (-1 when none) */
} Channel;

/* A connection in the master's table: a head coming, or a kept-open one waiting for its next request */
typedef struct {
    int fd;                     /* -1: a free entry */
    char tag;                   /* 0 while being read; once queued for a worker, C, L, T or E */
    bool idle;                  /* kept open and waiting for a request, under the rule's idle time */
    char *data;                 /* what it sent, all of which goes to the worker */
    size_t len, cap;
    size_t searched;            /* no head's end starts before this in data */
    double first_byte_at;       /* when the head's first byte came, or 0 */
    double deadline;            /* when the master stops waiting for it */
    uint32_t served;            /* requests answered on it so far, which a worker says as it gives it back */
} Conn;

/* The descriptors the master waits on: built again before each wait. ponytail: poll(), which costs
   time in the number of descriptors (about 0.3ms at 1000) and is why the table is bounded; with
   1000 idle connections in the table, wrk -c16 measured 8100 requests a second where it had 25400
   without them. A kqueue or epoll backend behind watch_clear()/watch_add()/watch_wait() would keep
   its registrations and lift that. */
typedef struct {
    struct pollfd *fds;
    int *tags;
    int len, cap;
} Watch;

/* The master's record of its workers, by number - 1 */
typedef struct {
    int count;
    pid_t *pids;            /* each number's worker, 0 once it has ended; then, at count + i, the
                               one retiring from number i + 1 while pids[i] takes over, or 0 */
    double *started;        /* when each number's worker was started */
    double *relieved;       /* when each number's retiring one was asked to stop, or 0 */
    volatile Slot *slots;
    Channel *channels;      /* each pid's channel, at the same index */
    bool registered;        /* a worker has given the rule (worker_accept()) */
    Rule rule;
    int listener;           /* the master's copy of the listener, from the rule's message, or -1 */
    double listener_rest;   /* out of descriptors: the listener is left alone until then */
    Conn *conns;            /* the table, rule.max_connections entries, once registered */
    int open;               /* entries in use */
    int top;                /* every entry from here on is free, so a scan of the table stops here */
    int *queue;             /* entries ready for a worker, oldest first, a ring of max_connections */
    int queue_start, queue_len;
    uint64_t asked;         /* what the last Channel.asked was */
    Watch watch;
    double now;             /* now(), read once each time round the master's loop and after each wait */
} Pool;

/* Room for need bytes in a growing buffer */
static void reserve(char **data, size_t *cap, size_t need) {
    if (need <= *cap) return;
    size_t grown = *cap ? *cap : 1024;
    while (grown < need) grown *= 2;
    *data = xrealloc(*data, grown);
    *cap = grown;
}

static void channel_close(Pool *pool, int i) {
    Channel *ch = &pool->channels[i];
    if (ch->fd >= 0) close(ch->fd);
    for (int k = 0; k < ch->nfds; k++) close(ch->fds[k]);
    if (ch->sent >= 0) close(ch->sent);
    free(ch->in);
    *ch = (Channel){.fd = -1, .sent = -1};
}

static void conn_close(Pool *pool, int k) {
    Conn *c = &pool->conns[k];
    if (c->fd >= 0) close(c->fd);
    free(c->data);
    *c = (Conn){.fd = -1};
    pool->open--;
    while (pool->top > 0 && pool->conns[pool->top - 1].fd < 0) pool->top--;
}

/* Everything the master holds for its workers and their connections: freed by the master at its
   end, and by each worker as it is forked, which must not keep a copy of any of the master's
   descriptors (a connection the master closed would stay open in it) */
static void pool_free(Pool *pool) {
    for (int i = 0; i < 2 * pool->count; i++) channel_close(pool, i);
    for (int k = pool->top - 1; pool->conns && k >= 0; k--) {
        if (pool->conns[k].fd >= 0) conn_close(pool, k);
    }
    if (pool->listener >= 0) close(pool->listener);
    free(pool->channels);
    free(pool->conns);
    free(pool->queue);
    free(pool->watch.fds);
    free(pool->watch.tags);
    free(pool->pids);
    free(pool->started);
    free(pool->relieved);
}

/* Fork worker i + 1, with its channel: its pid in the master, 0 in the worker, -1 if it couldn't.
   The stop signals are blocked across the fork, or one sent to a worker before it has put its
   handlers back would only set its copy of the master's flag, and the master would wait for it for
   ever. */
static pid_t fork_worker(Pool *pool, int i, Value *out) {
    volatile Slot *own = &pool->slots[i];
    own->accepted = 0;
    own->listening = 0;
    own->retiring = 0;
    int ends[2];
    if (socketpair(AF_UNIX, SOCK_STREAM, 0, ends) != 0) return -1;
    int size = CHANNEL_BUFFER;
    for (int e = 0; e < 2; e++) {
        fcntl(ends[e], F_SETFD, FD_CLOEXEC);
        setsockopt(ends[e], SOL_SOCKET, SO_SNDBUF, &size, sizeof size);
        setsockopt(ends[e], SOL_SOCKET, SO_RCVBUF, &size, sizeof size);
    }
    sigset_t stops, before;
    sigemptyset(&stops);
    for (int k = 0; k < NSTOP; k++) sigaddset(&stops, STOP_SIGNALS[k]);
    pthread_sigmask(SIG_BLOCK, &stops, &before);
    pid_t pid = fork();
    if (pid == 0) {
        vm_worker = true;
        vm_process++;
        restore_signals();
        slot = own;
        close(ends[0]);
        channel = ends[1];
        /* Not SA_RESTART, so a socket_accept() waiting is woken to give null */
        struct sigaction stop = {.sa_handler = on_worker_stop};
        sigemptyset(&stop.sa_mask);
        if (saved_stop[1].sa_handler != SIG_IGN) sigaction(SIGTERM, &stop, NULL);
        /* Or every worker would draw the same random numbers */
        random_seed_unpredictable();
        *out = v_int(i + 1);
    } else {
        int err = errno;
        close(ends[1]);
        if (pid > 0) pool->channels[i].fd = ends[0];
        else close(ends[0]);
        errno = err;
    }
    /* Now a signal waiting is handled: by the master's handler, or a worker's own */
    pthread_sigmask(SIG_SETMASK, &before, NULL);
    return pid;
}

/* Connections open: in the table, and held by workers */
static int connections_open(Pool *pool) {
    int open = pool->open;
    for (int i = 0; i < 2 * pool->count; i++) open += pool->channels[i].holding;
    return open;
}

static void queue_up(Pool *pool, int k, char tag) {
    pool->conns[k].tag = tag;
    int ring = (int)pool->rule.max_connections;
    pool->queue[(pool->queue_start + pool->queue_len) % ring] = k;
    pool->queue_len++;
}

/* A head's first byte: the head now has the rule's time from here if it came after an idle wait,
   as a fresh connection's had it from its accept */
static void first_byte(Pool *pool, Conn *c, double t) {
    c->first_byte_at = t;
    if (c->idle) {
        c->idle = false;
        c->deadline = t + pool->rule.within;
    }
}

/* A connection into the table, with what it already sent (a kept-open one's bytes read past its
   last request, at most a head's cap); idle for one coming back from a worker */
static void conn_add(Pool *pool, int fd, const char *data, size_t len, bool idle, uint32_t served) {
    int k = 0;
    while (k < (int)pool->rule.max_connections && pool->conns[k].fd >= 0) k++;
    if (k == (int)pool->rule.max_connections) {
        /* Can't happen: an entry is kept for every connection a worker holds */
        close(fd);
        return;
    }
    double t = pool->now;
    Conn *c = &pool->conns[k];
    *c = (Conn){.fd = fd, .idle = idle, .served = served};
    if (k >= pool->top) pool->top = k + 1;
    c->deadline = t + (idle ? pool->rule.idle : pool->rule.within);
    pool->open++;
    if (len == 0) return;
    reserve(&c->data, &c->cap, len);
    memcpy(c->data, data, len);
    c->len = len;
    first_byte(pool, c, t);
    char state = head_state(&pool->rule, c->data, c->len, &c->searched);
    if (state) queue_up(pool, k, state);
}

/* Read what a connection has sent: a head that is whole, over the cap or cut off goes in the queue,
   and a connection that ends without having said anything is closed without a word */
static void read_conn(Pool *pool, int k) {
    Conn *c = &pool->conns[k];
    size_t cap = head_cap(&pool->rule);
    size_t want = c->len < cap ? cap - c->len : 0;
    if (want > READ_CHUNK) want = READ_CHUNK;
    reserve(&c->data, &c->cap, c->len + want);
    /* MSG_DONTWAIT rather than O_NONBLOCK, which would go with the descriptor to the worker */
    ssize_t n = recv(c->fd, c->data + c->len, want, MSG_DONTWAIT);
    if (n < 0 && (errno == EAGAIN || errno == EWOULDBLOCK || errno == EINTR)) return;
    if (n <= 0) {
        /* The end, or a failure such as a reset */
        if (c->first_byte_at > 0) queue_up(pool, k, HAND_CLOSED);
        else conn_close(pool, k);
        return;
    }
    if (c->first_byte_at == 0) first_byte(pool, c, pool->now);
    c->len += (size_t)n;
    char state = head_state(&pool->rule, c->data, c->len, &c->searched);
    if (state) queue_up(pool, k, state);
}

/* Connections whose wait is over: a head begun is handed over for its 408, and one that said
   nothing is closed without a word */
static void expire(Pool *pool, double t) {
    for (int k = 0; k < pool->top; k++) {
        Conn *c = &pool->conns[k];
        if (c->fd < 0 || c->tag || t < c->deadline) continue;
        if (c->first_byte_at > 0) queue_up(pool, k, HAND_TIMED_OUT);
        else conn_close(pool, k);
    }
}

/*
 * The most connections the master can hold at once: the rule's, or fewer if the open file limit
 * leaves less room, each being a descriptor. The soft limit is raised toward the hard one first
 * (macOS refuses one above OPEN_MAX). This is at the first worker's rule, after the first workers
 * were forked, so they keep the limit the program started with, and only workers forked later
 * (replacements) inherit the raised one; the master's table is what needs the room.
 */
static int64_t connections_room(Pool *pool, int64_t asked) {
    int64_t room = asked;
    struct rlimit limit;
    if (getrlimit(RLIMIT_NOFILE, &limit) == 0) {
        rlim_t want = limit.rlim_max;
#ifdef OPEN_MAX
        if (want > OPEN_MAX) want = OPEN_MAX;
#endif
        if (limit.rlim_cur < want) {
            struct rlimit raised = {.rlim_cur = want, .rlim_max = limit.rlim_max};
            if (setrlimit(RLIMIT_NOFILE, &raised) == 0) limit.rlim_cur = want;
        }
        if (limit.rlim_cur != RLIM_INFINITY) {
            int64_t fits = (int64_t)limit.rlim_cur - SPARE_FDS - 2 * pool->count;
            if (fits < room) room = fits;
        }
    }
    int64_t pollable = MOST_POLLED - 2 * pool->count - 1;
    if (pollable < room) room = pollable;
    if (room < 1) room = 1;
    if (room < asked) {
        fprintf(stderr, "gaz: max_connections %lld lowered to %lld, which the open file limit leaves room for\n",
                (long long)asked, (long long)room);
    }
    return room;
}

/* The first worker's rule, and the listener: from now on the master accepts. Every worker runs
   the same program, so a later worker's rule is the same, and its copy of the listener is closed.
   False for a rule worker_accept() wouldn't have sent. ponytail: a worker whose rule differed
   would be served by the first one's. */
static bool take_rule(Pool *pool, const char *payload, int fd) {
    Rule rule;
    memcpy(&rule, payload, sizeof rule);
    if (!rule_sound(&rule)) {
        close(fd);
        return false;
    }
    if (pool->registered) {
        close(fd);
        return true;
    }
    pool->rule = rule;
    pool->listener = fd;
    pool->rule.max_connections = connections_room(pool, pool->rule.max_connections);
    int entries = (int)pool->rule.max_connections;
    pool->conns = xmalloc((size_t)entries * sizeof *pool->conns);
    for (int k = 0; k < entries; k++) pool->conns[k] = (Conn){.fd = -1};
    pool->queue = xmalloc((size_t)entries * sizeof *pool->queue);
    pool->registered = true;
    return true;
}

static int take_fd(Channel *ch) {
    if (ch->nfds == 0) return -1;
    int fd = ch->fds[0];
    memmove(ch->fds, ch->fds + 1, (size_t)(ch->nfds - 1) * sizeof ch->fds[0]);
    ch->nfds--;
    return fd;
}

/* Worker i waits for a connection */
static void wants(Pool *pool, Channel *ch) {
    ch->wants = true;
    ch->holding = false;
    ch->asked = ++pool->asked;
}

/* One message from worker i, its header's fields given; false if it isn't one a worker sends */
static bool take_message(Pool *pool, int i, const char *header, const char *payload, size_t len) {
    Channel *ch = &pool->channels[i];
    char type = header[0];
    if (ch->sent >= 0) {
        close(ch->sent);
        ch->sent = -1;
    }
    if (type == MSG_WANT) {
        wants(pool, ch);
        return true;
    }
    int fd = type == MSG_REGISTER || type == MSG_IDLE ? take_fd(ch) : -1;
    if (fd < 0) return false;
    if (type == MSG_REGISTER && len == sizeof pool->rule) return take_rule(pool, payload, fd);
    if (type == MSG_IDLE && pool->registered && ch->holding && len <= head_cap(&pool->rule)) {
        uint32_t served;
        memcpy(&served, header + AT_SERVED, sizeof served);
        ch->holding = false;
        conn_add(pool, fd, payload, len, true, served);
        if (header[AT_FLAGS] & FLAG_WAITS) wants(pool, ch);
        return true;
    }
    close(fd);
    return false;
}

/* What worker i has sent: each whole message taken, the rest kept for the next read */
static void read_channel(Pool *pool, int i) {
    Channel *ch = &pool->channels[i];
    for (;;) {
        reserve(&ch->in, &ch->in_cap, ch->in_len + CHANNEL_CHUNK);
        ssize_t n = receive(ch->fd, ch->in + ch->in_len, CHANNEL_CHUNK, MSG_DONTWAIT, ch->fds, &ch->nfds);
        if (n < 0 && errno == EINTR) continue;
        if (n < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) break;
        if (n <= 0) {
            /* The worker has ended, or is ending; its pid is reaped as any other's */
            channel_close(pool, i);
            return;
        }
        ch->in_len += (size_t)n;
        /* A short read took all there was: asking again would only say so */
        if (n < CHANNEL_CHUNK) break;
    }
    size_t at = 0;
    while (ch->in_len - at >= HEADER_SIZE) {
        uint32_t len;
        memcpy(&len, ch->in + at + AT_LENGTH, sizeof len);
        if (ch->in_len - at - HEADER_SIZE < len) break;
        if (!take_message(pool, i, ch->in + at, ch->in + at + HEADER_SIZE, len)) {
            fprintf(stderr, "gaz: worker %d sent what a worker never sends; no longer handing it connections\n", i % pool->count + 1);
            channel_close(pool, i);
            return;
        }
        at += HEADER_SIZE + len;
    }
    memmove(ch->in, ch->in + at, ch->in_len - at);
    ch->in_len -= at;
}

/*
 * The worker to hand the next connection: of those waiting for one, the one that asked last (its
 * memory likeliest still in the processor's caches, and the others' left to sleep); a retiring one
 * only when no other waits, since it serves on only until its replacement is ready, and never one
 * asked to stop. -1 when none waits.
 */
static int pick_worker(Pool *pool) {
    int best = -1;
    bool best_leaving = false;
    for (int i = 0; i < 2 * pool->count; i++) {
        Channel *ch = &pool->channels[i];
        if (ch->fd < 0 || !ch->wants) continue;
        if (i >= pool->count && pool->relieved[i - pool->count]) continue;
        bool leaving = i >= pool->count || pool->slots[i].retiring;
        bool better = best < 0 || (best_leaving && !leaving)
                   || (best_leaving == leaving && ch->asked > pool->channels[best].asked);
        if (better) {
            best = i;
            best_leaving = leaving;
        }
    }
    return best;
}

/* Hand the queued connections, oldest first, to the workers waiting for one */
static void dispatch(Pool *pool) {
    int ring = (int)pool->rule.max_connections;
    while (pool->queue_len > 0) {
        int w = pick_worker(pool);
        if (w < 0) return;
        int k = pool->queue[pool->queue_start];
        Conn *c = &pool->conns[k];
        Channel *ch = &pool->channels[w];
        if (!send_message(ch->fd, c->tag, 0, c->first_byte_at, c->served, c->data, c->len, c->fd)) {
            /* That worker has gone: the connection waits for another */
            channel_close(pool, w);
            continue;
        }
        ch->wants = false;
        ch->holding = true;
        pool->queue_start = (pool->queue_start + 1) % ring;
        pool->queue_len--;
        /* Kept until the worker's next message shows it has its own copy */
        ch->sent = c->fd;
        c->fd = -1;
        conn_close(pool, k);
    }
}

static void watch_clear(Watch *w) { w->len = 0; }

static void watch_add(Watch *w, int fd, int tag) {
    if (w->len == w->cap) {
        w->cap = w->cap ? 2 * w->cap : 64;
        w->fds = xrealloc(w->fds, (size_t)w->cap * sizeof *w->fds);
        w->tags = xrealloc(w->tags, (size_t)w->cap * sizeof *w->tags);
    }
    w->fds[w->len] = (struct pollfd){.fd = fd, .events = POLLIN};
    w->tags[w->len] = tag;
    w->len++;
}

/* Wait up to ms for any watched descriptor; how many are ready, 0 when interrupted */
static int watch_wait(Watch *w, int ms) {
    int ready = poll(w->fds, (nfds_t)w->len, ms);
    return ready < 0 ? 0 : ready;
}

/* Whether a descriptor has something to read now */
static bool readable_now(int fd) {
    struct pollfd p = {.fd = fd, .events = POLLIN};
    return poll(&p, 1, 0) > 0;
}

/* New connections, a few at a time, while the table has room */
static void accept_some(Pool *pool) {
    for (int k = 0; k < ACCEPT_BURST; k++) {
        if (connections_open(pool) >= pool->rule.max_connections) return;
        if (k > 0 && !readable_now(pool->listener)) return;
        int fd = accept(pool->listener, NULL, NULL);
        if (fd < 0) {
            /* Out of descriptors, the listener would stay ready and the wait spin: leave it a while */
            if (errno == EMFILE || errno == ENFILE || errno == ENOBUFS || errno == ENOMEM) {
                pool->listener_rest = pool->now + LISTENER_REST;
            }
            /* Otherwise interrupted, or a client gone before it was accepted */
            return;
        }
        /* Its timeouts are the socket's, so they go with it to every worker, set once here */
        net_prepare(fd, pool->rule.timeout);
        conn_add(pool, fd, NULL, 0, false, 0);
    }
}

/* Whole milliseconds from a now() time to a later one, rounded up, between 0 and most */
static int ms_until(double from, double t, int most) {
    double left = (t - from) * 1000;
    if (left <= 0) return 0;
    return left >= most ? most : (int)left + 1;
}

/*
 * Wait up to ms (less if a connection's deadline is sooner) for the workers' channels, the listener
 * (while the table has room: past it the kernel's backlog holds the rest) and the connections being
 * read, and take what came
 */
static void master_wait(Pool *pool, int ms) {
    Watch *w = &pool->watch;
    watch_clear(w);
    for (int i = 0; i < 2 * pool->count; i++) {
        if (pool->channels[i].fd >= 0) watch_add(w, pool->channels[i].fd, -2 - i);
    }
    if (pool->registered) {
        if (connections_open(pool) < pool->rule.max_connections && pool->now >= pool->listener_rest) {
            watch_add(w, pool->listener, LISTENER_TAG);
        }
        for (int k = 0; k < pool->top; k++) {
            Conn *c = &pool->conns[k];
            if (c->fd < 0 || c->tag) continue;
            watch_add(w, c->fd, k);
            ms = ms_until(pool->now, c->deadline, ms);
        }
    }
    int ready = watch_wait(w, ms);
    pool->now = now();
    if (ready == 0) return;
    for (int e = 0; e < w->len; e++) {
        if (!w->fds[e].revents) continue;
        int tag = w->tags[e];
        if (tag == LISTENER_TAG) accept_some(pool);
        else if (tag >= 0) read_conn(pool, tag);
        else read_channel(pool, -2 - tag);
    }
}

/* Whether descriptor a is the listener b is: on the same address, and with no peer, as a connection
   accepted from it has (macOS can't say SO_ACCEPTCONN) */
static bool same_listener(int a, int b) {
    struct sockaddr_storage x, y, peer;
    socklen_t xlen = sizeof x, ylen = sizeof y, peer_len = sizeof peer;
    if (getsockname(a, (struct sockaddr *)&x, &xlen) != 0 || getsockname(b, (struct sockaddr *)&y, &ylen) != 0) return false;
    if (getpeername(a, (struct sockaddr *)&peer, &peer_len) == 0 || errno != ENOTCONN) return false;
    return xlen == ylen && memcmp(&x, &y, xlen) == 0;
}

static void ask_to_stop(pid_t *pids, int count);

/*
 * A stop's start: nothing listens any more, so a new connection is refused rather than queued for
 * a pool that is going away, and every connection in the table is closed, a kept-open one seeing
 * the end as after an idle wait. The master's own copy of the listener, made before workers(), has
 * the number the rule names; each worker points its own at /dev/null as it is asked to stop
 * (on_worker_stop()), which is done before the connections are closed, so a client that sees its
 * end finds nothing listening a moment later.
 */
static void close_front(Pool *pool) {
    if (!pool->registered) return;
    int own = pool->rule.listener_number;
    if (own != pool->listener && same_listener(own, pool->listener)) abandon_fd(own);
    close(pool->listener);
    pool->listener = -1;
    ask_to_stop(pool->pids, 2 * pool->count);
    for (int k = pool->top - 1; k >= 0; k--) {
        if (pool->conns[k].fd >= 0) conn_close(pool, k);
    }
    pool->queue_len = 0;
}

/* ---- The master's side: its workers ---- */

/* Whether a worker is retiring, or one that has retired has yet to leave */
static bool handing_over(Pool *pool) {
    for (int i = 0; i < pool->count; i++) {
        if ((pool->slots[i].retiring && pool->pids[i] > 0) || pool->pids[pool->count + i] > 0) return true;
    }
    return false;
}

/* Fork a replacement for worker `i` (0-based) and record it. True means the caller is now the new
   worker itself, and start_workers() must return true at once; false means the master carries on,
   having either recorded the new pid or, on a fork failure, failed the whole pool. */
static bool start_replacement(Pool *pool, int i, Value *out, int *failed, int *alive) {
    pid_t again = fork_worker(pool, i, out);
    if (again == 0) return true;
    if (again < 0) {
        fprintf(stderr, "gaz: cannot start worker %d again: %s; stopping\n", i + 1, strerror(errno));
        *failed = 1;
        (*alive)--;
        return false;
    }
    pool->pids[i] = again;
    pool->started[i] = now();
    return false;
}

/* Worker i + 1's hand-over (worker_retire()): its replacement started while it serves on, unless
   the one before it is still leaving, which it soon will; then the one leaving asked to stop once
   the replacement waits for connections, or once the number has ended for good, and killed if it
   is still there STOP_GRACE seconds later, as a stop does. Its channel goes with it, to the second
   half of the table. True and false as start_replacement() says. */
static bool hand_over(Pool *pool, int i, Value *out, int *failed, int *alive) {
    pid_t *leaving = &pool->pids[pool->count + i];
    if (pool->slots[i].retiring && pool->pids[i] > 0 && *leaving == 0) {
        fprintf(stderr, "gaz: worker %d recycled; starting another\n", i + 1);
        *leaving = pool->pids[i];
        pool->pids[i] = 0;
        pool->relieved[i] = 0;
        pool->channels[pool->count + i] = pool->channels[i];
        pool->channels[i] = (Channel){.fd = -1, .sent = -1};
        return start_replacement(pool, i, out, failed, alive);
    }
    if (*leaving <= 0) return false;
    if (!pool->relieved[i] && (pool->slots[i].listening || pool->pids[i] == 0)) {
        kill(*leaving, SIGTERM);
        pool->relieved[i] = now();
    } else if (pool->relieved[i] && now() - pool->relieved[i] > STOP_GRACE) {
        kill(*leaving, SIGKILL);
    }
    return false;
}

/* A retiring worker has ended: its number was handed over, so it isn't replaced, and only an end
   other than worker_recycle()'s or a clean exit is worth a line */
static void retired(int i, int status) {
    if (WIFSIGNALED(status) && WTERMSIG(status) == SIGUSR2) return;
    if (WIFEXITED(status) && WEXITSTATUS(status) == 0) return;
    char how[64];
    describe(status, how, sizeof how);
    fprintf(stderr, "gaz: worker %d %s while retiring\n", i + 1, how);
}

/* Ask every worker still running to stop (SIGTERM, which a worker can be sent more than once) */
static void ask_to_stop(pid_t *pids, int count) {
    for (int i = 0; i < count; i++) {
        if (pids[i] > 0) kill(pids[i], SIGTERM);
    }
}

/* Ask every worker still running to stop, give them STOP_GRACE seconds, kill the rest; each one's
   channel closed as it ends, so the master's copy of a connection it last handed it goes too */
static void stop_all(Pool *pool, int count) {
    pid_t *pids = pool->pids;
    ask_to_stop(pids, count);
    int left = 0;
    for (int i = 0; i < count; i++) left += pids[i] > 0;
    double until = now() + STOP_GRACE;
    while (left > 0) {
        bool late = now() >= until;
        for (int i = 0; i < count; i++) {
            if (pids[i] <= 0) continue;
            if (late) kill(pids[i], SIGKILL);
            pid_t done;
            while ((done = waitpid(pids[i], NULL, late ? 0 : WNOHANG)) < 0 && errno == EINTR) {}
            if (done != 0) {
                pids[i] = 0;
                channel_close(pool, i);
                left--;
            }
        }
        if (left > 0) pause_briefly();
    }
}

bool start_workers(int64_t count, Value *out) {
    if (vm_worker) return raisef("workers() in a worker: only the first process can start them");
    if (count < 1 || count > MAX_WORKERS) {
        return raisef("workers() expects 1 to %d workers, got %lld", MAX_WORKERS, (long long)count);
    }
    /* Or what is buffered would be written once by each of them: the output, and a file_open()
       file's writes, which a worker that ends by exit() would write out again (fflush(NULL) is
       every stream open for writing) */
    flush_output();
    fflush(NULL);
#ifdef GAZ_PG
    /* libpq is loaded on a program's first postgres:// open; load it now, so every worker has it
       (and on macOS can: see pg.c). Only for a program that names db_open, the only way to open
       one, so a server without a database doesn't load libpq at all. */
    if (program->opens_databases) pg_load_before_fork();
#endif

    struct sigaction stop = {.sa_handler = on_stop};
    sigemptyset(&stop.sa_mask);
    /* A signal the program was started ignoring (nohup's SIGHUP, SIGINT in a background job) stays
       ignored: it is someone's choice that it shouldn't stop the server */
    for (int i = 0; i < NSTOP; i++) {
        sigaction(STOP_SIGNALS[i], NULL, &saved_stop[i]);
        if (saved_stop[i].sa_handler != SIG_IGN) sigaction(STOP_SIGNALS[i], &stop, NULL);
    }

    master_pid = getpid();
    int n = (int)count;
    Pool pool = {.count = n, .listener = -1};
    pool.slots = mmap(NULL, (size_t)n * sizeof(Slot), PROT_READ | PROT_WRITE, MAP_SHARED | MAP_ANON, -1, 0);
    if (pool.slots == MAP_FAILED) {
        restore_signals();
        return raisef("workers() cannot share memory with its workers: %s", strerror(errno));
    }
    pool.pids = xcalloc(2 * (size_t)n, sizeof *pool.pids);
    pool.started = xcalloc((size_t)n, sizeof *pool.started);
    pool.relieved = xcalloc((size_t)n, sizeof *pool.relieved);
    pool.channels = xmalloc(2 * (size_t)n * sizeof *pool.channels);
    for (int i = 0; i < 2 * n; i++) pool.channels[i] = (Channel){.fd = -1, .sent = -1};
    /* The master and its workers write lines to standard error (http::serve's access log, the
       master's "starting another"), which may be a pipe to a log reader that goes away. At its
       default SIGPIPE would end a worker at its next line and the master, and so the whole pool, at
       its; ignored, such a write fails with EPIPE and the line is lost. For good, since neither
       process goes back to code that wants the default (the master ends in C, and a worker is a
       server, whose sockets already ignore it), and run() gives what it starts the default back.
       A program that never calls workers() keeps it, so `gaz tool | head` still ends quietly. It
       covers the channels too: a send to a worker that has died fails rather than ending the
       master. */
    struct sigaction ignore_pipe = {.sa_handler = SIG_IGN}, saved_pipe;
    sigemptyset(&ignore_pipe.sa_mask);
    sigaction(SIGPIPE, &ignore_pipe, &saved_pipe);
    for (int i = 0; i < n; i++) {
        pid_t pid = fork_worker(&pool, i, out);
        if (pid == 0) {
            pool_free(&pool);
            return true;
        }
        if (pid < 0) {
            int err = errno;
            stop_all(&pool, n);
            restore_signals();
            sigaction(SIGPIPE, &saved_pipe, NULL);
            pool_free(&pool);
            return raisef("workers() cannot start a worker: %s", strerror(err));
        }
        pool.pids[i] = pid;
        pool.started[i] = now();
    }

    /* The master, from here to its end */
    int alive = n, failed = -1;
    double looked = 0;   /* when the master last looked at its workers */
    while (alive > 0 && failed < 0 && !stop_signal) {
        /*
         * ponytail: the master looks at its workers (waitpid(), the slots, hand-overs) every
         * POLL_MS, which is how long a restart, a stop or noticing a hand-over can wait (it can't
         * be woken: see the program's own thread in docs/http.md); while a hand-over is under way
         * every HAND_OVER_POLL_MS, since the retiring worker serves a connection a request
         * meanwhile, and a quick relief is throughput back. Not at every wake between, which comes
         * for every request.
         */
        double cadence = (handing_over(&pool) ? HAND_OVER_POLL_MS : POLL_MS) / 1000.0;
        pool.now = now();
        bool look = pool.now - looked >= cadence;
        if (look) looked = pool.now;
        pid_t pid;
        int status;
        while (look && failed < 0 && (pid = waitpid(-1, &status, WNOHANG)) > 0) {
            int i = 0;
            while (i < 2 * n && pool.pids[i] != pid) i++;
            if (i == 2 * n) continue;   /* not a worker: something run() started and left */
            pool.pids[i] = 0;
            /* A connection it held goes with it, and only that one */
            channel_close(&pool, i);
            if (i >= n) {
                retired(i - n, status);
                continue;
            }
            if (WIFEXITED(status) && WEXITSTATUS(status) == 0) {
                alive--;
                continue;
            }
            if (WIFSIGNALED(status) && WTERMSIG(status) == SIGUSR2) {
                /* A deliberate recycle (worker_recycle()), not a failure: no anti-flapping check,
                   no failure-style log line */
                fprintf(stderr, "gaz: worker %d recycled; starting another\n", i + 1);
                if (start_replacement(&pool, i, out, &failed, &alive)) {
                    pool_free(&pool);
                    return true;
                }
                if (failed >= 0) break;
                continue;
            }
            char how[64];
            int code = describe(status, how, sizeof how);
            if (pool.now - pool.started[i] < 1.0 && !pool.slots[i].accepted) {
                fprintf(stderr, "gaz: worker %d %s within a second of starting; stopping\n", i + 1, how);
                failed = code;
                break;
            }
            fprintf(stderr, "gaz: worker %d %s; starting another\n", i + 1, how);
            if (start_replacement(&pool, i, out, &failed, &alive)) {
                pool_free(&pool);
                return true;
            }
            if (failed >= 0) break;
        }
        for (int i = 0; look && i < n && failed < 0; i++) {
            if (hand_over(&pool, i, out, &failed, &alive)) {
                pool_free(&pool);
                return true;
            }
        }
        /* Between looks it waits on its channels, the listener and the connections */
        if (alive > 0 && failed < 0 && !stop_signal) {
            expire(&pool, pool.now);
            dispatch(&pool);
            master_wait(&pool, ms_until(pool.now, looked + cadence, POLL_MS));
            dispatch(&pool);
        }
    }
    close_front(&pool);
    stop_all(&pool, 2 * n);
    pool_free(&pool);
    flush_output();
    /* It ends mid-instruction, as exit() does, holding what the program held */
    if (getenv("GAZVM_STATS")) fputs("gazvm: leaks not checked: workers()\n", stderr);
    if (stop_signal) {
        /* Ended as the signal would have ended it: with what handled it before (term.c's puts the
           terminal back and does the same), or the default */
        int sig = stop_signal;
        restore_signals();
        raise(sig);
        /* A handler that returned instead of ending the program */
        struct sigaction dfl = {.sa_handler = SIG_DFL};
        sigemptyset(&dfl.sa_mask);
        sigaction(sig, &dfl, NULL);
        raise(sig);
    }
    exit(failed < 0 ? 0 : failed);
}
