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
 */
#include "gazvm.h"

#include <libpq-fe.h>
#include <math.h>
#include <stdlib.h>
#include <string.h>

/* Type numbers from pg_type.dat, which are fixed for good */
enum { OID_BOOL = 16, OID_INT8 = 20, OID_INT2 = 21, OID_INT4 = 23, OID_FLOAT4 = 700, OID_FLOAT8 = 701 };

static bool open_pg(Str *url, void **conn) {
    PGconn *c = PQconnectdb(url->data);
    if (!c) return raisef("postgres: out of memory");
    if (PQstatus(c) != CONNECTION_OK) {
        /* libpq's message is two lines and ends in a newline: one line of it is easier to read in an error */
        char *msg = strdup(PQerrorMessage(c));
        size_t n = msg ? strlen(msg) : 0;
        while (n && msg[n - 1] == '\n') msg[--n] = '\0';
        for (size_t i = 0, o = 0; i <= n; i++) {
            if (msg[i] != '\t') msg[o++] = msg[i] == '\n' ? ' ' : msg[i];
        }
        bool r = raisef("postgres: cannot connect: %s", msg ? msg : "out of memory");
        free(msg);
        PQfinish(c);
        return r;
    }
    *conn = c;
    return true;
}

static void close_pg(void *conn) { PQfinish(conn); }

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
    if (PQgetisnull(res, row, col)) {
        *out = v_null();
        return true;
    }
    const char *text = PQgetvalue(res, row, col);
    size_t len = (size_t)PQgetlength(res, row, col);
    switch (PQftype(res, col)) {
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
        res = PQexec(c, sql->data);
    } else {
        const char **values = xcalloc(params->len, sizeof *values);
        char **owned = xcalloc(params->len, sizeof *owned);
        bool ok = true;
        for (size_t i = 0; ok && i < params->len; i++) ok = param_text(i, params->items[i], &values[i], &owned[i]);
        res = ok ? PQexecParams(c, sql->data, (int)params->len, NULL, values, NULL, NULL, 0) : NULL;
        for (size_t i = 0; i < params->len; i++) free(owned[i]);
        free(values);
        free(owned);
        if (!ok) return false;
    }
    if (!res) return raisef("postgres: %s", PQerrorMessage(c));
    ExecStatusType status = PQresultStatus(res);
    if (status != PGRES_COMMAND_OK && status != PGRES_TUPLES_OK && status != PGRES_EMPTY_QUERY) {
        /* The server's own words, not libpq's "ERROR:  " and query excerpt around them */
        const char *msg = PQresultErrorField(res, PG_DIAG_MESSAGE_PRIMARY);
        bool r = raisef("postgres: %s", msg ? msg : PQresultErrorMessage(res));
        PQclear(res);
        return r;
    }
    List *rows = list_new((size_t)PQntuples(res));
    for (int r = 0; r < PQntuples(res); r++) {
        Map *m = map_new();
        Value row = v_map(m);
        for (int col = 0; col < PQnfields(res); col++) {
            Value v;
            if (!cell(res, r, col, &v)) {
                decref(row);
                decref(v_list(rows));
                PQclear(res);
                return false;
            }
            const char *name = PQfname(res, col);
            db_put(m, name, strlen(name), v);
        }
        list_push(rows, row);
    }
    /* The command tag's count is a SELECT's, FETCH's and COPY's row count too, which isn't a change */
    const char *tag = PQcmdStatus(res);
    int64_t changes = 0;
    if (!strncmp(tag, "INSERT", 6) || !strncmp(tag, "UPDATE", 6) || !strncmp(tag, "DELETE", 6) || !strncmp(tag, "MERGE", 5)) {
        const char *n = PQcmdTuples(res);
        parse_integer(n, strlen(n), &changes);
    }
    PQclear(res);
    *out = db_result(rows, changes);
    return true;
}

const DbDriver pg_driver = {"pg", open_pg, run_pg, close_pg};
