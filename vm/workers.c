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
 * starting is a program that can't start, not bad luck, so rather than start it again and again the
 * master stops the others and exits with its code. SIGINT, SIGTERM and SIGHUP to the master stop
 * the workers, and then end the master as that signal would have; one the program was started
 * ignoring (nohup's SIGHUP) stays ignored.
 *
 * ponytail: a worker killed mid-request drops that request (no graceful drain), and workers whose
 * master is killed with SIGKILL run on as orphans (Linux's PR_SET_PDEATHSIG would end them; macOS
 * has nothing like it).
 */
#include "gazvm.h"

#include <errno.h>
#include <pthread.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#define MAX_WORKERS 1024

bool vm_worker;

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

/* What the master had before it took the signals over, which a worker gets back */
static struct sigaction saved_stop[NSTOP];

static void restore_signals(void) {
    for (int i = 0; i < NSTOP; i++) sigaction(STOP_SIGNALS[i], &saved_stop[i], NULL);
}

/* Fork worker `number`: its pid in the master, 0 in the worker, -1 if it couldn't. The stop signals
   are blocked across the fork, or one sent to a worker before it has put its handlers back would
   only set its copy of the master's flag, and the master would wait for it for ever. */
static pid_t fork_worker(int number, Value *out) {
    sigset_t stops, before;
    sigemptyset(&stops);
    for (int i = 0; i < NSTOP; i++) sigaddset(&stops, STOP_SIGNALS[i]);
    pthread_sigmask(SIG_BLOCK, &stops, &before);
    pid_t pid = fork();
    if (pid == 0) {
        vm_worker = true;
        restore_signals();
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

/* Stop every worker still running and wait for them */
static void stop_all(pid_t *pids, int count) {
    for (int i = 0; i < count; i++) {
        if (pids[i] > 0) kill(pids[i], SIGTERM);
    }
    for (int i = 0; i < count; i++) {
        if (pids[i] > 0) {
            while (waitpid(pids[i], NULL, 0) < 0 && errno == EINTR) {}
            pids[i] = 0;
        }
    }
}

bool start_workers(int64_t count, Value *out) {
    if (vm_worker) return raisef("workers() in a worker: only the first process can start them");
    if (count < 1 || count > MAX_WORKERS) {
        return raisef("workers() expects 1 to %d workers, got %lld", MAX_WORKERS, (long long)count);
    }
    /* Or what is buffered would be written once by each of them */
    flush_output();
    fflush(stderr);

    struct sigaction stop = {.sa_handler = on_stop};
    sigemptyset(&stop.sa_mask);
    /* A signal the program was started ignoring (nohup's SIGHUP, SIGINT in a background job) stays
       ignored: it is someone's choice that it shouldn't stop the server */
    for (int i = 0; i < NSTOP; i++) {
        sigaction(STOP_SIGNALS[i], NULL, &saved_stop[i]);
        if (saved_stop[i].sa_handler != SIG_IGN) sigaction(STOP_SIGNALS[i], &stop, NULL);
    }

    int n = (int)count;
    pid_t *pids = xcalloc((size_t)n, sizeof *pids);
    double *started = xcalloc((size_t)n, sizeof *started);
    for (int i = 0; i < n; i++) {
        pid_t pid = fork_worker(i + 1, out);
        if (pid == 0) {
            free(pids);
            free(started);
            return true;
        }
        if (pid < 0) {
            int err = errno;
            stop_all(pids, n);
            restore_signals();
            free(pids);
            free(started);
            return raisef("workers() cannot start a worker: %s", strerror(err));
        }
        pids[i] = pid;
        started[i] = now();
    }

    /* The master, from here to its end */
    int alive = n, failed = -1;
    while (alive > 0 && failed < 0 && !stop_signal) {
        pid_t pid;
        int status;
        while (failed < 0 && (pid = waitpid(-1, &status, WNOHANG)) > 0) {
            int i = 0;
            while (i < n && pids[i] != pid) i++;
            if (i == n) continue;   /* not a worker: something run() started and left */
            pids[i] = 0;
            if (WIFEXITED(status) && WEXITSTATUS(status) == 0) {
                alive--;
                continue;
            }
            char how[64];
            int code = describe(status, how, sizeof how);
            if (now() - started[i] < 1.0) {
                fprintf(stderr, "gazlang: worker %d %s within a second of starting; stopping\n", i + 1, how);
                failed = code;
                break;
            }
            fprintf(stderr, "gazlang: worker %d %s; starting another\n", i + 1, how);
            pid_t again = fork_worker(i + 1, out);
            if (again == 0) {
                free(pids);
                free(started);
                return true;
            }
            if (again < 0) {
                fprintf(stderr, "gazlang: cannot start worker %d again: %s; stopping\n", i + 1, strerror(errno));
                failed = 1;
                alive--;
                break;
            }
            pids[i] = again;
            started[i] = now();
        }
        /* ponytail: polled every 50ms, which is how long a restart or a stop can wait */
        if (alive > 0 && failed < 0 && !stop_signal) nanosleep(&(struct timespec){.tv_nsec = 50000000}, NULL);
    }
    stop_all(pids, n);
    free(pids);
    free(started);
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
