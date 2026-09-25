/*
 * The sqlite driver for db.c, on the system's libsqlite3. "sqlite:PATH" opens a file (made if it
 * isn't there), "sqlite::memory:" a database that lives as long as the connection.
 *
 * Values: INTEGER is an int, REAL a float, TEXT and BLOB strings, NULL null. Going in, null, int,
 * float and string are bound as such and a bool as 0 or 1; anything else is an error. Placeholders
 * are SQLite's (`?`, `?1`).
 *
 * With parameters the SQL is one statement; without, it is a script and the last statement's
 * result is the one given, so a schema can be made in one call.
 */
#include "gazvm.h"

#include <math.h>
#include <sqlite3.h>
#include <string.h>

static bool open_sqlite(Str *url, void **conn) {
    const char *path = url->data + strlen("sqlite:");
    if (!*path) return raisef("sqlite: no path: write sqlite:FILE or sqlite::memory:");
    sqlite3 *db;
    int rc = sqlite3_open_v2(path, &db, SQLITE_OPEN_READWRITE | SQLITE_OPEN_CREATE, NULL);
    if (rc != SQLITE_OK) {
        bool r = raisef("sqlite: cannot open %s: %s", path, db ? sqlite3_errmsg(db) : "out of memory");
        sqlite3_close(db);
        return r;
    }
    sqlite3_busy_timeout(db, 5000);
    *conn = db;
    return true;
}

static void close_sqlite(void *conn) { sqlite3_close(conn); }

static bool bind_params(sqlite3 *db, sqlite3_stmt *st, List *params) {
    int wanted = sqlite3_bind_parameter_count(st);
    if ((size_t)wanted != params->len) {
        return raisef("sqlite: the SQL takes %d parameter%s, got %zu", wanted, wanted == 1 ? "" : "s", params->len);
    }
    for (size_t i = 0; i < params->len; i++) {
        Value v = params->items[i];
        int rc;
        switch (v.type) {
        case T_NULL: rc = sqlite3_bind_null(st, (int)i + 1); break;
        case T_INT: rc = sqlite3_bind_int64(st, (int)i + 1, v.i); break;
        case T_FLOAT: rc = sqlite3_bind_double(st, (int)i + 1, v.f); break;
        case T_BOOL: rc = sqlite3_bind_int(st, (int)i + 1, v.b); break;
        case T_STRING: rc = sqlite3_bind_text64(st, (int)i + 1, v.s->data, v.s->len, SQLITE_STATIC, SQLITE_UTF8); break;
        default: return raisef("sqlite: parameter %zu is a %s, which cannot be stored", i + 1, type_name(v));
        }
        if (rc != SQLITE_OK) return raisef("sqlite: %s", sqlite3_errmsg(db));
    }
    return true;
}

/* One row as a map from column name to value; a repeated name keeps the last */
static bool read_row(sqlite3_stmt *st, Value *out) {
    Map *m = map_new();
    for (int i = 0; i < sqlite3_column_count(st); i++) {
        Value v;
        switch (sqlite3_column_type(st, i)) {
        case SQLITE_INTEGER: v = v_int(sqlite3_column_int64(st, i)); break;
        case SQLITE_FLOAT: {
            double f = sqlite3_column_double(st, i);
            if (isinf(f)) {
                decref(v_map(m));
                return raisef("sqlite: column %s is infinite, and floats are always finite", sqlite3_column_name(st, i));
            }
            v = v_float(f);
            break;
        }
        case SQLITE_NULL: v = v_null(); break;
        default: {
            /* TEXT and BLOB: the bytes as they are. Read the pointer first, then the length */
            const void *data = sqlite3_column_blob(st, i);
            v = v_str(str_new(data ? data : "", (size_t)sqlite3_column_bytes(st, i)));
        }
        }
        const char *name = sqlite3_column_name(st, i);
        db_put(m, name, strlen(name), v);
    }
    *out = v_map(m);
    return true;
}

/* Whether a statement follows: not white space, semicolons or comments, which prepare gives no statement for */
static bool has_more(sqlite3 *db, const char *at, const char *end) {
    while (at < end && (*at == ' ' || *at == '\n' || *at == '\t' || *at == '\r' || *at == ';')) at++;
    if (at >= end) return false;
    sqlite3_stmt *st;
    /* A syntax error counts as more, for the statement that follows to report */
    if (sqlite3_prepare_v2(db, at, (int)(end - at), &st, NULL) != SQLITE_OK) return true;
    sqlite3_finalize(st);
    return st != NULL;
}

static bool run_sqlite(void *conn, Str *sql, List *params, Value *out) {
    sqlite3 *db = conn;
    const char *at = sql->data, *end = sql->data + sql->len;
    int before = sqlite3_total_changes(db);
    List *rows = list_new(0);
    int64_t changes = 0;
    bool script = params->len == 0;
    for (;;) {
        sqlite3_stmt *st;
        const char *tail;
        if (sqlite3_prepare_v2(db, at, (int)(end - at), &st, &tail) != SQLITE_OK) {
            bool r = raisef("sqlite: %s", sqlite3_errmsg(db));
            decref(v_list(rows));
            return r;
        }
        if (!st) {
            /* Only white space or comments left */
            if (tail >= end) break;
            at = tail;
            continue;
        }
        const char *rest = tail;
        while (rest < end && (*rest == ' ' || *rest == '\n' || *rest == '\t' || *rest == '\r' || *rest == ';')) rest++;
        bool more = has_more(db, rest, end);
        if (!script && more) {
            sqlite3_finalize(st);
            decref(v_list(rows));
            return raisef("sqlite: parameters need a single statement");
        }
        /* A script's last statement is the result, so what an earlier one selected is dropped */
        decref(v_list(rows));
        rows = list_new(0);
        bool ok = bind_params(db, st, params);
        int rc = SQLITE_ROW;
        while (ok && (rc = sqlite3_step(st)) == SQLITE_ROW) {
            Value row;
            if (!(ok = read_row(st, &row))) break;
            list_push(rows, row);
        }
        if (ok && rc != SQLITE_DONE) ok = raisef("sqlite: %s", sqlite3_errmsg(db));
        /* changes() is the last insert, update or delete, so it counts only if one ran here */
        changes = ok && sqlite3_total_changes(db) != before ? sqlite3_changes(db) : 0;
        sqlite3_finalize(st);
        if (!ok) {
            decref(v_list(rows));
            return false;
        }
        if (!more) break;
        at = rest;
        before = sqlite3_total_changes(db);
    }
    *out = db_result(rows, changes);
    return true;
}

const DbDriver sqlite_driver = {"sqlite", open_sqlite, run_sqlite, close_sqlite};
