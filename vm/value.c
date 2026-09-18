/*
 * Values: memory, strings, lists, maps, printing and equality (Runtime\Values, part 1)
 */
#include "gazvm.h"

#include <math.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>

/* ---- Memory ---------------------------------------------------------------------------- */

/* malloc that never returns NULL: running out of memory ends the program, as it does PHP */
void *xmalloc(size_t size) {
    void *p = malloc(size ? size : 1);
    if (!p) {
        fputs("Error: out of memory\n", stderr);
        exit(1);
    }
    return p;
}

void *xcalloc(size_t count, size_t size) {
    void *p = calloc(count ? count : 1, size ? size : 1);
    if (!p) {
        fputs("Error: out of memory\n", stderr);
        exit(1);
    }
    return p;
}

void *xrealloc(void *p, size_t size) {
    p = realloc(p, size ? size : 1);
    if (!p) {
        fputs("Error: out of memory\n", stderr);
        exit(1);
    }
    return p;
}

/* Every reference-counted value alive: strings, errors, and what gc.c tracks. Values kept for
   the whole run on purpose (interned and one-byte strings, named functions) are not counted.
   GAZVM_STATS reports what is left at the end, which is how the tests find a missing decref. */
int64_t counted;

/* Free a heap value whose reference count reached 0, dropping what it holds */
void value_free(Value v) {
    counted--;
    if (v.type >= T_LIST && v.type <= T_OBJECT) gc_untrack((Gc *)v.rc);
    switch (v.type) {
    case T_STRING:
        free(v.s);
        break;
    case T_LIST:
        for (size_t i = 0; i < v.l->len; i++) decref(v.l->items[i]);
        free(v.l->items);
        free(v.l);
        break;
    case T_MAP:
        for (size_t i = 0; i < v.m->used; i++) {
            decref(v.m->entries[i].key);
            decref(v.m->entries[i].value);
        }
        free(v.m->entries);
        free(v.m->index);
        free(v.m);
        break;
    case T_FUNCTION: {
        Func *f = v.fn;
        if (f->receiver) decref(v_object(f->receiver));
        if (f->captured) {
            for (int i = 0; i < f->lambda->block->ncaptures; i++) decref(f->captured[i]);
            free(f->captured);
        }
        free(f);
        break;
    }
    case T_OBJECT:
        for (int i = 0; i < v.o->cls->nfields; i++) decref(v.o->fields[i]);
        free(v.o);
        break;
    case T_ERROR: {
        Error *e = v.e;
        decref(v_str(e->reason));
        if (e->path) decref(v_str(e->path));
        decref(e->value);
        decref(e->trace);
        decref(e->caught);
        free(e);
        break;
    }
    default:
        break;
    }
}

/* ---- Strings --------------------------------------------------------------------------- */

Str *str_empty(size_t cap) {
    Str *s = xmalloc(sizeof(Str) + cap + 1);
    counted++;
    s->rc = 1;
    s->len = 0;
    s->cap = cap + 1;
    s->hash = 0;
    s->data[0] = '\0';
    return s;
}

Str *str_new(const char *data, size_t len) {
    Str *s = str_empty(len);
    if (len) memcpy(s->data, data, len);
    s->len = len;
    s->data[len] = '\0';
    return s;
}

Str *str_cstr(const char *text) { return str_new(text, strlen(text)); }

/* A one-byte string, shared and never freed: $s[$i] and chr() make one for every character a
   lexer reads, and allocating each was most of what such a loop spent on memory */
Str *str_byte(unsigned char byte) {
    static Str *bytes[256];
    if (!bytes[byte]) {
        char c = (char)byte;
        bytes[byte] = str_new(&c, 1);
        bytes[byte]->rc = INT64_MAX / 2;
        counted--;
    }
    return bytes[byte];
}

/* Append to a string nobody else holds, growing it by doubling so a loop of appends is linear.
   realloc may move it, so the caller must use the pointer this returns. */
Str *str_append(Str *s, const char *data, size_t len) {
    if (s->len + len + 1 > s->cap) {
        size_t cap = s->cap * 2;
        if (cap < s->len + len + 1) cap = s->len + len + 1;
        s = xrealloc(s, sizeof(Str) + cap);
        s->cap = cap;
    }
    memcpy(s->data + s->len, data, len);
    s->len += len;
    s->data[s->len] = '\0';
    s->hash = 0;
    return s;
}

bool str_eq(const Str *a, const Str *b) {
    return a == b || (a->len == b->len && memcmp(a->data, b->data, a->len) == 0);
}

/* Byte by byte, as PHP's strcmp: negative, zero or positive */
int str_cmp(const Str *a, const Str *b) {
    size_t n = a->len < b->len ? a->len : b->len;
    int c = memcmp(a->data, b->data, n);
    if (c != 0) return c;
    return a->len < b->len ? -1 : a->len > b->len;
}

/* FNV-1a, 64 bits; never 0, which means "not worked out yet" */
uint64_t str_hash(Str *s) {
    if (s->hash) return s->hash;
    uint64_t h = 14695981039346656037ULL;
    for (size_t i = 0; i < s->len; i++) {
        h ^= (unsigned char)s->data[i];
        h *= 1099511628211ULL;
    }
    s->hash = h ? h : 1;
    return s->hash;
}

/* The intern table: one Str per name, so names can be compared by pointer. Open addressing. */
static Str **interned;
static size_t interned_cap, interned_count;

Str *str_intern(const char *data, size_t len) {
    Str *probe = str_new(data, len);
    uint64_t h = str_hash(probe);
    if (interned_count * 2 >= interned_cap) {
        size_t cap = interned_cap ? interned_cap * 2 : 256;
        Str **table = xcalloc(cap, sizeof(Str *));
        for (size_t i = 0; i < interned_cap; i++) {
            if (!interned[i]) continue;
            size_t j = interned[i]->hash & (cap - 1);
            while (table[j]) j = (j + 1) & (cap - 1);
            table[j] = interned[i];
        }
        free(interned);
        interned = table;
        interned_cap = cap;
    }
    size_t i = h & (interned_cap - 1);
    while (interned[i]) {
        if (interned[i]->hash == h && str_eq(interned[i], probe)) {
            free(probe);
            counted--;
            return interned[i];
        }
        i = (i + 1) & (interned_cap - 1);
    }
    /* Interned strings live for the whole run: a count this large never reaches 0 */
    probe->rc = INT64_MAX / 2;
    counted--;
    interned[i] = probe;
    interned_count++;
    return probe;
}

/* ---- Buffers --------------------------------------------------------------------------- */

void buf_add(Buf *b, const char *data, size_t len) {
    if (b->len + len + 1 > b->cap) {
        size_t cap = b->cap ? b->cap * 2 : 64;
        while (cap < b->len + len + 1) cap *= 2;
        b->data = xrealloc(b->data, cap);
        b->cap = cap;
    }
    if (len) memcpy(b->data + b->len, data, len);
    b->len += len;
    b->data[b->len] = '\0';
}

void buf_adds(Buf *b, const char *s) { buf_add(b, s, strlen(s)); }
void buf_addc(Buf *b, char c) { buf_add(b, &c, 1); }
void buf_add_str(Buf *b, const Str *s) { buf_add(b, s->data, s->len); }

void buf_addf(Buf *b, const char *fmt, ...) {
    char small[256];
    va_list args;
    va_start(args, fmt);
    int n = vsnprintf(small, sizeof small, fmt, args);
    va_end(args);
    if (n < (int)sizeof small) {
        buf_add(b, small, (size_t)n);
        return;
    }
    char *big = xmalloc((size_t)n + 1);
    va_start(args, fmt);
    vsnprintf(big, (size_t)n + 1, fmt, args);
    va_end(args);
    buf_add(b, big, (size_t)n);
    free(big);
}

Str *buf_to_str(Buf *b) {
    Str *s = str_new(b->data ? b->data : "", b->len);
    free(b->data);
    b->data = NULL;
    b->len = b->cap = 0;
    return s;
}

/* ---- Lists ----------------------------------------------------------------------------- */

List *list_new(size_t cap) {
    List *l = xmalloc(sizeof(List));
    gc_track(&l->gc, T_LIST);
    l->len = 0;
    l->cap = cap;
    l->items = cap ? xmalloc(cap * sizeof(Value)) : NULL;
    return l;
}

void list_push(List *l, Value v) {
    if (l->len == l->cap) {
        l->cap = l->cap ? l->cap * 2 : 4;
        l->items = xrealloc(l->items, l->cap * sizeof(Value));
    }
    l->items[l->len++] = v;
}

/* Copy on write: make the list in a slot the slot's own before writing to it */
List *list_unique(Value *slot) {
    List *l = slot->l;
    if (l->gc.rc == 1) return l;
    List *copy = list_new(l->len);
    for (size_t i = 0; i < l->len; i++) {
        incref(l->items[i]);
        copy->items[i] = l->items[i];
    }
    copy->len = l->len;
    set_slot(slot, v_list(copy));
    return copy;
}

/* ---- Maps ------------------------------------------------------------------------------ */

static uint64_t key_hash(Value key) {
    if (key.type == T_STRING) return str_hash(key.s);
    /* splitmix64's finaliser: spreads consecutive ints across the buckets */
    uint64_t x = (uint64_t)key.i;
    x ^= x >> 30;
    x *= 0xbf58476d1ce4e5b9ULL;
    x ^= x >> 27;
    x *= 0x94d049bb133111ebULL;
    x ^= x >> 31;
    return x;
}

static bool key_eq(Value a, Value b) {
    if (a.type != b.type) return false;
    return a.type == T_INT ? a.i == b.i : str_eq(a.s, b.s);
}

Map *map_new(void) {
    Map *m = xcalloc(1, sizeof(Map));
    gc_track(&m->gc, T_MAP);
    return m;
}

/* The position in entries[] of a live key, or -1 */
static int64_t map_lookup(Map *m, Value key, uint64_t h) {
    if (!m->index_cap) return -1;
    size_t mask = m->index_cap - 1;
    for (size_t i = h & mask;; i = (i + 1) & mask) {
        int32_t e = m->index[i];
        if (e < 0) return -1;
        MapEntry *entry = &m->entries[e];
        if (entry->hash == h && entry->key.type != T_UNSET && key_eq(entry->key, key)) return e;
    }
}

Value *map_find(Map *m, Value key) {
    int64_t e = map_lookup(m, key, key_hash(key));
    return e < 0 ? NULL : &m->entries[e].value;
}

/* Rebuild the index, dropping removed entries, with room to grow */
static void map_rehash(Map *m, size_t want) {
    if (m->count < m->used) {
        size_t j = 0;
        for (size_t i = 0; i < m->used; i++) {
            if (m->entries[i].key.type != T_UNSET) m->entries[j++] = m->entries[i];
        }
        m->used = j;
    }
    size_t cap = 8;
    while (cap < want * 4) cap *= 2;
    free(m->index);
    m->index = xmalloc(cap * sizeof(int32_t));
    memset(m->index, 0xff, cap * sizeof(int32_t));   /* every bucket -1 */
    m->index_cap = cap;
    for (size_t i = 0; i < m->used; i++) {
        size_t b = m->entries[i].hash & (cap - 1);
        while (m->index[b] >= 0) b = (b + 1) & (cap - 1);
        m->index[b] = (int32_t)i;
    }
}

void map_set(Map *m, Value key, Value v) {
    uint64_t h = key_hash(key);
    int64_t e = map_lookup(m, key, h);
    if (e >= 0) {
        set_slot(&m->entries[e].value, v);
        return;
    }
    if ((m->used + 1) * 2 > m->index_cap) map_rehash(m, m->count + 1);
    if (m->used == m->cap) {
        m->cap = m->cap ? m->cap * 2 : 4;
        m->entries = xrealloc(m->entries, m->cap * sizeof(MapEntry));
    }
    incref(key);
    m->entries[m->used] = (MapEntry){key, v, h};
    size_t mask = m->index_cap - 1;
    size_t b = h & mask;
    while (m->index[b] >= 0) b = (b + 1) & mask;
    m->index[b] = (int32_t)m->used;
    m->used++;
    m->count++;
}

bool map_remove(Map *m, Value key) {
    int64_t e = map_lookup(m, key, key_hash(key));
    if (e < 0) return false;
    MapEntry *entry = &m->entries[e];
    Value k = entry->key, v = entry->value;
    entry->key = v_unset();
    entry->value = v_unset();
    m->count--;
    decref(k);
    decref(v);
    return true;
}

Map *map_unique(Value *slot) {
    Map *m = slot->m;
    if (m->gc.rc == 1) return m;
    Map *copy = map_new();
    for (size_t i = 0; map_next(m, &i); i++) {
        incref(m->entries[i].value);
        map_set(copy, m->entries[i].key, m->entries[i].value);
    }
    set_slot(slot, v_map(copy));
    return copy;
}

/* ---- What values mean ------------------------------------------------------------------ */

const char *type_name(Value v) {
    switch (v.type) {
    case T_NULL: return "null";
    case T_BOOL: return "bool";
    case T_INT: return "int";
    case T_FLOAT: return "float";
    case T_STRING: return "string";
    case T_LIST: return "list";
    case T_MAP: return "map";
    case T_FUNCTION: return "function";
    case T_OBJECT: return "object";
    case T_CLASS: return "class";
    case T_ERROR: return "GazLang\\GazLangError";
    case T_ENTRY: return "array";
    default: return "unset";
    }
}

bool is_truthy(Value v) {
    switch (v.type) {
    case T_BOOL: return v.b;
    case T_INT: return v.i != 0;
    case T_FLOAT: return v.f != 0.0;
    case T_STRING: return v.s->len != 0;
    case T_LIST: return v.l->len != 0;
    case T_MAP: return v.m->count != 0;
    case T_NULL:
    case T_UNSET: return false;
    default: return true;
    }
}

/*
 * A float as GazLang writes it: the shortest digits that read back as the same float, laid
 * out as PHP's var_export() does (zend_gcvt with 17 digits): 1.0, 0.30000000000000004,
 * 1.0E+25, 1.0E-5, -0.0. Always with a dot or an exponent, so it never looks like an int.
 */
void format_float(double f, Buf *out) {
    char buf[64];
    char digits[32];
    int decpt;
    if (f == 0.0) {
        strcpy(digits, "0");
        decpt = 1;
    } else {
        /* The shortest digits that read back as the same float: for each length, the correctly
           rounded digits printf gives, or failing those the neighbour on the value's other side,
           since next to a power of two the floats around it are not evenly spaced and only that
           one may read back. The two are the only candidates of that length, and the first that
           reads back is the shortest, and the closer of two, which is what dtoa gives.
           ponytail: up to 34 printf/strtod pairs per float; a Ryu-style algorithm if printing
           floats ever measures slow. */
        double a = fabs(f);
        uint64_t mantissa = 0;
        int exponent = 0, precision;
        for (precision = 0; precision < 17; precision++) {
            snprintf(buf, sizeof buf, "%.*e", precision, a);
            char *e = strchr(buf, 'e');
            exponent = atoi(e + 1);
            mantissa = 0;
            for (char *p = buf; p < e; p++) {
                if (*p != '.') mantissa = mantissa * 10 + (uint64_t)(*p - '0');
            }
            double rounded = strtod(buf, NULL);
            if (rounded == a) break;
            uint64_t other = rounded < a ? mantissa + 1 : mantissa - 1;
            if (other == 0) continue;
            snprintf(buf, sizeof buf, "%llue%d", (unsigned long long)other, exponent - precision);
            if (strtod(buf, NULL) == a) {
                mantissa = other;
                break;
            }
        }
        /* mantissa * 10^(exponent - precision) is the value; its digits without trailing zeros */
        int n = snprintf(digits, sizeof digits, "%llu", (unsigned long long)mantissa);
        decpt = n + exponent - precision;
        while (n > 1 && digits[n - 1] == '0') n--;
        digits[n] = '\0';
    }

    if (signbit(f)) buf_addc(out, '-');
    size_t start = out->len;
    int ndigits = (int)strlen(digits);
    if (decpt < 0 ? decpt < -3 : decpt > 17) {
        /* exponential: d.dddE+x */
        buf_addc(out, digits[0]);
        buf_addc(out, '.');
        if (ndigits == 1) buf_addc(out, '0');
        else buf_adds(out, digits + 1);
        int exponent = decpt - 1;
        buf_addf(out, "E%c%d", exponent < 0 ? '-' : '+', abs(exponent));
    } else if (decpt < 0) {
        /* 0.000ddd */
        buf_adds(out, "0.");
        for (int i = decpt; i < 0; i++) buf_addc(out, '0');
        buf_adds(out, digits);
    } else {
        for (int i = 0; i < decpt; i++) buf_addc(out, i < ndigits ? digits[i] : '0');
        if (decpt < ndigits) {
            if (decpt == 0) buf_addc(out, '0');
            buf_addc(out, '.');
            buf_adds(out, digits + decpt);
        }
    }
    if (!memchr(out->data + start, '.', out->len - start) && !memchr(out->data + start, 'E', out->len - start)) {
        buf_adds(out, ".0");
    }
}

/*
 * A string as a double-quoted GazLang literal that reads back as the same bytes (Lexer::quote):
 * named escapes, \xHH for other control bytes and NUL, and \$ and \{ only where they would
 * otherwise interpolate
 */
void quote(const Str *s, Buf *out) {
    buf_addc(out, '"');
    for (size_t i = 0; i < s->len; i++) {
        unsigned char c = (unsigned char)s->data[i];
        unsigned char next = i + 1 < s->len ? (unsigned char)s->data[i + 1] : 0;
        const char *named = NULL;
        switch (c) {
        case '\n': named = "\\n"; break;
        case '\t': named = "\\t"; break;
        case '\r': named = "\\r"; break;
        case '\v': named = "\\v"; break;
        case '\f': named = "\\f"; break;
        case 0x1b: named = "\\e"; break;
        case '\\': named = "\\\\"; break;
        case '"': named = "\\\""; break;
        case '$':
            if ((next >= 'A' && next <= 'Z') || (next >= 'a' && next <= 'z') || next == '_') named = "\\$";
            break;
        case '{':
            if (next == '$' || next == '@' || next == '#') named = "\\{";
            break;
        }
        if (named) buf_adds(out, named);
        else if (c < 0x20 || c == 0x7f) buf_addf(out, "\\x%02X", c);
        else buf_addc(out, (char)c);
    }
    buf_addc(out, '"');
}

/* How a function is named in output and messages: add, Point.area, or -> at file.gaz:12 */
static void describe_function(Func *f, Buf *out) {
    if (f->kind == F_BOUND) {
        buf_add_str(out, f->cls->name);
        buf_addc(out, '.');
        buf_add_str(out, f->name);
    } else if (f->kind == F_CLOSURE) {
        buf_adds(out, "-> ");
        location_text(f->file, f->line, out);
    } else {
        buf_add_str(out, f->name);
    }
}

static bool append_literal(Value v, Buf *out) {
    if (v.type == T_STRING) {
        quote(v.s, out);
        return true;
    }
    return append_string(v, out);
}

/* An object as its class and the fields that are set: Account {#owner => "Werner"} */
static bool describe_object(Object *o, Buf *out) {
    buf_add_str(out, o->cls->name);
    if (o->printing) {
        buf_adds(out, " {...}");
        return true;
    }
    o->printing = true;
    buf_adds(out, " {");
    bool first = true, ok = true;
    for (int i = 0; i < o->cls->nfields && ok; i++) {
        if (o->fields[i].type == T_UNSET) continue;
        if (!first) buf_adds(out, ", ");
        first = false;
        buf_addc(out, '#');
        buf_add_str(out, o->cls->fields[i]);
        buf_adds(out, " => ");
        ok = append_literal(o->fields[i], out);
    }
    o->printing = false;
    buf_addc(out, '}');
    return ok;
}

/* The text echo prints and .. joins, appended to a buffer */
bool append_string(Value v, Buf *out) {
    switch (v.type) {
    case T_STRING:
        buf_add_str(out, v.s);
        return true;
    case T_INT:
        buf_addf(out, "%lld", (long long)v.i);
        return true;
    case T_FLOAT:
        format_float(v.f, out);
        return true;
    case T_BOOL:
        buf_adds(out, v.b ? "true" : "false");
        return true;
    case T_NULL:
        buf_adds(out, "null");
        return true;
    case T_FUNCTION:
        buf_adds(out, "function ");
        describe_function(v.fn, out);
        return true;
    case T_LIST:
        buf_addc(out, '[');
        for (size_t i = 0; i < v.l->len; i++) {
            if (i) buf_adds(out, ", ");
            if (!append_literal(v.l->items[i], out)) return false;
        }
        buf_addc(out, ']');
        return true;
    case T_MAP: {
        buf_addc(out, '{');
        bool first = true;
        for (size_t i = 0; map_next(v.m, &i); i++) {
            if (!first) buf_adds(out, ", ");
            first = false;
            append_literal(v.m->entries[i].key, out);
            buf_adds(out, " => ");
            if (!append_literal(v.m->entries[i].value, out)) return false;
        }
        buf_addc(out, '}');
        return true;
    }
    case T_OBJECT: {
        static Str *to_string_name;
        if (!to_string_name) to_string_name = str_intern("to_string", 9);
        int m = class_method(v.o->cls, to_string_name);
        if (m < 0) return describe_object(v.o, out);
        Class *definer = v.o->cls->definers[m];
        Value text;
        if (!call_method(v.o, definer, to_string_name, &text)) return false;
        if (text.type != T_STRING) {
            const char *type = type_name(text);
            decref(text);
            return raise("%s.to_string must return a string, got %s", definer->name->data, type);
        }
        buf_add_str(out, text.s);
        decref(text);
        return true;
    }
    case T_CLASS:
        buf_adds(out, "class ");
        buf_add_str(out, v.c->name);
        return true;
    default:
        return raise("Cannot convert %s to string", type_name(v));
    }
}

bool to_string(Value v, Str **out) {
    if (v.type == T_STRING) {
        incref(v);
        *out = v.s;
        return true;
    }
    Buf b = {0};
    if (!append_string(v, &b)) {
        free(b.data);
        return false;
    }
    *out = buf_to_str(&b);
    return true;
}

/*
 * Order two numbers exactly, as Values::compare(): an int and a float compare as the numbers
 * they are, not by turning the int into a float (9007199254740993 != 9007199254740992.0)
 */
int compare_numbers(Value a, Value b) {
    if (a.type == T_INT && b.type == T_INT) return (a.i > b.i) - (a.i < b.i);
    if (a.type == T_FLOAT && b.type == T_FLOAT) return (a.f > b.f) - (a.f < b.f);
    int64_t i;
    double f;
    int sign;
    if (a.type == T_INT) {
        i = a.i, f = b.f, sign = 1;
    } else {
        i = b.i, f = a.f, sign = -1;
    }
    if (floor(f) != f) {
        double x = (double)i;
        return sign * ((x > f) - (x < f));
    }
    if (f >= 9223372036854775808.0) return -sign;
    if (f < -9223372036854775808.0) return sign;
    int64_t j = (int64_t)f;
    return sign * ((i > j) - (i < j));
}

/*
 * ==, as Values::equals(): no conversion between strings and numbers, numbers by value,
 * lists element by element, maps by keys and values in any order, functions, classes and
 * objects by identity (two bound methods when they bind the same method to the same object)
 */
bool values_equal(Value a, Value b) {
    switch (a.type) {
    case T_NULL:
        return b.type == T_NULL;
    case T_BOOL:
        return b.type == T_BOOL && a.b == b.b;
    case T_INT:
    case T_FLOAT:
        return (b.type == T_INT || b.type == T_FLOAT) && compare_numbers(a, b) == 0;
    case T_STRING:
        return b.type == T_STRING && str_eq(a.s, b.s);
    case T_LIST:
        if (b.type != T_LIST) return false;
        if (a.l == b.l) return true;
        if (a.l->len != b.l->len) return false;
        for (size_t i = 0; i < a.l->len; i++) {
            if (!values_equal(a.l->items[i], b.l->items[i])) return false;
        }
        return true;
    case T_MAP:
        if (b.type != T_MAP) return false;
        if (a.m == b.m) return true;
        if (a.m->count != b.m->count) return false;
        for (size_t i = 0; map_next(a.m, &i); i++) {
            Value *other = map_find(b.m, a.m->entries[i].key);
            if (!other || !values_equal(a.m->entries[i].value, *other)) return false;
        }
        return true;
    case T_FUNCTION:
        if (b.type != T_FUNCTION) return false;
        if (a.fn == b.fn) return true;
        return a.fn->kind == F_BOUND && b.fn->kind == F_BOUND && a.fn->receiver == b.fn->receiver
            && a.fn->cls == b.fn->cls && a.fn->name == b.fn->name;
    case T_OBJECT:
        return b.type == T_OBJECT && a.o == b.o;
    case T_CLASS:
        return b.type == T_CLASS && a.c == b.c;
    default:
        return false;
    }
}

/* Decimal digits with an optional minus that fit an int (Lexer::parse_integer) */
bool parse_integer(const char *s, size_t len, int64_t *out) {
    size_t i = 0;
    bool negative = false;
    if (i < len && s[i] == '-') negative = true, i++;
    if (i == len) return false;
    /* Accumulate as a negative number, whose range reaches the smallest int */
    int64_t value = 0;
    for (; i < len; i++) {
        if (s[i] < '0' || s[i] > '9') return false;
        int digit = s[i] - '0';
        if (__builtin_mul_overflow(value, 10, &value) || __builtin_sub_overflow(value, digit, &value)) return false;
    }
    if (!negative) {
        if (value == INT64_MIN) return false;
        value = -value;
    }
    *out = value;
    return true;
}

/* A GazLang number literal with an optional minus (Lexer::parse_number), for to_float() */
bool parse_number(const char *s, size_t len, Value *out) {
    size_t i = 0;
    if (i < len && s[i] == '-') i++;
    size_t digits = i;
    while (i < len && s[i] >= '0' && s[i] <= '9') i++;
    if (i == digits) return false;
    bool fraction = false, exponent = false;
    if (i < len && s[i] == '.') {
        size_t start = ++i;
        while (i < len && s[i] >= '0' && s[i] <= '9') i++;
        if (i == start) return false;
        fraction = true;
    }
    if (i < len && (s[i] == 'e' || s[i] == 'E')) {
        i++;
        if (i < len && (s[i] == '+' || s[i] == '-')) i++;
        size_t start = i;
        while (i < len && s[i] >= '0' && s[i] <= '9') i++;
        if (i == start) return false;
        exponent = true;
    }
    if (i != len) return false;
    if (!fraction && !exponent) {
        int64_t n;
        if (!parse_integer(s, len, &n)) return false;
        *out = v_int(n);
        return true;
    }
    char *copy = xmalloc(len + 1);
    memcpy(copy, s, len);
    copy[len] = '\0';
    double f = strtod(copy, NULL);
    free(copy);
    if (!isfinite(f)) return false;
    *out = v_float(f);
    return true;
}
