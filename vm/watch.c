/*
 * gaz --watch FILE [ARGS...]: runs the program, and runs it again whenever one of the files it is
 * made of changes, the edit-and-refresh loop for a server or a script.
 *
 * The process that watches (the supervisor) runs no GazLang itself. It starts gaz again as a child,
 * the same binary by the name it was started with (argv[0], found on the PATH as the shell found
 * it when it has no slash), with the file and the arguments but without --watch. The child is put
 * in a process group of its own, so that a workers() master and its workers are stopped together
 * by signalling the group.
 *
 * What is watched is the files the program is made of: the main file, and every file whose code is
 * in its bytecode, which a `gaz -c` of the main file names in its @ lines (source_files() in
 * load.c), templates included. The standard library is built in, so it has nothing to watch. The
 * list is worked out again before every start, so a new include is watched from the run it first
 * appears in; when the program doesn't compile, the last list is kept (the main file at least).
 *
 * The files' modification times, sizes and inodes are polled every POLL_SECONDS: portable, where
 * inotify and kqueue are one system each. A change, or a file disappearing, restarts the program:
 * SIGTERM to its group, which workers() turns into a graceful stop, SIGKILL for whatever is left
 * after STOP_GRACE seconds, and a new start. A program that ends by itself, or fails to compile
 * (it prints its own error), is started again at the next change.
 *
 * SIGINT, SIGTERM and SIGHUP to the supervisor stop the program the same way, and then end the
 * supervisor as the signal would have. The child's group isn't the terminal's, so Ctrl-C at the
 * terminal reaches only the supervisor, which is why it passes the stop on.
 *
 * ponytail: a file whose code leaves no instruction in the bytecode (one holding only constants,
 * which are folded into their uses) has no @ line and isn't watched; the front end reporting its
 * includes would lift that. The program can't use the terminal as its input: reading it or turning
 * on raw mode from a group that isn't the terminal's stops it (SIGTTIN, SIGTTOU), which the
 * supervisor reports, then ends the program. Handing the terminal to the child's group (tcsetpgrp)
 * would lift that, at the price of Ctrl-C going to the program rather than the supervisor.
 */
#include "gazvm.h"

#include <errno.h>
#include <fcntl.h>
#include <signal.h>
#include <spawn.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

extern char **environ;

#define POLL_SECONDS 0.25   /* how often the files are looked at */
#define STOP_GRACE 1.0      /* seconds the program has to stop before it is killed */

static const int STOP_SIGNALS[] = {SIGINT, SIGTERM, SIGHUP};
#define NSTOP (int)(sizeof STOP_SIGNALS / sizeof STOP_SIGNALS[0])

static volatile sig_atomic_t stop_signal;

static void on_stop(int sig) { stop_signal = sig; }

static double now(void) {
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);
    return (double)t.tv_sec + (double)t.tv_nsec / 1e9;
}

static void pause_for(double seconds) {
    struct timespec t = {.tv_sec = (time_t)seconds, .tv_nsec = (long)((seconds - (double)(time_t)seconds) * 1e9)};
    nanosleep(&t, NULL);   /* a signal ends it early, which is fine: the caller looks again */
}

/* What a file looked like: a change to any of these is a change to the file. The inode too, since
   an editor that saves by writing a new file and renaming it over the old one may keep the size
   and, on a coarse clock, the time. */
typedef struct {
    bool exists;
    time_t seconds;
    long nanoseconds;
    off_t size;
    ino_t inode;
} Stamp;

static Stamp stamp_of(const char *path) {
    struct stat st;
    if (stat(path, &st) != 0) return (Stamp){.exists = false};
#ifdef __APPLE__
    long nanoseconds = st.st_mtimespec.tv_nsec;
#else
    long nanoseconds = st.st_mtim.tv_nsec;
#endif
    return (Stamp){true, st.st_mtime, nanoseconds, st.st_size, st.st_ino};
}

static bool same_stamp(Stamp a, Stamp b) {
    if (!a.exists || !b.exists) return a.exists == b.exists;
    return a.seconds == b.seconds && a.nanoseconds == b.nanoseconds && a.size == b.size && a.inode == b.inode;
}

/* A watched file: its path as the loader shows it, and how it looked when the program started */
typedef struct {
    char *path;
    Stamp seen;
} Watched;

typedef struct {
    Watched *files;
    int count;
} FileList;

static void free_list(FileList *list) {
    for (int i = 0; i < list->count; i++) free(list->files[i].path);
    free(list->files);
    *list = (FileList){0};
}

/* Whether the list names a file already: the same file by another path counts too */
static bool listed(FileList *list, const char *path) {
    struct stat a, b;
    bool exists = stat(path, &a) == 0;
    for (int i = 0; i < list->count; i++) {
        if (strcmp(list->files[i].path, path) == 0) return true;
        if (exists && stat(list->files[i].path, &b) == 0 && a.st_dev == b.st_dev && a.st_ino == b.st_ino) return true;
    }
    return false;
}

static void add_file(FileList *list, const char *path) {
    if (listed(list, path)) return;
    list->files = xrealloc(list->files, (size_t)(list->count + 1) * sizeof(Watched));
    list->files[list->count++] = (Watched){strdup(path), stamp_of(path)};
}

/* The program compiled by `gaz -c` into memory, its errors dropped (the run itself shows them), or
   NULL when it doesn't compile */
static char *compile(const char *self, const char *file, size_t *len) {
    int out[2];
    if (pipe(out) != 0) return NULL;
    posix_spawn_file_actions_t actions;
    posix_spawn_file_actions_init(&actions);
    posix_spawn_file_actions_addopen(&actions, STDIN_FILENO, "/dev/null", O_RDONLY, 0);
    posix_spawn_file_actions_adddup2(&actions, out[1], STDOUT_FILENO);
    posix_spawn_file_actions_addopen(&actions, STDERR_FILENO, "/dev/null", O_WRONLY, 0);
    posix_spawn_file_actions_addclose(&actions, out[0]);
    posix_spawn_file_actions_addclose(&actions, out[1]);
    char *argv[] = {(char *)self, "-c", "-f", (char *)file, NULL};
    pid_t pid = 0;
    int failed = posix_spawnp(&pid, self, &actions, NULL, argv, environ);
    posix_spawn_file_actions_destroy(&actions);
    close(out[1]);
    Buf text = {0};
    char chunk[65536];
    ssize_t n;
    while (!failed && ((n = read(out[0], chunk, sizeof chunk)) > 0 || (n < 0 && errno == EINTR))) {
        if (n > 0) buf_add(&text, chunk, (size_t)n);
    }
    close(out[0]);
    int status = 1;
    while (!failed && waitpid(pid, &status, 0) < 0 && errno == EINTR) {}
    if (failed || !WIFEXITED(status) || WEXITSTATUS(status) != 0) {
        free(text.data);
        return NULL;
    }
    *len = text.len;
    return text.data;
}

/* The files to watch this run: the main file, then the ones its bytecode names. When it doesn't
   compile, the last run's list stays, so an edit to an included file can fix it. */
static void find_files(const char *self, const char *file, FileList *list) {
    size_t len = 0;
    char *text = compile(self, file, &len);
    int count = 0;
    char **found = text ? source_files(text, len, file, &count) : NULL;
    free(text);
    FileList fresh = {0};
    add_file(&fresh, file);
    if (found) {
        for (int i = 0; i < count; i++) {
            add_file(&fresh, found[i]);
            free(found[i]);
        }
        free(found);
    } else {
        for (int i = 0; i < list->count; i++) add_file(&fresh, list->files[i].path);
    }
    free_list(list);
    *list = fresh;
}

/* The first watched file that has changed since the program started, or NULL */
static const char *changed_file(FileList *list) {
    for (int i = 0; i < list->count; i++) {
        if (!same_stamp(list->files[i].seen, stamp_of(list->files[i].path))) return list->files[i].path;
    }
    return NULL;
}

static bool same_paths(FileList *a, FileList *b) {
    if (a->count != b->count) return false;
    for (int i = 0; i < a->count; i++) {
        if (strcmp(a->files[i].path, b->files[i].path) != 0) return false;
    }
    return true;
}

static void announce(FileList *list) {
    int included = list->count - 1;
    if (included == 0) fprintf(stderr, "gaz: watching %s\n", list->files[0].path);
    else fprintf(stderr, "gaz: watching %s and %d file%s it includes\n", list->files[0].path, included, included == 1 ? "" : "s");
}

/* The program as a child in a process group of its own, or -1 */
static pid_t start_program(const char *self, char **argv) {
    posix_spawnattr_t attr;
    posix_spawnattr_init(&attr);
    posix_spawnattr_setflags(&attr, POSIX_SPAWN_SETPGROUP);
    posix_spawnattr_setpgroup(&attr, 0);   /* 0: a new group, numbered after the child */
    pid_t pid = 0;
    int failed = posix_spawnp(&pid, self, NULL, &attr, argv, environ);
    posix_spawnattr_destroy(&attr);
    if (failed) {
        fprintf(stderr, "gaz: cannot start %s: %s\n", self, strerror(failed));
        return -1;
    }
    return pid;
}

/* Whether the program has ended by itself, reaping it if so. One stopped for using the terminal
   (see the ponytail above) is told so and ended, or it would wait there for ever. */
static bool program_ended(pid_t pid) {
    int status;
    pid_t done;
    while ((done = waitpid(pid, &status, WNOHANG | WUNTRACED)) < 0 && errno == EINTR) {}
    if (done != pid) return false;
    if (!WIFSTOPPED(status)) return true;
    int sig = WSTOPSIG(status);
    if (sig != SIGTTIN && sig != SIGTTOU) return false;
    fputs("gaz: the program wanted the terminal, which it can't have under --watch; ending it\n", stderr);
    kill(-pid, SIGKILL);
    while (waitpid(pid, NULL, 0) < 0 && errno == EINTR) {}
    return true;
}

/* Stop the program's whole group: SIGTERM, then SIGKILL for what is left after STOP_GRACE. Done
   once the child is reaped and nothing is left in its group (a worker's master reaps the workers;
   one orphaned by the kill is reaped by init, soon). */
static void stop_program(pid_t pid) {
    kill(-pid, SIGTERM);
    kill(-pid, SIGCONT);   /* a stopped process only sees the SIGTERM once it runs */
    double until = now() + STOP_GRACE;
    bool reaped = false, killed = false;
    for (;;) {
        if (!reaped) {
            pid_t done;
            while ((done = waitpid(pid, NULL, killed ? 0 : WNOHANG)) < 0 && errno == EINTR) {}
            reaped = done != 0;
        }
        if (reaped && kill(-pid, 0) != 0) return;
        if (now() >= until) {
            if (killed) return;   /* ponytail: leftovers init hasn't reaped within a second are left to it */
            kill(-pid, SIGKILL);
            killed = true;
            until = now() + STOP_GRACE;
        }
        pause_for(0.01);
    }
}

/* End as the signal would have ended the supervisor, the program already stopped */
static _Noreturn void die_of(int sig) {
    struct sigaction dfl = {.sa_handler = SIG_DFL};
    sigemptyset(&dfl.sa_mask);
    sigaction(sig, &dfl, NULL);
    raise(sig);
    exit(128 + sig);
}

int watch(const char *self, const char *file, int argc, char **argv) {
    /* A signal the supervisor was started ignoring (nohup's SIGHUP) stays ignored, and the child
       inherits that */
    struct sigaction stop = {.sa_handler = on_stop};
    sigemptyset(&stop.sa_mask);
    for (int i = 0; i < NSTOP; i++) {
        struct sigaction before;
        sigaction(STOP_SIGNALS[i], NULL, &before);
        if (before.sa_handler != SIG_IGN) sigaction(STOP_SIGNALS[i], &stop, NULL);
    }

    /* gaz -f FILE -- ARGS: the file whatever its name, and the arguments whatever they look like */
    char **child = xmalloc((size_t)(argc + 5) * sizeof(char *));
    child[0] = (char *)self;
    child[1] = "-f";
    child[2] = (char *)file;
    child[3] = "--";
    for (int i = 0; i < argc; i++) child[4 + i] = argv[i];
    child[4 + argc] = NULL;

    FileList files = {0}, shown = {0};
    for (;;) {
        find_files(self, file, &files);
        if (!same_paths(&files, &shown)) {
            announce(&files);
            free_list(&shown);
            for (int i = 0; i < files.count; i++) add_file(&shown, files.files[i].path);
        }
        /* A stop asked for while the files were being worked out (whose compile a Ctrl-C also
           ends) must not start the program once more on the way out */
        if (stop_signal) die_of(stop_signal);
        pid_t pid = start_program(self, child);
        bool running = pid > 0;
        if (!running) fputs("gaz: waiting for a change\n", stderr);
        const char *changed = NULL;
        while (!stop_signal && !(changed = changed_file(&files))) {
            if (running && program_ended(pid)) {
                running = false;
                fputs("gaz: waiting for a change\n", stderr);
            }
            pause_for(POLL_SECONDS);
        }
        if (changed) fprintf(stderr, "gaz: %s changed, restarting\n", changed);
        if (running) stop_program(pid);
        if (stop_signal) die_of(stop_signal);
    }
}
