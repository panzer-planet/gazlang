/*
 * workers($count): the program as several processes from here on, which is how a server
 * answers more than one request at a time (prefork, as PHP-FPM and Apache do). Each worker is a
 * fork(): it carries on from the call with a copy of everything, a socket_listen() listener
 * included, so they all accept on the one port and the system hands each connection to one of
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
 * graceful: a worker is sent SIGTERM, which makes its next socket_accept() (or the one it waits in)
 * give null, so http::serve() returns after the request in hand and the worker's program ends; one
 * still running after STOP_GRACE seconds is killed. Ctrl-C reaches the workers themselves too, and
 * ends them at once, which is what it is for.
 *
 * ponytail: workers whose master is killed with SIGKILL run on as orphans (Linux's
 * PR_SET_PDEATHSIG would end them; macOS has nothing like it), and the grace is fixed.
 */
#include "gazvm.h"

#include <errno.h>
#include <fcntl.h>
#include <pthread.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
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

static void on_worker_stop(int sig) {
    (void)sig;
    stopping = 1;
}

bool workers_stopping(void) { return stopping; }

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

/* What the master had before it took the signals over, which a worker gets back */
static struct sigaction saved_stop[NSTOP];

static void restore_signals(void) {
    for (int i = 0; i < NSTOP; i++) sigaction(STOP_SIGNALS[i], &saved_stop[i], NULL);
}

/* Fork worker `number`: its pid in the master, 0 in the worker, -1 if it couldn't. The stop signals
   are blocked across the fork, or one sent to a worker before it has put its handlers back would
   only set its copy of the master's flag, and the master would wait for it for ever. */
static pid_t fork_worker(int number, volatile Slot *slots, Value *out) {
    volatile Slot *own = &slots[number - 1];
    own->accepted = 0;
    own->listening = 0;
    own->retiring = 0;
    sigset_t stops, before;
    sigemptyset(&stops);
    for (int i = 0; i < NSTOP; i++) sigaddset(&stops, STOP_SIGNALS[i]);
    pthread_sigmask(SIG_BLOCK, &stops, &before);
    pid_t pid = fork();
    if (pid == 0) {
        vm_worker = true;
        vm_process++;
        restore_signals();
        slot = own;
        /* Not SA_RESTART, so a socket_accept() waiting is woken to give null */
        struct sigaction stop = {.sa_handler = on_worker_stop};
        sigemptyset(&stop.sa_mask);
        if (saved_stop[1].sa_handler != SIG_IGN) sigaction(SIGTERM, &stop, NULL);
        /* Or every worker would draw the same random numbers */
        random_seed_unpredictable();
        *out = v_int(number);
    }
    /* Now a signal waiting is handled: by the master's handler, or a worker's own */
    pthread_sigmask(SIG_SETMASK, &before, NULL);
    return pid;
}

/* How a worker ended, for the master's message, and the exit code that stands for it */
static int describe(int status, char *text, size_t size) {
    if (WIFSIGNALED(status)) {
        snprintf(text, size, "died of signal %d", WTERMSIG(status));
        return 128 + WTERMSIG(status);
    }
    snprintf(text, size, "exited with code %d", WEXITSTATUS(status));
    return WEXITSTATUS(status);
}

static void pause_briefly(void) { nanosleep(&(struct timespec){.tv_nsec = 50000000}, NULL); }

/* The master's record of its workers, by number - 1 */
typedef struct {
    int count;
    pid_t *pids;            /* each number's worker, 0 once it has ended; then, at count + i, the
                               one retiring from number i + 1 while pids[i] takes over, or 0 */
    double *started;        /* when each number's worker was started */
    double *relieved;       /* when each number's retiring one was asked to stop, or 0 */
    volatile Slot *slots;
} Pool;

static void pool_free(Pool *pool) {
    free(pool->pids);
    free(pool->started);
    free(pool->relieved);
}

/* Whether a worker is retiring, or one that has retired has yet to leave */
static bool handing_over(Pool *pool) {
    for (int i = 0; i < pool->count; i++) {
        if ((pool->slots[i].retiring && pool->pids[i] > 0) || pool->pids[pool->count + i] > 0) return true;
    }
    return false;
}

static void pause_for_hand_over(void) { nanosleep(&(struct timespec){.tv_nsec = 5000000}, NULL); }

/* Fork a replacement for worker `i` (0-based) and record it. True means the caller is now the new
   worker itself, and start_workers() must return true at once; false means the master carries on,
   having either recorded the new pid or, on a fork failure, failed the whole pool. */
static bool start_replacement(Pool *pool, int i, Value *out, int *failed, int *alive) {
    pid_t again = fork_worker(i + 1, pool->slots, out);
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
   is still there STOP_GRACE seconds later, as a stop does. True and false as start_replacement()
   says. */
static bool hand_over(Pool *pool, int i, Value *out, int *failed, int *alive) {
    pid_t *leaving = &pool->pids[pool->count + i];
    if (pool->slots[i].retiring && pool->pids[i] > 0 && *leaving == 0) {
        fprintf(stderr, "gaz: worker %d recycled; starting another\n", i + 1);
        *leaving = pool->pids[i];
        pool->pids[i] = 0;
        pool->relieved[i] = 0;
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

/* Ask every worker still running to stop, give them STOP_GRACE seconds, kill the rest */
static void stop_all(pid_t *pids, int count) {
    int left = 0;
    for (int i = 0; i < count; i++) {
        if (pids[i] > 0) {
            kill(pids[i], SIGTERM);
            left++;
        }
    }
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

    int n = (int)count;
    Pool pool = {.count = n};
    pool.slots = mmap(NULL, (size_t)n * sizeof(Slot), PROT_READ | PROT_WRITE, MAP_SHARED | MAP_ANON, -1, 0);
    if (pool.slots == MAP_FAILED) {
        restore_signals();
        return raisef("workers() cannot share memory with its workers: %s", strerror(errno));
    }
    pool.pids = xcalloc(2 * (size_t)n, sizeof *pool.pids);
    pool.started = xcalloc((size_t)n, sizeof *pool.started);
    pool.relieved = xcalloc((size_t)n, sizeof *pool.relieved);
    /* The master and its workers write lines to standard error (http::serve's access log, the
       master's "starting another"), which may be a pipe to a log reader that goes away. At its
       default SIGPIPE would end a worker at its next line and the master, and so the whole pool, at
       its; ignored, such a write fails with EPIPE and the line is lost. For good, since neither
       process goes back to code that wants the default (the master ends in C, and a worker is a
       server, whose sockets already ignore it), and run() gives what it starts the default back.
       A program that never calls workers() keeps it, so `gaz tool | head` still ends quietly. */
    struct sigaction ignore_pipe = {.sa_handler = SIG_IGN}, saved_pipe;
    sigemptyset(&ignore_pipe.sa_mask);
    sigaction(SIGPIPE, &ignore_pipe, &saved_pipe);
    for (int i = 0; i < n; i++) {
        pid_t pid = fork_worker(i + 1, pool.slots, out);
        if (pid == 0) {
            pool_free(&pool);
            return true;
        }
        if (pid < 0) {
            int err = errno;
            stop_all(pool.pids, n);
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
    while (alive > 0 && failed < 0 && !stop_signal) {
        pid_t pid;
        int status;
        while (failed < 0 && (pid = waitpid(-1, &status, WNOHANG)) > 0) {
            int i = 0;
            while (i < 2 * n && pool.pids[i] != pid) i++;
            if (i == 2 * n) continue;   /* not a worker: something run() started and left */
            pool.pids[i] = 0;
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
            if (now() - pool.started[i] < 1.0 && !pool.slots[i].accepted) {
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
        for (int i = 0; i < n && failed < 0; i++) {
            if (hand_over(&pool, i, out, &failed, &alive)) {
                pool_free(&pool);
                return true;
            }
        }
        /* ponytail: polled every 50ms, which is how long a restart, a stop or noticing a hand-over can
           wait; while one is under way every 5ms, since the retiring worker serves a connection a
           request meanwhile, and a quick relief is throughput back */
        if (alive > 0 && failed < 0 && !stop_signal) {
            if (handing_over(&pool)) pause_for_hand_over();
            else pause_briefly();
        }
    }
    stop_all(pool.pids, 2 * n);
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
