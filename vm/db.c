/*
 * Databases: db_open(), db_run() and db_close(), whatever the database. The URL's scheme picks a
 * driver (sqlite.c, pg.c), each built in only if its library was found (make SQLITE=0 or PG=0
 * leaves one out), so a program's global names are these three however many drivers there are.
 * lib/db.gaz is what programs are meant to use.
 */
#include "gazvm.h"

#include <string.h>

static const DbDriver *drivers[] = {
#ifdef GAZ_SQLITE
    &sqlite_driver,
#endif
#ifdef GAZ_PG
    &pg_driver,
#endif
    NULL,
};

/* db_open($url): "sqlite:PATH" (or "sqlite::memory:"), "postgres://..." or "postgresql://..." */
bool db_open(Str *url, Value *out) {
    const char *scheme = NULL;
    const DbDriver *want = NULL;
    static const struct { const char *prefix, *driver; } schemes[] = {
        {"sqlite:", "sqlite"}, {"postgres:", "pg"}, {"postgresql:", "pg"},
    };
    for (size_t i = 0; i < sizeof schemes / sizeof schemes[0]; i++) {
        size_t n = strlen(schemes[i].prefix);
        if (url->len >= n && !memcmp(url->data, schemes[i].prefix, n)) {
            scheme = schemes[i].prefix;
            for (int d = 0; drivers[d]; d++) {
                if (!strcmp(drivers[d]->name, schemes[i].driver)) want = drivers[d];
            }
            if (!want) {
                return raisef("db_open(): %s is not built in: this gazlang was built with make %s=0, or without the library",
                    schemes[i].driver, strcmp(schemes[i].driver, "pg") ? "SQLITE" : "PG");
            }
            break;
        }
    }
    if (!scheme) return raisef("db_open() expects a URL starting sqlite: or postgres:, got %s", url->len ? url->data : "an empty string");
    if (memchr(url->data, '\0', url->len)) return raisef("db_open() expects a URL without NUL bytes");
    void *conn;
    if (!want->open(url, &conn)) return false;
    Db *d = xmalloc(sizeof *d);
    *d = (Db){.rc = 1, .driver = want, .conn = conn};
    counted++;
    *out = v_db(d);
    return true;
}

bool db_run(Db *d, Str *sql, List *params, Value *out) {
    if (!d->conn) return raisef("db_run() on a closed database");
    return d->driver->run(d->conn, sql, params, out);
}

/* Also what freeing the last reference does, so closing twice is fine */
void db_close(Db *d) {
    if (!d->conn) return;
    d->driver->close(d->conn);
    d->conn = NULL;
}

/* m[key] = v, for a key that is C text (map_set copies the key, so ours is dropped) */
void db_put(Map *m, const char *key, size_t len, Value v) {
    Value k = v_str(str_new(key, len));
    map_set(m, k, v);
    decref(k);
}

/* {"rows" => [...], "changes" => N}: what every driver's run() gives back */
Value db_result(List *rows, int64_t changes) {
    Map *m = map_new();
    db_put(m, "rows", 4, v_list(rows));
    db_put(m, "changes", 7, v_int(changes));
    return v_map(m);
}
