/*
 * The PostgreSQL driver for db.c, on libpq. The URL is given to libpq whole, so it takes
 * everything libpq's connection strings do: postgres://user:password@host:port/database?sslmode=...
 *
 * Values: int2, int4 and int8 are ints, float4 and float8 floats, bool a bool, NULL null, and
 * everything else (numeric, text, timestamps, json, bytea's \x hex...) is the text Postgres prints
 * it as. Going in, null, int, float, string and bool are sent as text, which the server reads as
 * whatever type the query wants there; anything else is an error. Placeholders are Postgres's
 * ($1, $2...).
 *
 * With parameters the SQL is one statement; without, it may be several, and the last one's
 * result is the one given.
 *
 * libpq is loaded (dlopen) by the first postgres:// db_open(), not linked: linked, it and the
 * libraries it brings cost every start of gaz several milliseconds, for programs that never open
 * a database. Building needs only its header; running a program that opens one needs the library.
 */
#include "gazvm.h"

#include <dlfcn.h>
#include <libpq-fe.h>
#include <math.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

/* Type numbers from pg_type.dat, which are fixed for good */
enum { OID_BOOL = 16, OID_INT8 = 20, OID_INT2 = 21, OID_INT4 = 23, OID_FLOAT4 = 700, OID_FLOAT8 = 701 };

/* ---- Loading libpq ---------------------------------------------------------------------- */

/* The libpq functions this file calls, found in the library once it is loaded: pq.PQexec(...)
   is libpq's PQexec(...), with the type libpq-fe.h declares it with */
static struct {
    PGconn *(*PQconnectdb)(const char *conninfo);
    ConnStatusType (*PQstatus)(const PGconn *conn);
    char *(*PQerrorMessage)(const PGconn *conn);
    int (*PQsocket)(const PGconn *conn);
    void (*PQfinish)(PGconn *conn);
    PGresult *(*PQexec)(PGconn *conn, const char *query);
    PGresult *(*PQexecParams)(PGconn *conn, const char *command, int nParams, const Oid *paramTypes,
                              const char *const *paramValues, const int *paramLengths,
                              const int *paramFormats, int resultFormat);
    ExecStatusType (*PQresultStatus)(const PGresult *res);
    char *(*PQresultErrorField)(const PGresult *res, int fieldcode);
    char *(*PQresultErrorMessage)(const PGresult *res);
    int (*PQntuples)(const PGresult *res);
    int (*PQnfields)(const PGresult *res);
    char *(*PQfname)(const PGresult *res, int field_num);
    Oid (*PQftype)(const PGresult *res, int field_num);
    int (*PQgetisnull)(const PGresult *res, int tup_num, int field_num);
    char *(*PQgetvalue)(const PGresult *res, int tup_num, int field_num);
    int (*PQgetlength)(const PGresult *res, int tup_num, int field_num);
    char *(*PQcmdStatus)(PGresult *res);
    char *(*PQcmdTuples)(PGresult *res);
    void (*PQclear)(PGresult *res);
} pq;

/* Each function's name, and where its address goes. dlsym() gives a function's address as a
   void *, which POSIX promises converts to a function pointer, hence the (void **). */
#define FUNCTION(name) {#name, (void **)&pq.name}
static const struct { const char *name; void **address; } FUNCTIONS[] = {
    FUNCTION(PQconnectdb), FUNCTION(PQstatus), FUNCTION(PQerrorMessage), FUNCTION(PQsocket),
    FUNCTION(PQfinish), FUNCTION(PQexec), FUNCTION(PQexecParams), FUNCTION(PQresultStatus),
    FUNCTION(PQresultErrorField), FUNCTION(PQresultErrorMessage), FUNCTION(PQntuples),
    FUNCTION(PQnfields), FUNCTION(PQfname), FUNCTION(PQftype), FUNCTION(PQgetisnull),
    FUNCTION(PQgetvalue), FUNCTION(PQgetlength), FUNCTION(PQcmdStatus), FUNCTION(PQcmdTuples),
    FUNCTION(PQclear),
};
#undef FUNCTION

/*
 * Where libpq is looked for, in order: the directory make found it in (GAZ_PG_LIBDIR, empty when
 * the header was on the compiler's own path), then elsewhere. Its major version, 5, is the one
 * libpq has had since PostgreSQL 8.0.
 *
 * On Linux, elsewhere is the name alone (an empty directory here), which ld.so finds through its
 * own search (the ld.so cache, /usr/lib), never the working directory. On macOS dyld looks for a
 * name alone in the working directory too, so a server would load a libpq.5.dylib planted where it
 * was started: there every place is a whole path, Homebrew's two prefixes and /usr/local/lib.
 */
#ifdef __APPLE__
#define LIBPQ "libpq.5.dylib"
#define INSTALL "brew install libpq"
static const char *const DIRECTORIES[] = {GAZ_PG_LIBDIR, "/opt/homebrew/opt/libpq/lib", "/usr/local/opt/libpq/lib", "/usr/local/lib"};
enum { BY_NAME_ALONE = false };
#else
#define LIBPQ "libpq.so.5"
#define INSTALL "apt install libpq5, or dnf install libpq"
static const char *const DIRECTORIES[] = {GAZ_PG_LIBDIR, ""};
enum { BY_NAME_ALONE = true };
#endif
enum { NDIRECTORIES = sizeof DIRECTORIES / sizeof DIRECTORIES[0] };

/* Whether to try directory i: not one already tried (as GAZ_PG_LIBDIR or Homebrew's can be), and
   not the name alone where that isn't safe */
static bool worth_trying(int i) {
    if (!DIRECTORIES[i][0] && !BY_NAME_ALONE) return false;
    for (int j = 0; j < i; j++) {
        if (!strcmp(DIRECTORIES[j], DIRECTORIES[i])) return false;
    }
    return true;
}

/* Why libpq couldn't be loaded, which db_open() raises */
static Buf failure;

/*
 * Load libpq and find its functions, or say why not in `failure`. Once loaded it stays. Only the
 * program's own thread opens databases or starts workers, so nothing here needs a lock.
 *
 * When nothing loads, the message lists every file tried, and gives dlopen()'s reason for one
 * that is there but wouldn't load (another architecture, a library it needs missing, a signature
 * refused), since then installing libpq isn't the answer. A file is there when it exists, or, for
 * a name alone, when the reason isn't that it wasn't found.
 */
static bool load_libpq(void) {
    static bool loaded;
    if (loaded) return true;
    failure.len = 0;
    Buf tried = {0}, reason = {0};
    void *library = NULL;
    for (int i = 0; i < NDIRECTORIES && !library; i++) {
        if (!worth_trying(i)) continue;
        Buf path = {0};
        if (DIRECTORIES[i][0]) {
            buf_adds(&path, DIRECTORIES[i]);
            buf_addc(&path, '/');
        }
        buf_adds(&path, LIBPQ);
        library = dlopen(path.data, RTLD_NOW | RTLD_LOCAL);
        if (!library && !reason.len) {
            const char *why = dlerror();
            bool there = DIRECTORIES[i][0] ? access(path.data, F_OK) == 0 : why && !strstr(why, "No such file");
            if (there && why) buf_adds(&reason, why);
        }
        if (tried.len) buf_adds(&tried, ", ");
        buf_adds(&tried, path.data);
        free(path.data);
    }
    if (!library && reason.len) {
        buf_addf(&failure, "postgres: libpq is there but could not be loaded: %s (gaz tried %s)", reason.data, tried.data);
    } else if (!library) {
        buf_addf(&failure, "postgres: libpq could not be loaded (gaz tried %s): install it (%s)", tried.data, INSTALL);
    }
    free(tried.data);
    free(reason.data);
    if (!library) return false;
    for (size_t i = 0; i < sizeof FUNCTIONS / sizeof FUNCTIONS[0]; i++) {
        *FUNCTIONS[i].address = dlsym(library, FUNCTIONS[i].name);
        if (!*FUNCTIONS[i].address) {
            buf_addf(&failure, "postgres: the libpq gaz loaded has no %s, so it is too old to use", FUNCTIONS[i].name);
            dlclose(library);
            return false;
        }
    }
    loaded = true;
    return true;
}

/*
 * Called by workers() before it forks, so the workers inherit libpq loaded rather than each
 * loading it on its first open. On macOS that is not only quicker but needed: Homebrew's libpq
 * brings in Kerberos.framework, whose Objective-C classes can't be set up in a child forked from
 * a process with two threads (gaz's main thread and the program's), so a worker that loaded it
 * itself would be killed by the Objective-C runtime (and so would one asking Kerberos for
 * credentials, which open_pg() prevents by default). workers() calls it only for a program that
 * names db_open (Program.opens_databases), so a server without a database never loads libpq. A
 * failure is left for db_open() to raise.
 */
void pg_load_before_fork(void) { load_libpq(); }

/* ---- The driver ------------------------------------------------------------------------- */

static bool open_pg(Str *url, void **conn) {
    if (!load_libpq()) return raisef("%s", failure.data);
#ifdef __APPLE__
    /*
     * libpq's gssencmode is "prefer" unless something says otherwise, so on every TCP connection
     * it first asks Kerberos whether there are credentials (gss_acquire_cred()). On macOS that
     * goes through Apple's Kerberos.framework, which reads its preferences with CoreFoundation and
     * so sets up some forty Objective-C classes (NSMutableString first). In a worker, a child
     * forked from a process with two threads, the Objective-C runtime aborts rather than set up a
     * class for the first time. So on macOS, in a worker only (a program that never forked may
     * use GSS as libpq likes), gaz makes "disable" the default, in libpq's own environment
     * variable: it then comes last, after the URL's gssencmode, a service file's and a
     * PGGSSENCMODE the user set, and with it no connection reaches Kerberos unless the server
     * asks for GSSAPI authentication. Loading libpq is the other half (pg_load_before_fork()).
     * Being an environment variable, the worker's getenv() sees it and programs it starts with
     * run() inherit it, as sqlite.c's OS_ACTIVITY_MODE is.
     * ponytail: GSSAPI (gssencmode prefer or require, or a server asking for gss authentication)
     * still aborts a worker unless gaz was started with OBJC_DISABLE_INITIALIZE_FORK_SAFETY=YES;
     * lifted only if Kerberos.framework stops using Objective-C or workers are started by exec
     * rather than fork.
     */
    if (vm_process != 0) setenv("PGGSSENCMODE", "disable", 0);
#endif
    PGconn *c = pq.PQconnectdb(url->data);
    if (!c) return raisef("postgres: out of memory");
    if (pq.PQstatus(c) != CONNECTION_OK) {
        /* libpq's message is two lines and ends in a newline: one line of it is easier to read in an error */
        char *msg = strdup(pq.PQerrorMessage(c));
        size_t n = msg ? strlen(msg) : 0;
        while (n && msg[n - 1] == '\n') msg[--n] = '\0';
        for (size_t i = 0, o = 0; i <= n; i++) {
            if (msg[i] != '\t') msg[o++] = msg[i] == '\n' ? ' ' : msg[i];
        }
        bool r = raisef("postgres: cannot connect: %s", msg ? msg : "out of memory");
        free(msg);
        pq.PQfinish(c);
        return r;
    }
    *conn = c;
    return true;
}

static void close_pg(void *conn) { pq.PQfinish(conn); }

/* pq.PQfinish() sends the server Terminate (and, over TLS, TLS's goodbye) before it closes the socket,
   and the server would end the session for the process that opened it too, since the socket is
   one connection shared by both; with the socket pointed at /dev/null first, the goodbye goes
   nowhere and pq.PQfinish() only frees what this process holds */
static void abandon_pg(void *conn) {
    abandon_fd(pq.PQsocket(conn));
    pq.PQfinish(conn);
}

/* A parameter as the text libpq sends; *owned is set when it must be freed */
static bool param_text(size_t i, Value v, const char **text, char **owned) {
    *owned = NULL;
    switch (v.type) {
    case T_NULL: *text = NULL; return true;
    case T_STRING:
        if (memchr(v.s->data, '\0', v.s->len)) return raisef("postgres: parameter %zu holds a NUL byte, which text cannot", i + 1);
        *text = v.s->data;
        return true;
    case T_BOOL: *text = v.b ? "t" : "f"; return true;
    case T_INT:
    case T_FLOAT: {
        Buf b = {0};
        if (v.type == T_INT) buf_add_int(&b, v.i);
        else format_float(v.f, &b);
        buf_addc(&b, '\0');
        *owned = b.data;
        *text = b.data;
        return true;
    }
    default: return raisef("postgres: parameter %zu is a %s, which cannot be stored", i + 1, type_name(v));
    }
}

static bool cell(PGresult *res, int row, int col, Value *out) {
    if (pq.PQgetisnull(res, row, col)) {
        *out = v_null();
        return true;
    }
    const char *text = pq.PQgetvalue(res, row, col);
    size_t len = (size_t)pq.PQgetlength(res, row, col);
    switch (pq.PQftype(res, col)) {
    case OID_BOOL: *out = v_bool(text[0] == 't'); return true;
    case OID_INT2:
    case OID_INT4:
    case OID_INT8: {
        int64_t i;
        if (!parse_integer(text, len, &i)) return raisef("postgres: cannot read %s as an int", text);
        *out = v_int(i);
        return true;
    }
    case OID_FLOAT4:
    case OID_FLOAT8: {
        Value f;
        /* NaN and Infinity aren't numbers GazLang has: parse_number refuses them */
        if (!parse_number(text, len, &f)) return raisef("postgres: cannot read %s as a float, which is always finite", text);
        *out = f.type == T_INT ? v_float((double)f.i) : f;
        return true;
    }
    default:
        *out = v_str(str_new(text, len));
        return true;
    }
}

static bool run_pg(void *conn, Str *sql, List *params, Value *out) {
    PGconn *c = conn;
    if (memchr(sql->data, '\0', sql->len)) return raisef("postgres: the SQL holds a NUL byte");
    PGresult *res;
    if (params->len == 0) {
        res = pq.PQexec(c, sql->data);
    } else {
        const char **values = xcalloc(params->len, sizeof *values);
        char **owned = xcalloc(params->len, sizeof *owned);
        bool ok = true;
        for (size_t i = 0; ok && i < params->len; i++) ok = param_text(i, params->items[i], &values[i], &owned[i]);
        res = ok ? pq.PQexecParams(c, sql->data, (int)params->len, NULL, values, NULL, NULL, 0) : NULL;
        for (size_t i = 0; i < params->len; i++) free(owned[i]);
        free(values);
        free(owned);
        if (!ok) return false;
    }
    if (!res) return raisef("postgres: %s", pq.PQerrorMessage(c));
    ExecStatusType status = pq.PQresultStatus(res);
    if (status != PGRES_COMMAND_OK && status != PGRES_TUPLES_OK && status != PGRES_EMPTY_QUERY) {
        /* The server's own words, not libpq's "ERROR:  " and query excerpt around them */
        const char *msg = pq.PQresultErrorField(res, PG_DIAG_MESSAGE_PRIMARY);
        bool r = raisef("postgres: %s", msg ? msg : pq.PQresultErrorMessage(res));
        pq.PQclear(res);
        return r;
    }
    List *rows = list_new((size_t)pq.PQntuples(res));
    for (int r = 0; r < pq.PQntuples(res); r++) {
        Map *m = map_new();
        Value row = v_map(m);
        for (int col = 0; col < pq.PQnfields(res); col++) {
            Value v;
            if (!cell(res, r, col, &v)) {
                decref(row);
                decref(v_list(rows));
                pq.PQclear(res);
                return false;
            }
            const char *name = pq.PQfname(res, col);
            db_put(m, name, strlen(name), v);
        }
        list_push(rows, row);
    }
    /* The command tag's count is a SELECT's, FETCH's and COPY's row count too, which isn't a change */
    const char *tag = pq.PQcmdStatus(res);
    int64_t changes = 0;
    if (!strncmp(tag, "INSERT", 6) || !strncmp(tag, "UPDATE", 6) || !strncmp(tag, "DELETE", 6) || !strncmp(tag, "MERGE", 5)) {
        const char *n = pq.PQcmdTuples(res);
        parse_integer(n, strlen(n), &changes);
    }
    pq.PQclear(res);
    *out = db_result(rows, changes);
    return true;
}

const DbDriver pg_driver = {"pg", open_pg, run_pg, close_pg, abandon_pg};
