/*
 * The builtin functions
 *
 * The loader checks every call's builtin exists and the parser checked its argument count;
 * argument types are checked here, always in the same order, so a call with two bad arguments
 * always names the same one.
 */
#include "gazvm.h"

#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <math.h>
#include <poll.h>
#include <spawn.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>
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

/* In the order builtins() gives them */
const BuiltinInfo builtin_info[] = {
    {"len", 1, 1}, {"slice", 2, 3}, {"lower", 1, 1}, {"upper", 1, 1}, {"trim", 1, 1},
    {"split", 2, 2}, {"join", 2, 2}, {"replace", 3, 3}, {"contains", 2, 2}, {"starts_with", 2, 3},
    {"ends_with", 2, 2}, {"index_of", 2, 3}, {"repeat", 2, 2}, {"chr", 1, 1}, {"ord", 1, 1},
    {"to_int", 1, 2}, {"to_float", 1, 2}, {"floor", 1, 1}, {"ceil", 1, 1}, {"round", 1, 2},
    {"abs", 1, 1}, {"intdiv", 2, 2}, {"min", 1, 2}, {"max", 1, 2}, {"sum", 1, 1}, {"to_string", 1, 1},
    {"in_array", 2, 2}, {"has_key", 2, 2}, {"keys", 1, 1}, {"values", 1, 1}, {"last", 1, 1}, {"reverse", 1, 1},
    {"map", 2, 2},
    {"filter", 2, 2}, {"reduce", 3, 3}, {"sort", 2, 2}, {"type_of", 1, 1},
    {"is_a", 2, 2}, {"kind_of", 1, 1}, {"fields", 1, 1}, {"object_id", 1, 1}, {"error", 1, 1}, {"exit", 0, 1},
    {"read_file", 1, 1}, {"write_file", 2, 2}, {"file_exists", 1, 1}, {"real_path", 1, 1},
    {"cwd", 0, 0}, {"print", 1, 1}, {"print_error", 1, 1}, {"read_stdin", 0, 0}, {"args", 0, 0},
    {"builtins", 0, 0}, {"rand_int", 2, 2}, {"rand_float", 0, 0}, {"rand_seed", 0, 1},
    {"run", 1, 2}, {"socket_open", 2, 4}, {"socket_read", 1, 1}, {"socket_write", 2, 2},
    {"socket_close", 1, 1}, {"term_raw", 1, 1}, {"term_read", 0, 1}, {"term_size", 0, 0},
    {"term_is_tty", 1, 1}, {"monotonic_time", 0, 0}, {"std_source", 1, 1},
    {"db_open", 1, 1}, {"db_run", 2, 3}, {"db_close", 1, 1},
    {"socket_listen", 2, 3}, {"socket_accept", 1, 2}, {"socket_port", 1, 1}, {"workers", 1, 1},
    {"time", 0, 0}, {"kind_name", 1, 1},
};
const int nbuiltins = sizeof builtin_info / sizeof builtin_info[0];

enum {
    B_LEN, B_SLICE, B_LOWER, B_UPPER, B_TRIM, B_SPLIT, B_JOIN, B_REPLACE, B_CONTAINS,
    B_STARTS_WITH, B_ENDS_WITH, B_INDEX_OF, B_REPEAT, B_CHR, B_ORD, B_TO_INT, B_TO_FLOAT, B_FLOOR,
    B_CEIL, B_ROUND, B_ABS, B_INTDIV, B_MIN, B_MAX, B_SUM, B_TO_STRING, B_IN_ARRAY, B_HAS_KEY, B_KEYS,
    B_VALUES, B_LAST, B_REVERSE, B_MAP, B_FILTER, B_REDUCE, B_SORT, B_TYPE_OF, B_IS_A, B_KIND_OF, B_FIELDS, B_OBJECT_ID, B_ERROR, B_EXIT, B_READ_FILE,
    B_WRITE_FILE, B_FILE_EXISTS, B_REAL_PATH, B_CWD, B_PRINT, B_PRINT_ERROR, B_READ_STDIN,
    B_ARGS, B_BUILTINS, B_RAND_INT, B_RAND_FLOAT, B_RAND_SEED, B_RUN,
    B_SOCKET_OPEN, B_SOCKET_READ, B_SOCKET_WRITE, B_SOCKET_CLOSE,
    B_TERM_RAW, B_TERM_READ, B_TERM_SIZE, B_TERM_IS_TTY,
    B_MONOTONIC_TIME, B_STD_SOURCE,
    B_DB_OPEN, B_DB_RUN, B_DB_CLOSE,
    B_SOCKET_LISTEN, B_SOCKET_ACCEPT, B_SOCKET_PORT, B_WORKERS,
    B_TIME, B_KIND_NAME,
};

int builtin_find(const char *name, size_t len) {
    for (int i = 0; i < nbuiltins; i++) {
        if (strlen(builtin_info[i].name) == len && !memcmp(builtin_info[i].name, name, len)) return i;
    }
    return -1;
}

/* The one value per builtin that PUSH_FN pushes, so == on builtins is identity */
Func *builtin_value(int index) {
    static Func *values[64];
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


/* "Function add expects 2 arguments, 1 given" */
bool raise_arity(const char *what, int lo, int hi, int argc) {
    if (lo == hi) return raisef("%s expects %d arguments, %d given", what, lo, argc);
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
    static const Type order[] = {T_INT, T_FLOAT, T_STRING, T_LIST, T_MAP, T_BOOL, T_NULL, T_KIND, T_OBJECT, T_SOCKET, T_DB};
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
 * taking from the right only when it is below zero. A new list, or NULL with the error raised.
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
        if (!call_value(compare, args, 2, &order)) goto fail;
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

static Value split(Str *s, Str *sep) {
    List *l = list_new(0);
    if (sep->len == 0) {
        for (size_t i = 0; i < s->len; i++) list_push(l, v_str(str_byte((unsigned char)s->data[i])));
        return v_list(l);
    }
    const char *p = s->data, *end = s->data + s->len;
    for (;;) {
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
    buf_adds(&path, "/gazlang-run-XXXXXX");
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
    pid_t pid;
    int err = posix_spawnp(&pid, argv[0], &actions, NULL, argv, environ);
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

static bool write_text(FILE *stream, Value v) {
    Buf b = {0};
    if (!append_string(v, &b)) {
        free(b.data);
        return false;
    }
    if (stream == stderr) flush_output();
    fwrite(b.data ? b.data : "", 1, b.len, stream);
    free(b.data);
    return true;
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
        /* The same whitespace the lexer skips: space, tab, newline, carriage return */
        if (!want(index, a, STRING)) return false;
        size_t from = 0, to = a.s->len;
        while (from < to && strchr(" \t\n\r", a.s->data[from]) && a.s->data[from]) from++;
        while (to > from && strchr(" \t\n\r", a.s->data[to - 1]) && a.s->data[to - 1]) to--;
        *out = v_string(a.s->data + from, to - from);
        return true;
    }
    case B_SPLIT:
        if (!want(index, a, STRING) || !want(index, b, STRING)) return false;
        *out = split(a.s, b.s);
        return true;
    case B_JOIN: {
        if (!want(index, b, STRING) || !want(index, a, M(T_LIST))) return false;
        Buf text = {0};
        for (size_t i = 0; i < a.l->len; i++) {
            if (i) buf_add_str(&text, b.s);
            if (!append_string(a.l->items[i], &text)) {
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
        if (!want(index, a, M(T_LIST) | M(T_MAP)) || !want(index, args[1], M(T_FUNCTION) | M(T_KIND))) return false;
        List *sorted;
        if (a.type == T_LIST) {
            sorted = merge_sort(a.l->items, a.l->len, args[1]);
        } else {
            /* The map's values, in order, as values() gives them (which can't fail on a map) */
            Value values;
            call_builtin(B_VALUES, args, 1, &values);
            sorted = merge_sort(values.l->items, values.l->len, args[1]);
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
    case B_ERROR:
        return raise_value(a);
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
        char chunk[65536];
        size_t n;
        while ((n = fread(chunk, 1, sizeof chunk, f)) > 0) buf_add(&text, chunk, n);
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
    case B_CWD: {
        char dir[PATH_MAX];
        if (!getcwd(dir, sizeof dir)) return raisef("Cannot get the working directory");
        *out = v_str(str_cstr(dir));
        return true;
    }
    case B_PRINT:
    case B_PRINT_ERROR:
        if (!write_text(index == B_PRINT ? output : stderr, a)) return false;
        *out = v_null();
        return true;
    case B_READ_STDIN: {
        Buf text = {0};
        /* Standard input main() read already, to see whether it was bytecode */
        if (piped_input) {
            buf_add(&text, piped_input, piped_input_len);
            free(piped_input);
            piped_input = NULL;
        }
        char chunk[65536];
        size_t n;
        while ((n = fread(chunk, 1, sizeof chunk, stdin)) > 0) buf_add(&text, chunk, n);
        *out = v_str(buf_to_str(&text));
        return true;
    }
    case B_ARGS: {
        List *l = list_new((size_t)program_argc);
        for (int i = 0; i < program_argc; i++) list_push(l, v_str(str_cstr(program_argv[i])));
        *out = v_list(l);
        return true;
    }
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
    case B_SOCKET_READ:
        if (!want(index, a, M(T_SOCKET))) return false;
        return net_read(a.sock, out);
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
    case B_WORKERS:
        if (!want(index, a, INT)) return false;
        return start_workers(a.i, out);
    case B_TIME:
        /* Whole seconds since 1 January 1970 UTC, the wall clock: it can jump when the clock is set,
           so monotonic_time() is what measures how long something took */
        *out = v_int((int64_t)time(NULL));
        return true;
    case B_DB_OPEN:
        if (!want(index, a, STRING)) return false;
        return db_open(a.s, out);
    case B_DB_RUN: {
        /* db_run($db, $sql, $params = []) */
        Value params = argc > 2 ? c : v_list(list_new(0));
        bool ok = want(index, a, M(T_DB)) && want(index, b, STRING) && want(index, params, M(T_LIST))
            && db_run(a.db, b.s, params.l, out);
        if (argc < 3) decref(params);
        return ok;
    }
    case B_DB_CLOSE:
        if (!want(index, a, M(T_DB))) return false;
        db_close(a.db);
        *out = v_null();
        return true;
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
    case B_STD_SOURCE: {
        /* The text of a standard library file, or null: what `include "std/name.gaz"` reads. GAZLIB, a
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
            char chunk[65536];
            size_t n;
            while ((n = fread(chunk, 1, sizeof chunk, f)) > 0) buf_add(&text, chunk, n);
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
        struct timespec now;
        if (clock_gettime(CLOCK_MONOTONIC, &now) != 0) return raisef("monotonic_time() failed: %s", strerror(errno));
        *out = v_float((double)now.tv_sec + (double)now.tv_nsec * 1e-9);
        return true;
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
    }
    return raisef("Unknown builtin: %d", index);
}
