/*
 * gazvm: the GazLang VM in C. Runs the bytecode `gazlang -c` writes (docs/bytecode.md).
 *
 * The PHP implementation (src/VM, src/Runtime) is the reference: every operator, builtin,
 * error message and location here must match it, and tests/CVMTest.php checks that they do.
 *
 * Files:
 *   gazvm.h     this: the value model and everything the files share
 *   value.c     memory, strings, lists, maps, printing, equality (Runtime\Values, part 1)
 *   ops.c       operators, indexing, members, write paths (Runtime\Values, part 2)
 *   load.c      reading and checking a bytecode file (CodeGenerator\BytecodeReader)
 *   vm.c        the dispatch loop, calls, errors and traces (VM\VM), and main()
 *   builtins.c  the builtin functions (Runtime\Builtins)
 *   gc.c        the cycle collector, for what reference counting can't free
 *
 * Errors: a function that can fail returns bool, false meaning an error was raised. The
 * error itself is in `vm_error` (see raise()), and the caller passes the false up until the
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
    T_ERROR,    /* a raised error, as the stack holds it in a catch or finally block */
    T_CLASS,    /* lives as long as the program: not counted */
    T_ENTRY,    /* the method entry GET_METHOD pushes: points into a class, not counted */
} Type;

#define IS_HEAP(t) ((t) >= T_STRING && (t) <= T_ERROR)

typedef struct Str Str;
typedef struct List List;
typedef struct Map Map;
typedef struct Func Func;
typedef struct Object Object;
typedef struct Error Error;
typedef struct Class Class;
typedef struct Entry Entry;

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
        Class *c;
        Entry *entry;
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
    Class *cls;         /* bound: the class whose version runs */
    Object *receiver;   /* bound, and a closure made in a method: the object # is (counted) */
    Lambda *lambda;     /* closure: its lambda */
    Value *captured;    /* closure: its captured variables, by capture index */
    Str *file;          /* closure: where it was made, NULL for piped source */
    int64_t line;
};

/* An object: a handle, so == is identity. fields[] holds one value per field of its class,
   in layout order; a field never set is T_UNSET. */
struct Object {
    Gc gc;
    Class *cls;
    bool printing;      /* while echo prints it, so one that holds itself prints Name {...} */
    Value fields[];
};

/* An error on its way up (GazLangError in PHP). */
struct Error {
    int64_t rc;
    Str *reason;        /* the message without the location */
    Str *path;          /* the file, NULL for piped source */
    int64_t line;       /* 0 when unknown */
    bool show_location; /* false for error("text") */
    bool gaz;           /* a GazLangError: false only for an error not yet given a location,
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
    Str *key;           /* "Class.name" of the version that runs */
    Function *function;
};

struct Class {
    Str *name;
    Class *parent;
    bool abstract;
    int nfields;
    Str **fields;       /* field names in layout order (interned, so == compares them) */
    int nmethods;
    Str **methods;      /* every method it can call, the constructor _ included */
    Class **definers;   /* the class whose version of each runs */
    Entry *entries;     /* each method's entry, parallel to methods (the constructor's too) */
    int lo, hi;         /* the constructor's arity, 0 0 without one */
    Block *block;       /* the code that makes an object */
};

typedef enum { B_TOP, B_FN, B_CLASS, B_LAMBDA } BlockKind;

struct Block {
    BlockKind kind;
    Str *name;          /* fn: its name; class: the class name */
    Str *key;           /* '' top, name, "new Class", "->n": what traces and messages call it */
    int index;          /* lambda: its index */
    int lo, hi;         /* fn, lambda: arity */
    int nlocals;
    Str **locals;       /* the variable in each slot */
    int ncaptures;
    Str **captures;     /* lambda: the captured variables, by capture index */
    int self;           /* lambda: the capture that holds the closure itself, or -1 */
    int nmap;
    struct { bool from_closure; int outer; int inner; } *map;   /* lambda: where each capture comes from */
    bool is_abstract;   /* class */
    Str *parent;        /* class: the parent's name, or NULL */
    int nfields;
    Str **field_names, **field_declarers;
    int nmethods;
    Str **method_names, **method_definers;
    int entry;          /* its first instruction in the program's code */
    int max_stack;      /* the greatest stack depth the loader's walk found */
    int line_no;        /* the line of its header, for nothing but debugging */
    /* while loading */
    struct RawInstr *raw;
    int nraw;
};

struct Function {
    Str *name;          /* "f", or "Class.name" for a method */
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
    PathStep steps[];
} Path;

/* One instruction, after loading: names resolved to what they name */
typedef struct Instr {
    uint8_t op;
    int32_t a, b;       /* a slot, count, jump target, builtin index... per instruction */
    void *p;            /* a Function, Class, Lambda, Path or member name */
    Value v;            /* PUSH's value; PUSH_FN's function */
    Str *file;          /* where it came from; file NULL and line 0 when unknown */
    int32_t line;
    /* A member instruction's inline cache: the class it last found the member in, and where
       (a field's slot or a method's position), so the next object of that class needs no search */
    int32_t cached_at;
    Class *cached_class;
} Instr;

typedef struct Program {
    Instr *code;
    int ncode;
    int nglobals;
    Str **globals;
    int nblocks;
    Block **blocks;
    int nfunctions;
    Function *functions;
    int nlambdas;
    Lambda *lambdas;    /* by index */
    int nclasses;
    Class *classes;
    Class *error_class; /* the builtin Error class, when the program has it */
    int max_frame;      /* the most stack one frame can need: locals plus its deepest stack */
} Program;

/* The instructions, in the order of the table in load.c */
enum {
    OP_LABEL, OP_PUSH, OP_POP, OP_PRINT, OP_LOAD, OP_LOAD_QUIET, OP_STORE, OP_LOAD_GLOBAL,
    OP_LOAD_QUIET_GLOBAL, OP_STORE_GLOBAL, OP_LOAD_CAPTURED, OP_LOAD_QUIET_CAPTURED,
    OP_STORE_CAPTURED, OP_ADD, OP_SUB, OP_MUL, OP_DIV, OP_MOD, OP_BIT_AND, OP_BIT_OR,
    OP_BIT_XOR, OP_SHL, OP_SHR, OP_BIT_NOT, OP_CONCAT, OP_EQUALS, OP_NOT_EQUALS, OP_LT, OP_LE,
    OP_GT, OP_GE, OP_CMP, OP_NOT, OP_NO_MATCH, OP_NO_CONDITION, OP_CONCAT_ASSIGN,
    OP_CONCAT_ASSIGN_GLOBAL, OP_CONCAT_ASSIGN_CAPTURED, OP_NEG, OP_INC, OP_DEC, OP_JMP, OP_JZ,
    OP_JNN, OP_NEW_ARRAY, OP_ARRAY_PUSH, OP_ARRAY_EXTEND, OP_NEW_MAP, OP_MAP_SET, OP_KEY_CHECK,
    OP_FOREACH_CHECK, OP_DESTRUCTURE, OP_INDEX_GET, OP_INDEX_GET_QUIET, OP_INDEX_GET_EXISTING,
    OP_SET_PATH, OP_SET_PATH_GLOBAL, OP_SET_PATH_CAPTURED, OP_SET_PATH_THIS, OP_DELETE_PATH,
    OP_DELETE_PATH_GLOBAL, OP_DELETE_PATH_CAPTURED, OP_DELETE_PATH_THIS, OP_CALL,
    OP_CALL_BUILTIN, OP_CALL_VALUE, OP_ARGC, OP_RET, OP_PUSH_FN, OP_MAKE_CLOSURE, OP_PUSH_CLASS,
    OP_NEW, OP_CALL_CONSTRUCTOR, OP_CALL_PARENT, OP_BIND_PARENT, OP_LOAD_THIS, OP_LOAD_FIELD,
    OP_SET_FIELD, OP_GET_PROPERTY, OP_GET_PROPERTY_QUIET, OP_GET_PROPERTY_EXISTING,
    OP_GET_METHOD, OP_CALL_METHOD, OP_TRY, OP_END_TRY, OP_CATCH_MATCH, OP_CATCH_VALUE,
    OP_RETHROW, OP_HALT,
    OP_COUNT
};

/* ---- value.c --------------------------------------------------------------------------- */

void *xmalloc(size_t size);
void *xcalloc(size_t count, size_t size);
void *xrealloc(void *p, size_t size);

void value_free(Value v);   /* frees a heap value whose count has reached 0 */

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
static inline Value v_class(Class *c) { Value v = {.type = T_CLASS, .c = c}; return v; }

Str *str_new(const char *data, size_t len);
Str *str_cstr(const char *s);
Str *str_byte(unsigned char byte);           /* a shared one-byte string, not counted */
Str *str_empty(size_t cap);
Str *str_intern(const char *data, size_t len);  /* one shared Str per name, never freed */
Str *str_append(Str *s, const char *data, size_t len);  /* s must be unshared; may move it */
bool str_eq(const Str *a, const Str *b);
int str_cmp(const Str *a, const Str *b);
uint64_t str_hash(Str *s);

/* A growable buffer for building text */
typedef struct { char *data; size_t len, cap; } Buf;
void buf_add(Buf *b, const char *data, size_t len);
void buf_adds(Buf *b, const char *s);
void buf_addc(Buf *b, char c);
void buf_addf(Buf *b, const char *fmt, ...) __attribute__((format(printf, 2, 3)));
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
int class_field(Class *c, Str *name);                     /* the slot, or -1 */
int class_method(Class *c, Str *name);                    /* the method's position, or -1 */
bool class_is_a(Class *c, Class *ancestor);
bool property(Value target, Str *name, bool quiet, Value *out);
bool property_existing(Value target, Str *name, Value *out);
bool store_path(Value *slot, Str *var_name, Path *path, Value *keys, Value value);
bool remove_path(Value *slot, Str *var_name, Path *path, Value *keys);
bool concat_assign(Value *slot, Value v, Value *out);
Func *bound_method(Object *o, Class *cls, Str *name);
bool raise_undefined_key(Value key);

/* ---- errors (vm.c) ---------------------------------------------------------------------- */

extern Error *vm_error;     /* the error being raised */
bool raise(const char *fmt, ...) __attribute__((format(printf, 1, 2)));  /* always false */
bool raise_str(Str *reason);                                             /* always false */
bool raise_value(Value v);  /* error($v): a thrown value */
Error *error_new(Str *reason, Str *path, int64_t line, bool show_location);
Str *error_message(Error *e);
void location_text(Str *path, int64_t line, Buf *out);

/* ---- vm.c ------------------------------------------------------------------------------ */

extern Program *program;
bool call_method(Object *o, Class *definer, Str *name, Value *out);   /* runs a method to its end */
void flush_output(void);

/* ---- load.c ---------------------------------------------------------------------------- */

Program *load(const char *text, size_t len, const char *path);   /* NULL with vm_error set */
Str *absolute_path(const char *path);

/* ---- builtins.c ------------------------------------------------------------------------ */

typedef struct { const char *name; int lo, hi; } BuiltinInfo;
extern const BuiltinInfo builtin_info[];
extern const int nbuiltins;
extern int program_argc;
extern char **program_argv;
int builtin_find(const char *name, size_t len);
bool call_builtin(int index, Value *args, int argc, Value *out);
bool arity_fits(int lo, int hi, int argc);
bool raise_arity(const char *what, int lo, int hi, int argc);
Func *builtin_value(int index);

#endif
