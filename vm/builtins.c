/*
 * The builtin functions
 *
 * The loader checks every call's builtin exists and the parser checked its argument count;
 * argument types are checked here, always in the same order, so a call with two bad arguments
 * always names the same one.
 */
/* memmem() is declared by glibc before 2.38 only under _GNU_SOURCE, not _DEFAULT_SOURCE; undeclared,
   C takes it to return an int, which cuts the pointer it gives in half */
#define _GNU_SOURCE
#include "gazvm.h"

#include <dirent.h>
#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <math.h>
#include <poll.h>
#include <spawn.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>
#include <signal.h>
#include <sys/random.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

extern char **environ;

int program_argc;
char *piped_input;
size_t piped_input_len;
char **program_argv;
char *program_exe;   /* argv[0] as main() was given it: program_path() */

/* In the order builtins() gives them */
const BuiltinInfo builtin_info[] = {
    {"len", 1, 1}, {"slice", 2, 3}, {"lower", 1, 1}, {"upper", 1, 1}, {"trim", 1, 2},
    {"split", 2, 3}, {"join", 2, 2}, {"replace", 3, 3}, {"contains", 2, 2}, {"starts_with", 2, 3},
    {"ends_with", 2, 2}, {"index_of", 2, 3}, {"repeat", 2, 2}, {"chr", 1, 1}, {"ord", 1, 1},
    {"to_int", 1, 2}, {"to_float", 1, 2}, {"floor", 1, 1}, {"ceil", 1, 1}, {"round", 1, 2},
    {"abs", 1, 1}, {"intdiv", 2, 2}, {"min", 1, 2}, {"max", 1, 2}, {"sum", 1, 1}, {"to_string", 1, 1},
    {"in_array", 2, 2}, {"has_key", 2, 2}, {"keys", 1, 1}, {"values", 1, 1}, {"last", 1, 1}, {"reverse", 1, 1},
    {"map", 2, 2},
    {"filter", 2, 2}, {"reduce", 3, 3}, {"sort", 1, 2}, {"type_of", 1, 1},
    {"is_a", 2, 2}, {"kind_of", 1, 1}, {"fields", 1, 1}, {"object_id", 1, 1}, {"exit", 0, 1},
    {"read_file", 1, 1}, {"write_file", 2, 2}, {"file_exists", 1, 1}, {"real_path", 1, 1},
    {"cwd", 0, 0}, {"print", 1, 1}, {"print_error", 1, 1}, {"read_stdin", 0, 0}, {"args", 0, 0},
    {"program_path", 0, 0},
    {"builtins", 0, 0}, {"rand_int", 2, 2}, {"rand_float", 0, 0}, {"rand_seed", 0, 1},
    {"run", 1, 2}, {"socket_open", 2, 4}, {"socket_read", 1, 2}, {"socket_write", 2, 2},
    {"socket_close", 1, 1}, {"term_raw", 1, 1}, {"term_read", 0, 1}, {"term_size", 0, 0},
    {"term_is_tty", 1, 1}, {"monotonic_time", 0, 0}, {"std_source", 1, 1},
    {"db_open", 1, 1}, {"db_run", 2, 3}, {"db_close", 1, 1},
    {"socket_listen", 2, 3}, {"socket_accept", 1, 2}, {"socket_port", 1, 1}, {"workers", 1, 1},
    {"time", 0, 0}, {"kind_name", 1, 1}, {"sqrt", 1, 1},
    {"getenv", 1, 1}, {"sleep", 1, 1},
    {"list_dir", 1, 1}, {"is_dir", 1, 1}, {"make_dir", 1, 3}, {"delete_file", 1, 1}, {"delete_dir", 1, 1},
    {"read_line", 0, 0},
    {"random_bytes", 1, 1}, {"sha256", 1, 1}, {"hmac_sha256", 2, 2}, {"pbkdf2_sha256", 4, 4},
    {"scrypt", 6, 6}, {"argon2id", 6, 8},
    {"read_stdin_bytes", 1, 1},
    {"flush_output", 0, 0}, {"worker_recycle", 0, 0},
    {"term_is_virtual", 0, 0},
    {"file_open", 1, 2}, {"file_read_line", 1, 1}, {"file_close", 1, 1},
    {"utf8_valid", 1, 1}, {"utf8_length", 1, 1}, {"utf8_chars", 1, 1}, {"socket_peer", 1, 1},
    {"socket_wait", 2, 2},
    {"file_read", 2, 2}, {"file_write", 2, 2}, {"file_seek", 2, 3}, {"file_info", 1, 2},
    {"rename_file", 2, 2},
    {"chmod", 2, 2}, {"symlink", 2, 2}, {"readlink", 1, 1}, {"file_sync", 1, 1}, {"sync_dir", 1, 1},
    {"file_truncate", 2, 2}, {"set_mtime", 2, 2}, {"chdir", 1, 1},
    {"worker_retire", 0, 0}, {"worker_deadline", 2, 4},
    {"worker_accept", 2, 2}, {"worker_release", 2, 3},
    {"db_error", 1, 1},
};
const int nbuiltins = sizeof builtin_info / sizeof builtin_info[0];

enum {
    B_LEN, B_SLICE, B_LOWER, B_UPPER, B_TRIM, B_SPLIT, B_JOIN, B_REPLACE, B_CONTAINS,
    B_STARTS_WITH, B_ENDS_WITH, B_INDEX_OF, B_REPEAT, B_CHR, B_ORD, B_TO_INT, B_TO_FLOAT, B_FLOOR,
    B_CEIL, B_ROUND, B_ABS, B_INTDIV, B_MIN, B_MAX, B_SUM, B_TO_STRING, B_IN_ARRAY, B_HAS_KEY, B_KEYS,
    B_VALUES, B_LAST, B_REVERSE, B_MAP, B_FILTER, B_REDUCE, B_SORT, B_TYPE_OF, B_IS_A, B_KIND_OF, B_FIELDS, B_OBJECT_ID, B_EXIT, B_READ_FILE,
    B_WRITE_FILE, B_FILE_EXISTS, B_REAL_PATH, B_CWD, B_PRINT, B_PRINT_ERROR, B_READ_STDIN,
    B_ARGS, B_PROGRAM_PATH, B_BUILTINS, B_RAND_INT, B_RAND_FLOAT, B_RAND_SEED, B_RUN,
    B_SOCKET_OPEN, B_SOCKET_READ, B_SOCKET_WRITE, B_SOCKET_CLOSE,
    B_TERM_RAW, B_TERM_READ, B_TERM_SIZE, B_TERM_IS_TTY,
    B_MONOTONIC_TIME, B_STD_SOURCE,
    B_DB_OPEN, B_DB_RUN, B_DB_CLOSE,
    B_SOCKET_LISTEN, B_SOCKET_ACCEPT, B_SOCKET_PORT, B_WORKERS,
    B_TIME, B_KIND_NAME, B_SQRT,
    B_GETENV, B_SLEEP,
    B_LIST_DIR, B_IS_DIR, B_MAKE_DIR, B_DELETE_FILE, B_DELETE_DIR,
    B_READ_LINE,
    B_RANDOM_BYTES, B_SHA256, B_HMAC_SHA256, B_PBKDF2_SHA256, B_SCRYPT, B_ARGON2ID,
    B_READ_STDIN_BYTES,
    B_FLUSH_OUTPUT, B_WORKER_RECYCLE,
    B_TERM_IS_VIRTUAL,
    B_FILE_OPEN, B_FILE_READ_LINE, B_FILE_CLOSE,
    B_UTF8_VALID, B_UTF8_LENGTH, B_UTF8_CHARS, B_SOCKET_PEER,
    B_SOCKET_WAIT,
    B_FILE_READ, B_FILE_WRITE, B_FILE_SEEK, B_FILE_INFO,
    B_RENAME_FILE,
    B_CHMOD, B_SYMLINK, B_READLINK, B_FILE_SYNC, B_SYNC_DIR,
    B_FILE_TRUNCATE, B_SET_MTIME, B_CHDIR,
    B_WORKER_RETIRE, B_WORKER_DEADLINE,
    B_WORKER_ACCEPT, B_WORKER_RELEASE,
    B_DB_ERROR,
};

int builtin_find(const char *name, size_t len) {
    for (int i = 0; i < nbuiltins; i++) {
        if (strlen(builtin_info[i].name) == len && !memcmp(builtin_info[i].name, name, len)) return i;
    }
    return -1;
}

/* The one value per builtin that PUSH_FN pushes, so == on builtins is identity */
Func *builtin_value(int index) {
    static Func *values[sizeof builtin_info / sizeof builtin_info[0]];
    if (!values[index]) {
        Func *f = xcalloc(1, sizeof(Func));
        f->gc.rc = INT64_MAX / 2;
        f->kind = F_BUILTIN;
        f->builtin = index;
        f->name = str_intern(builtin_info[index].name, strlen(builtin_info[index].name));
        values[index] = f;
    }
    return values[index];
}


/* "Function add expects 2 arguments, 1 given", or "1 argument" */
bool raise_arity(const char *what, int lo, int hi, int argc) {
    if (lo == hi) return raisef("%s expects %d argument%s, %d given", what, lo, lo == 1 ? "" : "s", argc);
    return raisef("%s expects %d to %d arguments, %d given", what, lo, hi, argc);
}

/* Type masks for argument checks */
#define M(t) (1u << (t))

/*
 * Random numbers: xoshiro256** seeded through SplitMix64, which is what PHP's
 * Random\Engine\Xoshiro256StarStar does, so a seed always gives the same numbers.
 * Not cryptographically secure.
 */
static uint64_t random_state[4];

static uint64_t rotl(uint64_t x, int k) { return (x << k) | (x >> (64 - k)); }

/* rand_seed($seed): four SplitMix64 outputs from the seed are the state */
static void random_seed(uint64_t seed) {
    for (int i = 0; i < 4; i++) {
        uint64_t z = (seed += 0x9e3779b97f4a7c15u);
        z = (z ^ (z >> 30)) * 0xbf58476d1ce4e5b9u;
        z = (z ^ (z >> 27)) * 0x94d049bb133111ebu;
        random_state[i] = z ^ (z >> 31);
    }
}

/* rand_seed() without a seed, which every program starts with: one from the operating system */
void random_seed_unpredictable(void) {
    uint64_t seed;
    if (getentropy(&seed, sizeof seed) != 0) {
        perror("gazvm: getentropy");
        exit(70);
    }
    random_seed(seed);
}

static uint64_t random_next(void) {
    uint64_t *s = random_state, result = rotl(s[1] * 5, 7) * 9, t = s[1] << 17;
    s[2] ^= s[0];
    s[3] ^= s[1];
    s[1] ^= s[2];
    s[0] ^= s[3];
    s[2] ^= t;
    s[3] = rotl(s[3], 45);
    return result;
}

/* rand_int($min, $max): draw as many low bits as the span $max - $min uses until they are at
   most the span, so every result is equally likely */
static int64_t random_between(int64_t min, int64_t max) {
    uint64_t span = (uint64_t)max - (uint64_t)min, mask = span, offset;
    for (int shift = 1; shift < 64; shift *= 2) mask |= mask >> shift;
    do offset = random_next() & mask; while (offset > span);
    /* Unsigned, since the sum only fits once it is back in range; the cast back is exact */
    return (int64_t)((uint64_t)min + offset);
}

/* Check an argument's type: "len() expects list or map or string, got int" */
static bool want(int builtin, Value v, unsigned mask) {
    if (mask & M(v.type)) return true;
    static const Type order[] = {T_INT, T_FLOAT, T_STRING, T_LIST, T_MAP, T_BOOL, T_NULL, T_KIND, T_OBJECT, T_SOCKET, T_DB, T_FILE};
    Buf b = {0};
    /* Each builtin names its types in its own order; these are those orders */
    const char *names = NULL;
    switch (mask) {
    case M(T_LIST) | M(T_MAP) | M(T_STRING): names = "list or map or string"; break;
    case M(T_INT) | M(T_NULL): names = "int or null"; break;
    case M(T_STRING) | M(T_LIST): names = "string or list"; break;
    case M(T_INT) | M(T_FLOAT): names = "int or float"; break;
    case M(T_LIST) | M(T_MAP): names = "list or map"; break;
    case M(T_FUNCTION) | M(T_KIND): names = "function or kind"; break;
    case M(T_FUNCTION) | M(T_KIND) | M(T_NULL): names = "function or kind or null"; break;
    }
    if (!names) {
        for (size_t i = 0; i < sizeof order / sizeof order[0]; i++) {
            if (mask & M(order[i])) {
                if (b.len) buf_adds(&b, " or ");
                buf_adds(&b, type_name((Value){.type = order[i]}));
            }
        }
    }
    raisef("%s() expects %s, got %s", builtin_info[builtin].name, names ? names : b.data, type_name(v));
    free(b.data);
    return false;
}

static Value v_string(const char *data, size_t len) { return v_str(str_new(data, len)); }

/*
 * map($x, $f) and filter($x, $keep): call back for each element in order; a list gives a list, a
 * map a map with the same keys. The list or map is an argument on the stack, so nothing the
 * callback does can change or free it.
 */
static bool map_or_filter(bool map, Value x, Value f, Value *out) {
    bool is_list = x.type == T_LIST;
    List *list = is_list ? list_new(x.l->len) : NULL;
    Map *m = is_list ? NULL : map_new();
    size_t n = is_list ? x.l->len : x.m->used;
    /* A function that needs two arguments is given the element's index (a list) or key (a map) too */
    bool with_key = callable_min_args(f) >= 2;
    for (size_t i = 0; i < n; i++) {
        if (!is_list && !map_next(x.m, &i)) break;
        Value args[2] = {is_list ? x.l->items[i] : x.m->entries[i].value, is_list ? v_int((int64_t)i) : x.m->entries[i].key};
        Value value = args[0], r;
        if (!call_value(f, args, with_key ? 2 : 1, &r)) {
            decref(is_list ? v_list(list) : v_map(m));
            return false;
        }
        if (!map) {
            bool keep = is_truthy(r);
            decref(r);
            if (!keep) continue;
            incref(value);
            r = value;
        }
        if (is_list) list_push(list, r);
        else map_set(m, x.m->entries[i].key, r);
    }
    *out = is_list ? v_list(list) : v_map(m);
    return true;
}

/* reduce($x, $f, $initial): folds the values left, $carry = $f($carry, $value) */
static bool reduce(Value x, Value f, Value initial, Value *out) {
    Value carry = initial;
    incref(carry);
    size_t n = x.type == T_LIST ? x.l->len : x.m->used;
    /* A function that needs three arguments is given the element's index or key as the third */
    bool with_key = callable_min_args(f) >= 3;
    for (size_t i = 0; i < n; i++) {
        if (x.type == T_MAP && !map_next(x.m, &i)) break;
        Value args[3] = {carry, x.type == T_LIST ? x.l->items[i] : x.m->entries[i].value, x.type == T_LIST ? v_int((int64_t)i) : x.m->entries[i].key}, next;
        bool ok = call_value(f, args, with_key ? 3 : 2, &next);
        decref(carry);
        if (!ok) return false;
        carry = next;
    }
    *out = carry;
    return true;
}

/*
 * sort($x, $compare): a defined merge sort, since a program sees which comparisons are made
 * and in what order: split in the middle, sort each half, merge asking $compare(right, left) and
 * taking from the right only when it is below zero. A null $compare is <=>, with its errors. A
 * new list, or NULL with the error raised.
 */
static List *merge_sort(Value *items, size_t n, Value compare) {
    if (n < 2) {
        List *l = list_new(n);
        for (size_t i = 0; i < n; i++) {
            incref(items[i]);
            list_push(l, items[i]);
        }
        return l;
    }
    size_t middle = n / 2;
    List *left = merge_sort(items, middle, compare);
    if (!left) return NULL;
    List *right = merge_sort(items + middle, n - middle, compare);
    if (!right) {
        decref(v_list(left));
        return NULL;
    }
    List *merged = list_new(n);
    size_t l = 0, r = 0;
    while (l < left->len && r < right->len) {
        Value args[2] = {right->items[r], left->items[l]}, order;
        if (compare.type == T_NULL ? !binary_op(OP_CMP, args[0], args[1], &order) : !call_value(compare, args, 2, &order)) goto fail;
        if (order.type != T_INT) {
            raisef("sort's comparator must return an int, got %s", type_name(order));
            decref(order);
            goto fail;
        }
        Value take = order.i < 0 ? right->items[r++] : left->items[l++];
        incref(take);
        list_push(merged, take);
    }
    for (; l < left->len; l++) incref(left->items[l]), list_push(merged, left->items[l]);
    for (; r < right->len; r++) incref(right->items[r]), list_push(merged, right->items[r]);
    decref(v_list(left));
    decref(v_list(right));
    return merged;
fail:
    decref(v_list(merged));
    decref(v_list(left));
    decref(v_list(right));
    return NULL;
}

/* The part of a length-n string or list that slice() takes, with PHP's substr rules */
static bool slice_bounds(int64_t n, int64_t start, bool has_length, int64_t length, int64_t *from, int64_t *count) {
    if (start > n) return false;
    /* Compared as start < -n rather than -start > n: negating the smallest int overflows */
    if (start < 0) start = start < -n ? 0 : n + start;
    int64_t rest = n - start;
    if (!has_length) {
        length = rest;
    } else if (length < 0) {
        if (length < -rest) return false;
        length = rest + length;
    } else if (length > rest) {
        length = rest;
    }
    *from = start;
    *count = length;
    return length > 0;
}

static bool slice(Value *args, int argc, Value *out) {
    if (!want(B_SLICE, args[1], M(T_INT))) return false;
    Value length = argc > 2 ? args[2] : v_null();
    if (!want(B_SLICE, length, M(T_INT) | M(T_NULL))) return false;
    if (!want(B_SLICE, args[0], M(T_STRING) | M(T_LIST))) return false;
    bool is_list = args[0].type == T_LIST;
    int64_t n = is_list ? (int64_t)args[0].l->len : (int64_t)args[0].s->len;
    int64_t from = 0, count = 0;
    bool any = slice_bounds(n, args[1].i, length.type == T_INT, length.i, &from, &count);
    if (!is_list) {
        *out = v_string(args[0].s->data + from, any ? (size_t)count : 0);
        return true;
    }
    List *l = list_new(any ? (size_t)count : 0);
    for (int64_t i = 0; any && i < count; i++) {
        incref(args[0].l->items[from + i]);
        list_push(l, args[0].l->items[from + i]);
    }
    *out = v_list(l);
    return true;
}

static const char *find(const char *hay, size_t hay_len, const char *needle, size_t needle_len) {
    if (needle_len == 0) return hay;
    return memmem(hay, hay_len, needle, needle_len);
}

/*
 * UTF-8 as RFC 3629 defines it, written out here rather than asked of the C library, whose answer
 * depends on the locale. A character is a lead byte and as many continuation bytes (0x80 to 0xBF)
 * as the lead says: none below 0x80, one after C2 to DF, two after E0 to EF, three after F0 to F4.
 * The first continuation byte has a narrower range after four leads, which is what leaves out the
 * three things a plain count of bytes would let through:
 *   E0 A0..BF   not E0 80..9F, which would spell a character that fits in two bytes (overlong)
 *   ED 80..9F   not ED A0..BF, which would be a surrogate, U+D800 to U+DFFF
 *   F0 90..BF   not F0 80..8F, overlong again
 *   F4 80..8F   not F4 90..BF, which would be above U+10FFFF
 * C0 and C1 could only start an overlong two-byte form and F5 to FF something above U+10FFFF, so
 * they never lead. utf8_valid(), utf8_length() and utf8_chars() all read text through this.
 */
static size_t utf8_character(const unsigned char *text, size_t len, size_t at) {
    unsigned char lead = text[at];
    size_t followers;
    unsigned char lowest = 0x80;
    unsigned char highest = 0xBF;
    if (lead < 0x80) return 1;
    if (lead >= 0xC2 && lead <= 0xDF) {
        followers = 1;
    } else if (lead >= 0xE0 && lead <= 0xEF) {
        followers = 2;
        if (lead == 0xE0) lowest = 0xA0;
        if (lead == 0xED) highest = 0x9F;
    } else if (lead >= 0xF0 && lead <= 0xF4) {
        followers = 3;
        if (lead == 0xF0) lowest = 0x90;
        if (lead == 0xF4) highest = 0x8F;
    } else {
        return 0;
    }
    if (len - at <= followers) return 0;   /* cut short: the string ends inside the character */
    if (text[at + 1] < lowest || text[at + 1] > highest) return 0;
    for (size_t i = 2; i <= followers; i++) {
        if (text[at + i] < 0x80 || text[at + i] > 0xBF) return 0;
    }
    return followers + 1;
}

/* Where the first character that isn't well formed starts, or the string's length if none */
static size_t utf8_first_bad(Str *s) {
    const unsigned char *text = (const unsigned char *)s->data;
    size_t at = 0;
    while (at < s->len) {
        size_t size = utf8_character(text, s->len, at);
        if (size == 0) return at;
        at += size;
    }
    return at;
}

/* utf8_length() and utf8_chars(): the characters counted, or made into a list of strings */
static bool utf8_characters(int index, Str *s, Value *out) {
    size_t bad = utf8_first_bad(s);
    if (bad < s->len) {
        return raisef("%s() expects well formed UTF-8, but byte %zu doesn't start a well formed character",
                      builtin_info[index].name, bad);
    }
    const unsigned char *text = (const unsigned char *)s->data;
    List *chars = index == B_UTF8_CHARS ? list_new(0) : NULL;
    int64_t count = 0;
    for (size_t at = 0; at < s->len; count++) {
        size_t size = utf8_character(text, s->len, at);
        if (chars && size == 1) list_push(chars, v_str(str_byte(text[at])));
        else if (chars) list_push(chars, v_string(s->data + at, size));
        at += size;
    }
    *out = chars ? v_list(chars) : v_int(count);
    return true;
}

/* split($s, $sep, $limit): at most $limit parts, the last holding the rest of the string */
static Value split(Str *s, Str *sep, int64_t limit) {
    List *l = list_new(0);
    if (sep->len == 0) {
        size_t i = 0;
        for (; i < s->len && (int64_t)l->len < limit - 1; i++) list_push(l, v_str(str_byte((unsigned char)s->data[i])));
        if (i < s->len) list_push(l, v_string(s->data + i, s->len - i));
        return v_list(l);
    }
    const char *p = s->data, *end = s->data + s->len;
    while ((int64_t)l->len < limit - 1) {
        const char *hit = find(p, (size_t)(end - p), sep->data, sep->len);
        if (!hit) break;
        list_push(l, v_string(p, (size_t)(hit - p)));
        p = hit + sep->len;
    }
    list_push(l, v_string(p, (size_t)(end - p)));
    return v_list(l);
}

static Value replace(Str *s, Str *search, Str *with) {
    Buf b = {0};
    const char *p = s->data, *end = s->data + s->len;
    for (;;) {
        const char *hit = find(p, (size_t)(end - p), search->data, search->len);
        if (!hit) break;
        buf_add(&b, p, (size_t)(hit - p));
        buf_add_str(&b, with);
        p = hit + search->len;
    }
    buf_add(&b, p, (size_t)(end - p));
    return v_str(buf_to_str(&b));
}

/* A string, or a float too large for an int, that can't be converted gives the default when there
   is one (to_int($s, null)); any other type is still an error, as it is a mistake, not bad input */
static bool fall_back(Value v, int argc, Value fallback, Value *out) {
    if (argc < 2 || (v.type != T_STRING && v.type != T_FLOAT)) return false;
    incref(fallback);
    *out = fallback;
    return true;
}

static bool to_int(Value v, int argc, Value fallback, Value *out) {
    int64_t n;
    switch (v.type) {
    case T_INT: *out = v; return true;
    case T_BOOL: *out = v_int(v.b); return true;
    case T_STRING:
        if (parse_integer(v.s->data, v.s->len, &n)) {
            *out = v_int(n);
            return true;
        }
        break;
    case T_FLOAT:
        /* Truncated toward zero, when the result fits: -2^63 <= value < 2^63 */
        if (v.f >= -9.2233720368547758E+18 && v.f < 9.2233720368547758E+18) {
            *out = v_int((int64_t)v.f);
            return true;
        }
        break;
    default:
        break;
    }
    if (fall_back(v, argc, fallback, out)) return true;
    Buf b = {0};
    buf_adds(&b, "to_int() cannot convert ");
    if (v.type == T_STRING) quote(v.s, &b);
    else if (v.type == T_FLOAT) format_float(v.f, &b);
    else buf_adds(&b, type_name(v));
    return raise_str(buf_to_str(&b));
}

static bool all_digits(Str *s) {
    size_t i = s->len && s->data[0] == '-' ? 1 : 0;
    if (i == s->len) return false;
    for (; i < s->len; i++) {
        if (s->data[i] < '0' || s->data[i] > '9') return false;
    }
    return true;
}

static bool to_float(Value v, int argc, Value fallback, Value *out) {
    switch (v.type) {
    case T_INT: *out = v_float((double)v.i); return true;
    case T_FLOAT: *out = v; return true;
    case T_BOOL: *out = v_float(v.b ? 1.0 : 0.0); return true;
    case T_STRING: {
        Value n;
        if (parse_number(v.s->data, v.s->len, &n)) {
            *out = n.type == T_INT ? v_float((double)n.i) : n;
            return true;
        }
        /* Integer digits too large for an int are still a fine float: "99999999999999999999" is 1.0E+20 */
        if (all_digits(v.s)) {
            *out = v_float(strtod(v.s->data, NULL));
            return true;
        }
        break;
    }
    default:
        break;
    }
    if (fall_back(v, argc, fallback, out)) return true;
    Buf b = {0};
    buf_adds(&b, "to_float() cannot convert ");
    if (v.type == T_STRING) quote(v.s, &b);
    else buf_adds(&b, type_name(v));
    return raise_str(buf_to_str(&b));
}

/* PHP's round() with PHP_ROUND_HALF_UP, from ext/standard/math.c (_php_math_round), step for step */
static double php_intpow10(int power) {
    static const double powers[] = {1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11,
                                    1e12, 1e13, 1e14, 1e15, 1e16, 1e17, 1e18, 1e19, 1e20, 1e21, 1e22};
    if (power < 0 || power > 22) return pow(10.0, (double)power);
    return powers[power];
}

static double php_round(double value, int places) {
    if (!isfinite(value) || value == 0.0) return value;
    if (places == 0 && value == trunc(value)) return value;
    places = places < INT_MIN + 1 ? INT_MIN + 1 : places;
    double exponent = php_intpow10(abs(places));
    double tmp, tmp2;
    if (value >= 0.0) {
        tmp = floor(places > 0 ? value * exponent : value / exponent);
        tmp2 = tmp + 1.0;
    } else {
        tmp = ceil(places > 0 ? value * exponent : value / exponent);
        tmp2 = tmp - 1.0;
    }
    if ((places > 0 ? tmp2 / exponent : tmp2 * exponent) == value) tmp = tmp2;
    if (fabs(tmp) >= 1e16) return value;
    /* Half up: away from zero when the value is at or past the halfway point */
    double edge = places > 0 ? fabs((tmp + copysign(0.5, tmp)) / exponent) : fabs((tmp + copysign(0.5, tmp)) * exponent);
    if (fabs(value) >= edge) tmp = tmp + copysign(1.0, tmp);
    if (abs(places) < 23) {
        tmp = places > 0 ? tmp / exponent : tmp * exponent;
    } else {
        /* PHP reads the text back with zend_strtod, which gives a zero without its sign
           ("-0.000000e30" is 0.0); the C library's strtod keeps the sign, except when the
           exponent is too far out, where it drops it too */
        if (tmp == 0.0) return 0.0;
        char buf[40];
        snprintf(buf, 39, "%15fe%d", tmp, -places);
        buf[39] = '\0';
        tmp = strtod(buf, NULL);
        if (!isfinite(tmp)) tmp = value;
    }
    return tmp;
}

/* min() and max() order two numbers or two strings as < does; in a list, each against the
   smallest or largest so far */
static bool extreme(int builtin, Value a, Value b, bool in_list, int *cmp) {
    bool na = a.type == T_INT || a.type == T_FLOAT, nb = b.type == T_INT || b.type == T_FLOAT;
    if (na && nb) {
        *cmp = compare_numbers(a, b);
        return true;
    }
    if (a.type == T_STRING && b.type == T_STRING) {
        int c = str_cmp(a.s, b.s);
        *cmp = (c > 0) - (c < 0);
        return true;
    }
    if (in_list) return raisef("%s() expects a list of numbers or of strings, got %s and %s", builtin_info[builtin].name, type_name(a), type_name(b));
    return raisef("%s() expects two numbers or two strings, got %s and %s", builtin_info[builtin].name, type_name(a), type_name(b));
}

/* The values of a list, or of a map in order, as one array; a map's are gathered first */
static Value *values_of(Value v, size_t *n, Value **gathered) {
    *gathered = NULL;
    if (v.type == T_LIST) {
        *n = v.l->len;
        return v.l->items;
    }
    *gathered = malloc((v.m->count ? v.m->count : 1) * sizeof(Value));
    *n = 0;
    for (size_t i = 0; map_next(v.m, &i); i++) (*gathered)[(*n)++] = v.m->entries[i].value;
    return *gathered;
}

/* realpath(3), with "" and a NUL byte being nothing there. PHP also refuses a path in which
   anything followed by a slash is not a directory ("file/", "file/.."), which macOS allows. */
static char *resolve(Str *path) {
    if (path->len == 0 || memchr(path->data, '\0', path->len)) return NULL;
    char *prefix = xmalloc(path->len + 1);
    for (size_t i = 1; i < path->len; i++) {
        if (path->data[i] != '/' || path->data[i - 1] == '/') continue;
        memcpy(prefix, path->data, i);
        prefix[i] = '\0';
        struct stat st;
        if (stat(prefix, &st) != 0 || !S_ISDIR(st.st_mode)) {
            free(prefix);
            return NULL;
        }
    }
    free(prefix);
    return realpath(path->data, NULL);
}

/*
 * run()'s $input as a file whose name is already gone, open at its start, so only the program
 * reads it and nothing is left behind; -1 with errno set if it can't be made. A pipe would have
 * to be written while the outputs are read, and would send us SIGPIPE if the program stopped
 * reading.
 */
static int input_file(Str *input) {
    const char *dir = getenv("TMPDIR");
    Buf path = {0};
    buf_adds(&path, dir && *dir ? dir : "/tmp");
    buf_adds(&path, "/gaz-run-XXXXXX");
    buf_add(&path, "", 1);
    int fd = mkstemp(path.data);
    if (fd >= 0) unlink(path.data);
    free(path.data);
    if (fd < 0) return -1;
    for (size_t done = 0; done < input->len;) {
        ssize_t n = write(fd, input->data + done, input->len - done);
        if (n < 0 && errno == EINTR) continue;
        if (n < 0) {
            int err = errno;
            close(fd);
            errno = err;
            return -1;
        }
        done += (size_t)n;
    }
    lseek(fd, 0, SEEK_SET);
    fcntl(fd, F_SETFD, FD_CLOEXEC);
    return fd;
}

/*
 * run($argv, $input = ""): start argv[0], found on PATH, with the rest as its arguments and no
 * shell between, so nothing in them is ever interpreted. It inherits the environment and working
 * directory and reads $input (/dev/null when there is none). Both outputs are read as they come
 * (poll), since a program that fills one pipe while we wait on the other would never finish.
 */
static bool run_process(List *args, Str *input, Value *out) {
    if (args->len == 0) return raisef("run() expects a program to run, got an empty list");
    for (size_t i = 0; i < args->len; i++) {
        Value v = args->items[i];
        if (v.type != T_STRING) return raisef("run() expects a list of strings, got %s", type_name(v));
        if (memchr(v.s->data, '\0', v.s->len)) return raisef("run() arguments can't contain a NUL byte");
    }
    int in = -1;
    if (input && input->len > 0 && (in = input_file(input)) < 0) {
        return raisef("Cannot run a program: no file for its input: %s", strerror(errno));
    }
    /* stdout's and stderr's pipes, each [read end, write end] */
    int pipes[2][2];
    if (pipe(pipes[0]) != 0) {
        int err = errno;
        if (in >= 0) close(in);
        return raisef("Cannot run a program: %s", strerror(err));
    }
    if (pipe(pipes[1]) != 0) {
        int err = errno;
        close(pipes[0][0]);
        close(pipes[0][1]);
        if (in >= 0) close(in);
        return raisef("Cannot run a program: %s", strerror(err));
    }
    /* Closed in the child when it starts, so it keeps only the copies made below as 1 and 2 */
    for (int i = 0; i < 4; i++) fcntl(pipes[i / 2][i % 2], F_SETFD, FD_CLOEXEC);
    posix_spawn_file_actions_t actions;
    posix_spawn_file_actions_init(&actions);
    if (in >= 0) posix_spawn_file_actions_adddup2(&actions, in, 0);
    else posix_spawn_file_actions_addopen(&actions, 0, "/dev/null", O_RDONLY, 0);
    posix_spawn_file_actions_adddup2(&actions, pipes[0][1], 1);
    posix_spawn_file_actions_adddup2(&actions, pipes[1][1], 2);
    char **argv = xmalloc((args->len + 1) * sizeof *argv);
    for (size_t i = 0; i < args->len; i++) argv[i] = args->items[i].s->data;
    argv[args->len] = NULL;
    /* SIGPIPE at its default in the program, whatever gaz does with it (a worker ignores it, and an
       ignored signal stays ignored through exec), so `yes | head` in it ends as anywhere else */
    posix_spawnattr_t attr;
    posix_spawnattr_init(&attr);
    sigset_t pipe_default;
    sigemptyset(&pipe_default);
    sigaddset(&pipe_default, SIGPIPE);
    posix_spawnattr_setsigdefault(&attr, &pipe_default);
    posix_spawnattr_setflags(&attr, POSIX_SPAWN_SETSIGDEF);
    pid_t pid;
    int err = posix_spawnp(&pid, argv[0], &actions, &attr, argv, environ);
    posix_spawnattr_destroy(&attr);
    posix_spawn_file_actions_destroy(&actions);
    free(argv);
    close(pipes[0][1]);
    close(pipes[1][1]);
    if (in >= 0) close(in);
    if (err != 0) {
        close(pipes[0][0]);
        close(pipes[1][0]);
        Buf m = {0};
        buf_adds(&m, "Cannot run ");
        quote(args->items[0].s, &m);
        buf_adds(&m, ": ");
        buf_adds(&m, strerror(err));
        return raise_str(buf_to_str(&m));
    }
    Buf text[2] = {{0}, {0}};
    struct pollfd fds[2] = {{.fd = pipes[0][0], .events = POLLIN}, {.fd = pipes[1][0], .events = POLLIN}};
    char chunk[65536];
    /* poll() skips a negative fd, which is how a stream that has ended drops out */
    while (fds[0].fd >= 0 || fds[1].fd >= 0) {
        /* After EINTR the revents are stale, and a read on one could block: poll again */
        if (poll(fds, 2, -1) < 0) {
            if (errno == EINTR) continue;
            break;
        }
        for (int i = 0; i < 2; i++) {
            if (fds[i].fd < 0 || !fds[i].revents) continue;
            ssize_t n = read(fds[i].fd, chunk, sizeof chunk);
            if (n > 0) {
                buf_add(&text[i], chunk, (size_t)n);
            } else if (n == 0 || errno != EINTR) {
                close(fds[i].fd);
                fds[i].fd = -1;
            }
        }
    }
    /* Only if poll() failed; the program then gets SIGPIPE rather than blocking on a full pipe */
    for (int i = 0; i < 2; i++) if (fds[i].fd >= 0) close(fds[i].fd);
    int status, waited;
    while ((waited = waitpid(pid, &status, 0)) < 0 && errno == EINTR) {}
    if (waited < 0) {
        free(text[0].data);
        free(text[1].data);
        return raisef("Cannot wait for a program: %s", strerror(errno));
    }
    /* Killed by a signal: minus its number, which no exit code can be */
    int64_t code = WIFEXITED(status) ? WEXITSTATUS(status) : WIFSIGNALED(status) ? -WTERMSIG(status) : -1;
    Map *m = map_new();
    const char *names[] = {"status", "stdout", "stderr"};
    Value values[] = {v_int(code), v_str(buf_to_str(&text[0])), v_str(buf_to_str(&text[1]))};
    for (int i = 0; i < 3; i++) {
        Value name = v_str(str_cstr(names[i]));
        map_set(m, name, values[i]);
        decref(name);
    }
    *out = v_map(m);
    return true;
}

/* 'Cannot list directory "tmp/x": No such file or directory': the path quoted, so a NUL byte or a
   newline in it shows, and the system's reason, whose words are the same on Linux and macOS */
static bool raise_path(const char *what, Str *path, int err) {
    Buf m = {0};
    buf_adds(&m, what);
    buf_addc(&m, ' ');
    quote(path, &m);
    buf_adds(&m, ": ");
    buf_adds(&m, strerror(err));
    return raise_str(buf_to_str(&m));
}

/* A file mode, as file_open() takes it, and what it asks of fopen() */
typedef struct {
    const char *name;
    const char *fopen_mode;     /* "b" changes nothing on POSIX, and says these are bytes */
    bool readable, writable;
} FileMode;

static const FileMode FILE_MODES[] = {
    {"r", "rb", true, false},       /* read; the file must exist */
    {"w", "wb", false, true},       /* write, making the file or emptying it */
    {"a", "ab", false, true},       /* write at the end, making the file if it isn't there */
    {"r+", "r+b", true, true},      /* read and write; the file must exist */
};
#define NFILE_MODES (sizeof FILE_MODES / sizeof FILE_MODES[0])

/* file_open($path, $mode = "r"): a handle on a file, to read a line or some bytes at a time, to
   write, or to seek. A file "w" or "a" makes gets 0666 less the umask, as fopen() gives it. A
   directory is refused in every mode: fopen() opens one for reading on Linux and macOS, and it
   then fails at the first read. A pipe or /dev/stdin is fine for reading, since reading as it
   arrives is what a handle is for. */
static bool open_file(Str *path, Str *mode_name, Value *out) {
    const FileMode *mode = NULL;
    for (size_t i = 0; i < NFILE_MODES; i++) {
        if (strlen(FILE_MODES[i].name) == mode_name->len && !memcmp(FILE_MODES[i].name, mode_name->data, mode_name->len)) {
            mode = &FILE_MODES[i];
        }
    }
    if (!mode) {
        Buf m = {0};
        buf_adds(&m, "file_open() mode must be \"r\", \"w\", \"a\" or \"r+\", got ");
        quote(mode_name, &m);
        return raise_str(buf_to_str(&m));
    }
    if (memchr(path->data, '\0', path->len)) return raise_path("Cannot open", path, ENOENT);
    FILE *fp = fopen(path->data, mode->fopen_mode);
    if (!fp) return raise_path("Cannot open", path, errno);
    struct stat st;
    if (fstat(fileno(fp), &st) == 0 && S_ISDIR(st.st_mode)) {
        fclose(fp);
        return raise_path("Cannot open", path, EISDIR);
    }
    /* Not for a run() child to inherit, as no socket is */
    fcntl(fileno(fp), F_SETFD, FD_CLOEXEC);
    File *f = xmalloc(sizeof *f);
    counted++;
    incref(v_str(path));
    *f = (File){.rc = 1, .fp = fp, .owner = vm_process, .path = path, .mode = mode->name,
                .readable = mode->readable, .writable = mode->writable, .last = FILE_IDLE};
    *out = v_file(f);
    return true;
}

/* 'Cannot read "data.txt": Input/output error', for a handle */
static bool raise_file(const char *verb, File *f, int err) {
    return raise_path(verb, f->path, err);
}

/* What every handle builtin checks first: the file is open and this process's */
static bool open_here(const char *builtin, File *f) {
    if (!f->fp) return raisef("%s() on a closed file", builtin);
    if (f->owner != vm_process) return refuse_inherited(builtin, "file");
    return true;
}

/* And then that it can do what is asked: 'file_read() on a file opened with "w", which can't be
   read'. C asks for a flush between a write and a read and a seek between a read and a write; a
   seek to where the file already is does both, and fails only where there is no position at all,
   a pipe, where there is no mixing either. */
static bool usable_file(const char *builtin, File *f, bool to_write) {
    if (!open_here(builtin, f)) return false;
    if (to_write && !f->writable) return raisef("%s() on a file opened with \"%s\", which can't be written", builtin, f->mode);
    if (!to_write && !f->readable) return raisef("%s() on a file opened with \"%s\", which can't be read", builtin, f->mode);
    if (f->last == (to_write ? FILE_READING : FILE_WRITING)) fseeko(f->fp, 0, SEEK_CUR);
    f->last = to_write ? FILE_WRITING : FILE_READING;
    return true;
}

/* The end of a short read. C's end-of-file flag sticks, and a stream that has it gives nothing
   more, so it is cleared, and a file that someone is still writing to is read further next time.
   An error is cleared too, once reported. */
static bool finish_read(File *f, int err) {
    bool failed = ferror(f->fp);
    clearerr(f->fp);
    if (failed) return raise_file("Cannot read", f, err);
    return true;
}

/* file_read_line($file): the next line without its "\n" or "\r\n", or null at the end, as
   read_line() gives one of standard input */
static bool read_file_line(File *f, Value *out) {
    if (!usable_file("file_read_line", f, false)) return false;
    char *line = NULL;
    size_t cap = 0;
    ssize_t n = getline(&line, &cap, f->fp);
    if (n < 0) {
        int err = errno;
        free(line);
        *out = v_null();
        return finish_read(f, err);
    }
    if (n > 0 && line[n - 1] == '\n') n -= n > 1 && line[n - 2] == '\r' ? 2 : 1;
    *out = v_str(str_new(line, (size_t)n));
    free(line);
    return true;
}

/* file_read($file, $length): up to $length bytes, fewer only at the end of the file, and "" there.
   The cap keeps a mistake from asking for all the memory there is; a program wanting more reads
   again. The bytes are read into a heap buffer, never call_builtin()'s frame. */
#define FILE_READ_MAX (16 * 1024 * 1024)

static bool read_file_bytes(File *f, int64_t length, Value *out) {
    if (length < 1 || length > FILE_READ_MAX) {
        return raisef("file_read() length must be 1 to %d, got %lld", FILE_READ_MAX, (long long)length);
    }
    if (!usable_file("file_read", f, false)) return false;
    char *bytes = xmalloc((size_t)length);
    size_t n = fread(bytes, 1, (size_t)length, f->fp);
    int err = errno;
    if (n < (size_t)length && !finish_read(f, err)) {
        free(bytes);
        return false;
    }
    *out = v_str(str_new(bytes, n));
    free(bytes);
    return true;
}

/* file_write($file, $data): all of it, into stdio's buffer first, so a failure may only show
   when the buffer is written out: at a later write, a seek, or file_close() */
static bool write_file_bytes(File *f, Str *data) {
    if (!usable_file("file_write", f, true)) return false;
    if (fwrite(data->data, 1, data->len, f->fp) != data->len) {
        int err = errno;
        clearerr(f->fp);
        return raise_file("Cannot write", f, err);
    }
    return true;
}

/* file_seek($file, $offset, $from = "start"): the new position, counted from the start of the
   file. A seek writes out what is buffered and clears the end of the file, so a read after it
   reads on. */
static bool seek_file(File *f, int64_t offset, Str *from, Value *out) {
    static const char *const FROM[] = {"start", "current", "end"};
    static const int WHENCE[] = {SEEK_SET, SEEK_CUR, SEEK_END};
    int whence = -1;
    for (int i = 0; i < 3; i++) {
        if (strlen(FROM[i]) == from->len && !memcmp(FROM[i], from->data, from->len)) whence = WHENCE[i];
    }
    if (whence < 0) {
        Buf m = {0};
        buf_adds(&m, "file_seek() from must be \"start\", \"current\" or \"end\", got ");
        quote(from, &m);
        return raise_str(buf_to_str(&m));
    }
    if (!open_here("file_seek", f)) return false;
    /* off_t is 64 bits wherever gaz builds: always on macOS, and on 64-bit Linux */
    if (fseeko(f->fp, (off_t)offset, whence) != 0) return raise_file("Cannot seek", f, errno);
    f->last = FILE_IDLE;
    off_t at = ftello(f->fp);
    if (at < 0) return raise_file("Cannot seek", f, errno);
    *out = v_int((int64_t)at);
    return true;
}

/* What freeing a file does, and file_close() of one a worker inherited; closing twice does
   nothing. An inherited file shares its offset with the process that opened it, and fclose() may
   move that offset back to where this process's reading had got to (POSIX asks it to for a file
   being read, and macOS does), moving it for the other process too, so its descriptor is pointed
   at /dev/null first (see workers.c), which is where anything still buffered goes too. Freeing
   can't raise, so a failed write is lost here: close_file() is the close that tells. */
void file_close(File *f) {
    if (!f->fp) return;
    if (f->owner != vm_process) abandon_fd(fileno(f->fp));
    fclose(f->fp);
    f->fp = NULL;
}

/* file_close($file) as the program asks for it: what is buffered is written out first, and a
   failure (a full disk, a file grown past its limit) is an error, so the program learns that its
   data didn't arrive */
static bool close_file(File *f) {
    if (!f->fp || f->owner != vm_process || !f->writable) {
        file_close(f);
        return true;
    }
    int err = fflush(f->fp) == 0 ? 0 : errno;
    if (fclose(f->fp) != 0 && !err) err = errno;
    f->fp = NULL;
    if (err) return raise_file("Cannot write", f, err);
    return true;
}

/* file_info($path, $follow = true): what is at $path, or null if nothing is. With $follow false a
   symbolic link is itself, "link", rather than what it points at. */
static bool file_info(Str *path, bool follow, Value *out) {
    struct stat st;
    bool named = !memchr(path->data, '\0', path->len);
    int err = 0;
    if (!named) err = ENOENT;
    else if ((follow ? stat(path->data, &st) : lstat(path->data, &st)) != 0) err = errno;
    /* Nothing there, or a path through something that isn't a directory, which names nothing */
    if (err == ENOENT || err == ENOTDIR) {
        *out = v_null();
        return true;
    }
    if (err) return raise_path("Cannot get information about", path, err);
    const char *kind = S_ISREG(st.st_mode) ? "file" : S_ISDIR(st.st_mode) ? "dir" : S_ISLNK(st.st_mode) ? "link" : "other";
    const char *names[] = {"kind", "size", "mtime", "mode"};
    Value values[] = {v_str(str_cstr(kind)), v_int((int64_t)st.st_size), v_int((int64_t)st.st_mtime),
                      v_int((int64_t)(st.st_mode & 07777))};
    Map *m = map_new();
    for (int i = 0; i < 4; i++) {
        Value name = v_str(str_cstr(names[i]));
        map_set(m, name, values[i]);
        decref(name);
    }
    *out = v_map(m);
    return true;
}

/* rename_file($from, $to): rename(2), which replaces a file at $to in one step, so a reader sees
   the old file or the new one and never half of either. Between file systems it can't, and says
   so in words of its own, since strerror()'s for EXDEV differ between Linux and macOS. */
static bool rename_path(Str *from, Str *to) {
    int err = 0;
    if (memchr(from->data, '\0', from->len) || memchr(to->data, '\0', to->len)) err = ENOENT;
    else if (rename(from->data, to->data) != 0) err = errno;
    if (!err) return true;
    Buf m = {0};
    buf_adds(&m, "Cannot rename ");
    quote(from, &m);
    buf_adds(&m, " to ");
    quote(to, &m);
    buf_adds(&m, ": ");
    buf_adds(&m, err == EXDEV ? "they are on different file systems" : strerror(err));
    return raise_str(buf_to_str(&m));
}

/* Whether a path names nothing because it holds a NUL byte, which no name can */
static bool has_nul(const Str *path) {
    return memchr(path->data, '\0', path->len) != NULL;
}

/* Permission bits as chmod() and make_dir() take them: what file_info()'s "mode" gives, the
   setuid, setgid and sticky bits included, 0 to 0o7777 */
#define MODE_BITS 07777

static bool check_mode(const char *builtin, int64_t mode) {
    if (mode < 0 || mode > MODE_BITS) return raisef("%s() mode must be 0 to 0o7777, got %lld", builtin, (long long)mode);
    return true;
}

/* chmod($path, $mode): chmod(2), which follows a symbolic link to what it points at */
static bool change_mode(Str *path, int64_t mode) {
    if (!check_mode("chmod", mode)) return false;
    int err = has_nul(path) ? ENOENT : chmod(path->data, (mode_t)mode) == 0 ? 0 : errno;
    if (err) return raise_path("Cannot change the mode of", path, err);
    return true;
}

/* 'Cannot make link "l" to "t": File exists', both quoted as rename_file() quotes its two */
static bool raise_link(Str *link, Str *target, int err) {
    Buf m = {0};
    buf_adds(&m, "Cannot make link ");
    quote(link, &m);
    buf_adds(&m, " to ");
    quote(target, &m);
    buf_adds(&m, ": ");
    buf_adds(&m, strerror(err));
    return raise_str(buf_to_str(&m));
}

/* symlink($target, $link): a symbolic link at $link whose text is $target exactly as given, which
   needn't exist and is resolved from the link's directory when it is followed. An empty target is
   ENOENT, written out, since systems differ on it. */
static bool make_link(Str *target, Str *link) {
    int err = 0;
    if (has_nul(target) || has_nul(link) || target->len == 0) err = ENOENT;
    else if (symlink(target->data, link->data) != 0) err = errno;
    if (err) return raise_link(link, target, err);
    return true;
}

/* readlink($path): a symbolic link's text, as symlink() was given it. Read into a heap buffer
   that grows until the text fits, since a link's length isn't limited to PATH_MAX everywhere.
   What isn't a link is said in words of its own, as strerror()'s EINVAL is "Invalid argument". */
static bool read_link(Str *path, Value *out) {
    int err = has_nul(path) ? ENOENT : 0;
    size_t cap = 256;
    char *text = NULL;
    ssize_t n = -1;
    while (!err) {
        text = xrealloc(text, cap);
        n = readlink(path->data, text, cap);
        if (n < 0) err = errno;
        else if ((size_t)n < cap) break;
        else cap *= 2;
    }
    if (err) {
        free(text);
        if (err != EINVAL) return raise_path("Cannot read link", path, err);
        Buf m = {0};
        buf_adds(&m, "Cannot read link ");
        quote(path, &m);
        buf_adds(&m, ": it is not a symbolic link");
        return raise_str(buf_to_str(&m));
    }
    *out = v_str(str_new(text, (size_t)n));
    free(text);
    return true;
}

/*
 * Making what was written durable, GazLang's rule rather than each system's: fsync(2) on Linux,
 * but on macOS fsync() only hands the data to the drive, which may keep it in its own cache
 * through a power cut, so there it is fcntl(F_FULLFSYNC), which asks the drive to write it out,
 * and fsync() only where F_FULLFSYNC fails (a file system that doesn't support it). 0 or errno.
 */
static int sync_fd(int fd) {
#ifdef F_FULLFSYNC
    if (fcntl(fd, F_FULLFSYNC) == 0) return 0;
#endif
    return fsync(fd) == 0 ? 0 : errno;
}

/* file_sync($file): what is in stdio's buffer is written out, and then made durable */
static bool sync_file(File *f) {
    if (!usable_file("file_sync", f, true)) return false;
    int err = fflush(f->fp) == 0 ? 0 : errno;
    if (!err) err = sync_fd(fileno(f->fp));
    if (err) return raise_file("Cannot sync", f, err);
    return true;
}

/* sync_dir($path): a directory's entries made durable, so a file just made or renamed into it is
   still there after a power cut. file_open() refuses a directory, so this opens it itself. */
static bool sync_dir(Str *path) {
    int fd = has_nul(path) ? -1 : open(path->data, O_RDONLY | O_DIRECTORY | O_CLOEXEC);
    int err = has_nul(path) ? ENOENT : fd < 0 ? errno : sync_fd(fd);
    if (fd >= 0) close(fd);
    if (err) return raise_path("Cannot sync", path, err);
    return true;
}

/* file_truncate($file, $length): the file cut to $length bytes, or grown to it with zero bytes,
   after what is buffered is written out; the position stays where it was */
static bool truncate_file(File *f, int64_t length) {
    if (length < 0) return raisef("file_truncate() length must be 0 or more, got %lld", (long long)length);
    if (!usable_file("file_truncate", f, true)) return false;
    int err = fflush(f->fp) == 0 ? 0 : errno;
    if (!err && ftruncate(fileno(f->fp), (off_t)length) != 0) err = errno;
    if (err) return raise_file("Cannot truncate", f, err);
    return true;
}

/* set_mtime($path, $seconds): the access and modification times both set to $seconds since 1970,
   following a symbolic link, as file_info() reads them */
static bool set_mtime(Str *path, int64_t seconds) {
    struct timespec times[2] = {{.tv_sec = (time_t)seconds}, {.tv_sec = (time_t)seconds}};
    int err = has_nul(path) ? ENOENT : utimensat(AT_FDCWD, path->data, times, 0) == 0 ? 0 : errno;
    if (err) return raise_path("Cannot set the time of", path, err);
    return true;
}

/* chdir($path): the working directory of this process, and so of the programs run() starts; a
   worker's is its own, since each is a process */
static bool change_dir(Str *path) {
    int err = has_nul(path) ? ENOENT : chdir(path->data) == 0 ? 0 : errno;
    if (err) return raise_path("Cannot change directory to", path, err);
    return true;
}

/* make_dir($path, true): every directory along $path that isn't there, a level at a time, and one
   that is there already is fine. 0, or the errno that stopped it, ENOTDIR for something in the
   way that isn't a directory. The copy it cuts up is on the heap, not in call_builtin()'s frame.
   Only the last level gets $mode, as mkdir -p -m does: a level above with fewer bits than 0700
   would refuse the next one inside it. */
static int make_dirs(const Str *path, mode_t mode) {
    char *copy = xmalloc(path->len + 1);
    memcpy(copy, path->data, path->len);
    copy[path->len] = '\0';
    int err = 0;
    /* Where the last level ends, before any trailing slashes */
    size_t last = path->len;
    while (last > 1 && copy[last - 1] == '/') last--;
    /* Each '/' after the first byte ends a level, and so does the end of the path */
    for (size_t end = 1; end <= path->len && !err; end++) {
        if (end < path->len && copy[end] != '/') continue;
        copy[end] = '\0';
        struct stat st;
        if (mkdir(copy, end >= last ? mode : 0777) != 0) {
            err = errno;
            if (err == EEXIST) err = stat(copy, &st) == 0 && S_ISDIR(st.st_mode) ? 0 : ENOTDIR;
        }
        if (end < path->len) copy[end] = '/';
    }
    free(copy);
    return err;
}

static int by_bytes(const void *a, const void *b) { return str_cmp(((const Value *)a)->s, ((const Value *)b)->s); }

/* list_dir($path): the entries' names but . and .., sorted byte by byte, since the order
   readdir() gives differs between file systems */
static bool list_dir(Str *path, Value *out) {
    /* No name holds a NUL byte, so a path with one names nothing */
    if (memchr(path->data, '\0', path->len)) return raise_path("Cannot list directory", path, ENOENT);
    DIR *dir = opendir(path->data);
    if (!dir) return raise_path("Cannot list directory", path, errno);
    List *l = list_new(0);
    struct dirent *entry;
    for (;;) {
        errno = 0;
        if (!(entry = readdir(dir))) break;
        if (strcmp(entry->d_name, ".") && strcmp(entry->d_name, "..")) list_push(l, v_str(str_cstr(entry->d_name)));
    }
    int err = errno;
    closedir(dir);
    if (err) {
        decref(v_list(l));
        return raise_path("Cannot list directory", path, err);
    }
    if (l->len) qsort(l->items, l->len, sizeof *l->items, by_bytes);
    *out = v_list(l);
    return true;
}

static Value keys_of(Value v) {
    if (v.type == T_LIST) {
        List *l = list_new(v.l->len);
        for (size_t i = 0; i < v.l->len; i++) list_push(l, v_int((int64_t)i));
        return v_list(l);
    }
    List *l = list_new(v.m->count);
    for (size_t i = 0; map_next(v.m, &i); i++) {
        incref(v.m->entries[i].key);
        list_push(l, v.m->entries[i].key);
    }
    return v_list(l);
}

/* print_error()'s text in one write() where the system takes it all at once: stderr is unbuffered,
   and macOS's stdio writes an unbuffered stream 1024 bytes at a time, so a line from one worker could
   be split by another's (http::serve's access log, where several workers share standard error). A
   pipe takes up to PIPE_BUF bytes (512 at least) whole, which is why the log keeps its lines shorter.
   A failed write is dropped, as fwrite() to stderr would drop it. */
static void write_whole(int fd, const char *data, size_t len) {
    while (len > 0) {
        ssize_t n = write(fd, data, len);
        if (n < 0 && errno == EINTR) continue;
        if (n <= 0) return;
        data += n;
        len -= (size_t) n;
    }
}

static bool write_text(FILE *stream, Value v) {
    Buf b = {0};
    if (!append_string(v, &b)) {
        free(b.data);
        return false;
    }
    if (stream == stderr) {
        flush_output();
        write_whole(STDERR_FILENO, b.data, b.len);
    } else {
        fwrite(b.data ? b.data : "", 1, b.len, stream);
    }
    free(b.data);
    return true;
}

/* A buffer on the stack belongs in a function of its own, not in call_builtin(): its frame is
   paid for at every level of a recursive call (map or filter calling back into GazLang, say),
   and a sanitized build gives each case's locals a slot of their own, so four 64KB chunks and a
   PATH_MAX buffer made it 200KB. noinline, so the compiler can't fold them back in. */
__attribute__((noinline)) static void read_stream(FILE *f, Buf *text) {
    char chunk[65536];
    size_t n;
    while ((n = fread(chunk, 1, sizeof chunk, f)) > 0) buf_add(text, chunk, n);
}

/* The working directory as a string, or null when it can't be read */
__attribute__((noinline)) static Str *working_directory(void) {
    char dir[PATH_MAX];
    if (!getcwd(dir, sizeof dir)) return NULL;
    return str_cstr(dir);
}

bool call_builtin(int index, Value *args, int argc, Value *out) {
    const unsigned STRING = M(T_STRING), INT = M(T_INT);
    Value a = argc > 0 ? args[0] : v_null(), b = argc > 1 ? args[1] : v_null(), c = argc > 2 ? args[2] : v_null();
    switch (index) {
    case B_LEN:
        if (!want(index, a, M(T_LIST) | M(T_MAP) | STRING)) return false;
        *out = v_int(a.type == T_LIST ? (int64_t)a.l->len : a.type == T_STRING ? (int64_t)a.s->len : (int64_t)a.m->count);
        return true;
    case B_SLICE:
        return slice(args, argc, out);
    case B_LOWER:
    case B_UPPER: {
        if (!want(index, a, STRING)) return false;
        Str *s = str_new(a.s->data, a.s->len);
        for (size_t i = 0; i < s->len; i++) {
            char ch = s->data[i];
            if (index == B_LOWER && ch >= 'A' && ch <= 'Z') s->data[i] = (char)(ch + 32);
            if (index == B_UPPER && ch >= 'a' && ch <= 'z') s->data[i] = (char)(ch - 32);
        }
        *out = v_str(s);
        return true;
    }
    case B_TRIM: {
        /* trim($s, $chars = null): the bytes of $chars off both ends, or without them the same
           whitespace the lexer skips: space, tab, newline, carriage return */
        Value chars = argc > 1 ? b : v_null();
        if (!want(index, a, STRING) || !want(index, chars, STRING | M(T_NULL))) return false;
        const char *set = chars.type == T_STRING ? chars.s->data : " \t\n\r";
        size_t set_len = chars.type == T_STRING ? chars.s->len : 4;
        size_t from = 0, to = a.s->len;
        while (from < to && memchr(set, a.s->data[from], set_len)) from++;
        while (to > from && memchr(set, a.s->data[to - 1], set_len)) to--;
        *out = v_string(a.s->data + from, to - from);
        return true;
    }
    case B_SPLIT: {
        Value limit = argc > 2 ? c : v_null();
        if (!want(index, a, STRING) || !want(index, b, STRING) || !want(index, limit, INT | M(T_NULL))) return false;
        if (limit.type == T_INT && limit.i < 1) return raisef("split() limit must be 1 or more, got %lld", (long long)limit.i);
        *out = split(a.s, b.s, limit.type == T_INT ? limit.i : INT64_MAX);
        return true;
    }
    case B_JOIN: {
        if (!want(index, b, STRING) || !want(index, a, M(T_LIST))) return false;
        Buf text = {0};
        for (size_t i = 0; i < a.l->len; i++) {
            if (i) buf_add_str(&text, b.s);
            if (!append_joined(a.l->items[i], &text)) {
                free(text.data);
                return false;
            }
        }
        *out = v_str(buf_to_str(&text));
        return true;
    }
    case B_REPLACE:
        if (!want(index, a, STRING) || !want(index, b, STRING) || !want(index, c, STRING)) return false;
        if (b.s->len == 0) return raisef("replace() cannot search for an empty string");
        *out = replace(a.s, b.s, c.s);
        return true;
    case B_CONTAINS:
    case B_ENDS_WITH:
        if (!want(index, a, STRING) || !want(index, b, STRING)) return false;
        if (index == B_CONTAINS) *out = v_bool(find(a.s->data, a.s->len, b.s->data, b.s->len) != NULL);
        else if (b.s->len > a.s->len) *out = v_bool(false);
        else *out = v_bool(memcmp(a.s->data + a.s->len - b.s->len, b.s->data, b.s->len) == 0);
        return true;
    case B_STARTS_WITH: {
        /* starts_with($s, $prefix, $offset = 0): whether $prefix is there at $offset, which means
           what it does in index_of(): from the end when negative, and an error outside the string
           (the end itself is fine: only an empty prefix is there) */
        Value offset = argc > 2 ? c : v_int(0);
        if (!want(index, a, STRING) || !want(index, b, STRING) || !want(index, offset, INT)) return false;
        int64_t n = (int64_t)a.s->len, at = offset.i;
        if (at > n || at < -n) return raisef("starts_with() offset %lld is outside the string", (long long)at);
        if (at < 0) at += n;
        *out = v_bool((size_t)(n - at) >= b.s->len && memcmp(a.s->data + at, b.s->data, b.s->len) == 0);
        return true;
    }
    case B_INDEX_OF: {
        Value offset = argc > 2 ? c : v_int(0);
        if (!want(index, a, STRING) || !want(index, b, STRING) || !want(index, offset, INT)) return false;
        if (b.s->len == 0) return raisef("index_of() cannot search for an empty string");
        int64_t n = (int64_t)a.s->len, at = offset.i;
        if (at > n || at < -n) return raisef("index_of() offset %lld is outside the string", (long long)at);
        if (at < 0) at += n;
        const char *hit = find(a.s->data + at, (size_t)(n - at), b.s->data, b.s->len);
        *out = hit ? v_int(hit - a.s->data) : v_null();
        return true;
    }
    case B_REPEAT: {
        if (!want(index, a, STRING) || !want(index, b, INT)) return false;
        if (b.i < 0) return raisef("repeat() count must not be negative, got %lld", (long long)b.i);
        /* Nothing repeated is nothing, however many times: the loop below would otherwise
           append no bytes as many times as it was asked to, which is a run with no end */
        if (a.s->len == 0 || b.i == 0) {
            *out = v_str(str_new("", 0));
            return true;
        }
        /* The length is worked out before anything is allocated, since it would otherwise wrap
           around and ask for a size that isn't the one it needs */
        if ((size_t)b.i > (SIZE_MAX / 2 - 1) / a.s->len) {
            return raisef("repeat() would make a string of %llu bytes times %lld, which is longer than a string can be",
                          (unsigned long long)a.s->len, (long long)b.i);
        }
        Str *s = str_empty(a.s->len * (size_t)b.i);
        for (int64_t i = 0; i < b.i; i++) s = str_append(s, a.s->data, a.s->len);
        *out = v_str(s);
        return true;
    }
    case B_CHR: {
        if (!want(index, a, INT)) return false;
        if (a.i < 0 || a.i > 255) return raisef("chr() expects a byte value from 0 to 255, got %lld", (long long)a.i);
        *out = v_str(str_byte((unsigned char)a.i));
        return true;
    }
    case B_ORD:
        if (!want(index, a, STRING)) return false;
        if (a.s->len != 1) {
            Buf m = {0};
            buf_adds(&m, "ord() expects a one character string, got ");
            quote(a.s, &m);
            return raise_str(buf_to_str(&m));
        }
        *out = v_int((unsigned char)a.s->data[0]);
        return true;
    case B_TO_INT:
        return to_int(a, argc, b, out);
    case B_TO_FLOAT:
        return to_float(a, argc, b, out);
    case B_FLOOR:
    case B_CEIL:
        if (!want(index, a, INT | M(T_FLOAT))) return false;
        if (a.type == T_INT) *out = v_float((double)a.i);
        else *out = v_float(index == B_FLOOR ? floor(a.f) : ceil(a.f));
        return true;
    case B_ROUND: {
        Value places = argc > 1 ? b : v_int(0);
        if (!want(index, a, INT | M(T_FLOAT)) || !want(index, places, INT)) return false;
        /* PHP clamps the precision to what a C int holds on the way into _php_math_round */
        int p = places.i > INT_MAX ? INT_MAX : places.i < INT_MIN ? INT_MIN : (int)places.i;
        if (a.type == T_INT && p >= 0) *out = v_float((double)a.i);
        else *out = v_float(php_round(a.type == T_INT ? (double)a.i : a.f, p));
        return true;
    }
    case B_SQRT: {
        /* IEEE 754 requires a correctly rounded square root, so every platform gives the same bits */
        if (!want(index, a, INT | M(T_FLOAT))) return false;
        double x = a.type == T_INT ? (double)a.i : a.f;
        if (x < 0) {
            Buf m = {0};
            append_string(a, &m);
            raisef("sqrt() expects a number that is not negative, got %s", m.data);
            free(m.data);
            return false;
        }
        *out = v_float(sqrt(x));
        return true;
    }
    case B_ABS:
        if (!want(index, a, INT | M(T_FLOAT))) return false;
        if (a.type == T_FLOAT) {
            *out = v_float(fabs(a.f));
            return true;
        }
        if (a.i == INT64_MIN) return raisef("Integer overflow");
        *out = v_int(a.i < 0 ? -a.i : a.i);
        return true;
    case B_INTDIV:
        if (!want(index, a, INT) || !want(index, b, INT)) return false;
        if (b.i == 0) return raisef("Division by zero");
        if (a.i == INT64_MIN && b.i == -1) return raisef("Integer overflow");
        *out = v_int(a.i / b.i);
        return true;
    case B_MIN:
    case B_MAX: {
        int cmp = 0;   /* extreme() sets it; gcc can't tell */
        if (argc == 2) {
            if (!extreme(index, a, b, false, &cmp)) return false;
            *out = (index == B_MIN ? cmp <= 0 : cmp >= 0) ? a : b;
            incref(*out);
            return true;
        }
        /* One argument: the smallest or largest of a list's or map's values, the first on a tie */
        if (!want(index, a, M(T_LIST) | M(T_MAP))) return false;
        Value *gathered, *items;
        size_t n;
        items = values_of(a, &n, &gathered);
        if (n == 0) {
            free(gathered);
            return raisef("%s() expects a non-empty list or map", builtin_info[index].name);
        }
        Value best = items[0];
        /* A lone value is still checked, against itself, so min([[]]) is an error as min([[], []]) is */
        for (size_t i = n == 1 ? 0 : 1; i < n; i++) {
            if (!extreme(index, best, items[i], true, &cmp)) {
                free(gathered);
                return false;
            }
            if (index == B_MIN ? cmp > 0 : cmp < 0) best = items[i];
        }
        free(gathered);
        incref(best);
        *out = best;
        return true;
    }
    case B_SUM: {
        /* The values added left to right from 0 with +, so its errors and its int or float are +'s */
        if (!want(index, a, M(T_LIST) | M(T_MAP))) return false;
        Value *gathered, *items;
        size_t n;
        items = values_of(a, &n, &gathered);
        Value total = v_int(0);
        for (size_t i = 0; i < n; i++) {
            Value next;
            if (!binary_op(OP_ADD, total, items[i], &next)) {
                free(gathered);
                return false;
            }
            total = next;
        }
        free(gathered);
        *out = total;
        return true;
    }
    case B_TO_STRING: {
        Str *s;
        if (!to_string(a, &s)) return false;
        *out = v_str(s);
        return true;
    }
    case B_IN_ARRAY:
        if (!want(index, b, M(T_LIST))) return false;
        *out = v_bool(false);
        for (size_t i = 0; i < b.l->len; i++) {
            if (values_equal(a, b.l->items[i])) {
                *out = v_bool(true);
                break;
            }
        }
        return true;
    case B_HAS_KEY:
        if (!want(index, a, M(T_LIST) | M(T_MAP)) || !array_key(b)) return false;
        if (a.type == T_MAP) {
            *out = v_bool(map_find(a.m, b) != NULL);
            return true;
        }
        if (b.type != T_INT) return raisef("List indexes must be int, got string");
        *out = v_bool(b.i >= 0 && (uint64_t)b.i < a.l->len);
        return true;
    case B_KEYS:
        if (!want(index, a, M(T_LIST) | M(T_MAP))) return false;
        *out = keys_of(a);
        return true;
    case B_VALUES: {
        if (!want(index, a, M(T_LIST) | M(T_MAP))) return false;
        if (a.type == T_LIST) {
            incref(a);
            *out = a;
            return true;
        }
        List *l = list_new(a.m->count);
        for (size_t i = 0; map_next(a.m, &i); i++) {
            incref(a.m->entries[i].value);
            list_push(l, a.m->entries[i].value);
        }
        *out = v_list(l);
        return true;
    }
    case B_LAST:
        if (!want(index, a, M(T_LIST))) return false;
        if (a.l->len == 0) return raisef("last() expects a non-empty list");
        *out = a.l->items[a.l->len - 1];
        incref(*out);
        return true;
    case B_REVERSE:
        /* A list's elements, a string's bytes or a map's entries in the other order; a map keeps its keys */
        if (!want(index, a, M(T_LIST) | M(T_MAP) | M(T_STRING))) return false;
        if (a.type == T_STRING) {
            Str *s = str_new(a.s->data, a.s->len);
            for (size_t i = 0; i < s->len / 2; i++) {
                char c = s->data[i];
                s->data[i] = s->data[s->len - 1 - i];
                s->data[s->len - 1 - i] = c;
            }
            *out = v_str(s);
        } else if (a.type == T_LIST) {
            List *l = list_new(a.l->len);
            for (size_t i = a.l->len; i-- > 0;) {
                incref(a.l->items[i]);
                list_push(l, a.l->items[i]);
            }
            *out = v_list(l);
        } else {
            Map *m = map_new();
            for (size_t i = a.m->used; i-- > 0;) {
                if (a.m->entries[i].key.type == T_UNSET) continue;   /* a removed entry's hole */
                incref(a.m->entries[i].value);
                map_set(m, a.m->entries[i].key, a.m->entries[i].value);
            }
            *out = v_map(m);
        }
        return true;
    case B_MAP:
    case B_FILTER:
        if (!want(index, a, M(T_LIST) | M(T_MAP)) || !want(index, args[1], M(T_FUNCTION) | M(T_KIND))) return false;
        return map_or_filter(index == B_MAP, a, args[1], out);
    case B_REDUCE:
        if (!want(index, a, M(T_LIST) | M(T_MAP)) || !want(index, args[1], M(T_FUNCTION) | M(T_KIND))) return false;
        return reduce(a, args[1], args[2], out);
    case B_SORT: {
        /* sort($x, $compare = null) */
        Value compare = argc > 1 ? b : v_null();
        if (!want(index, a, M(T_LIST) | M(T_MAP)) || !want(index, compare, M(T_FUNCTION) | M(T_KIND) | M(T_NULL))) return false;
        List *sorted;
        if (a.type == T_LIST) {
            sorted = merge_sort(a.l->items, a.l->len, compare);
        } else {
            /* The map's values, in order, as values() gives them (which can't fail on a map) */
            Value values;
            call_builtin(B_VALUES, args, 1, &values);
            sorted = merge_sort(values.l->items, values.l->len, compare);
            decref(values);
        }
        if (!sorted) return false;
        *out = v_list(sorted);
        return true;
    }
    case B_TYPE_OF:
        *out = v_str(str_cstr(type_name(a)));
        return true;
    case B_IS_A:
        if (!want(index, b, M(T_KIND))) return false;
        *out = v_bool(a.type == T_OBJECT && kind_is_a(a.o->kind, b.k));
        return true;
    case B_KIND_OF:
        if (!want(index, a, M(T_OBJECT))) return false;
        *out = v_kind(a.o->kind);
        return true;
    case B_KIND_NAME: {
        /* The name as declared, namespace included (json::Reader): what echo prints after "kind ".
           An object has one kind, so its name is that kind's, with nothing to confuse it with. */
        if (!want(index, a, M(T_KIND) | M(T_OBJECT))) return false;
        Value name = v_str(a.type == T_KIND ? a.k->name : a.o->kind->name);
        incref(name);
        *out = name;
        return true;
    }
    case B_OBJECT_ID:
        if (!want(index, a, M(T_OBJECT))) return false;
        *out = v_int(a.o->id);
        return true;
    case B_FIELDS: {
        if (!want(index, a, M(T_OBJECT))) return false;
        Map *m = map_new();
        for (int i = 0; i < a.o->kind->nfields; i++) {
            if (a.o->fields[i].type == T_UNSET) continue;
            incref(a.o->fields[i]);
            map_set(m, v_str(a.o->kind->fields[i]), a.o->fields[i]);
        }
        *out = v_map(m);
        return true;
    }
    case B_EXIT: {
        Value code = argc > 0 ? a : v_int(0);
        if (!want(index, code, INT)) return false;
        if (code.i < 0 || code.i > 255) return raisef("exit() expects a code from 0 to 255, got %lld", (long long)code.i);
        /* Not an error, so no try sees it and no finally runs: the program simply ends */
        flush_output();
        /* It ends the program mid-instruction, holding whatever it holds: nothing to check */
        if (getenv("GAZVM_STATS")) fputs("gazvm: leaks not checked: exit()\n", stderr);
        exit((int)code.i);
    }
    case B_READ_FILE: {
        if (!want(index, a, STRING)) return false;
        struct stat st;
        FILE *f = NULL;
        if (!memchr(a.s->data, '\0', a.s->len) && stat(a.s->data, &st) == 0 && S_ISREG(st.st_mode) && access(a.s->data, R_OK) == 0) {
            f = fopen(a.s->data, "rb");
        }
        if (!f) return raisef("Cannot read file: %s", a.s->data);
        Buf text = {0};
        read_stream(f, &text);
        fclose(f);
        *out = v_str(buf_to_str(&text));
        return true;
    }
    case B_WRITE_FILE: {
        if (!want(index, a, STRING) || !want(index, b, STRING)) return false;
        FILE *f = memchr(a.s->data, '\0', a.s->len) ? NULL : fopen(a.s->data, "wb");
        if (!f || fwrite(b.s->data, 1, b.s->len, f) != b.s->len) {
            if (f) fclose(f);
            return raisef("Cannot write file: %s", a.s->data);
        }
        fclose(f);
        *out = v_null();
        return true;
    }
    case B_FILE_EXISTS:
    case B_REAL_PATH: {
        if (!want(index, a, STRING)) return false;
        char *real = resolve(a.s);
        if (index == B_FILE_EXISTS) {
            *out = v_bool(real != NULL);
            free(real);
            return true;
        }
        if (!real) {
            Buf m = {0};
            buf_adds(&m, "No such file or directory: ");
            quote(a.s, &m);
            return raise_str(buf_to_str(&m));
        }
        *out = v_str(str_cstr(real));
        free(real);
        return true;
    }
    case B_LIST_DIR:
        if (!want(index, a, STRING)) return false;
        return list_dir(a.s, out);
    case B_IS_DIR: {
        /* Following a symlink, as file_exists() does */
        if (!want(index, a, STRING)) return false;
        struct stat st;
        *out = v_bool(!memchr(a.s->data, '\0', a.s->len) && stat(a.s->data, &st) == 0 && S_ISDIR(st.st_mode));
        return true;
    }
    case B_MAKE_DIR:
    case B_DELETE_FILE:
    case B_DELETE_DIR: {
        /* One level each: make_dir() needs the parent there and the name free, delete_dir() an
           empty directory, and delete_file() refuses a directory (a symlink to one is a file), whose
           reason is written out here since unlink() gives EPERM on macOS and EISDIR on Linux */
        if (!want(index, a, STRING)) return false;
        int err = 0;
        struct stat st;
        Value parents = index == B_MAKE_DIR && argc > 1 ? b : v_bool(false);
        if (!want(index, parents, M(T_BOOL))) return false;
        /* make_dir()'s $mode, before the umask as mkdir(2) takes it; null is 0777 */
        Value mode = index == B_MAKE_DIR && argc > 2 ? c : v_null();
        if (!want(index, mode, INT | M(T_NULL))) return false;
        if (mode.type == T_INT && !check_mode("make_dir", mode.i)) return false;
        mode_t bits = mode.type == T_INT ? (mode_t)mode.i : 0777;
        if (memchr(a.s->data, '\0', a.s->len) || (parents.b && a.s->len == 0)) err = ENOENT;
        else if (parents.b) err = make_dirs(a.s, bits);
        else if (index == B_MAKE_DIR) err = mkdir(a.s->data, bits) == 0 ? 0 : errno;
        else if (index == B_DELETE_DIR) err = rmdir(a.s->data) == 0 ? 0 : errno;
        else if (lstat(a.s->data, &st) == 0 && S_ISDIR(st.st_mode)) err = EISDIR;
        else err = unlink(a.s->data) == 0 ? 0 : errno;
        if (err) return raise_path(index == B_MAKE_DIR ? "Cannot make directory" : index == B_DELETE_DIR ? "Cannot delete directory" : "Cannot delete file", a.s, err);
        *out = v_null();
        return true;
    }
    case B_CWD: {
        Str *dir = working_directory();
        if (!dir) return raisef("Cannot get the working directory");
        *out = v_str(dir);
        return true;
    }
    case B_PRINT:
    case B_PRINT_ERROR:
        if (!write_text(index == B_PRINT ? output : stderr, a)) return false;
        *out = v_null();
        return true;
    case B_READ_STDIN: {
        Buf text = {0};
        /* Source handed to the front end (piped_input) is all of it: what main() read from a pipe
           to its end, or a built-in program's (gaz test, gaz -S), whose standard input was never
           read and may be a terminal nobody will close, so it is left alone */
        if (piped_input) {
            buf_add(&text, piped_input, piped_input_len);
            free(piped_input);
            piped_input = NULL;
            *out = v_str(buf_to_str(&text));
            return true;
        }
        read_stream(stdin, &text);
        *out = v_str(buf_to_str(&text));
        return true;
    }
    case B_READ_LINE: {
        /* The next line of standard input without its "\n" or "\r\n", or null at the end. Through
           the same stdio buffer as read_stdin(), so the two never lose each other's bytes. A
           program that was itself piped in finds its input at the end already: main() read it.
           (piped_input, which read_stdin() gives first, is only ever the front end's.) Output is
           flushed first, so a prompt is on the screen before the wait. */
        flush_output();
        char *line = NULL;
        size_t cap = 0;
        ssize_t n = getline(&line, &cap, stdin);
        if (n < 0) {
            int err = errno;
            bool failed = ferror(stdin);
            free(line);
            if (failed) {
                clearerr(stdin);
                return raisef("Cannot read standard input: %s", strerror(err));
            }
            *out = v_null();
            return true;
        }
        if (n > 0 && line[n - 1] == '\n') n -= n > 1 && line[n - 2] == '\r' ? 2 : 1;
        *out = v_string(line, (size_t)n);
        free(line);
        return true;
    }
    case B_READ_STDIN_BYTES: {
        /* Exactly $n bytes of standard input, for a protocol framed by a byte count (an LSP
           message's Content-Length): unlike read_stdin(), which reads to the end, this reads
           only what is asked and leaves the rest for the next call. Through the same buffer
           as read_stdin() and read_line() (piped_input first, for a program piped in whole). */
        if (!want(index, a, INT)) return false;
        if (a.i < 0) return raisef("read_stdin_bytes() expects n >= 0, got %lld", (long long)a.i);
        flush_output();
        /* One allocation of exactly $n, not a stack chunk read in a loop: $n is known up front,
           and a stack buffer here would grow call_builtin's frame, which every level of a
           recursive call pays for (map/filter calling back into GazLang, say). */
        size_t n = (size_t)a.i;
        char *buf = xmalloc(n ? n : 1);
        size_t have = 0;
        if (piped_input) {
            have = n < piped_input_len ? n : piped_input_len;
            memcpy(buf, piped_input, have);
            piped_input_len -= have;
            if (piped_input_len) memmove(piped_input, piped_input + have, piped_input_len);
            else {
                free(piped_input);
                piped_input = NULL;
            }
        }
        while (have < n) {
            size_t got = fread(buf + have, 1, n - have, stdin);
            if (got == 0) {
                int err = errno;
                bool failed = ferror(stdin);
                size_t short_by = n - have;
                free(buf);
                if (failed) {
                    clearerr(stdin);
                    return raisef("Cannot read standard input: %s", strerror(err));
                }
                return raisef("read_stdin_bytes() expects %lld bytes, standard input ended %zu short",
                              (long long)a.i, short_by);
            }
            have += got;
        }
        *out = v_string(buf, n);
        free(buf);
        return true;
    }
    case B_ARGS: {
        List *l = list_new((size_t)program_argc);
        for (int i = 0; i < program_argc; i++) list_push(l, v_str(str_cstr(program_argv[i])));
        *out = v_list(l);
        return true;
    }
    case B_PROGRAM_PATH:
        *out = v_str(str_cstr(program_exe));
        return true;
    case B_RAND_INT:
        if (!want(index, a, INT) || !want(index, b, INT)) return false;
        if (b.i < a.i) return raisef("rand_int() expects min <= max, got %lld and %lld", (long long)a.i, (long long)b.i);
        *out = v_int(random_between(a.i, b.i));
        return true;
    case B_RAND_FLOAT:
        /* The top 53 bits, the most a double holds exactly, as a fraction of 2^53 */
        *out = v_float((double)(random_next() >> 11) * 0x1p-53);
        return true;
    case B_RAND_SEED:
        if (!want(index, a, INT | M(T_NULL))) return false;
        if (a.type == T_INT) random_seed((uint64_t)a.i);
        else random_seed_unpredictable();
        *out = v_null();
        return true;
    case B_RUN:
        if (!want(index, a, M(T_LIST)) || (argc > 1 && !want(index, b, STRING))) return false;
        return run_process(a.l, argc > 1 ? b.s : NULL, out);
    case B_SOCKET_OPEN: {
        /* socket_open($host, $port, $tls = false, $timeout = 30) */
        Value tls = argc > 2 ? c : v_bool(false), timeout = argc > 3 ? args[3] : v_int(30);
        if (!want(index, a, STRING) || !want(index, b, INT) || !want(index, tls, M(T_BOOL))
            || !want(index, timeout, INT | M(T_FLOAT))) return false;
        return net_open(a.s, b.i, tls.b, timeout.type == T_INT ? (double)timeout.i : timeout.f, out);
    }
    case B_SOCKET_READ: {
        /* socket_read($socket, $seconds = its own timeout) */
        if (!want(index, a, M(T_SOCKET)) || (argc > 1 && !want(index, b, INT | M(T_FLOAT)))) return false;
        double seconds = argc < 2 ? 0 : b.type == T_INT ? (double)b.i : b.f;
        if (argc > 1 && !(seconds > 0)) return raisef("socket_read() expects a timeout above 0 seconds");
        return net_read(a.sock, seconds, out);
    }
    case B_SOCKET_WRITE:
        if (!want(index, a, M(T_SOCKET)) || !want(index, b, STRING)) return false;
        if (!net_write(a.sock, b.s)) return false;
        *out = v_null();
        return true;
    case B_SOCKET_CLOSE:
        if (!want(index, a, M(T_SOCKET))) return false;
        net_close(a.sock);
        *out = v_null();
        return true;
    case B_SOCKET_LISTEN: {
        /* socket_listen($host, $port, $backlog = 128) */
        Value backlog = argc > 2 ? c : v_int(128);
        if (!want(index, a, STRING) || !want(index, b, INT) || !want(index, backlog, INT)) return false;
        return net_listen(a.s, b.i, backlog.i, out);
    }
    case B_SOCKET_ACCEPT: {
        /* socket_accept($listener, $timeout = 30) */
        Value timeout = argc > 1 ? b : v_int(30);
        if (!want(index, a, M(T_SOCKET)) || !want(index, timeout, INT | M(T_FLOAT))) return false;
        return net_accept(a.sock, timeout.type == T_INT ? (double)timeout.i : timeout.f, out);
    }
    case B_SOCKET_PORT:
        if (!want(index, a, M(T_SOCKET))) return false;
        return net_port(a.sock, out);
    case B_SOCKET_PEER:
        if (!want(index, a, M(T_SOCKET))) return false;
        return net_peer(a.sock, out);
    case B_SOCKET_WAIT: {
        if (!want(index, a, M(T_LIST)) || !want(index, b, INT | M(T_FLOAT))) return false;
        double seconds = b.type == T_INT ? (double)b.i : b.f;
        if (seconds < 0) {
            Buf m = {0};
            append_string(b, &m);
            raisef("socket_wait() expects 0 seconds or more, got %s", m.data);
            free(m.data);
            return false;
        }
        return net_wait(a.l, seconds, out);
    }
    case B_WORKERS:
        if (!want(index, a, INT)) return false;
        return start_workers(a.i, out);
    case B_TIME:
        /* Whole seconds since 1 January 1970 UTC, the wall clock: it can jump when the clock is set,
           so monotonic_time() is what measures how long something took */
        *out = v_int((int64_t)time(NULL));
        return true;
    case B_GETENV: {
        /* A name with a NUL byte is a mistake rather than one that isn't set: no name can hold one */
        if (!want(index, a, STRING)) return false;
        if (memchr(a.s->data, '\0', a.s->len)) return raisef("getenv() expects a name without a NUL byte");
        const char *value = getenv(a.s->data);
        *out = value ? v_str(str_cstr(value)) : v_null();
        return true;
    }
    case B_SLEEP: {
        /* Output is flushed first, so what was printed before the pause is on the screen during it */
        if (!want(index, a, INT | M(T_FLOAT))) return false;
        double seconds = a.type == T_INT ? (double)a.i : a.f;
        if (seconds < 0) {
            Buf m = {0};
            append_string(a, &m);
            raisef("sleep() expects 0 seconds or more, got %s", m.data);
            free(m.data);
            return false;
        }
        flush_output();
        if (tty_rows > 0) {
            /* gaz --tty: time passes on the pretend terminal's clock, at once, and stays finite */
            if (!isfinite(tty_clock + seconds)) return raisef("Float overflow");
            tty_clock += seconds;
            *out = v_null();
            return true;
        }
        /* A day at a time, so any finite number of seconds fits a timespec; a signal that
           interrupts it (a worker being stopped) doesn't cut it short */
        while (seconds > 0) {
            double step = seconds < 86400 ? seconds : 86400;
            struct timespec wait = {(time_t)step, (long)((step - floor(step)) * 1e9)}, left;
            while (nanosleep(&wait, &left) != 0 && errno == EINTR) wait = left;
            seconds -= step;
        }
        *out = v_null();
        return true;
    }
    case B_DB_OPEN:
        if (!want(index, a, STRING)) return false;
        return db_open(a.s, out);
    case B_DB_RUN: {
        /* db_run($db, $sql, $params = []) */
        if (!want(index, a, M(T_DB))) return false;
        db_forget_error(a.db);  // before the other checks, so a refused argument leaves no old failure
        Value params = argc > 2 ? c : v_list(list_new(0));
        bool ok = want(index, b, STRING) && want(index, params, M(T_LIST)) && db_run(a.db, b.s, params.l, out);
        if (argc < 3) decref(params);
        return ok;
    }
    case B_DB_CLOSE:
        if (!want(index, a, M(T_DB))) return false;
        db_close(a.db);
        *out = v_null();
        return true;
    case B_DB_ERROR:
        if (!want(index, a, M(T_DB))) return false;
        *out = db_error(a.db);
        return true;
    case B_FILE_OPEN: {
        Value mode = argc > 1 ? b : v_null();
        if (!want(index, a, STRING) || (argc > 1 && !want(index, mode, STRING))) return false;
        static Str *read_mode;
        if (!read_mode) read_mode = str_intern("r", 1);
        return open_file(a.s, argc > 1 ? mode.s : read_mode, out);
    }
    case B_FILE_READ:
        if (!want(index, a, M(T_FILE)) || !want(index, b, INT)) return false;
        return read_file_bytes(a.file, b.i, out);
    case B_FILE_WRITE:
        if (!want(index, a, M(T_FILE)) || !want(index, b, STRING)) return false;
        *out = v_null();
        return write_file_bytes(a.file, b.s);
    case B_FILE_SEEK: {
        if (!want(index, a, M(T_FILE)) || !want(index, b, INT) || (argc > 2 && !want(index, c, STRING))) return false;
        static Str *from_start;
        if (!from_start) from_start = str_intern("start", 5);
        return seek_file(a.file, b.i, argc > 2 ? c.s : from_start, out);
    }
    case B_FILE_INFO: {
        Value follow = argc > 1 ? b : v_bool(true);
        if (!want(index, a, STRING) || !want(index, follow, M(T_BOOL))) return false;
        return file_info(a.s, follow.b, out);
    }
    case B_RENAME_FILE:
        if (!want(index, a, STRING) || !want(index, b, STRING)) return false;
        *out = v_null();
        return rename_path(a.s, b.s);
    case B_FILE_READ_LINE:
        if (!want(index, a, M(T_FILE))) return false;
        return read_file_line(a.file, out);
    case B_CHMOD:
        if (!want(index, a, STRING) || !want(index, b, INT)) return false;
        *out = v_null();
        return change_mode(a.s, b.i);
    case B_SYMLINK:
        if (!want(index, a, STRING) || !want(index, b, STRING)) return false;
        *out = v_null();
        return make_link(a.s, b.s);
    case B_READLINK:
        if (!want(index, a, STRING)) return false;
        return read_link(a.s, out);
    case B_FILE_SYNC:
        if (!want(index, a, M(T_FILE))) return false;
        *out = v_null();
        return sync_file(a.file);
    case B_SYNC_DIR:
        if (!want(index, a, STRING)) return false;
        *out = v_null();
        return sync_dir(a.s);
    case B_FILE_TRUNCATE:
        if (!want(index, a, M(T_FILE)) || !want(index, b, INT)) return false;
        *out = v_null();
        return truncate_file(a.file, b.i);
    case B_SET_MTIME:
        if (!want(index, a, STRING) || !want(index, b, INT)) return false;
        *out = v_null();
        return set_mtime(a.s, b.i);
    case B_CHDIR:
        if (!want(index, a, STRING)) return false;
        *out = v_null();
        return change_dir(a.s);
    case B_UTF8_VALID:
        if (!want(index, a, STRING)) return false;
        *out = v_bool(utf8_first_bad(a.s) == a.s->len);
        return true;
    case B_UTF8_LENGTH:
    case B_UTF8_CHARS:
        if (!want(index, a, STRING)) return false;
        return utf8_characters(index, a.s, out);
    case B_FILE_CLOSE:
        if (!want(index, a, M(T_FILE))) return false;
        *out = v_null();
        return close_file(a.file);
    case B_TERM_RAW:
        if (!want(index, a, M(T_BOOL))) return false;
        if (!term_raw(a.b)) return false;
        *out = v_null();
        return true;
    case B_TERM_READ: {
        /* term_read($timeout = null): seconds to wait, null for as long as it takes */
        Value timeout = argc > 0 ? a : v_null();
        if (!want(index, timeout, INT | M(T_FLOAT) | M(T_NULL))) return false;
        if (timeout.type != T_NULL && (timeout.type == T_INT ? (double)timeout.i : timeout.f) < 0) {
            return raisef("term_read() expects a timeout of 0 seconds or more");
        }
        return term_read(timeout.type == T_NULL ? -1 : timeout.type == T_INT ? (double)timeout.i : timeout.f, out);
    }
    case B_TERM_SIZE:
        return term_size(out);
    case B_TERM_IS_TTY:
        if (!want(index, a, INT)) return false;
        return term_is_tty(a.i, out);
    case B_TERM_IS_VIRTUAL:
        return term_is_virtual(out);
    case B_STD_SOURCE: {
        /* The text of a standard library file, or null: what `import "std/name.gaz"` reads. GAZLIB, a
           directory, is read instead of the built-in copy, so the library can be edited without a
           rebuild. A name is one file's, never a path. */
        if (!want(index, a, STRING)) return false;
        *out = v_null();
        if (memchr(a.s->data, '\0', a.s->len) || memchr(a.s->data, '/', a.s->len) || a.s->data[0] == '.' || a.s->len == 0) return true;
        const char *dir = getenv("GAZLIB");
        if (dir && *dir) {
            Buf path = {0};
            buf_adds(&path, dir);
            buf_adds(&path, "/");
            buf_add(&path, a.s->data, a.s->len);
            buf_add(&path, "", 1);
            FILE *f = fopen(path.data, "rb");
            free(path.data);
            if (!f) return true;
            Buf text = {0};
            read_stream(f, &text);
            fclose(f);
            *out = v_str(buf_to_str(&text));
            return true;
        }
        for (const StdFile *file = std_files; file->name; file++) {
            if (strlen(file->name) == a.s->len && !memcmp(file->name, a.s->data, a.s->len)) {
                *out = v_str(str_new((const char *)file->data, (int)file->len));
                return true;
            }
        }
        return true;
    }
    case B_MONOTONIC_TIME: {
        /* Seconds on the system's monotonic clock, from a point nobody promises: only the difference
           between two readings means anything. It doesn't jump when the clock is set, and there are
           no dates in it, which is the point: time() would be different on every run. */
        if (tty_rows > 0) {
            *out = v_float(tty_clock);
            return true;
        }
        struct timespec now;
        if (clock_gettime(CLOCK_MONOTONIC, &now) != 0) return raisef("monotonic_time() failed: %s", strerror(errno));
        *out = v_float((double)now.tv_sec + (double)now.tv_nsec * 1e-9);
        return true;
    }
    case B_RANDOM_BYTES:
        if (!want(index, a, INT)) return false;
        return crypto_random_bytes(a.i, out);
    case B_SHA256:
        if (!want(index, a, STRING)) return false;
        *out = crypto_sha256(a.s);
        return true;
    case B_HMAC_SHA256:
        /* hmac_sha256($data, $key): the data first, as every builtin takes its subject */
        if (!want(index, a, STRING) || !want(index, b, STRING)) return false;
        *out = crypto_hmac_sha256(a.s, b.s);
        return true;
    case B_PBKDF2_SHA256:
        /* pbkdf2_sha256($password, $salt, $iterations, $length) */
        if (!want(index, a, STRING) || !want(index, b, STRING) || !want(index, c, INT) || !want(index, args[3], INT)) return false;
        return crypto_pbkdf2_sha256(a.s, b.s, c.i, args[3].i, out);
    case B_SCRYPT:
        /* scrypt($password, $salt, $cost, $block_size, $parallelism, $length) */
        if (!want(index, a, STRING) || !want(index, b, STRING)) return false;
        for (int i = 2; i < 6; i++) {
            if (!want(index, args[i], INT)) return false;
        }
        return crypto_scrypt(a.s, b.s, c.i, args[3].i, args[4].i, args[5].i, out);
    case B_ARGON2ID: {
        /* argon2id($password, $salt, $passes, $memory, $lanes, $length, $secret = "", $data = "") */
        if (!want(index, a, STRING) || !want(index, b, STRING)) return false;
        for (int i = 2; i < 6; i++) {
            if (!want(index, args[i], INT)) return false;
        }
        for (int i = 6; i < argc; i++) {
            if (!want(index, args[i], STRING)) return false;
        }
        Str *none = str_empty(0);
        Str *secret = argc > 6 ? args[6].s : none, *data = argc > 7 ? args[7].s : none;
        bool ok = crypto_argon2id(a.s, b.s, c.i, args[3].i, args[4].i, args[5].i, secret, data, out);
        decref(v_str(none));
        return ok;
    }
    case B_BUILTINS: {
        Map *m = map_new();
        for (int i = 0; i < nbuiltins; i++) {
            Value arity;
            if (builtin_info[i].lo == builtin_info[i].hi) {
                arity = v_int(builtin_info[i].lo);
            } else {
                List *l = list_new(2);
                list_push(l, v_int(builtin_info[i].lo));
                list_push(l, v_int(builtin_info[i].hi));
                arity = v_list(l);
            }
            Value name = v_str(str_cstr(builtin_info[i].name));
            map_set(m, name, arity);
            decref(name);
        }
        *out = v_map(m);
        return true;
    }
    case B_FLUSH_OUTPUT:
        flush_output();
        *out = v_null();
        return true;
    case B_WORKER_RECYCLE:
        /* A worker retiring itself on purpose (http::serve's max_requests): flush what's buffered,
           since a signal-terminated process doesn't run stdio's own atexit flush, then raise SIGUSR2
           on itself. No handler is installed, so the default disposition (terminate) applies, and
           workers.c's master tells this apart from a crash and restarts it without logging a failure.
           A reserved exit code was rejected: exit($code) already lets a program pick any code 0-255,
           so an unrelated exit() somewhere could collide with one; a signal can't. */
        flush_output();
        raise(SIGUSR2);
        *out = v_null();
        return true;
    case B_WORKER_RETIRE:
        /* A worker asking to be replaced while it serves on (http::serve's max_requests): see
           workers.c. False outside a worker, or once asked: there is nothing more to hand over. */
        *out = v_bool(worker_retire());
        return true;
    case B_WORKER_DEADLINE: {
        /* worker_deadline($socket, $seconds, $answer = "", $line = ""): see workers.c. The socket
           and the seconds are checked in a single process too, where it then does nothing (false),
           so a mistake shows before the program meets workers() */
        if (!want(index, a, M(T_SOCKET) | M(T_NULL)) || !want(index, b, INT | M(T_FLOAT) | M(T_NULL))
            || (argc > 2 && !want(index, c, STRING)) || (argc > 3 && !want(index, args[3], STRING))) return false;
        Str *answer = argc > 2 ? c.s : NULL, *line = argc > 3 ? args[3].s : NULL;
        double seconds = b.type == T_INT ? (double)b.i : b.type == T_FLOAT ? b.f : 0;
        if (!(seconds >= 0)) {
            Buf m = {0};
            append_string(b, &m);
            raisef("worker_deadline() expects 0 seconds or more, got %s", m.data);
            free(m.data);
            return false;
        }
        if (a.type == T_NULL) {
            *out = v_bool(false);
            return true;
        }
        Socket *s = a.sock;
        if (s->fd < 0) return raisef("worker_deadline() on a closed socket");
        if (s->listening) return raisef("worker_deadline() on a listening socket: socket_accept() a connection");
        if (s->tls) return raisef("worker_deadline() on a TLS connection, which it can't write to");
        if (s->owner != vm_process) return refuse_inherited("worker_deadline", "socket");
        *out = v_bool(worker_deadline(s->fd, seconds, answer, line));
        return true;
    }
    case B_WORKER_ACCEPT:
        /* worker_accept($listener, $rule): see workers.c. Checked in a single process too, where
           it gives false, so a mistake shows before the program meets workers() */
        if (!want(index, a, M(T_SOCKET)) || !want(index, b, M(T_MAP))) return false;
        return worker_accept(a.sock, b.m, out);
    case B_WORKER_RELEASE:
        /* worker_release($socket, $bytes, $served = 0): see workers.c */
        if (!want(index, a, M(T_SOCKET)) || !want(index, b, STRING | M(T_NULL)) || (argc > 2 && !want(index, c, INT))) return false;
        return worker_release(a.sock, b, argc > 2 ? c.i : 0, out);
    }
    return raisef("Unknown builtin: %d", index);
}
