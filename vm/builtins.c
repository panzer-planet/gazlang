/*
 * The builtin functions (Runtime\Builtins)
 *
 * The loader checks every call's builtin exists and the parser checked its argument count;
 * argument types are checked here, in the order the PHP checks them, so a call with two bad
 * arguments names the same one.
 */
#include "gazvm.h"

#include <errno.h>
#include <limits.h>
#include <math.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

int program_argc;
char **program_argv;

/* In the order of Builtins::ARITIES, which builtins() gives */
const BuiltinInfo builtin_info[] = {
    {"len", 1, 1}, {"slice", 2, 3}, {"lower", 1, 1}, {"upper", 1, 1}, {"trim", 1, 1},
    {"split", 2, 2}, {"join", 2, 2}, {"replace", 3, 3}, {"contains", 2, 2}, {"starts_with", 2, 2},
    {"ends_with", 2, 2}, {"index_of", 2, 3}, {"repeat", 2, 2}, {"chr", 1, 1}, {"ord", 1, 1},
    {"to_int", 1, 1}, {"to_float", 1, 1}, {"floor", 1, 1}, {"ceil", 1, 1}, {"round", 1, 2},
    {"abs", 1, 1}, {"intdiv", 2, 2}, {"min", 2, 2}, {"max", 2, 2}, {"to_string", 1, 1},
    {"in_array", 2, 2}, {"has_key", 2, 2}, {"keys", 1, 1}, {"values", 1, 1}, {"type_of", 1, 1},
    {"is_a", 2, 2}, {"class_of", 1, 1}, {"fields", 1, 1}, {"error", 1, 1}, {"exit", 0, 1},
    {"read_file", 1, 1}, {"write_file", 2, 2}, {"file_exists", 1, 1}, {"real_path", 1, 1},
    {"cwd", 0, 0}, {"print", 1, 1}, {"print_error", 1, 1}, {"read_stdin", 0, 0}, {"args", 0, 0},
    {"builtins", 0, 0},
};
const int nbuiltins = sizeof builtin_info / sizeof builtin_info[0];

enum {
    B_LEN, B_SLICE, B_LOWER, B_UPPER, B_TRIM, B_SPLIT, B_JOIN, B_REPLACE, B_CONTAINS,
    B_STARTS_WITH, B_ENDS_WITH, B_INDEX_OF, B_REPEAT, B_CHR, B_ORD, B_TO_INT, B_TO_FLOAT, B_FLOOR,
    B_CEIL, B_ROUND, B_ABS, B_INTDIV, B_MIN, B_MAX, B_TO_STRING, B_IN_ARRAY, B_HAS_KEY, B_KEYS,
    B_VALUES, B_TYPE_OF, B_IS_A, B_CLASS_OF, B_FIELDS, B_ERROR, B_EXIT, B_READ_FILE,
    B_WRITE_FILE, B_FILE_EXISTS, B_REAL_PATH, B_CWD, B_PRINT, B_PRINT_ERROR, B_READ_STDIN,
    B_ARGS, B_BUILTINS,
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
        f->rc = INT64_MAX / 2;
        f->kind = F_BUILTIN;
        f->builtin = index;
        f->name = str_intern(builtin_info[index].name, strlen(builtin_info[index].name));
        values[index] = f;
    }
    return values[index];
}

bool arity_fits(int lo, int hi, int argc) { return argc >= lo && argc <= hi; }

/* "Function add expects 2 arguments, 1 given" (Builtins::arityError) */
bool raise_arity(const char *what, int lo, int hi, int argc) {
    if (lo == hi) return raise("%s expects %d arguments, %d given", what, lo, argc);
    return raise("%s expects %d to %d arguments, %d given", what, lo, hi, argc);
}

/* Type masks for argument checks */
#define M(t) (1u << (t))

/* Check an argument's type: "len() expects list or map or string, got int" */
static bool want(int builtin, Value v, unsigned mask) {
    if (mask & M(v.type)) return true;
    static const Type order[] = {T_INT, T_FLOAT, T_STRING, T_LIST, T_MAP, T_NULL, T_CLASS, T_OBJECT};
    Buf b = {0};
    /* The PHP lists the types in the order each builtin names them; these are those orders */
    const char *names = NULL;
    switch (mask) {
    case M(T_LIST) | M(T_MAP) | M(T_STRING): names = "list or map or string"; break;
    case M(T_INT) | M(T_NULL): names = "int or null"; break;
    case M(T_STRING) | M(T_LIST): names = "string or list"; break;
    case M(T_INT) | M(T_FLOAT): names = "int or float"; break;
    case M(T_LIST) | M(T_MAP): names = "list or map"; break;
    }
    if (!names) {
        for (size_t i = 0; i < sizeof order / sizeof order[0]; i++) {
            if (mask & M(order[i])) {
                if (b.len) buf_adds(&b, " or ");
                buf_adds(&b, type_name((Value){.type = order[i]}));
            }
        }
    }
    raise("%s() expects %s, got %s", builtin_info[builtin].name, names ? names : b.data, type_name(v));
    free(b.data);
    return false;
}

static Value v_string(const char *data, size_t len) { return v_str(str_new(data, len)); }

/* The part of a length-n string or list that slice() takes, with PHP's substr rules */
static bool slice_bounds(int64_t n, int64_t start, bool has_length, int64_t length, int64_t *from, int64_t *count) {
    if (start > n) return false;
    if (start < 0) start = -start > n ? 0 : n + start;
    int64_t rest = n - start;
    if (!has_length) {
        length = rest;
    } else if (length < 0) {
        if (-length > rest) return false;
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
        for (size_t i = 0; i < s->len; i++) list_push(l, v_string(s->data + i, 1));
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

static bool to_int(Value v, Value *out) {
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

static bool to_float(Value v, Value *out) {
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
        char buf[40];
        snprintf(buf, 39, "%15fe%d", tmp, -places);
        buf[39] = '\0';
        tmp = strtod(buf, NULL);
        if (!isfinite(tmp)) tmp = value;
    }
    return tmp;
}

/* min() and max() order two numbers or two strings as < does */
static bool extreme(int builtin, Value a, Value b, int *cmp) {
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
    return raise("%s() expects two numbers or two strings, got %s and %s", builtin_info[builtin].name, type_name(a), type_name(b));
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
        if (b.s->len == 0) return raise("replace() cannot search for an empty string");
        *out = replace(a.s, b.s, c.s);
        return true;
    case B_CONTAINS:
    case B_STARTS_WITH:
    case B_ENDS_WITH:
        if (!want(index, a, STRING) || !want(index, b, STRING)) return false;
        if (index == B_CONTAINS) *out = v_bool(find(a.s->data, a.s->len, b.s->data, b.s->len) != NULL);
        else if (b.s->len > a.s->len) *out = v_bool(false);
        else if (index == B_STARTS_WITH) *out = v_bool(memcmp(a.s->data, b.s->data, b.s->len) == 0);
        else *out = v_bool(memcmp(a.s->data + a.s->len - b.s->len, b.s->data, b.s->len) == 0);
        return true;
    case B_INDEX_OF: {
        Value offset = argc > 2 ? c : v_int(0);
        if (!want(index, a, STRING) || !want(index, b, STRING) || !want(index, offset, INT)) return false;
        if (b.s->len == 0) return raise("index_of() cannot search for an empty string");
        int64_t n = (int64_t)a.s->len, at = offset.i;
        if (at > n || at < -n) return raise("index_of() offset %lld is outside the string", (long long)at);
        if (at < 0) at += n;
        const char *hit = find(a.s->data + at, (size_t)(n - at), b.s->data, b.s->len);
        *out = hit ? v_int(hit - a.s->data) : v_null();
        return true;
    }
    case B_REPEAT: {
        if (!want(index, a, STRING) || !want(index, b, INT)) return false;
        if (b.i < 0) return raise("repeat() count must not be negative, got %lld", (long long)b.i);
        Str *s = str_empty(a.s->len * (size_t)b.i);
        for (int64_t i = 0; i < b.i; i++) s = str_append(s, a.s->data, a.s->len);
        *out = v_str(s);
        return true;
    }
    case B_CHR: {
        if (!want(index, a, INT)) return false;
        if (a.i < 0 || a.i > 255) return raise("chr() expects a byte value from 0 to 255, got %lld", (long long)a.i);
        char ch = (char)a.i;
        *out = v_string(&ch, 1);
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
        return to_int(a, out);
    case B_TO_FLOAT:
        return to_float(a, out);
    case B_FLOOR:
    case B_CEIL:
        if (!want(index, a, INT | M(T_FLOAT))) return false;
        if (a.type == T_INT) *out = v_float((double)a.i);
        else *out = v_float(index == B_FLOOR ? floor(a.f) : ceil(a.f));
        return true;
    case B_ROUND: {
        Value places = argc > 1 ? b : v_int(0);
        if (!want(index, a, INT | M(T_FLOAT)) || !want(index, places, INT)) return false;
        /* A zend_long becomes an int on the way into _php_math_round, as a C cast does */
        int p = (int)places.i;
        if (a.type == T_INT && places.i >= 0) *out = v_float((double)a.i);
        else *out = v_float(php_round(a.type == T_INT ? (double)a.i : a.f, p));
        return true;
    }
    case B_ABS:
        if (!want(index, a, INT | M(T_FLOAT))) return false;
        if (a.type == T_FLOAT) {
            *out = v_float(fabs(a.f));
            return true;
        }
        if (a.i == INT64_MIN) return raise("Integer overflow");
        *out = v_int(a.i < 0 ? -a.i : a.i);
        return true;
    case B_INTDIV:
        if (!want(index, a, INT) || !want(index, b, INT)) return false;
        if (b.i == 0) return raise("Division by zero");
        if (a.i == INT64_MIN && b.i == -1) return raise("Integer overflow");
        *out = v_int(a.i / b.i);
        return true;
    case B_MIN:
    case B_MAX: {
        int cmp;
        if (!extreme(index, a, b, &cmp)) return false;
        *out = (index == B_MIN ? cmp <= 0 : cmp >= 0) ? a : b;
        incref(*out);
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
        if (b.type != T_INT) return raise("List indexes must be int, got string");
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
    case B_TYPE_OF:
        *out = v_str(str_cstr(type_name(a)));
        return true;
    case B_IS_A:
        if (!want(index, b, M(T_CLASS))) return false;
        *out = v_bool(a.type == T_OBJECT && class_is_a(a.o->cls, b.c));
        return true;
    case B_CLASS_OF:
        if (!want(index, a, M(T_OBJECT))) return false;
        *out = v_class(a.o->cls);
        return true;
    case B_FIELDS: {
        if (!want(index, a, M(T_OBJECT))) return false;
        Map *m = map_new();
        for (int i = 0; i < a.o->cls->nfields; i++) {
            if (a.o->fields[i].type == T_UNSET) continue;
            incref(a.o->fields[i]);
            map_set(m, v_str(a.o->cls->fields[i]), a.o->fields[i]);
        }
        *out = v_map(m);
        return true;
    }
    case B_ERROR:
        return raise_value(a);
    case B_EXIT: {
        Value code = argc > 0 ? a : v_int(0);
        if (!want(index, code, INT)) return false;
        if (code.i < 0 || code.i > 255) return raise("exit() expects a code from 0 to 255, got %lld", (long long)code.i);
        /* Not an error, so no try sees it and no finally runs: the program simply ends */
        flush_output();
        exit((int)code.i);
    }
    case B_READ_FILE: {
        if (!want(index, a, STRING)) return false;
        struct stat st;
        FILE *f = NULL;
        if (!memchr(a.s->data, '\0', a.s->len) && stat(a.s->data, &st) == 0 && S_ISREG(st.st_mode) && access(a.s->data, R_OK) == 0) {
            f = fopen(a.s->data, "rb");
        }
        if (!f) return raise("Cannot read file: %s", a.s->data);
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
            return raise("Cannot write file: %s", a.s->data);
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
        if (!getcwd(dir, sizeof dir)) return raise("Cannot get the working directory");
        *out = v_str(str_cstr(dir));
        return true;
    }
    case B_PRINT:
    case B_PRINT_ERROR:
        if (!write_text(index == B_PRINT ? stdout : stderr, a)) return false;
        *out = v_null();
        return true;
    case B_READ_STDIN: {
        Buf text = {0};
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
    return raise("Unknown builtin: %d", index);
}
