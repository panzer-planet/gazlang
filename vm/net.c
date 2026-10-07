/*
 * Sockets: socket_open(), socket_read(), socket_write() and socket_close(), plain TCP or TLS, and
 * socket_listen(), socket_accept() and socket_port() for a server, plain TCP only (TLS is a proxy's
 * job in front of it), and socket_wait() for whichever of several has something to read.
 *
 * TLS is OpenSSL's, built in unless make is given TLS=0 (GAZ_TLS says which). It checks the
 * server's certificate against the system's trusted ones (or the file SSL_CERT_FILE names) and
 * against the host name, and speaks TLS 1.2 or later.
 */
#include "gazvm.h"

#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <netdb.h>
#include <poll.h>
#include <signal.h>
#include <stdio.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>

#ifdef GAZ_TLS
#include <openssl/err.h>
#include <openssl/ssl.h>
#include <openssl/x509v3.h>
#endif

/* Writing to a connection the other end has closed raises SIGPIPE, which would end the program.
   It is ignored while a socket is in use (as curl does), so the write fails with EPIPE instead,
   and put back after, so programs started by run() get the usual behaviour. */
static struct sigaction saved_sigpipe;

static void sigpipe_ignore(void) {
    struct sigaction ignore = {.sa_handler = SIG_IGN};
    sigemptyset(&ignore.sa_mask);
    sigaction(SIGPIPE, &ignore, &saved_sigpipe);
}

static void sigpipe_restore(void) { sigaction(SIGPIPE, &saved_sigpipe, NULL); }

/* A connection's timeout for each read and write, and no SIGPIPE from it where the system can
   say so per socket (macOS); elsewhere sigpipe_ignore() covers each call */
static void connection_options(int fd, int timeout_ms) {
    struct timeval tv = {.tv_sec = timeout_ms / 1000, .tv_usec = (timeout_ms % 1000) * 1000};
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof tv);
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &tv, sizeof tv);
#ifdef SO_NOSIGPIPE
    int one = 1;
    setsockopt(fd, SOL_SOCKET, SO_NOSIGPIPE, &one, sizeof one);
#endif
}

/* Seconds as whole milliseconds, at least 1 and at most a day */
static int to_ms(double timeout) {
    int ms = timeout > 86400 ? 86400000 : (int)(timeout * 1000);
    return ms == 0 ? 1 : ms;
}

/* Connect to one address within the timeout: a non-blocking connect() that poll() waits for,
   then back to blocking. -1 with errno set if not. */
static int connect_one(struct addrinfo *ai, int timeout_ms) {
    int fd = socket(ai->ai_family, ai->ai_socktype, ai->ai_protocol);
    if (fd < 0) return -1;
    fcntl(fd, F_SETFD, FD_CLOEXEC);
    int flags = fcntl(fd, F_GETFL);
    fcntl(fd, F_SETFL, flags | O_NONBLOCK);
    if (connect(fd, ai->ai_addr, ai->ai_addrlen) != 0) {
        if (errno != EINPROGRESS) goto fail;
        struct pollfd p = {.fd = fd, .events = POLLOUT};
        int ready;
        while ((ready = poll(&p, 1, timeout_ms)) < 0 && errno == EINTR) {}
        if (ready == 0) errno = ETIMEDOUT;
        if (ready <= 0) goto fail;
        int err = 0;
        socklen_t len = sizeof err;
        getsockopt(fd, SOL_SOCKET, SO_ERROR, &err, &len);
        if (err != 0) {
            errno = err;
            goto fail;
        }
    }
    fcntl(fd, F_SETFL, flags);
    connection_options(fd, timeout_ms);
    return fd;
fail: {
    int err = errno;
    close(fd);
    errno = err;
    return -1;
}
}

#ifdef GAZ_TLS
#ifdef __APPLE__
#define MACOS_CERTIFICATES "/etc/ssl/cert.pem"
#endif

/* Made on first use and kept for the run */
static SSL_CTX *tls_context;

/* Why OpenSSL gave up: the certificate check's reason if that failed, else its own, else the
   system's */
static const char *tls_reason(SSL *ssl, int ret) {
    long verify = SSL_get_verify_result(ssl);
    if (verify != X509_V_OK) return X509_verify_cert_error_string(verify);
    /* A timeout comes out of OpenSSL as wanting to read or write more */
    int e = SSL_get_error(ssl, ret);
    if (e == SSL_ERROR_WANT_READ || e == SSL_ERROR_WANT_WRITE) return "timed out";
    unsigned long code = ERR_peek_last_error();
    if (code) return ERR_reason_error_string(code) ? ERR_reason_error_string(code) : "unknown error";
    if (e == SSL_ERROR_SYSCALL && errno) return strerror(errno);
    return "the connection closed during the handshake";
}

static bool tls_start(Socket *s, const char *host) {
    if (!tls_context) {
        tls_context = SSL_CTX_new(TLS_client_method());
        if (!tls_context) return raisef("TLS error: OpenSSL could not start");
        SSL_CTX_set_min_proto_version(tls_context, TLS1_2_VERSION);
        SSL_CTX_set_verify(tls_context, SSL_VERIFY_PEER, NULL);
        SSL_CTX_set_default_verify_paths(tls_context);
#ifdef __APPLE__
        /* An OpenSSL linked in (the release binaries) looks for certificates where Homebrew
           keeps them, which a Mac without Homebrew doesn't have; the system's own bundle is always
           here. SSL_CERT_FILE, when set, is the only file trusted, as OpenSSL has it. */
        if (!getenv("SSL_CERT_FILE") && access(MACOS_CERTIFICATES, R_OK) == 0) {
            SSL_CTX_load_verify_locations(tls_context, MACOS_CERTIFICATES, NULL);
        }
#endif
#ifdef SSL_OP_IGNORE_UNEXPECTED_EOF
        /* Many servers close without TLS's goodbye; HTTP's own framing catches a cut-off body */
        SSL_CTX_set_options(tls_context, SSL_OP_IGNORE_UNEXPECTED_EOF);
#endif
    }
    ERR_clear_error();
    SSL *ssl = SSL_new(tls_context);
    SSL_set_fd(ssl, s->fd);
    /* An address is checked against the certificate's addresses, a name against its names (and
       sent as SNI, which must not carry an address) */
    unsigned char addr[16];
    bool is_address = inet_pton(AF_INET, host, addr) == 1 || inet_pton(AF_INET6, host, addr) == 1;
    X509_VERIFY_PARAM *param = SSL_get0_param(ssl);
    if (is_address) {
        X509_VERIFY_PARAM_set1_ip_asc(param, host);
    } else {
        SSL_set_tlsext_host_name(ssl, host);
        X509_VERIFY_PARAM_set_hostflags(param, X509_CHECK_FLAG_NO_PARTIAL_WILDCARDS);
        X509_VERIFY_PARAM_set1_host(param, host, 0);
    }
    errno = 0;   /* connect() left EINPROGRESS, which would name a handshake cut short */
    int ret = SSL_connect(ssl);
    if (ret != 1) {
        bool ok = raisef("TLS error with %s: %s", host, tls_reason(ssl, ret));
        SSL_free(ssl);
        return ok;
    }
    s->tls = ssl;
    return true;
}
#endif

static Socket *socket_new(int fd, bool listening, int ms) {
    Socket *s = xmalloc(sizeof *s);
    counted++;
    *s = (Socket){.rc = 1, .fd = fd, .listening = listening, .tls = NULL, .timeout_ms = ms, .owner = vm_process};
    return s;
}

/* socket_open($host, $port, $tls, $timeout) */
bool net_open(Str *host, int64_t port, bool tls, double timeout, Value *out) {
#ifndef GAZ_TLS
    if (tls) return raisef("TLS is not built in: this gaz was built with make TLS=0");
#endif
    if (host->len == 0 || memchr(host->data, '\0', host->len)) return raisef("socket_open() expects a host name");
    if (port < 1 || port > 65535) return raisef("socket_open() expects a port from 1 to 65535, got %lld", (long long)port);
    if (!(timeout > 0)) return raisef("socket_open() expects a timeout above 0 seconds");
    int ms = to_ms(timeout);
    char service[8];
    snprintf(service, sizeof service, "%lld", (long long)port);
    struct addrinfo hints = {.ai_family = AF_UNSPEC, .ai_socktype = SOCK_STREAM}, *found;
    int gai = getaddrinfo(host->data, service, &hints, &found);
    if (gai != 0) return raisef("Cannot find host %s: %s", host->data, gai_strerror(gai));
    /* Each address the name has, in the order given, until one answers */
    int fd = -1, err = 0;
    for (struct addrinfo *ai = found; ai && fd < 0; ai = ai->ai_next) {
        fd = connect_one(ai, ms);
        if (fd < 0) err = errno;
    }
    freeaddrinfo(found);
    if (fd < 0) return raisef("Cannot connect to %s port %lld: %s", host->data, (long long)port, strerror(err));
    Socket *s = socket_new(fd, false, ms);
#ifdef GAZ_TLS
    if (tls) {
        sigpipe_ignore();
        bool started = tls_start(s, host->data);
        sigpipe_restore();
        if (!started) {
            decref(v_socket(s));
            return false;
        }
    }
#endif
    *out = v_socket(s);
    return true;
}

/* socket_listen($host, $port, $backlog): a listener on the first of the host's addresses that
   binds, port 0 being one the system picks (socket_port() says which). SO_REUSEADDR, so a server
   restarted at once can have its port back while the old connections time out. */
bool net_listen(Str *host, int64_t port, int64_t backlog, Value *out) {
    if (host->len == 0 || memchr(host->data, '\0', host->len)) return raisef("socket_listen() expects a host name or address");
    if (port < 0 || port > 65535) return raisef("socket_listen() expects a port from 0 to 65535, got %lld", (long long)port);
    if (backlog < 1) return raisef("socket_listen() expects a backlog of 1 or more, got %lld", (long long)backlog);
    char service[8];
    snprintf(service, sizeof service, "%lld", (long long)port);
    struct addrinfo hints = {.ai_family = AF_UNSPEC, .ai_socktype = SOCK_STREAM, .ai_flags = AI_PASSIVE}, *found;
    int gai = getaddrinfo(host->data, service, &hints, &found);
    if (gai != 0) return raisef("Cannot find host %s: %s", host->data, gai_strerror(gai));
    int fd = -1, err = 0;
    for (struct addrinfo *ai = found; ai && fd < 0; ai = ai->ai_next) {
        fd = socket(ai->ai_family, ai->ai_socktype, ai->ai_protocol);
        if (fd < 0) {
            err = errno;
            continue;
        }
        fcntl(fd, F_SETFD, FD_CLOEXEC);
        int one = 1;
        setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &one, sizeof one);
        if (bind(fd, ai->ai_addr, ai->ai_addrlen) != 0 || listen(fd, backlog > INT32_MAX ? INT32_MAX : (int)backlog) != 0) {
            err = errno;
            close(fd);
            fd = -1;
        }
    }
    freeaddrinfo(found);
    if (fd < 0) return raisef("Cannot listen on %s port %lld: %s", host->data, (long long)port, strerror(err));
    *out = v_socket(socket_new(fd, true, 0));
    return true;
}

/* socket_accept($listener, $timeout): the next connection, waiting as long as it takes; $timeout
   bounds each read and write on it, as socket_open()'s does. In a worker asked to stop, null. */
bool net_accept(Socket *listener, double timeout, Value *out) {
    if (listener->fd < 0) return raisef("socket_accept() on a closed socket");
    if (!listener->listening) return raisef("socket_accept() expects a listening socket, got a connection");
    if (!(timeout > 0)) return raisef("socket_accept() expects a timeout above 0 seconds");
    worker_listening();
    int fd = -1;
    while (fd < 0) {
        if (workers_stopping()) {
            *out = v_null();
            return true;
        }
        /* Waiting in poll() a second at a time, so a stop that comes just before it is still seen;
           SIGTERM wakes it, or the accept() after it, sooner */
        struct pollfd p = {.fd = listener->fd, .events = POLLIN};
        int ready = poll(&p, 1, 1000);
        if (ready < 0 && errno != EINTR) return raisef("socket_accept() failed: %s", strerror(errno));
        if (ready <= 0) continue;
        fd = accept(listener->fd, NULL, NULL);
        /* Interrupted, or a connection given up on while it queued: wait for the next */
        if (fd < 0 && errno != EINTR && errno != ECONNABORTED) return raisef("socket_accept() failed: %s", strerror(errno));
    }
    worker_accepted();
    fcntl(fd, F_SETFD, FD_CLOEXEC);
    int ms = to_ms(timeout);
    connection_options(fd, ms);
    *out = v_socket(socket_new(fd, false, ms));
    return true;
}

/* A connection accepted for another process (workers.c's master), as socket_accept() would make
   it: close-on-exec here, and $timeout bounding each read and write, which are the socket's own
   options and so go with it to the process it is given to */
void net_prepare(int fd, double timeout) {
    fcntl(fd, F_SETFD, FD_CLOEXEC);
    connection_options(fd, to_ms(timeout));
}

/* A connection this process was given rather than accepted (workers.c's worker_accept()), already
   net_prepare()d with the same timeout, so no system call is needed */
Value net_adopt(int fd, double timeout) {
    return v_socket(socket_new(fd, false, to_ms(timeout)));
}

/* Seconds on a clock that never goes back, for a deadline */
static double monotonic_seconds(void) {
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);
    return (double)t.tv_sec + (double)t.tv_nsec / 1e9;
}

/* Whether a socket has bytes OpenSSL has already decrypted, which poll() can't see */
static bool tls_pending(Socket *s) {
#ifdef GAZ_TLS
    if (s->tls) return SSL_pending(s->tls) > 0;
#else
    (void)s;
#endif
    return false;
}

/*
 * socket_wait($sockets, $seconds): the index of the first socket in the list with something to
 * read (data or the end on a connection, a connection queued on a listener), or null once $seconds
 * pass, or at once in a worker asked to stop. Like socket_accept(), it waits in poll() a second at
 * a time and takes an interruption as a reason to look at the stop flag again, so a worker idle on
 * a kept-alive connection still stops within about a second.
 *
 * A hang-up or an error counts as something to read, since socket_read() won't wait on either: it
 * gives "" or the error (so does a descriptor poll() calls invalid, which would otherwise spin). Linux reports a closed peer as POLLIN, macOS as POLLIN or POLLHUP.
 */
bool net_wait(List *sockets, double seconds, Value *out) {
    if (sockets->len == 0) return raisef("socket_wait() expects at least one socket");
    if (sockets->len > SOCKET_WAIT_MAX) {
        return raisef("socket_wait() takes at most %d sockets, got %zu", SOCKET_WAIT_MAX, sockets->len);
    }
    struct pollfd polls[SOCKET_WAIT_MAX];
    bool pending[SOCKET_WAIT_MAX];
    size_t n = sockets->len;
    for (size_t i = 0; i < n; i++) {
        Value v = sockets->items[i];
        if (v.type != T_SOCKET) return raisef("socket_wait() expects a list of sockets, got %s at %zu", type_name(v), i);
        Socket *s = v.sock;
        if (s->fd < 0) return raisef("socket_wait() on a closed socket");
        if (!s->listening && s->owner != vm_process) return refuse_inherited("socket_wait", "socket");
        polls[i] = (struct pollfd){.fd = s->fd, .events = POLLIN};
    }
    double deadline = monotonic_seconds() + seconds;
    for (;;) {
        if (workers_stopping()) {
            *out = v_null();
            return true;
        }
        bool any_pending = false;
        for (size_t i = 0; i < n; i++) {
            pending[i] = tls_pending(sockets->items[i].sock);
            any_pending = any_pending || pending[i];
        }
        double left = deadline - monotonic_seconds();
        /* Bytes already decrypted are ready now: poll() only to see whether one before them is too */
        int ms = any_pending || left <= 0 ? 0 : left >= 1 ? 1000 : (int)(left * 1000) + 1;
        int ready = poll(polls, (nfds_t)n, ms);
        if (ready < 0 && errno != EINTR) return raisef("socket_wait() failed: %s", strerror(errno));
        for (size_t i = 0; ready >= 0 && i < n; i++) {
            if (pending[i] || (ready > 0 && (polls[i].revents & (POLLIN | POLLHUP | POLLERR | POLLNVAL)))) {
                *out = v_int((int64_t)i);
                return true;
            }
        }
        if (ready >= 0 && left <= 0) {
            *out = v_null();
            return true;
        }
    }
}

/* socket_port($socket): the port this end has, which is how a listener on port 0 says which */
bool net_port(Socket *s, Value *out) {
    if (s->fd < 0) return raisef("socket_port() on a closed socket");
    if (!s->listening && s->owner != vm_process) return refuse_inherited("socket_port", "socket");
    struct sockaddr_storage addr;
    socklen_t len = sizeof addr;
    if (getsockname(s->fd, (struct sockaddr *)&addr, &len) != 0) return raisef("socket_port() failed: %s", strerror(errno));
    int port = addr.ss_family == AF_INET6 ? ntohs(((struct sockaddr_in6 *)&addr)->sin6_port)
                                          : ntohs(((struct sockaddr_in *)&addr)->sin_port);
    *out = v_int(port);
    return true;
}

/* socket_peer($socket): who is at the other end of a connection, as {"address" => text, "port" => int}.
   Ask right after socket_accept(): once the client has gone some systems no longer know. */
bool net_peer(Socket *s, Value *out) {
    if (s->fd < 0) return raisef("socket_peer() on a closed socket");
    if (s->listening) return raisef("socket_peer() on a listening socket: it has no peer, socket_accept() a connection");
    if (s->owner != vm_process) return refuse_inherited("socket_peer", "socket");
    struct sockaddr_storage addr;
    socklen_t len = sizeof addr;
    if (getpeername(s->fd, (struct sockaddr *)&addr, &len) != 0) return raisef("socket_peer() failed: %s", strerror(errno));
    char text[IP_TEXT_MAX];
    int port;
    if (addr.ss_family == AF_INET6) {
        struct sockaddr_in6 *in6 = (struct sockaddr_in6 *)&addr;
        ip_text(IP_V6, in6->sin6_addr.s6_addr, text);
        port = ntohs(in6->sin6_port);
    } else if (addr.ss_family == AF_INET) {
        struct sockaddr_in *in4 = (struct sockaddr_in *)&addr;
        ip_text(IP_V4, (const unsigned char *)&in4->sin_addr, text);
        port = ntohs(in4->sin_port);
    } else {
        return raisef("socket_peer(): the other end has an address of a kind GazLang doesn't read (family %d)", addr.ss_family);
    }
    Map *m = map_new();
    Value address_key = v_str(str_cstr("address"));
    Value address = v_str(str_cstr(text));
    Value port_key = v_str(str_cstr("port"));
    /* map_set() keeps the key it is given but takes over the value, so only the keys are let go */
    map_set(m, address_key, address);
    map_set(m, port_key, v_int(port));
    decref(address_key);
    decref(port_key);
    *out = v_map(m);
    return true;
}

/* Whole milliseconds left until a monotonic_seconds() deadline, rounded up, or 0 once it passed */
static int ms_until(double deadline) {
    double left = deadline - monotonic_seconds();
    return left > 0 ? (int)(left * 1000) + 1 : 0;
}

/* Wait up to ms for something to read on a plain socket, retrying an interrupted poll() for what
   is left; false if nothing came. A hang-up or an error counts, as in socket_wait(), so the read
   after it gives "" or the error. */
static bool readable_within(Socket *s, int ms) {
    double deadline = monotonic_seconds() + ms / 1000.0;
    struct pollfd p = {.fd = s->fd, .events = POLLIN};
    int ready;
    while ((ready = poll(&p, 1, ms)) < 0 && errno == EINTR) {
        ms = ms_until(deadline);
    }
    return ready != 0;
}

#ifdef GAZ_TLS
/*
 * SSL_read() waiting at most ms, giving SSL_read()'s result and its SSL_get_error() in *error, or
 * setting *timed_out. A readable socket is no promise of data over TLS: the bytes may be a session
 * ticket (TLS 1.3 sends them after the handshake) or part of a record, and a blocking SSL_read()
 * would take them and then wait out the socket's own timeout. So the descriptor is non-blocking for
 * this call alone, SSL_read() says what it wants (to read, or to write, as a key update can), and
 * poll() waits for that for the time left. The descriptor's flags are put back on every path.
 */
static int tls_read_within(Socket *s, char *buffer, int size, int ms, int *error, bool *timed_out) {
    double deadline = monotonic_seconds() + ms / 1000.0;
    int flags = fcntl(s->fd, F_GETFL);
    fcntl(s->fd, F_SETFL, flags | O_NONBLOCK);
    int r;
    for (;;) {
        ERR_clear_error();
        errno = 0;   /* as in net_read(): an end without TLS's goodbye leaves errno alone */
        r = SSL_read(s->tls, buffer, size);
        *error = r > 0 ? SSL_ERROR_NONE : SSL_get_error(s->tls, r);
        if (*error != SSL_ERROR_WANT_READ && *error != SSL_ERROR_WANT_WRITE) break;
        int left = ms_until(deadline);
        struct pollfd p = {.fd = s->fd, .events = *error == SSL_ERROR_WANT_READ ? POLLIN : POLLOUT};
        /* An interrupted poll() goes round again: SSL_read() asks once more, and poll() waits for what is left */
        if (left == 0 || poll(&p, 1, left) == 0) {
            *timed_out = true;
            break;
        }
    }
    int saved = errno;
    fcntl(s->fd, F_SETFL, flags);
    errno = saved;
    return r;
}
#endif

/* socket_read($socket, $seconds): what has arrived, up to 64KB, waiting for something; "" at the
   end. With $seconds shorter than the socket's own timeout, it waits only that long and gives null
   if nothing came, which is how a deadline holds to the moment (http::serve()'s request_timeout);
   seconds = 0 is no $seconds, builtins.c having refused 0 and below. */
bool net_read(Socket *s, double seconds, Value *out) {
    if (s->fd < 0) return raisef("socket_read() on a closed socket");
    if (s->listening) return raisef("socket_read() on a listening socket: socket_accept() a connection");
    if (s->owner != vm_process) return refuse_inherited("socket_read", "socket");
    /* The wait $seconds asks for, in ms, or 0 to wait as long as the socket's own timeout */
    int limit = seconds > 0 && to_ms(seconds) < s->timeout_ms ? to_ms(seconds) : 0;
    char chunk[65536];
    ssize_t n;
#ifdef GAZ_TLS
    if (s->tls) {
        int r, e;
        bool timed_out = false;
        sigpipe_ignore();
        if (limit > 0) {
            r = tls_read_within(s, chunk, sizeof chunk, limit, &e, &timed_out);
        } else {
            ERR_clear_error();
            /* An end without TLS's goodbye is SSL_ERROR_SYSCALL with errno untouched, so clear it */
            errno = 0;
            r = SSL_read(s->tls, chunk, sizeof chunk);
            e = r > 0 ? SSL_ERROR_NONE : SSL_get_error(s->tls, r);
        }
        sigpipe_restore();
        if (timed_out) {
            *out = v_null();
            return true;
        }
        if (r > 0) n = r;
        else if (e == SSL_ERROR_ZERO_RETURN || (e == SSL_ERROR_SYSCALL && errno == 0)) n = 0;
        else if (e == SSL_ERROR_WANT_READ || e == SSL_ERROR_WANT_WRITE) return raisef("socket_read() timed out");
        else if (e == SSL_ERROR_SYSCALL) return raisef("socket_read() failed: %s", strerror(errno));
        else return raisef("TLS error: %s", ERR_reason_error_string(ERR_peek_last_error()) ? ERR_reason_error_string(ERR_peek_last_error()) : "unknown error");
        *out = v_str(str_new(chunk, (size_t)n));
        return true;
    }
#endif
    if (limit > 0 && !readable_within(s, limit)) {
        *out = v_null();
        return true;
    }
    sigpipe_ignore();
    while ((n = read(s->fd, chunk, sizeof chunk)) < 0 && errno == EINTR) {}
    sigpipe_restore();
    if (n < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) return raisef("socket_read() timed out");
    if (n < 0) return raisef("socket_read() failed: %s", strerror(errno));
    *out = v_str(str_new(chunk, (size_t)n));
    return true;
}

/* socket_write($socket, $data): all of it */
bool net_write(Socket *s, Str *data) {
    if (s->fd < 0) return raisef("socket_write() on a closed socket");
    if (s->listening) return raisef("socket_write() on a listening socket: socket_accept() a connection");
    if (s->owner != vm_process) return refuse_inherited("socket_write", "socket");
    sigpipe_ignore();
    for (size_t done = 0; done < data->len;) {
        ssize_t n;
#ifdef GAZ_TLS
        if (s->tls) {
            ERR_clear_error();
            errno = 0;
            size_t left = data->len - done;
            int r = SSL_write(s->tls, data->data + done, left > INT32_MAX ? INT32_MAX : (int)left);
            n = r > 0 ? r : -1;
            int e = r > 0 ? SSL_ERROR_NONE : SSL_get_error(s->tls, r);
            if (e == SSL_ERROR_WANT_READ || e == SSL_ERROR_WANT_WRITE) errno = EAGAIN;
            else if (r <= 0 && e != SSL_ERROR_SYSCALL) errno = EPROTO;
            else if (r <= 0 && errno == 0) errno = EPIPE;   /* the other end went away */
        } else
#endif
        n = write(s->fd, data->data + done, data->len - done);
        if (n < 0 && errno == EINTR) continue;
        if (n < 0) {
            int err = errno;
            sigpipe_restore();
            if (err == EAGAIN || err == EWOULDBLOCK) return raisef("socket_write() timed out");
            return raisef("socket_write() failed: %s", strerror(err));
        }
        done += (size_t)n;
    }
    sigpipe_restore();
    return true;
}

/* socket_close($socket), and what freeing one does; closing twice does nothing. Closing only
   this process's descriptor never ends a connection another process still has, but TLS's goodbye
   would, so a worker lets go of a TLS connection it inherited without one (see workers.c). */
void net_close(Socket *s) {
    if (s->fd < 0) return;
    sigpipe_ignore();
#ifdef GAZ_TLS
    if (s->tls) {
        /* TLS's goodbye, sent without waiting for the other side's */
        if (s->owner == vm_process) SSL_shutdown(s->tls);
        SSL_free(s->tls);
        s->tls = NULL;
    }
#endif
    close(s->fd);
    s->fd = -1;
    sigpipe_restore();
}
