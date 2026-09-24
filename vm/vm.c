/*
 * The dispatch loop, calls, errors and traces, and main()
 *
 * One value stack holds every frame: a call's arguments, already pushed by the caller, become
 * the first local slots of the callee's frame, its other locals follow, and its own stack
 * grows above those. Frames are records in an array, not C recursion, so deep GazLang
 * recursion needs no C stack. The one exception is a method run to its end from inside an
 * instruction (echo calling to_string()), which runs a nested execute() above the stack as it is.
 */
#include "gazvm.h"

#include <pthread.h>
#include <sys/stat.h>
#include <unistd.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>

Program *program;
Error *vm_error;

typedef struct Frame {
    Block *block;
    Value *base;        /* its local slots; its stack starts after them */
    Instr *ret;         /* where the caller carries on; for the first frame of a nested execute(), after
                           the instruction that ran it (vm_here) */
    int argc;           /* how many arguments it was passed */
    Func *closure;      /* the closure it is a call of (counted), or NULL */
    Object *receiver;   /* the object # is (counted), or NULL */
} Frame;

typedef struct {
    Frame *frame;       /* the frame the try is in */
    Value *sp;          /* the stack as it was at TRY */
    Instr *target;      /* the handler */
} Handler;

static Value *stack, *stack_end;
static Value *vm_sp;    /* the top of the stack as the running instruction found it, where a nested execute() starts */
/* The running instruction, set only by those that can run program code from inside them (echo,
   .., ..=, a builtin): a method they run is called from there */
static Instr *vm_here;
static Value *globals;
static Value *statics;   /* every kind's static fields, one slot each, alive for the run */
static Frame *frames, *fp;  /* every frame; fp is the running one, and fp - frames the call depth */
static Handler *handlers;
static int nhandlers, handlers_cap;

/* ---- Errors ---------------------------------------------------------------------------- */

Error *error_new(Str *reason, Str *path, int64_t line, bool show_location) {
    Error *e = xcalloc(1, sizeof(Error));
    counted++;
    e->rc = 1;
    e->reason = reason;
    e->path = path;
    if (path) incref(v_str(path));
    e->line = line;
    e->show_location = show_location;
    e->gaz = true;
    return e;
}

/* An error with no location yet; it takes its reason's reference */
bool raise_str(Str *reason) {
    if (vm_error) decref((Value){.type = T_ERROR, .e = vm_error});
    vm_error = error_new(reason, NULL, 0, true);
    vm_error->gaz = false;
    return false;
}

bool raisef(const char *fmt, ...) {
    char small[512];
    va_list args;
    va_start(args, fmt);
    int n = vsnprintf(small, sizeof small, fmt, args);
    va_end(args);
    if (n < (int)sizeof small) return raise_str(str_new(small, (size_t)n));
    char *big = xmalloc((size_t)n + 1);
    va_start(args, fmt);
    vsnprintf(big, (size_t)n + 1, fmt, args);
    va_end(args);
    Str *s = str_new(big, (size_t)n);
    free(big);
    return raise_str(s);
}

/* error($v): a string is the message of an Error, anything else is thrown as it is */
bool raise_value(Value v) {
    if (vm_error) decref((Value){.type = T_ERROR, .e = vm_error});
    if (v.type == T_STRING) {
        incref(v);
        vm_error = error_new(v.s, NULL, 0, false);
        return false;
    }
    Buf b = {0};
    buf_adds(&b, "thrown ");
    buf_adds(&b, type_name(v));
    vm_error = error_new(buf_to_str(&b), NULL, 0, false);
    vm_error->has_value = true;
    vm_error->value = v;
    incref(v);
    return false;
}

void location_text(Str *path, int64_t line, Buf *out) {
    if (path) buf_addf(out, "at %s:%lld", path->data, (long long)line);
    else buf_addf(out, "on line %lld", (long long)line);
}

/* The message with its location, as getMessage() gives it */
Str *error_message(Error *e) {
    Buf b = {0};
    buf_add_str(&b, e->reason);
    if (e->line && e->show_location) {
        buf_addc(&b, ' ');
        location_text(e->path, e->line, &b);
    }
    return buf_to_str(&b);
}

static const char *frame_name(Frame *f) {
    if (f->closure) return "->";
    if (f->block->kind == B_TOP) return "top level";
    return f->block->key->data;
}

/*
 * The calls running, innermost first, for an error raised at an instruction: each call where
 * it was running, the innermost where the error happened and the ones around it at the call
 * they made, a method run from inside an instruction (to_string() by printing) included.
 * Keeps the innermost 10 and outermost 10, with a line saying how many it left out.
 */
static Value build_trace(Instr *at) {
    /* Down to the top level, or to a call with no caller */
    int n = 1;
    while (fp - n >= frames && (fp - n + 1)->ret) n++;
    List *trace = list_new((size_t)(n > 21 ? 21 : n));
    for (int i = 0; i < n; i++) {
        if (n > 20 && i == 10) {
            Buf b = {0};
            buf_addf(&b, "... %d more", n - 20);
            list_push(trace, v_str(buf_to_str(&b)));
        }
        if (n > 20 && i >= 10 && i < n - 10) continue;
        Frame *f = fp - i;
        Instr *where = i == 0 ? at : (fp - i + 1)->ret - 1;
        Buf b = {0};
        buf_adds(&b, frame_name(f));
        buf_addc(&b, ' ');
        location_text(where->file, where->line, &b);
        list_push(trace, v_str(buf_to_str(&b)));
    }
    return v_list(trace);
}

/* Give the error being raised the location of the instruction that raised it, and the trace */
static void locate(Instr *at) {
    Error *e = vm_error;
    if (at->line == 0 || (e->gaz && e->line)) return;
    Value trace = build_trace(at);
    if (e->trace.type == T_UNSET) e->trace = trace;
    else decref(trace);
    if (e->path) decref(v_str(e->path));
    e->path = at->file;
    if (e->path) incref(v_str(e->path));
    e->line = at->line;
    if (!e->gaz) {
        e->gaz = true;
        e->show_location = true;
    }
}

static Object *object_new(Kind *c);

/* What catch sees: an Error object for an error the program didn't throw itself, or the value
   it threw, given a file, line and trace if it is an Error that has none yet. Worked out once. */
static Value caught(Error *e) {
    static Str *message, *file, *line, *trace;
    if (!message) {
        message = str_intern("message", 7);
        file = str_intern("file", 4);
        line = str_intern("line", 4);
        trace = str_intern("trace", 5);
    }
    if (e->caught.type != T_UNSET) {
        incref(e->caught);
        return e->caught;
    }
    Kind *error_kind = program->error_kind;
    Value path = e->path ? v_str(e->path) : v_null();
    Value where = e->line ? v_int(e->line) : v_null();
    Value calls = e->trace.type == T_UNSET ? v_list(list_new(0)) : e->trace;
    if (e->trace.type != T_UNSET) incref(calls);
    if (!e->has_value) {
        Object *o = object_new(error_kind);
        incref(v_str(e->reason));
        o->fields[kind_field(error_kind, message, NULL)] = v_str(e->reason);
        incref(path);
        o->fields[kind_field(error_kind, file, NULL)] = path;
        o->fields[kind_field(error_kind, line, NULL)] = where;
        o->fields[kind_field(error_kind, trace, NULL)] = calls;
        e->caught = v_object(o);
    } else {
        Value v = e->value;
        if (v.type == T_OBJECT && error_kind && kind_is_a(v.o->kind, error_kind)
            && v.o->fields[kind_field(v.o->kind, line, NULL)].type == T_UNSET) {
            incref(path);
            set_slot(&v.o->fields[kind_field(v.o->kind, file, NULL)], path);
            set_slot(&v.o->fields[kind_field(v.o->kind, line, NULL)], where);
            set_slot(&v.o->fields[kind_field(v.o->kind, trace, NULL)], calls);
        } else {
            decref(calls);
        }
        incref(v);
        e->caught = v;
    }
    incref(e->caught);
    return e->caught;
}

/* ---- Output ---------------------------------------------------------------------------- */

FILE *output;
void flush_output(void) { fflush(output); }

/* ---- Calls ----------------------------------------------------------------------------- */

static bool raise_depth(const char *what) {
    return raisef("Maximum call depth of %d exceeded calling %s", MAX_CALL_DEPTH, what);
}

/*
 * Push a frame for a block whose argc arguments are the top of the stack, which become its first
 * locals. The closure and receiver references move into the frame.
 */
static bool push_frame(Block *block, Value **sp, int argc, Instr *ret, Func *closure, Object *receiver) {
    Value *base = *sp - argc;
    int nlocals = block->nlocals > argc ? block->nlocals : argc;
    if (base + nlocals + block->max_stack + 2 > stack_end) {
        if (closure) decref(v_func(closure));
        if (receiver) decref(v_object(receiver));
        return raisef("Stack overflow");
    }
    for (int i = argc; i < nlocals; i++) base[i] = v_unset();
    Frame *f = ++fp;
    f->block = block;
    f->base = base;
    f->ret = ret;
    f->argc = argc;
    f->closure = closure;
    f->receiver = receiver;
    *sp = base + nlocals;
    return true;
}

/* The id the next object gets: counted per program, so the compiler's objects, made before the
   program runs, don't shift the program's ids */
static int64_t next_object_id = 1;

static Object *object_new(Kind *c) {
    Object *o = xcalloc(1, sizeof(Object) + (size_t)c->nfields * sizeof(Value));
    gc_track(&o->gc, T_OBJECT);
    o->kind = c;
    o->id = next_object_id++;
    return o;
}

static void describe(Value callee, Buf *out) {
    Str *text;
    if (to_string(callee, &text)) {
        /* "function add": the name is what follows "function " */
        buf_add(out, text->data + 9, text->len - 9);
        decref(v_str(text));
    }
}

static Function *method_function(Kind *definer, Str *name) {
    int m = kind_method(definer, name, definer);
    return m < 0 ? NULL : definer->entries[m].function;
}

static bool execute(Instr *pc, Frame *first, Value *result);

/* Names the loop needs, interned once */
static Str *this_name(void) {
    static Str *name;
    return name ? name : (name = str_intern("#", 1));
}
static Str *constructor_name(void) {
    static Str *name;
    return name ? name : (name = str_intern("_", 1));
}

/* Run a method on an object to its end, from inside an instruction */
bool call_method(Object *o, Kind *definer, Str *name, Value *out) {
    Function *f = method_function(definer, name);
    if (fp - frames == MAX_CALL_DEPTH) {
        Buf b = {0};
        buf_add_str(&b, definer->name);
        buf_addc(&b, '.');
        buf_add_str(&b, name);
        bool r = raise_depth(b.data);
        free(b.data);
        return r;
    }
    Instr *here = vm_here;
    Value *sp = vm_sp;
    incref(v_object(o));
    /* Once the program has ended (printing an uncaught error) there is no caller */
    if (!push_frame(f->block, &sp, 0, here ? here + 1 : NULL, NULL, o)) return false;
    bool ok = execute(program->code + f->block->entry, fp, out);
    /* The method ran instructions of its own; the one running it may run another (join()) */
    vm_here = here;
    return ok;
}

/*
 * Start a call of the value below argc arguments on the stack for call_value(), checked as
 * CALL_VALUE checks it (which does the same inline: sharing this cost lambda calls 8%): a builtin
 * runs here, leaving its result where the callee was (*entered NULL); anything else gets a frame
 * that returns to ret, and *entered is the block to run. False with vm_error set if it can't be
 * called, leaving the stack for the caller to unwind.
 */
static bool enter_value(Value **spp, int argc, Instr *ret, Block **entered) {
    Value *sp = *spp;
    Value *callee_slot = sp - argc - 1;
    Value callee = *callee_slot;
    Value r;
    *entered = NULL;
    if (callee.type == T_KIND) {
        Kind *c = callee.k;
        if (c->abstract) return raisef("Cannot construct abstract kind %s", c->name->data);
        if (!arity_fits(c->lo, c->hi, argc)) {
            Buf what = {0};
            buf_adds(&what, "Kind ");
            buf_add_str(&what, c->name);
            raise_arity(what.data, c->lo, c->hi, argc);
            free(what.data);
            return false;
        }
        if (fp - frames == MAX_CALL_DEPTH) return raise_depth(c->name->data);
        memmove(callee_slot, sp - argc, (size_t)argc * sizeof(Value));
        sp--;
        if (!push_frame(c->block, &sp, argc, ret, NULL, object_new(c))) {
            *spp = sp;
            return false;
        }
        *spp = sp;
        *entered = c->block;
        return true;
    }
    if (callee.type != T_FUNCTION) return raisef("Cannot call %s", type_name(callee));
    Func *fn = callee.fn;
    int lo, hi;
    Block *block = NULL;
    if (fn->kind == F_CLOSURE) {
        block = fn->lambda->block;
        lo = block->lo, hi = block->hi;
    } else if (fn->kind == F_BOUND) {
        Function *f = method_function(fn->definer, fn->name);
        block = f->block;
        lo = f->lo, hi = f->hi;
    } else if (fn->kind == F_BUILTIN) {
        lo = builtin_info[fn->builtin].lo, hi = builtin_info[fn->builtin].hi;
    } else {
        block = fn->function->block;
        lo = fn->function->lo, hi = fn->function->hi;
    }
    if (!arity_fits(lo, hi, argc)) {
        Buf what = {0};
        buf_adds(&what, fn->kind == F_BOUND ? "Method " : "Function ");
        describe(callee, &what);
        raise_arity(what.data, lo, hi, argc);
        free(what.data);
        return false;
    }
    if (fn->kind == F_BUILTIN) {
        if (!call_builtin(fn->builtin, sp - argc, argc, &r)) return false;
        for (int i = 0; i < argc; i++) decref(*--sp);
        decref(*--sp);
        *sp++ = r;
        *spp = sp;
        return true;
    }
    if (fp - frames == MAX_CALL_DEPTH) {
        Buf what = {0};
        describe(callee, &what);
        raise_depth(what.data);
        free(what.data);
        return false;
    }
    /* The callee's reference moves into the frame for a closure, and is dropped otherwise */
    Func *closure = fn->kind == F_CLOSURE ? fn : NULL;
    Object *receiver = fn->receiver;
    if (receiver) incref(v_object(receiver));
    memmove(callee_slot, sp - argc, (size_t)argc * sizeof(Value));
    sp--;
    if (!closure) decref(callee);
    bool pushed = push_frame(block, &sp, argc, ret, closure, receiver);
    *spp = sp;
    *entered = pushed ? block : NULL;
    return pushed;
}

/* How many arguments a function the program defines (a closure, a named function or a bound method)
   must be given at least, or -1 for anything else (a builtin, a kind, a non-function): what lets map()
   and filter() pass an element's key as well to a callback that asks for it */
int callable_min_args(Value callee) {
    if (callee.type != T_FUNCTION) return -1;
    Func *fn = callee.fn;
    if (fn->kind == F_CLOSURE) return fn->lambda->block->lo;
    if (fn->kind == F_BOUND) return method_function(fn->definer, fn->name)->lo;
    if (fn->kind == F_BUILTIN) return -1;
    return fn->function->lo;
}

/* Call a value to its end from inside an instruction, as map() calls its callback: checked as a
   call in the program is, and called from where the instruction is running. Takes no reference. */
bool call_value(Value callee, Value *args, int argc, Value *out) {
    Instr *here = vm_here;
    Value *bottom = vm_sp, *sp = vm_sp;
    incref(callee);
    *sp++ = callee;
    for (int i = 0; i < argc; i++) {
        incref(args[i]);
        *sp++ = args[i];
    }
    /* A builtin callee runs on these slots, and may itself run program code above them */
    vm_sp = sp;
    Block *entered;
    /* A builtin runs from an instruction, which set vm_here */
    bool ok = enter_value(&sp, argc, here + 1, &entered);
    if (!ok) {
        while (sp > bottom) decref(*--sp);
    } else if (entered) {
        ok = execute(program->code + entered->entry, fp, out);
    } else {
        *out = *--sp;
    }
    /* The call ran instructions of its own; the one making it may call again */
    vm_here = here;
    vm_sp = bottom;
    return ok;
}

/* ---- The loop -------------------------------------------------------------------------- */

/* The slot of the field a member instruction names in an object, or -1: through the
   instruction's cache when the object's kind is the one it saw last */
static inline int field_slot(Instr *in, Object *o, Kind *asking) {
    if (o->kind != in->cached_kind) {
        in->cached_kind = o->kind;
        in->cached_at = kind_field(o->kind, in->p, asking);
    }
    return in->cached_at;
}

/* An operator's result when it is quick and can't fail: ints that don't overflow for arithmetic,
   % and ordering, anything for == and != (which never runs program code). The fast paths of
   the instructions themselves, for the superinstructions that end in one. */
static inline bool quick_binary(int op, Value a, Value b, Value *out) {
    int64_t n;
    if (op == OP_EQUALS || op == OP_NOT_EQUALS) {
        *out = v_bool(values_equal(a, b) == (op == OP_EQUALS));
        return true;
    }
    if (a.type != T_INT || b.type != T_INT) return false;
    switch (op) {
    case OP_ADD: if (__builtin_add_overflow(a.i, b.i, &n)) return false; *out = v_int(n); return true;
    case OP_SUB: if (__builtin_sub_overflow(a.i, b.i, &n)) return false; *out = v_int(n); return true;
    case OP_MUL: if (__builtin_mul_overflow(a.i, b.i, &n)) return false; *out = v_int(n); return true;
    case OP_MOD: if (b.i == 0 || b.i == -1) return false; *out = v_int(a.i % b.i); return true;
    case OP_LT: *out = v_bool(a.i < b.i); return true;
    case OP_LE: *out = v_bool(a.i <= b.i); return true;
    case OP_GT: *out = v_bool(a.i > b.i); return true;
    case OP_GE: *out = v_bool(a.i >= b.i); return true;
    }
    return false;
}

#define PUSH(v) (*sp++ = (v))
#define POP() (*--sp)
#define TOP() (sp[-1])

/*
 * Run from an instruction until HALT, or until the frame it starts in (first, already pushed)
 * returns, giving its value. False when an error escapes, with vm_error set and every frame and
 * value this execute() made dropped.
 */
static bool execute(Instr *pc, Frame *first, Value *result) {
    Instr *code = program->code;
    Value *sp = vm_sp = fp->base + (fp->block->nlocals > fp->argc ? fp->block->nlocals : fp->argc);
    int handler_base = nhandlers;
    Value a, b, r;
    int argc;   /* a call's argument count; here so CALL_METHOD can jump into CALL_VALUE's code */

    for (;;) {
        Instr *in = pc++;
        vm_sp = sp;
        int op = in->op;
    dispatch:
        switch (op) {
        case OP_PUSH:
            incref(in->v);
            PUSH(in->v);
            break;
        case OP_POP:
            decref(POP());
            break;
        case OP_PRINT: {
            vm_here = in;
            Buf out = {0};
            if (!append_string(TOP(), &out)) {
                free(out.data);
                goto error;
            }
            buf_addc(&out, '\n');
            fwrite(out.data, 1, out.len, output);
            free(out.data);
            decref(POP());
            break;
        }
        case OP_LOAD:
            a = fp->base[in->a];
            if (a.type == T_UNSET) {
                raisef("Undefined variable: %s", fp->block->locals[in->a]->data);
                goto error;
            }
            incref(a);
            PUSH(a);
            break;
        case OP_LOAD_QUIET:
            a = fp->base[in->a];
            if (a.type == T_UNSET) a = v_null();
            incref(a);
            PUSH(a);
            break;
        case OP_STORE:
            set_slot(&fp->base[in->a], POP());
            break;
        case OP_LOAD_GLOBAL:
            a = globals[in->a];
            if (a.type == T_UNSET) {
                raisef("Undefined variable: %s", program->globals[in->a]->data);
                goto error;
            }
            incref(a);
            PUSH(a);
            break;
        case OP_LOAD_QUIET_GLOBAL:
            a = globals[in->a];
            if (a.type == T_UNSET) a = v_null();
            incref(a);
            PUSH(a);
            break;
        case OP_STORE_GLOBAL:
            set_slot(&globals[in->a], POP());
            break;
        case OP_LOAD_CAPTURED:
            a = fp->closure ? fp->closure->captured[in->a] : v_unset();
            if (a.type == T_UNSET) {
                raisef("Undefined variable: %s", fp->closure->lambda->block->captures[in->a]->data);
                goto error;
            }
            incref(a);
            PUSH(a);
            break;
        case OP_LOAD_QUIET_CAPTURED:
            a = fp->closure ? fp->closure->captured[in->a] : v_unset();
            if (a.type == T_UNSET) a = v_null();
            incref(a);
            PUSH(a);
            break;
        case OP_STORE_CAPTURED:
            set_slot(&fp->closure->captured[in->a], POP());
            break;
        /* A static field always has a value: the compiler puts its default, a constant, at the
           top of the program, so nothing can read one before it is set */
        case OP_LOAD_STATIC:
            a = statics[in->a];
            incref(a);
            PUSH(a);
            break;
        case OP_STORE_STATIC:
            set_slot(&statics[in->a], POP());
            break;

        case OP_CONCAT_ASSIGN:
        case OP_CONCAT_ASSIGN_GLOBAL:
        case OP_CONCAT_ASSIGN_CAPTURED: {
            Value *slot;
            Str *name;
            if (op == OP_CONCAT_ASSIGN) {
                slot = &fp->base[in->a];
                name = fp->block->locals[in->a];
            } else if (op == OP_CONCAT_ASSIGN_GLOBAL) {
                slot = &globals[in->a];
                name = program->globals[in->a];
            } else {
                slot = &fp->closure->captured[in->a];
                name = fp->closure->lambda->block->captures[in->a];
            }
            if (slot->type == T_UNSET) {
                raisef("Undefined variable: %s", name->data);
                goto error;
            }
            vm_here = in;
            if (!concat_assign(slot, TOP(), &r)) goto error;
            set_slot(&TOP(), r);
            break;
        }

        case OP_ADD:
        case OP_SUB:
        case OP_MUL:
            a = sp[-2], b = sp[-1];
            if (a.type == T_INT && b.type == T_INT) {
                int64_t n;
                bool overflow = op == OP_ADD   ? __builtin_add_overflow(a.i, b.i, &n)
                                : op == OP_SUB ? __builtin_sub_overflow(a.i, b.i, &n)
                                                   : __builtin_mul_overflow(a.i, b.i, &n);
                if (!overflow) {
                    sp--;
                    sp[-1] = v_int(n);
                    break;
                }
            }
            goto binary;
        case OP_LT:
        case OP_LE:
        case OP_GT:
        case OP_GE:
            a = sp[-2], b = sp[-1];
            if (a.type == T_INT && b.type == T_INT) {
                bool yes = op == OP_LT ? a.i < b.i : op == OP_LE ? a.i <= b.i : op == OP_GT ? a.i > b.i : a.i >= b.i;
                sp--;
                sp[-1] = v_bool(yes);
                break;
            }
            goto binary;
        case OP_EQUALS:
        case OP_NOT_EQUALS: {
            a = sp[-2], b = sp[-1];
            bool equal = values_equal(a, b);
            decref(a);
            decref(b);
            sp--;
            sp[-1] = v_bool(equal == (op == OP_EQUALS));
            break;
        }
        case OP_MOD:
            a = sp[-2], b = sp[-1];
            /* % of the smallest int by -1 is 0 in PHP and a crash in C: that goes the long way */
            if (a.type == T_INT && b.type == T_INT && b.i != 0 && b.i != -1) {
                sp--;
                sp[-1] = v_int(a.i % b.i);
                break;
            }
            goto binary;
        case OP_DIV:
        case OP_BIT_AND:
        case OP_BIT_OR:
        case OP_BIT_XOR:
        case OP_SHL:
        case OP_SHR:
        case OP_CMP:
        binary:
            if (!binary_op(op, sp[-2], sp[-1], &r)) goto error;
            decref(POP());
            set_slot(&TOP(), r);
            break;
        case OP_CONCAT:
            vm_here = in;
            goto binary;
        case OP_NOT:
            a = TOP();
            set_slot(&TOP(), v_bool(!is_truthy(a)));
            break;
        case OP_NEG:
            if (!negate(TOP(), &r)) goto error;
            set_slot(&TOP(), r);
            break;
        case OP_BIT_NOT:
            if (!bitwise_not(TOP(), &r)) goto error;
            set_slot(&TOP(), r);
            break;
        case OP_INC:
        case OP_DEC:
            a = TOP();
            if (a.type == T_INT && a.i != (op == OP_INC ? INT64_MAX : INT64_MIN)) {
                TOP().i += op == OP_INC ? 1 : -1;
                break;
            }
            if (!step_value(a, op == OP_INC, &r)) goto error;
            set_slot(&TOP(), r);
            break;
        case OP_NO_MATCH: {
            a = TOP();
            Buf m = {0};
            buf_adds(&m, "No arm matches ");
            if (a.type == T_STRING) quote(a.s, &m);
            else if (a.type == T_INT || a.type == T_FLOAT || a.type == T_BOOL || a.type == T_NULL || a.type == T_KIND
                     || a.type == T_FUNCTION) append_string(a, &m);
            else buf_adds(&m, type_name(a));
            raise_str(buf_to_str(&m));
            goto error;
        }
        case OP_NO_CONDITION:
            raisef("No arm matched");
            goto error;

        case OP_JMP:
            /* A loop's jump back and every call are where the cycle collector may run: between
               instructions, with everything the program holds on the stack or in a frame */
            if (gc_wanted) gc_collect();
            pc = code + in->a;
            break;
        case OP_JZ:
            a = POP();
            if (!(a.type == T_BOOL ? a.b : is_truthy(a))) pc = code + in->a;
            decref(a);
            break;
        case OP_JNN:
            if (TOP().type != T_NULL) pc = code + in->a;
            else sp--;
            break;
        case OP_HALT:
            /* The loader keeps it to the top level, where there is no caller to give a value to */
            *result = v_unset();
            vm_sp = sp;
            return true;

        /* Superinstructions (fuse() in load.c): the quick case, or the first instruction alone */
        case OP_LOAD_PUSH_OP:
            a = fp->base[in->a];
            if (a.type != T_UNSET && quick_binary(in[2].orig, a, in[1].v, &r)) {
                PUSH(r);
                pc = in + 3;
                break;
            }
            op = in->orig;
            goto dispatch;
        case OP_LOAD_PUSH_OP_JZ:
            a = fp->base[in->a];
            if (a.type != T_UNSET && quick_binary(in[2].orig, a, in[1].v, &r)) {
                pc = r.b ? in + 4 : code + in[3].a;
                break;
            }
            op = in->orig;
            goto dispatch;
        case OP_STEP_LOCAL: {
            Value *slot = &fp->base[in->a];
            if (slot->type == T_INT && slot->i != (in[1].orig == OP_INC ? INT64_MAX : INT64_MIN)) {
                slot->i += in[1].orig == OP_INC ? 1 : -1;
                pc = in + 3;
                break;
            }
            op = in->orig;
            goto dispatch;
        }
        case OP_LOAD_LOAD_OP:
            a = fp->base[in->a], b = fp->base[in[1].a];
            if (a.type != T_UNSET && b.type != T_UNSET && quick_binary(in[2].orig, a, b, &r)) {
                PUSH(r);
                pc = in + 3;
                break;
            }
            op = in->orig;
            goto dispatch;
        case OP_NOT_JZ:
            a = POP();
            if (a.type == T_BOOL ? a.b : is_truthy(a)) pc = code + in[1].a;
            else pc = in + 2;
            decref(a);
            break;
        case OP_SET_FIELD_POP: {
            int f = field_slot(in, fp->receiver, fp->block->owner);
            if (f < 0) {
                op = in->orig;
                goto dispatch;
            }
            set_slot(&fp->receiver->fields[f], POP());
            pc = in + 2;
            break;
        }
        case OP_LOAD_LOAD_INDEX:
            a = fp->base[in->a], b = fp->base[in[1].a];
            if (a.type == T_LIST && b.type == T_INT && b.i >= 0 && (uint64_t)b.i < a.l->len) {
                r = a.l->items[b.i];
                incref(r);
                PUSH(r);
                pc = in + 3;
                break;
            }
            op = in->orig;
            goto dispatch;

        case OP_NEW_ARRAY:
            PUSH(v_list(list_new(0)));
            break;
        case OP_ARRAY_PUSH:
            /* The list is one NEW_ARRAY made in code the compiler wrote; hand-written bytecode
               can have anything under the value */
            if (sp[-2].type != T_LIST) {
                raisef("ARRAY_PUSH expects a list to append to, got %s", type_name(sp[-2]));
                goto error;
            }
            list_push(list_unique(&sp[-2]), sp[-1]);
            sp--;
            break;
        case OP_ARRAY_EXTEND: {
            a = TOP();
            if (a.type != T_LIST) {
                raisef("Cannot spread %s: only a list can be", type_name(a));
                goto error;
            }
            if (sp[-2].type != T_LIST) {
                raisef("ARRAY_EXTEND expects a list to spread into, got %s", type_name(sp[-2]));
                goto error;
            }
            List *l = list_unique(&sp[-2]);
            for (size_t i = 0; i < a.l->len; i++) {
                incref(a.l->items[i]);
                list_push(l, a.l->items[i]);
            }
            decref(POP());
            break;
        }
        case OP_NEW_MAP:
            PUSH(v_map(map_new()));
            break;
        case OP_MAP_EXTEND: {
            /* ...$m in a map literal: every key of $m set in the map below, in $m's order, so a
               key already there keeps its place and takes the new value */
            a = TOP();
            if (a.type != T_MAP) {
                raisef("Cannot spread %s: only a map can be", type_name(a));
                goto error;
            }
            if (sp[-2].type != T_MAP) {
                raisef("MAP_EXTEND expects a map to spread into, got %s", type_name(sp[-2]));
                goto error;
            }
            Map *m = map_unique(&sp[-2]);
            for (size_t i = 0; map_next(a.m, &i); i++) {
                incref(a.m->entries[i].value);
                map_set(m, a.m->entries[i].key, a.m->entries[i].value);
            }
            decref(POP());
            break;
        }
        case OP_MAP_SET:
            if (sp[-3].type != T_MAP) {
                raisef("MAP_SET expects a map to set the key in, got %s", type_name(sp[-3]));
                goto error;
            }
            if (!array_key(sp[-2])) goto error;
            map_set(map_unique(&sp[-3]), sp[-2], sp[-1]);
            decref(sp[-2]);
            sp -= 2;
            break;
        case OP_KEY_CHECK:
            if (!array_key(TOP())) goto error;
            break;
        case OP_FOREACH_CHECK:
            if (TOP().type != T_LIST && TOP().type != T_MAP) {
                raisef("foreach expects a list or map, got %s", type_name(TOP()));
                goto error;
            }
            break;
        case OP_DESTRUCTURE:
            a = TOP();
            if (a.type != T_LIST) {
                raisef("Cannot destructure %s: only a list can be", type_name(a));
                goto error;
            }
            if (a.l->len != (size_t)in->a) {
                raisef("Cannot destructure a list of %zu %s into %d", a.l->len, a.l->len == 1 ? "element" : "elements", in->a);
                goto error;
            }
            break;
        case OP_INDEX_GET:
            a = sp[-2], b = sp[-1];
            if (a.type == T_LIST && b.type == T_INT && b.i >= 0 && (uint64_t)b.i < a.l->len) {
                r = a.l->items[b.i];
                incref(r);
            } else if (!index_value(a, b, false, &r)) {
                goto error;
            }
            decref(POP());
            set_slot(&TOP(), r);
            break;
        case OP_INDEX_GET_QUIET:
            a = sp[-2], b = sp[-1];
            if (a.type == T_NULL) r = v_null();
            else if (!index_value(a, b, true, &r)) goto error;
            decref(POP());
            set_slot(&TOP(), r);
            break;
        case OP_INDEX_GET_EXISTING:
            if (!index_existing(sp[-2], sp[-1], &r)) goto error;
            decref(POP());
            set_slot(&TOP(), r);
            break;

        case OP_SET_PATH:
        case OP_SET_PATH_GLOBAL:
        case OP_SET_PATH_CAPTURED:
        case OP_SET_PATH_STATIC:
        case OP_SET_PATH_THIS: {
            Path *path = in->p;
            Value *keys = sp - 1 - path->nkeys;
            Value joined;       /* what a path ending in ..= leaves instead of the value */
            bool ok;
            if (op == OP_SET_PATH) {
                ok = store_path(&fp->base[in->a], fp->block->locals[in->a], path, keys, TOP(), fp->block->owner, &joined);
            } else if (op == OP_SET_PATH_GLOBAL) {
                ok = store_path(&globals[in->a], program->globals[in->a], path, keys, TOP(), fp->block->owner, &joined);
            } else if (op == OP_SET_PATH_CAPTURED) {
                ok = store_path(&fp->closure->captured[in->a], fp->closure->lambda->block->captures[in->a], path, keys, TOP(), fp->block->owner, &joined);
            } else if (op == OP_SET_PATH_STATIC) {
                ok = store_path(&statics[in->a], program->statics[in->a], path, keys, TOP(), fp->block->owner, &joined);
            } else {
                /* The object is a handle, so writing through a copy of it writes the object */
                Value self = fp->receiver ? v_object(fp->receiver) : v_null();
                ok = store_path(&self, this_name(), path, keys, TOP(), fp->block->owner, &joined);
            }
            if (!ok) goto error;
            if (path->concat) {
                decref(TOP());
                TOP() = joined;
            }
            for (int i = 0; i < path->nkeys; i++) decref(keys[i]);
            keys[0] = TOP();
            sp = keys + 1;
            break;
        }
        case OP_DELETE_PATH:
        case OP_DELETE_PATH_GLOBAL:
        case OP_DELETE_PATH_CAPTURED:
        case OP_DELETE_PATH_STATIC:
        case OP_DELETE_PATH_THIS: {
            Path *path = in->p;
            Value *keys = sp - path->nkeys;
            bool ok;
            if (op == OP_DELETE_PATH) {
                ok = remove_path(&fp->base[in->a], fp->block->locals[in->a], path, keys, fp->block->owner);
            } else if (op == OP_DELETE_PATH_GLOBAL) {
                ok = remove_path(&globals[in->a], program->globals[in->a], path, keys, fp->block->owner);
            } else if (op == OP_DELETE_PATH_CAPTURED) {
                ok = remove_path(&fp->closure->captured[in->a], fp->closure->lambda->block->captures[in->a], path, keys, fp->block->owner);
            } else if (op == OP_DELETE_PATH_STATIC) {
                ok = remove_path(&statics[in->a], program->statics[in->a], path, keys, fp->block->owner);
            } else {
                Value self = fp->receiver ? v_object(fp->receiver) : v_null();
                ok = remove_path(&self, this_name(), path, keys, fp->block->owner);
            }
            if (!ok) goto error;
            for (int i = 0; i < path->nkeys; i++) decref(keys[i]);
            sp = keys;
            break;
        }

        case OP_CALL: {
            if (gc_wanted) gc_collect();
            Function *f = in->p;
            if (fp - frames == MAX_CALL_DEPTH) {
                raise_depth(f->name->data);
                goto error;
            }
            if (!push_frame(f->block, &sp, in->a, pc, NULL, NULL)) goto error;
            pc = code + f->block->entry;
            break;
        }
        case OP_CALL_BUILTIN: {
            vm_here = in;
            argc = in->b;
            if (!call_builtin(in->a, sp - argc, argc, &r)) goto error;
            for (int i = 0; i < argc; i++) decref(POP());
            PUSH(r);
            break;
        }
        case OP_ARGC:
            PUSH(v_int(fp->argc));
            break;
        case OP_RET: {
            Frame *f = fp;
            r = POP();
            /* Handlers installed by this call are gone with its frame */
            while (nhandlers > handler_base && handlers[nhandlers - 1].frame == f) nhandlers--;
            while (sp > f->base) decref(POP());
            if (f->closure) decref(v_func(f->closure));
            if (f->receiver) decref(v_object(f->receiver));
            fp--;
            if (f == first) {
                *result = r;
                vm_sp = sp;
                return true;
            }
            pc = f->ret;
            PUSH(r);
            break;
        }
        case OP_PUSH_FN:
            incref(in->v);
            PUSH(in->v);
            break;
        case OP_MAKE_CLOSURE: {
            Lambda *lambda = in->p;
            Block *lb = lambda->block;
            Func *f = xcalloc(1, sizeof(Func));
            gc_track(&f->gc, T_FUNCTION);
            f->kind = F_CLOSURE;
            f->lambda = lambda;
            f->captured = xcalloc((size_t)lb->ncaptures + 1, sizeof(Value));
            /* Copies of the enclosing variables that exist, from the frame or the running closure */
            for (int i = 0; i < lb->nmap; i++) {
                Value v = lb->map[i].from_closure ? (fp->closure ? fp->closure->captured[lb->map[i].outer] : v_unset())
                                                  : fp->base[lb->map[i].outer];
                if (v.type == T_UNSET) continue;
                incref(v);
                f->captured[lb->map[i].inner] = v;
            }
            f->receiver = fp->receiver;
            if (f->receiver) incref(v_object(f->receiver));
            f->file = in->file;
            f->line = in->line;
            /* $f = <lambda>: the closure's $f is the closure (a cycle, left to the collector) */
            if (lb->self >= 0) {
                f->gc.rc++;
                set_slot(&f->captured[lb->self], v_func(f));
            }
            PUSH(v_func(f));
            break;
        }
        case OP_PUSH_KIND:
            PUSH(v_kind(in->p));
            break;
        case OP_LOAD_THIS:
            if (fp->receiver) {
                a = v_object(fp->receiver);
                incref(a);
            } else {
                a = v_null();
            }
            PUSH(a);
            break;
        case OP_LOAD_FIELD: {
            Object *o = fp->receiver;
            int f = field_slot(in, o, fp->block->owner);
            if (f >= 0 && o->fields[f].type != T_UNSET) {
                r = o->fields[f];
                incref(r);
            } else if (!property(v_object(o), in->p, false, fp->block->owner, &r)) {
                goto error;
            }
            PUSH(r);
            break;
        }
        case OP_SET_FIELD: {
            Object *o = fp->receiver;
            int f = field_slot(in, o, fp->block->owner);
            if (f < 0) {
                /* Only hand-written bytecode names a field its kind doesn't declare */
                raisef("Cannot set %s here", ((Str *)in->p)->data);
                goto error;
            }
            incref(TOP());
            set_slot(&o->fields[f], TOP());
            break;
        }
        case OP_GET_PROPERTY:
            /* A field that is set, found through the cache; anything else the long way */
            if (TOP().type == T_OBJECT) {
                int f = field_slot(in, TOP().o, fp->block->owner);
                if (f >= 0 && TOP().o->fields[f].type != T_UNSET) {
                    r = TOP().o->fields[f];
                    incref(r);
                    set_slot(&TOP(), r);
                    break;
                }
            }
            if (!property(TOP(), in->p, false, fp->block->owner, &r)) goto error;
            set_slot(&TOP(), r);
            break;
        case OP_GET_PROPERTY_QUIET:
            if (TOP().type == T_NULL) break;
            if (!property(TOP(), in->p, true, fp->block->owner, &r)) goto error;
            set_slot(&TOP(), r);
            break;
        case OP_GET_PROPERTY_EXISTING:
            if (!property_existing(TOP(), in->p, fp->block->owner, &r)) goto error;
            set_slot(&TOP(), r);
            break;
        case OP_GET_METHOD: {
            a = TOP();
            Str *name = in->p;
            if (a.type == T_OBJECT && !(name->len == 1 && name->data[0] == '_')) {
                int m;
                if (a.o->kind == in->cached_kind) {
                    m = in->cached_at;
                } else {
                    m = kind_method(a.o->kind, name, fp->block->owner);
                    in->cached_kind = a.o->kind;
                    in->cached_at = m;
                }
                if (m >= 0) {
                    PUSH(((Value){.type = T_ENTRY, .entry = &a.o->kind->entries[m]}));
                    break;
                }
            }
            if (!property(a, name, false, fp->block->owner, &r)) goto error;
            set_slot(&TOP(), r);
            PUSH(v_null());
            break;
        }
        case OP_CALL_METHOD: {
            if (gc_wanted) gc_collect();
            argc = in->a;
            Value method = sp[-argc - 1];
            Value *callee_slot = sp - argc - 2;
            if (method.type == T_NULL) {
                /* A field holding a function, or whatever else the member was */
                memmove(sp - argc - 1, sp - argc, (size_t)argc * sizeof(Value));
                sp--;
                goto call_value;
            }
            /* GET_METHOD pushed the method and left the object below it; hand-written
               bytecode can leave anything there, and no walk of the stack can tell */
            if (method.type != T_ENTRY || callee_slot->type != T_OBJECT) {
                raisef("CALL_METHOD expects a method of an object, got %s of %s", type_name(method), type_name(*callee_slot));
                goto error;
            }
            Entry *entry = method.entry;
            Function *f = entry->function;
            if (!arity_fits(f->lo, f->hi, argc)) {
                Buf what = {0};
                buf_adds(&what, "Method ");
                buf_add_str(&what, entry->key);
                raise_arity(what.data, f->lo, f->hi, argc);
                free(what.data);
                goto error;
            }
            if (fp - frames == MAX_CALL_DEPTH) {
                raise_depth(entry->key->data);
                goto error;
            }
            Object *receiver = callee_slot->o;
            memmove(callee_slot, sp - argc, (size_t)argc * sizeof(Value));
            sp -= 2;
            if (!push_frame(f->block, &sp, argc, pc, NULL, receiver)) goto error;
            pc = code + f->block->entry;
            break;
        }
        case OP_NEW: {
            if (gc_wanted) gc_collect();
            Kind *c = in->p;
            if (fp - frames == MAX_CALL_DEPTH) {
                raise_depth(c->name->data);
                goto error;
            }
            if (!push_frame(c->block, &sp, in->a, pc, NULL, object_new(c))) goto error;
            pc = code + c->block->entry;
            break;
        }
        case OP_CALL_CONSTRUCTOR: {
            Kind *c = in->p;
            Function *f = method_function(c, constructor_name());
            if (fp - frames == MAX_CALL_DEPTH) {
                /* Located where the object is being made, not in the initialiser */
                Buf what = {0};
                buf_add_str(&what, c->name);
                buf_adds(&what, "._");
                raise_depth(what.data);
                free(what.data);
                Instr *at = fp->ret - 1;
                vm_error->gaz = true;
                vm_error->path = at->file;
                vm_error->line = at->line;
                vm_error->trace = build_trace(at);
                goto error;
            }
            argc = fp->argc;
            for (int i = 0; i < argc; i++) {
                incref(fp->base[i]);
                PUSH(fp->base[i]);
            }
            if (fp->receiver) incref(v_object(fp->receiver));
            if (!push_frame(f->block, &sp, argc, pc, NULL, fp->receiver)) goto error;
            pc = code + f->block->entry;
            break;
        }
        case OP_CALL_PARENT: {
            Function *f = in->p;
            if (fp - frames == MAX_CALL_DEPTH) {
                raise_depth(f->name->data);
                goto error;
            }
            if (fp->receiver) incref(v_object(fp->receiver));
            if (!push_frame(f->block, &sp, in->a, pc, NULL, fp->receiver)) goto error;
            pc = code + f->block->entry;
            break;
        }
        case OP_BIND_PARENT:
            PUSH(v_func(bound_method(fp->receiver, in->p, in->v.s)));
            break;
        case OP_CALL_VALUE: {
            if (gc_wanted) gc_collect();
            argc = in->a;
        call_value:;
            Value *callee_slot = sp - argc - 1;
            Value callee = *callee_slot;
            if (callee.type == T_KIND) {
                Kind *c = callee.k;
                if (c->abstract) {
                    raisef("Cannot construct abstract kind %s", c->name->data);
                    goto error;
                }
                if (!arity_fits(c->lo, c->hi, argc)) {
                    Buf what = {0};
                    buf_adds(&what, "Kind ");
                    buf_add_str(&what, c->name);
                    raise_arity(what.data, c->lo, c->hi, argc);
                    free(what.data);
                    goto error;
                }
                if (fp - frames == MAX_CALL_DEPTH) {
                    raise_depth(c->name->data);
                    goto error;
                }
                memmove(callee_slot, sp - argc, (size_t)argc * sizeof(Value));
                sp--;
                if (!push_frame(c->block, &sp, argc, pc, NULL, object_new(c))) goto error;
                pc = code + c->block->entry;
                break;
            }
            if (callee.type != T_FUNCTION) {
                raisef("Cannot call %s", type_name(callee));
                goto error;
            }
            Func *fn = callee.fn;
            int lo, hi;
            Block *block = NULL;
            if (fn->kind == F_CLOSURE) {
                block = fn->lambda->block;
                lo = block->lo, hi = block->hi;
            } else if (fn->kind == F_BOUND) {
                Function *f = method_function(fn->definer, fn->name);
                block = f->block;
                lo = f->lo, hi = f->hi;
            } else if (fn->kind == F_BUILTIN) {
                lo = builtin_info[fn->builtin].lo, hi = builtin_info[fn->builtin].hi;
            } else {
                block = fn->function->block;
                lo = fn->function->lo, hi = fn->function->hi;
            }
            if (!arity_fits(lo, hi, argc)) {
                Buf what = {0};
                buf_adds(&what, fn->kind == F_BOUND ? "Method " : "Function ");
                describe(callee, &what);
                raise_arity(what.data, lo, hi, argc);
                free(what.data);
                goto error;
            }
            if (fn->kind == F_BUILTIN) {
                vm_here = in;
                if (!call_builtin(fn->builtin, sp - argc, argc, &r)) goto error;
                for (int i = 0; i < argc; i++) decref(POP());
                decref(POP());
                PUSH(r);
                break;
            }
            if (fp - frames == MAX_CALL_DEPTH) {
                Buf what = {0};
                describe(callee, &what);
                raise_depth(what.data);
                free(what.data);
                goto error;
            }
            /* The callee's reference moves into the frame for a closure, and is dropped otherwise */
            Func *closure = fn->kind == F_CLOSURE ? fn : NULL;
            Object *receiver = fn->receiver;
            if (receiver) incref(v_object(receiver));
            memmove(callee_slot, sp - argc, (size_t)argc * sizeof(Value));
            sp--;
            if (!closure) decref(callee);
            if (!push_frame(block, &sp, argc, pc, closure, receiver)) goto error;
            pc = code + block->entry;
            break;
        }

        case OP_TRY:
            if (nhandlers == handlers_cap) {
                handlers_cap = handlers_cap ? handlers_cap * 2 : 16;
                handlers = xrealloc(handlers, (size_t)handlers_cap * sizeof(Handler));
            }
            handlers[nhandlers++] = (Handler){fp, sp, code + in->a};
            break;
        case OP_END_TRY:
            nhandlers--;
            break;
        case OP_CATCH_VALUE:
            /* The value on top is a catch's error in code the compiler wrote; hand-written
               bytecode can put anything there, and the loader can't know what a slot holds */
            if (TOP().type != T_ERROR) { raisef("CATCH_VALUE expects a caught error, got %s", type_name(TOP())); goto error; }
            r = caught(TOP().e);
            set_slot(&TOP(), r);
            break;
        case OP_CATCH_MATCH:
            if (TOP().type != T_ERROR) { raisef("CATCH_MATCH expects a caught error, got %s", type_name(TOP())); goto error; }
            r = caught(TOP().e);
            if (r.type == T_OBJECT && kind_is_a(r.o->kind, in->p)) {
                set_slot(&TOP(), r);
            } else {
                decref(r);
                pc = code + in->a;
            }
            break;
        case OP_RETHROW:
            if (TOP().type != T_ERROR) { raisef("RETHROW expects a caught error, got %s", type_name(TOP())); goto error; }
            if (vm_error) decref((Value){.type = T_ERROR, .e = vm_error});
            vm_error = POP().e;
            goto error;

        default:
            raisef("Unknown instruction %d", op);
            goto error;
        }
        continue;

    error:
        locate(pc - 1);
        if (vm_error->gaz && nhandlers > handler_base) {
            /* Unwind to the innermost try: drop the calls made inside it and whatever the failed
               expression left on the stack, then run its handler with the error pushed */
            Handler h = handlers[--nhandlers];
            for (; fp > h.frame; fp--) {
                if (fp->closure) decref(v_func(fp->closure));
                if (fp->receiver) decref(v_object(fp->receiver));
            }
            while (sp > h.sp) decref(POP());
            PUSH(((Value){.type = T_ERROR, .e = vm_error}));
            vm_error = NULL;
            pc = h.target;
            continue;
        }
        /* Nothing here catches it: drop this execute()'s frames and leave */
        nhandlers = handler_base;
        Value *bottom = first->base;
        for (; fp >= first; fp--) {
            if (fp->closure) decref(v_func(fp->closure));
            if (fp->receiver) decref(v_object(fp->receiver));
        }
        while (sp > bottom) decref(POP());
        vm_sp = bottom;
        return false;
    }
}

/* ---- main ------------------------------------------------------------------------------ */

/* An uncaught error as the CLI reports it: the message, then the calls under it, indented */
static void report(Error *e) {
    Str *message = e->gaz ? error_message(e) : e->reason;
    if (!e->gaz) incref(v_str(message));
    flush_output();
    fputs("Error: ", stderr);
    fwrite(message->data, 1, message->len, stderr);
    fputc('\n', stderr);
    decref(v_str(message));
    if (e->gaz && e->trace.type == T_LIST && e->trace.l->len > 1) {
        for (size_t i = 0; i < e->trace.l->len; i++) {
            Str *line = e->trace.l->items[i].s;
            fputs("  ", stderr);
            fwrite(line->data, 1, line->len, stderr);
            fputc('\n', stderr);
        }
    }
}

static char *read_all(const char *path, size_t *len) {
    FILE *f = fopen(path, "rb");
    if (!f) return NULL;
    Buf b = {0};
    char chunk[65536];
    size_t n;
    while ((n = fread(chunk, 1, sizeof chunk, f)) > 0) buf_add(&b, chunk, n);
    fclose(f);
    if (!b.data) buf_add(&b, "", 0);
    *len = b.len;
    return b.data;
}

/* What was alive before the program was loaded: nothing, or for source what the compiler's run
   left, which is nothing but its own constants */
static int64_t loaded;

/* Drop what a finished program still holds: its globals and static fields, and everything from
   the top frame's locals up. Then collect cycles, and free the run's stack, frames and slots. */
static void finish(Value *top) {
    for (int i = 0; i < program->nglobals; i++) set_slot(&globals[i], v_unset());
    for (int i = 0; i < program->nstatics; i++) set_slot(&statics[i], v_unset());
    while (top > stack) set_slot(--top, v_unset());
    gc_collect();
    free(stack);
    free(frames);
    free(globals);
    free(statics);
}

/* Run the loaded program from its first instruction, reporting an uncaught error: its exit code.
   With check, GAZVM_STATS then says whether the run freed everything it made: whatever is left
   once finish() has dropped what the program held and then its constants, leaked, a missing
   decref somewhere. The tests run every program this way, since a leak changes no output. */
static int run_program(bool check) {
    size_t capacity = (size_t)(MAX_CALL_DEPTH + 4) * (size_t)(program->max_frame + 8) + 1024;
    stack = xcalloc(capacity, sizeof(Value));
    stack_end = stack + capacity;
    frames = xcalloc(MAX_CALL_DEPTH + 4, sizeof(Frame));
    globals = xcalloc((size_t)program->nglobals + 1, sizeof(Value));
    statics = xcalloc((size_t)program->nstatics + 1, sizeof(Value));
    vm_here = NULL;
    /* Every program starts unpredictable, as if it had called rand_seed(), whatever ran before */
    random_seed_unpredictable();
    next_object_id = 1;

    Block *top = program->blocks[0];
    fp = frames;
    fp->block = top;
    fp->base = stack;
    for (int i = 0; i < top->nlocals; i++) stack[i] = v_unset();

    /* The top level ends by running off its code, which returns nothing; RET there is only
       written by hand, and the value it returns is dropped */
    Value returned = v_unset();
    int exit_code = 0;
    Value *held = NULL;
    if (execute(program->code, frames, &returned)) {
        held = vm_sp;
        decref(returned);
    } else {
        Error *thrown = vm_error, *e = thrown;
        if (e->has_value) {
            /* Nothing caught it: only now is a thrown value turned into text, which can run
               its to_string() (and fail, which is then the error reported) */
            vm_error = NULL;
            fp = frames;
            vm_sp = stack + top->nlocals;
            vm_here = NULL;     /* the program has ended: its to_string() has no caller */
            Str *text_value;
            if (to_string(e->value, &text_value)) {
                Error *shown = error_new(text_value, e->path, e->line, false);
                shown->trace = e->trace;
                incref(shown->trace);
                e = shown;
            } else {
                e = vm_error;
            }
        }
        report(e);
        if (e != thrown) decref((Value){.type = T_ERROR, .e = e});
        decref((Value){.type = T_ERROR, .e = thrown});
        vm_error = NULL;
        held = stack;   /* an uncaught error has dropped the stack already */
        exit_code = 1;
    }
    flush_output();
    finish(held);
    if (check && getenv("GAZVM_STATS")) {
        /* The constants in the code go too, so that a missing decref on one is a value left
           over like any other rather than a count one too high on something still held */
        for (int i = 0; i < program->ncode; i++) {
            if (program->code[i].orig == OP_PUSH) set_slot(&program->code[i].v, v_unset());
        }
        fprintf(stderr, "gazvm: %lld values leaked, at most %lld lists, maps, objects and functions alive at once\n",
                (long long)(counted - loaded), (long long)peak);
    }
    return exit_code;
}

/* The self-hosted front end's bytecode, compiler/gazlang.gzb, built into the VM (build/compiler.c) */
extern const unsigned char compiler_gzb[];
extern const unsigned long compiler_gzb_size;

/* What the CLI was asked to do, from its options */
typedef enum { M_RUN, M_CODE, M_TOKENS, M_AST } Mode;

/* What the VM's thread is given */
typedef struct {
    Mode mode;
    const char *path;   /* the file, or NULL for piped input */
    char *text;         /* its contents */
    size_t len;
    int argc;           /* the program's arguments */
    char **argv;
    int exit_code;
} Job;

/* Run the front end, compiler/gazlang.gaz, in a mode on the job's file, or on its text as
   piped input: onto standard output, or with *text set into memory. Its exit code; it reports its
   own errors on standard error. */
static int run_front_end(Job *job, const char *mode, char **text, size_t *len) {
    loaded = counted;
    program = load((const char *)compiler_gzb, compiler_gzb_size, "compiler/gazlang.gzb");
    if (!program) {
        report(vm_error);
        return 1;
    }
    char *args[] = {(char *)mode, (char *)job->path};
    program_argc = job->path ? 2 : 1;
    program_argv = args;
    if (!job->path) {
        /* The driver reads piped source with read_stdin(), which gives it what main() read */
        piped_input = job->text;
        piped_input_len = job->len;
        job->text = NULL;
    }
    if (text) output = open_memstream(text, len);
    int exit_code = run_program(!text);
    if (text) {
        fclose(output);   /* which also sets text and len */
        output = stdout;
    }
    return exit_code;
}

/* The job, from its first instruction to its exit code: the thread main() starts */
static void *run(void *arg) {
    Job *job = arg;
    /* Bytecode is recognised by its first line or its name, so a broken .gzb
       file gets the loader's error; anything else is source */
    const char *magic = "GAZLANG BYTECODE";
    size_t n = job->path ? strlen(job->path) : 0;
    bool bytecode = (n >= 4 && strcmp(job->path + n - 4, ".gzb") == 0)
        || (job->len >= strlen(magic) && memcmp(job->text, magic, strlen(magic)) == 0);

    if (bytecode && job->mode == M_CODE) {
        fwrite(job->text, 1, job->len, stdout);
        job->exit_code = 0;
        return NULL;
    }
    if (bytecode && job->mode != M_RUN) {
        /* On standard output */
        printf("Error: %s is bytecode, which only the VM runs\n", job->path ? job->path : "standard input");
        job->exit_code = 1;
        return NULL;
    }
    if (job->mode != M_RUN) {
        job->exit_code = run_front_end(job, job->mode == M_CODE ? "code" : job->mode == M_TOKENS ? "tokens" : "ast", NULL, NULL);
        return NULL;
    }

    if (!bytecode) {
        /* Compiled into memory, then loaded as if it were bytecode saved next to its source,
           which reports exactly the paths running the source does */
        char *compiled = NULL;
        size_t len = 0;
        int exit_code = run_front_end(job, "code", &compiled, &len);
        if (exit_code != 0) {
            free(compiled);
            job->exit_code = exit_code;
            return NULL;
        }
        free(job->text);
        job->text = compiled;
        job->len = len;
    }
    loaded = counted;
    program = load(job->text, job->len, job->path);
    if (!program) {
        report(vm_error);
        /* The loader gives up at the first problem and drops nothing it built */
        if (getenv("GAZVM_STATS")) fputs("gazvm: leaks not checked: the program did not load\n", stderr);
        job->exit_code = 1;
        return NULL;
    }
    program_argc = job->argc;
    program_argv = job->argv;
    job->exit_code = run_program(true);
    return NULL;
}

static const char *HELP =
    "GazLang - A simple programming language compiler\n"
    "Usage: gazlang [options] [--] [program arguments...]\n"
    "Options:\n"
    "  -h, --help     Show this help message\n"
    "  -v, --version  Show version information\n"
    "  -c, --code         Print the compiled bytecode instead of running it (gazlang -c -f x.gaz > x.gzb)\n"
    "  -t, --tokens   Print the lexer's tokens, one LINE TYPE VALUE per line, instead of interpreting\n"
    "      --ast      Print the parser's tree instead of running it\n"
    "  -f, --file     Read input from a file instead of stdin\n";

static int unknown_option(const char *arg) {
    fprintf(stderr, "Error: Unknown option %s (put program arguments after --)\n", arg);
    return 1;
}

/* The CLI, whose options are read as PHP's getopt("hvf:ct", [help, version, file:, code,
   tokens, ast]) and its check for options getopt doesn't know. Options end at the
   first argument that isn't one ("-" alone included) or after "--"; the rest are the program's. */
int main(int argc, char **argv) {
    bool help = false, version = false, code = false, tokens = false, ast = false;
    const char *file = NULL;
    int files = 0;
    int i = 1;
    for (; i < argc; i++) {
        char *arg = argv[i];
        if (strcmp(arg, "--") == 0) {
            i++;
            break;
        }
        if (arg[0] != '-' || arg[1] == '\0') break;
        if (arg[1] == '-') {
            char *name = arg + 2;
            if (strcmp(name, "help") == 0) help = true;
            else if (strcmp(name, "version") == 0) version = true;
            else if (strcmp(name, "code") == 0) code = true;
            else if (strcmp(name, "tokens") == 0) tokens = true;
            else if (strcmp(name, "ast") == 0) ast = true;
            else if (strncmp(name, "file=", 5) == 0 && name[5]) file = name + 5, files++;
            else if (strcmp(name, "file") == 0) {
                /* The next argument, whatever it is; none is no file, as getopt has it */
                if (i + 1 < argc) file = argv[++i], files++;
            } else return unknown_option(arg);
            continue;
        }
        /* Short options, which can be combined: -ct, or -cf x.gaz, or -cfx.gaz */
        for (char *c = arg + 1; *c; c++) {
            if (*c == 'h') help = true;
            else if (*c == 'v') version = true;
            else if (*c == 'c') code = true;
            else if (*c == 't') tokens = true;
            else if (*c == 'f') {
                if (c[1]) file = c + 1, files++;
                else if (i + 1 < argc) file = argv[++i], files++;
                break;
            } else return unknown_option(arg);
        }
    }

    if (help) {
        fputs(HELP, stdout);
        return 0;
    }
    if (version) {
        puts("GazLang version 0.1.0");
        return 0;
    }
    if (files > 1) {
        fputs("Error: Give one file, with -f or --file\n", stderr);
        return 1;
    }
    Job job = {
        .mode = tokens ? M_TOKENS : ast ? M_AST : code ? M_CODE : M_RUN,
        .path = file, .argc = argc - i, .argv = argv + i, .exit_code = 1,
    };

    if (file) {
        struct stat st;
        if (stat(file, &st) != 0 || !S_ISREG(st.st_mode) || access(file, R_OK) != 0 || !(job.text = read_all(file, &job.len))) {
            fprintf(stderr, "Error: Cannot read file: %s\n", file);
            return 1;
        }
    } else if (isatty(STDIN_FILENO)) {
        /* No file and nothing piped: there is no interactive mode */
        fputs(HELP, stderr);
        return 1;
    } else {
        Buf b = {0};
        char chunk[65536];
        size_t n;
        while ((n = fread(chunk, 1, sizeof chunk, stdin)) > 0) buf_add(&b, chunk, n);
        if (!b.data) buf_add(&b, "", 0);
        job.text = b.data;
        job.len = b.len;
    }
    output = stdout;

    /* Output is buffered in big blocks; anything written to standard error flushes it first,
       so the two stay in the order the program wrote them */
    setvbuf(stdout, NULL, _IOFBF, 1 << 16);

    /* Calls between GazLang functions don't use the C stack, but a method run from inside an
       instruction (echo calling to_string()) does, a nested execute() each, and a program can
       nest those as deep as the call depth limit. So the program runs on a thread whose stack
       is big enough for that: 1GB of address space, which the system only backs with memory
       as it is used. */
    pthread_attr_t attr;
    pthread_attr_init(&attr);
    pthread_attr_setstacksize(&attr, (size_t)1 << 30);
    pthread_t thread;
    if (pthread_create(&thread, &attr, run, &job) != 0) {
        fputs("Error: cannot start the VM's thread\n", stderr);
        return 1;
    }
    pthread_join(thread, NULL);
    free(job.text);
    fflush(stdout);
    return job.exit_code;
}

/* LeakSanitizer, which the sanitized builds run on Linux (not on macOS), reports what is left
   unreachable at exit. The loader gives up at the first problem in a file without dropping what
   it built, on purpose, so leaks from inside it aren't reported; everything else still is. The
   sanitizers look this function up by name, and other builds never call it. */
const char *__lsan_default_suppressions(void);
const char *__lsan_default_suppressions(void) {
    return "leak:^load$\nleak:^read_block$\nleak:^check_block$\nleak:^split_words$\n";
}
const char *__lsan_default_options(void);
const char *__lsan_default_options(void) {
    return "print_suppressions=0";   /* or it lists them on standard error, which the tests compare */
}
