/*
 * Sockets: socket_open(), socket_read(), socket_write() and socket_close(), plain TCP or TLS.
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

/* Connect to one address within the timeout: a non-blocking connect() that poll() waits for,
   then back to blocking, with the timeout on each read and write. -1 with errno set if not. */
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
    struct timeval tv = {.tv_sec = timeout_ms / 1000, .tv_usec = (timeout_ms % 1000) * 1000};
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof tv);
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &tv, sizeof tv);
    return fd;
fail: {
    int err = errno;
    close(fd);
    errno = err;
    return -1;
}
}

#ifdef GAZ_TLS
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

/* socket_open($host, $port, $tls, $timeout) */
bool net_open(Str *host, int64_t port, bool tls, double timeout, Value *out) {
#ifndef GAZ_TLS
    if (tls) return raisef("TLS is not built in: this gazlang was built with make TLS=0");
#endif
    if (host->len == 0 || memchr(host->data, '\0', host->len)) return raisef("socket_open() expects a host name");
    if (port < 1 || port > 65535) return raisef("socket_open() expects a port from 1 to 65535, got %lld", (long long)port);
    if (!(timeout > 0)) return raisef("socket_open() expects a timeout above 0 seconds");
    int timeout_ms = timeout > 86400 ? 86400000 : (int)(timeout * 1000);
    if (timeout_ms == 0) timeout_ms = 1;
    char service[8];
    snprintf(service, sizeof service, "%lld", (long long)port);
    struct addrinfo hints = {.ai_family = AF_UNSPEC, .ai_socktype = SOCK_STREAM}, *found;
    int gai = getaddrinfo(host->data, service, &hints, &found);
    if (gai != 0) return raisef("Cannot find host %s: %s", host->data, gai_strerror(gai));
    /* Each address the name has, in the order given, until one answers */
    int fd = -1, err = 0;
    for (struct addrinfo *ai = found; ai && fd < 0; ai = ai->ai_next) {
        fd = connect_one(ai, timeout_ms);
        if (fd < 0) err = errno;
    }
    freeaddrinfo(found);
    if (fd < 0) return raisef("Cannot connect to %s port %lld: %s", host->data, (long long)port, strerror(err));
#ifdef SO_NOSIGPIPE
    int one = 1;
    setsockopt(fd, SOL_SOCKET, SO_NOSIGPIPE, &one, sizeof one);
#endif
    Socket *s = xmalloc(sizeof *s);
    counted++;
    *s = (Socket){.rc = 1, .fd = fd, .tls = NULL, .timeout_ms = timeout_ms};
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

/* socket_read($socket): what has arrived, up to 64KB, waiting for something; "" at the end */
bool net_read(Socket *s, Value *out) {
    if (s->fd < 0) return raisef("socket_read() on a closed socket");
    char chunk[65536];
    ssize_t n;
    sigpipe_ignore();
#ifdef GAZ_TLS
    if (s->tls) {
        ERR_clear_error();
        int r = SSL_read(s->tls, chunk, sizeof chunk);
        int e = r > 0 ? SSL_ERROR_NONE : SSL_get_error(s->tls, r);
        sigpipe_restore();
        if (r > 0) n = r;
        else if (e == SSL_ERROR_ZERO_RETURN || (e == SSL_ERROR_SYSCALL && errno == 0)) n = 0;
        else if (e == SSL_ERROR_WANT_READ || e == SSL_ERROR_WANT_WRITE) return raisef("socket_read() timed out");
        else if (e == SSL_ERROR_SYSCALL) return raisef("socket_read() failed: %s", strerror(errno));
        else return raisef("TLS error: %s", ERR_reason_error_string(ERR_peek_last_error()) ? ERR_reason_error_string(ERR_peek_last_error()) : "unknown error");
        *out = v_str(str_new(chunk, (size_t)n));
        return true;
    }
#endif
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
    sigpipe_ignore();
    for (size_t done = 0; done < data->len;) {
        ssize_t n;
#ifdef GAZ_TLS
        if (s->tls) {
            ERR_clear_error();
            size_t left = data->len - done;
            int r = SSL_write(s->tls, data->data + done, left > INT32_MAX ? INT32_MAX : (int)left);
            n = r > 0 ? r : -1;
            int e = r > 0 ? SSL_ERROR_NONE : SSL_get_error(s->tls, r);
            if (e == SSL_ERROR_WANT_READ || e == SSL_ERROR_WANT_WRITE) errno = EAGAIN;
            else if (r <= 0 && e != SSL_ERROR_SYSCALL) errno = EPROTO;
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

/* socket_close($socket), and what freeing one does; closing twice does nothing */
void net_close(Socket *s) {
    if (s->fd < 0) return;
    sigpipe_ignore();
#ifdef GAZ_TLS
    if (s->tls) {
        /* TLS's goodbye, sent without waiting for the other side's */
        SSL_shutdown(s->tls);
        SSL_free(s->tls);
        s->tls = NULL;
    }
#endif
    close(s->fd);
    s->fd = -1;
    sigpipe_restore();
}
