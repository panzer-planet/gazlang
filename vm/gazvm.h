/*
 * gazvm: the GazLang VM in C. Runs the bytecode `gazlang -c` writes (docs/bytecode.md), or
 * source, which it first compiles with the self-hosted compiler built into it (see run() in vm.c).
 *
 * What every operator, builtin, error message and location does is defined here, and
 * tests/CVMTest.php checks each program's output against the output recorded for it.
 *
 * Files:
 *   gazvm.h     this: the value model and everything the files share
 *   value.c     memory, strings, lists, maps, printing, equality, truthiness
 *   ops.c       operators, indexing, members, write paths
 *   load.c      reading and checking a bytecode file, and the superinstructions
 *   vm.c        the dispatch loop, calls, errors and traces, and main() with the CLI
 *   builtins.c  the builtin functions and their arities
 *   gc.c        the cycle collector, for what reference counting can't free
 *   net.c       sockets, and TLS through OpenSSL (the one file that includes it)
 *
 * Errors: a function that can fail returns bool, false meaning an error was raised. The
 * error itself is in `vm_error` (see raisef()), and the caller passes the false up until the
 * dispatch loop catches it. No setjmp/longjmp, so nothing is skipped on the way out and every
 * reference count stays right.
 */
#ifndef GAZVM_H
#define GAZVM_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>

#define MAX_CALL_DEPTH 10000

/* ---- Values ---------------------------------------------------------------------------- */

typedef enum {
    T_UNSET,    /* a variable or field that was never set; zeroed memory is unset */
    T_NULL,
    T_BOOL,
    T_INT,
    T_FLOAT,
    T_STRING,   /* from here to T_ERROR: on the heap and reference counted */
    T_LIST,
    T_MAP,
    T_FUNCTION,
    T_OBJECT,
    T_SOCKET,   /* a connection, closed when the last reference goes */
    T_ERROR,    /* a raised error, as the stack holds it in a catch or finally block */
    T_KIND,    /* lives as long as the program: not counted */
    T_ENTRY,    /* the method entry GET_METHOD pushes: points into a kind, not counted */
} Type;

#define IS_HEAP(t) ((t) >= T_STRING && (t) <= T_ERROR)

typedef struct Str Str;
typedef struct List List;
typedef struct Map Map;
typedef struct Func Func;
typedef struct Object Object;
typedef struct Error Error;
typedef struct Kind Kind;
typedef struct Entry Entry;
typedef struct Socket Socket;

/* One value: a type tag and a payload. 16 bytes, passed around by value. */
typedef struct Value {
    Type type;
    union {
        bool b;
        int64_t i;
        double f;
        int64_t *rc;    /* every heap object starts with its reference count */
        Str *s;
        List *l;
        Map *m;
        Func *fn;
        Object *o;
        Error *e;
        Kind *k;
        Entry *entry;
        Socket *sock;
    };
} Value;

/* A byte string. data always has a NUL after the last byte, for C functions that want one,
   but a string may hold NULs of its own, so len is the length. */
struct Str {
    int64_t rc;
    size_t len, cap;    /* cap: bytes allocated for data, the NUL included */
    uint64_t hash;      /* 0 until worked out */
    char data[];        /* a "flexible array member": the bytes follow the struct */
};

/* The start of every value that can be part of a cycle: a list, map, object or function. The
   count comes first, where incref() finds every heap value's. Tracked ones are linked into the
   cycle collector's list (see gc.c). */
typedef struct Gc {
    int64_t rc;
    struct Gc *prev, *next;     /* the collector's list; both NULL when not tracked */
    int64_t refs;               /* the collector's scratch count */
    Type type;
} Gc;

/* A list: a growable array of values. Copy on write: a list held by two variables is shared
   until one of them writes, which copies it first (see list_unique()). */
struct List {
    Gc gc;
    size_t len, cap;
    Value *items;
};

/* A map entry; a removed entry keeps its place with key.type == T_UNSET until compacted */
typedef struct {
    Value key;          /* T_INT or T_STRING; "1" and 1 are different keys */
    Value value;
    uint64_t hash;
} MapEntry;

/* A map: entries in insertion order, plus a hash index into them (like PHP's arrays).
   index[] holds entry positions, -1 for an empty bucket; it is open addressing with linear
   probing, and a removed entry's bucket stays taken, so probing never stops early. */
struct Map {
    Gc gc;
    size_t count;       /* live entries */
    size_t used;        /* entries[] slots used, removed ones included */
    size_t cap;         /* entries[] slots allocated */
    MapEntry *entries;
    int32_t *index;
    size_t index_cap;   /* a power of two, or 0 before the first insert */
};

typedef struct Block Block;
typedef struct Function Function;
typedef struct Lambda Lambda;

typedef enum { F_NAMED, F_BUILTIN, F_CLOSURE, F_BOUND } FuncKind;

/* A function as a value: a named function or builtin (one shared value per name, so == is
   identity), a closure, or a method bound to an object */
struct Func {
    Gc gc;
    FuncKind kind;
    Str *name;          /* named, builtin, bound: the name */
    Function *function; /* named: what to run */
    int builtin;        /* builtin: its index in the builtin table */
    Kind *definer;      /* bound: the kind whose version runs */
    Object *receiver;   /* bound, and a closure made in a method: the object # is (counted) */
    Lambda *lambda;     /* closure: its lambda */
    Value *captured;    /* closure: its captured variables, by capture index */
    Str *file;          /* closure: where it was made, NULL for piped source */
    int64_t line;
};

/* An object: a handle, so == is identity. fields[] holds one value per field of its kind,
   in layout order; a field never set is T_UNSET. */
struct Object {
    Gc gc;
    Kind *kind;
    int64_t id;         /* object_id(): 1 for the program's first object, 2 for the next, never reused */
    bool printing;      /* while echo prints it, so one that holds itself prints Name {...} */
    Value fields[];
};

/* A connection made by socket_open(): a handle, so copies share it */
struct Socket {
    int64_t rc;
    int fd;             /* -1 once closed */
    void *tls;          /* OpenSSL's SSL *, or NULL for plain TCP: void, so only net.c needs OpenSSL */
    int timeout_ms;     /* for each read and write */
};

/* An error on its way up. */
struct Error {
    int64_t rc;
    Str *reason;        /* the message without the location */
    Str *path;          /* the file, NULL for piped source */
    int64_t line;       /* 0 when unknown */
    bool show_location; /* false for error("text") */
    bool gaz;           /* a GazLang error: false only for an error not yet given a location,
                           which a try doesn't catch if it never gets one */
    bool has_value;     /* error($v) with anything but a string: catch gets $v */
    Value value;
    Value trace;        /* a list of strings, or T_UNSET until recorded */
    Value caught;       /* what catch sees, once worked out (T_UNSET until then) */
};

/* ---- The program ----------------------------------------------------------------------- */

/* A method as GET_METHOD finds it: where the version that runs starts */
struct Entry {
    Str *name;          /* the method's name */
    Str *key;           /* "Kind.name" of the version that runs */
    Function *function;
};

/* What a member escapes: only its kind, its kind and what extends it, or anyone */
typedef enum { V_OWN, V_KIN, V_PUB } Vis;

struct Kind {
    Str *name;
    Kind *parent;
    bool abstract;
    int nfields;
    Str **fields;       /* field names in layout order (interned, so == compares them) */
    Kind **field_declarers;  /* the kind declaring each, which its visibility is against */
    Vis *field_vis;
    int nmethods;
    Str **methods;      /* every method it can call, the constructor _ included */
    Kind **definers;    /* the kind whose version of each runs */
    Kind **method_declarers; /* the kind that declared each, which its visibility is against */
    Vis *method_vis;
    Entry *entries;     /* each method's entry, parallel to methods (the constructor's too) */
    int lo, hi;         /* the constructor's arity, 0 0 without one */
    Block *block;       /* the code that makes an object */
};

typedef enum { B_TOP, B_FN, B_KIND, B_LAMBDA } BlockKind;

struct Block {
    BlockKind kind;
    Str *name;          /* fn: its name; kind: the kind name */
    Str *key;           /* '' top, name, "new Kind", "->n": what traces and messages call it */
    int index;          /* lambda: its index */
    int lo, hi;         /* fn, lambda: arity */
    int nlocals;
    Str **locals;       /* the variable in each slot */
    int ncaptures;
    Str **captures;     /* lambda: the captured variables, by capture index */
    int self;           /* lambda: the capture that holds the closure itself, or -1 */
    int nmap;
    struct { bool from_closure; int outer; int inner; } *map;   /* lambda: where each capture comes from */
    bool is_abstract;   /* kind */
    Str *parent;        /* kind: the parent's name, or NULL */
    /* The kind this block's code is written in, or NULL: what a member use in it is asked for
       by. A method's own name carries it; a static method's and a lambda's header say "in K". */
    Str *owner_name;
    Kind *owner;
    int nfields;
    Str **field_names, **field_declarers;
    Vis *field_vis;
    int nmethods;
    Str **method_names, **method_definers, **method_declarers;
    Vis *method_vis;
    int entry;          /* its first instruction in the program's code */
    int max_stack;      /* the greatest stack depth the loader's walk found */
    int line_no;        /* the line of its header, for nothing but debugging */
    /* while loading */
    bool objectless;    /* it can run without an object (see mark_objectless()) */
    struct RawInstr *raw;
    int nraw;
    int label_cap;      /* the labels by name (see find_label()), 0 until looked up */
    Str **label_names;
    int *label_at;
};

struct Function {
    Str *name;          /* "f", or "Kind.name" for a method */
    int lo, hi;
    Block *block;
    Func *value;        /* the one value PUSH_FN pushes for it */
};

struct Lambda {
    int index;
    Block *block;
};

/* A write path's step: a key from the stack, a field, or an append */
typedef enum { S_KEY, S_FIELD, S_APPEND } StepKind;
typedef struct {
    StepKind kind;
    Str *name;          /* S_FIELD */
} PathStep;
typedef struct {
    int nsteps;
    int nkeys;
    bool concat;        /* ends in ..=: what the steps reach is appended to, not replaced */
    PathStep steps[];
} Path;

/* One instruction, after loading: names resolved to what they name */
typedef struct Instr {
    uint8_t op;
    uint8_t orig;       /* the instruction the file had here, which op replaces with a superinstruction */
    int32_t a, b;       /* a slot, count, jump target, builtin index... per instruction */
    void *p;            /* a Function, Kind, Lambda, Path or member name */
    Value v;            /* PUSH's value; PUSH_FN's function */
    Str *file;          /* where it came from; file NULL and line 0 when unknown */
    int32_t line;
    /* A member instruction's inline cache: the kind it last found the member in, and where
       (a field's slot or a method's position), so the next object of that kind needs no search */
    int32_t cached_at;
    Kind *cached_kind;
} Instr;

typedef struct Program {
    Instr *code;
    int ncode;
    int nglobals;
    Str **globals;
    int nstatics;
    Str **statics;      /* each static field as "Kind::name", the kind being the declarer */
    int nblocks;
    Block **blocks;
    int nfunctions;
    Function *functions;
    int nlambdas;
    Lambda *lambdas;    /* by index */
    int nkinds;
    Kind *kinds;
    Kind *error_kind; /* the builtin Error kind, when the program has it */
    int max_frame;      /* the most stack one frame can need: locals plus its deepest stack */
} Program;

/* The instructions, in the order of the table in load.c */
enum {
    OP_LABEL, OP_PUSH, OP_POP, OP_PRINT, OP_LOAD, OP_LOAD_QUIET, OP_STORE, OP_LOAD_GLOBAL,
    OP_LOAD_QUIET_GLOBAL, OP_STORE_GLOBAL, OP_LOAD_CAPTURED, OP_LOAD_QUIET_CAPTURED,
    OP_STORE_CAPTURED, OP_LOAD_STATIC, OP_STORE_STATIC, OP_ADD, OP_SUB, OP_MUL, OP_DIV, OP_MOD, OP_BIT_AND, OP_BIT_OR,
    OP_BIT_XOR, OP_SHL, OP_SHR, OP_BIT_NOT, OP_CONCAT, OP_EQUALS, OP_NOT_EQUALS, OP_LT, OP_LE,
    OP_GT, OP_GE, OP_CMP, OP_NOT, OP_NO_MATCH, OP_NO_CONDITION, OP_CONCAT_ASSIGN,
    OP_CONCAT_ASSIGN_GLOBAL, OP_CONCAT_ASSIGN_CAPTURED, OP_NEG, OP_INC, OP_DEC, OP_JMP, OP_JZ,
    OP_JNN, OP_NEW_ARRAY, OP_ARRAY_PUSH, OP_ARRAY_EXTEND, OP_NEW_MAP, OP_MAP_EXTEND, OP_MAP_SET, OP_KEY_CHECK,
    OP_FOREACH_CHECK, OP_DESTRUCTURE, OP_INDEX_GET, OP_INDEX_GET_QUIET, OP_INDEX_GET_EXISTING,
    OP_SET_PATH, OP_SET_PATH_GLOBAL, OP_SET_PATH_CAPTURED, OP_SET_PATH_THIS,
    OP_SET_PATH_STATIC, OP_DELETE_PATH, OP_DELETE_PATH_GLOBAL, OP_DELETE_PATH_CAPTURED,
    OP_DELETE_PATH_THIS, OP_DELETE_PATH_STATIC, OP_CALL,
    OP_CALL_BUILTIN, OP_CALL_VALUE, OP_ARGC, OP_RET, OP_PUSH_FN, OP_MAKE_CLOSURE, OP_PUSH_KIND,
    OP_NEW, OP_CALL_CONSTRUCTOR, OP_CALL_PARENT, OP_BIND_PARENT, OP_LOAD_THIS, OP_LOAD_FIELD,
    OP_SET_FIELD, OP_GET_PROPERTY, OP_GET_PROPERTY_QUIET, OP_GET_PROPERTY_EXISTING,
    OP_GET_METHOD, OP_CALL_METHOD, OP_TRY, OP_END_TRY, OP_CATCH_MATCH, OP_CATCH_VALUE,
    OP_RETHROW, OP_HALT,
    OP_COUNT,
    /* Superinstructions, which the loader puts in place of the first instruction of a sequence
       (see fuse() in load.c); past OP_COUNT, so no file can name one */
    OP_LOAD_PUSH_OP,    /* LOAD; PUSH; a quick operator (quick_binary() in vm.c) */
    OP_LOAD_LOAD_OP,    /* LOAD; LOAD; a quick operator */
    OP_LOAD_PUSH_OP_JZ, /* LOAD; PUSH; a quick comparison; JZ */
    OP_STEP_LOCAL,      /* LOAD x; INC or DEC; STORE x */
    OP_NOT_JZ,          /* NOT; JZ */
    OP_SET_FIELD_POP,   /* SET_FIELD; POP */
    OP_LOAD_LOAD_INDEX, /* LOAD; LOAD; INDEX_GET */
};

/* ---- value.c --------------------------------------------------------------------------- */

void *xmalloc(size_t size);
void *xcalloc(size_t count, size_t size);
void *xrealloc(void *p, size_t size);

void value_free(Value v);   /* frees a heap value whose count has reached 0 */
extern int64_t counted;     /* how many reference-counted values are alive (see value.c) */

/* Reference counting: incref when storing a value somewhere new, decref when dropping one */
static inline void incref(Value v) {
    if (IS_HEAP(v.type)) (*v.rc)++;
}
static inline void decref(Value v) {
    if (IS_HEAP(v.type) && --(*v.rc) == 0) value_free(v);
}
/* Replace what a slot holds, dropping the old value (v's reference moves into the slot) */
static inline void set_slot(Value *slot, Value v) {
    Value old = *slot;
    *slot = v;
    decref(old);
}

static inline Value v_null(void) { Value v = {.type = T_NULL}; return v; }
static inline Value v_unset(void) { Value v = {.type = T_UNSET}; return v; }
static inline Value v_bool(bool b) { Value v = {.type = T_BOOL, .b = b}; return v; }
static inline Value v_int(int64_t i) { Value v = {.type = T_INT, .i = i}; return v; }
static inline Value v_float(double f) { Value v = {.type = T_FLOAT, .f = f}; return v; }
static inline Value v_str(Str *s) { Value v = {.type = T_STRING, .s = s}; return v; }
static inline Value v_list(List *l) { Value v = {.type = T_LIST, .l = l}; return v; }
static inline Value v_map(Map *m) { Value v = {.type = T_MAP, .m = m}; return v; }
static inline Value v_func(Func *f) { Value v = {.type = T_FUNCTION, .fn = f}; return v; }
static inline Value v_object(Object *o) { Value v = {.type = T_OBJECT, .o = o}; return v; }
static inline Value v_socket(Socket *s) { Value v = {.type = T_SOCKET, .sock = s}; return v; }
static inline Value v_kind(Kind *k) { Value v = {.type = T_KIND, .k = k}; return v; }

Str *str_new(const char *data, size_t len);
Str *str_cstr(const char *s);
Str *str_byte(unsigned char byte);           /* a shared one-byte string, not counted */
Str *str_empty(size_t cap);
Str *str_intern(const char *data, size_t len);  /* one shared Str per name, never freed */
Str *str_append(Str *s, const char *data, size_t len);  /* s must be unshared; may move it */
bool str_eq(const Str *a, const Str *b);
int str_cmp(const Str *a, const Str *b);
uint64_t str_hash(Str *s);

/* A growable buffer for building text. Zero-initialised (the usual `Buf b = {0}`), it grows on
   the heap exactly as before. A caller that expects a short result can instead point `data` at
   a stack array and set `cap` and `on_stack`, and buf_add only promotes it to the heap if it
   outgrows that array, which is how binary_op's OP_CONCAT avoids two heap round trips for a
   short `..`. */
typedef struct { char *data; size_t len, cap; bool on_stack; } Buf;
void buf_add(Buf *b, const char *data, size_t len);
void buf_adds(Buf *b, const char *s);
void buf_addc(Buf *b, char c);
void buf_addf(Buf *b, const char *fmt, ...) __attribute__((format(printf, 2, 3)));
void buf_add_int(Buf *b, long long v);
void buf_add_str(Buf *b, const Str *s);
Str *buf_to_str(Buf *b);   /* frees the buffer */

List *list_new(size_t cap);
void list_push(List *l, Value v);  /* takes v's reference */
List *list_unique(Value *slot);   /* the list in the slot, copied first if it is shared */

Map *map_new(void);
Value *map_find(Map *m, Value key);          /* NULL when missing */
void map_set(Map *m, Value key, Value v);    /* takes v's reference; key is copied */
bool map_remove(Map *m, Value key);
Map *map_unique(Value *slot);
/* Iterate a map's live entries: for (size_t i = 0; map_next(m, &i); i++) m->entries[i] */
static inline bool map_next(const Map *m, size_t *i) {
    while (*i < m->used && m->entries[*i].key.type == T_UNSET) (*i)++;
    return *i < m->used;
}

const char *type_name(Value v);
bool is_truthy(Value v);
void format_float(double f, Buf *out);
void quote(const Str *s, Buf *out);
bool to_string(Value v, Str **out);          /* echo's text; can run to_string() */
bool append_string(Value v, Buf *out);       /* the same, into a buffer */
bool values_equal(Value a, Value b);
int compare_numbers(Value a, Value b);       /* -1, 0 or 1 */
bool parse_integer(const char *s, size_t len, int64_t *out);
bool parse_number(const char *s, size_t len, Value *out);

/* ---- gc.c ------------------------------------------------------------------------------ */

void gc_track(Gc *g, Type type);    /* sets the count to 1 and links it in */
void gc_untrack(Gc *g);
extern bool gc_wanted;              /* enough has been made since the last collection */
extern int64_t live, peak;          /* how many lists, maps, objects and functions are alive, and the most there were */
void gc_collect(void);

/* ---- ops.c ----------------------------------------------------------------------------- */

bool binary_op(int op, Value l, Value r, Value *out);   /* op is an OP_ instruction */
bool negate(Value v, Value *out);
bool bitwise_not(Value v, Value *out);
bool step_value(Value v, bool up, Value *out);
bool array_key(Value k);                                  /* raises unless int or string */
bool index_value(Value target, Value index, bool quiet, Value *out);
bool index_existing(Value target, Value index, Value *out);
/* A member the asking kind may name: NULL asks from outside every kind, so only a pub one
   answers. Two kinds in one chain may each declare a private member of one name, so a name
   alone doesn't pick one. */
int kind_field(Kind *c, Str *name, Kind *asking);       /* the slot, or -1 */
int kind_method(Kind *c, Str *name, Kind *asking);      /* the method's position, or -1 */
bool member_escapes(Vis vis, Kind *declarer, Kind *asking);
/* The asking kind a kind block uses. That code is the object's initialiser: it sets every
   slot the kind has, its parents' private fields included, and reads and calls what a field
   default names, each of which the parser checked against the kind that declares it. A field
   name is unique across a hierarchy, so reaching past visibility here picks exactly one slot. */
extern Kind *const KIND_INITIALISER;
bool kind_is_a(Kind *c, Kind *ancestor);
bool property(Value target, Str *name, bool quiet, Kind *asking, Value *out);
bool property_existing(Value target, Str *name, Kind *asking, Value *out);
bool store_path(Value *slot, Str *var_name, Path *path, Value *keys, Value value, Kind *asking, Value *joined);
bool remove_path(Value *slot, Str *var_name, Path *path, Value *keys, Kind *asking);
bool concat_assign(Value *slot, Value v, Value *out);
Func *bound_method(Object *o, Kind *definer, Str *name);
bool raise_undefined_key(Value key);

/* ---- errors (vm.c) ---------------------------------------------------------------------- */

extern Error *vm_error;     /* the error being raised */
bool raisef(const char *fmt, ...) __attribute__((format(printf, 1, 2)));  /* always false */
bool raise_str(Str *reason);                                             /* always false */
bool raise_value(Value v);  /* error($v): a thrown value */
Error *error_new(Str *reason, Str *path, int64_t line, bool show_location);
Str *error_message(Error *e);
void location_text(Str *path, int64_t line, Buf *out);

/* ---- vm.c ------------------------------------------------------------------------------ */

extern Program *program;
bool call_method(Object *o, Kind *definer, Str *name, Value *out);   /* runs a method to its end */
bool call_value(Value callee, Value *args, int argc, Value *out);  /* calls a value to its end, as map() does */
extern FILE *output;        /* where echo and print write: standard output, or memory while compiling */
void flush_output(void);

/* ---- load.c ---------------------------------------------------------------------------- */

Program *load(const char *text, size_t len, const char *path);   /* NULL with vm_error set */

/* ---- builtins.c ------------------------------------------------------------------------ */

typedef struct { const char *name; int lo, hi; } BuiltinInfo;
extern const BuiltinInfo builtin_info[];
extern const int nbuiltins;
extern int program_argc;
extern char *piped_input;       /* standard input main() read, which read_stdin() gives first, or NULL */
extern size_t piped_input_len;
extern char **program_argv;
int builtin_find(const char *name, size_t len);
bool call_builtin(int index, Value *args, int argc, Value *out);
static inline bool arity_fits(int lo, int hi, int argc) { return argc >= lo && argc <= hi; }
bool raise_arity(const char *what, int lo, int hi, int argc);
Func *builtin_value(int index);
void random_seed_unpredictable(void);

/* ---- net.c ----------------------------------------------------------------------------- */

bool net_open(Str *host, int64_t port, bool tls, double timeout, Value *out);
bool net_read(Socket *s, Value *out);
bool net_write(Socket *s, Str *data);
void net_close(Socket *s);

#endif
