/*
 * The dispatch loop, calls, errors and traces (VM\VM), and main()
 *
 * One value stack holds every frame: a call's arguments, already pushed by the caller, become
 * the first local slots of the callee's frame, its other locals follow, and its own stack
 * grows above those. Frames are records in an array, not C recursion, so deep GazLang
 * recursion needs no C stack. The one exception is a method run to its end from inside an
 * instruction (echo calling to_string()), which runs a nested execute() above the stack as it
 * is, as the PHP VM does.
 */
#include "gazvm.h"

#include <pthread.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>

Program *program;
Error *vm_error;

typedef struct Frame {
    Block *block;
    Value *base;        /* its local slots; its stack starts after them */
    Instr *ret;         /* where the caller carries on; NULL for the first frame of an execute() */
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
static Value *globals;
static Frame *frames, *fp;  /* every frame; fp is the running one, and fp - frames the call depth */
static Handler *handlers;
static int nhandlers, handlers_cap;

/* ---- Errors ---------------------------------------------------------------------------- */

Error *error_new(Str *reason, Str *path, int64_t line, bool show_location) {
    Error *e = xcalloc(1, sizeof(Error));
    e->rc = 1;
    e->reason = reason;
    e->path = path;
    if (path) incref(v_str(path));
    e->line = line;
    e->show_location = show_location;
    e->gaz = true;
    return e;
}

/* An error with no location yet (a plain Exception in PHP); it takes its reason's reference */
bool raise_str(Str *reason) {
    if (vm_error) decref((Value){.type = T_ERROR, .e = vm_error});
    vm_error = error_new(reason, NULL, 0, true);
    vm_error->gaz = false;
    return false;
}

bool raise(const char *fmt, ...) {
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
 * they made. Only this execute()'s frames: a method run by printing starts a trace of its own.
 * Keeps the innermost 10 and outermost 10, with a line saying how many it left out.
 */
static Value build_trace(Instr *at, Frame *first) {
    int n = (int)(fp - first) + 1;
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
static void locate(Instr *at, Frame *first) {
    Error *e = vm_error;
    if (at->line == 0 || (e->gaz && e->line)) return;
    Value trace = build_trace(at, first);
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
    Class *error_class = program->error_class;
    Value path = e->path ? v_str(e->path) : v_null();
    Value where = e->line ? v_int(e->line) : v_null();
    Value calls = e->trace.type == T_UNSET ? v_list(list_new(0)) : e->trace;
    if (e->trace.type != T_UNSET) incref(calls);
    if (!e->has_value) {
        Object *o = xcalloc(1, sizeof(Object) + (size_t)error_class->nfields * sizeof(Value));
        gc_track(&o->gc, T_OBJECT);
        o->cls = error_class;
        incref(v_str(e->reason));
        o->fields[class_field(error_class, message)] = v_str(e->reason);
        incref(path);
        o->fields[class_field(error_class, file)] = path;
        o->fields[class_field(error_class, line)] = where;
        o->fields[class_field(error_class, trace)] = calls;
        e->caught = v_object(o);
    } else {
        Value v = e->value;
        if (v.type == T_OBJECT && error_class && class_is_a(v.o->cls, error_class)
            && v.o->fields[class_field(v.o->cls, line)].type == T_UNSET) {
            incref(path);
            set_slot(&v.o->fields[class_field(v.o->cls, file)], path);
            set_slot(&v.o->fields[class_field(v.o->cls, line)], where);
            set_slot(&v.o->fields[class_field(v.o->cls, trace)], calls);
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

void flush_output(void) { fflush(stdout); }

/* ---- Calls ----------------------------------------------------------------------------- */

static bool raise_depth(const char *what) {
    return raise("Maximum call depth of %d exceeded calling %s", MAX_CALL_DEPTH, what);
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
        return raise("Stack overflow");
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

static Object *object_new(Class *c) {
    Object *o = xcalloc(1, sizeof(Object) + (size_t)c->nfields * sizeof(Value));
    gc_track(&o->gc, T_OBJECT);
    o->cls = c;
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

static Function *method_function(Class *definer, Str *name) {
    int m = class_method(definer, name);
    return m < 0 ? NULL : definer->entries[m].function;
}

static bool execute(Instr *pc, Frame *first, Value *result);

/* Run a method on an object to its end, from inside an instruction (Values::$call_method) */
bool call_method(Object *o, Class *definer, Str *name, Value *out) {
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
    Value *sp = vm_sp;
    incref(v_object(o));
    if (!push_frame(f->block, &sp, 0, NULL, NULL, o)) return false;
    return execute(program->code + f->block->entry, fp, out);
}

/* ---- The loop -------------------------------------------------------------------------- */

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
        switch (in->op) {
        case OP_PUSH:
            incref(in->v);
            PUSH(in->v);
            break;
        case OP_POP:
            decref(POP());
            break;
        case OP_PRINT: {
            Buf out = {0};
            if (!append_string(TOP(), &out)) {
                free(out.data);
                goto error;
            }
            buf_addc(&out, '\n');
            fwrite(out.data, 1, out.len, stdout);
            free(out.data);
            decref(POP());
            break;
        }
        case OP_LOAD:
            a = fp->base[in->a];
            if (a.type == T_UNSET) {
                raise("Undefined variable: %s", fp->block->locals[in->a]->data);
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
                raise("Undefined variable: %s", program->globals[in->a]->data);
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
                raise("Undefined variable: %s", fp->closure->lambda->block->captures[in->a]->data);
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

        case OP_CONCAT_ASSIGN:
        case OP_CONCAT_ASSIGN_GLOBAL:
        case OP_CONCAT_ASSIGN_CAPTURED: {
            Value *slot;
            Str *name;
            if (in->op == OP_CONCAT_ASSIGN) {
                slot = &fp->base[in->a];
                name = fp->block->locals[in->a];
            } else if (in->op == OP_CONCAT_ASSIGN_GLOBAL) {
                slot = &globals[in->a];
                name = program->globals[in->a];
            } else {
                slot = &fp->closure->captured[in->a];
                name = fp->closure->lambda->block->captures[in->a];
            }
            if (slot->type == T_UNSET) {
                raise("Undefined variable: %s", name->data);
                goto error;
            }
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
                bool overflow = in->op == OP_ADD   ? __builtin_add_overflow(a.i, b.i, &n)
                                : in->op == OP_SUB ? __builtin_sub_overflow(a.i, b.i, &n)
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
                bool yes = in->op == OP_LT ? a.i < b.i : in->op == OP_LE ? a.i <= b.i : in->op == OP_GT ? a.i > b.i : a.i >= b.i;
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
            sp[-1] = v_bool(equal == (in->op == OP_EQUALS));
            break;
        }
        case OP_DIV:
        case OP_MOD:
        case OP_BIT_AND:
        case OP_BIT_OR:
        case OP_BIT_XOR:
        case OP_SHL:
        case OP_SHR:
        case OP_CONCAT:
        case OP_CMP:
        binary:
            if (!binary_op(in->op, sp[-2], sp[-1], &r)) goto error;
            decref(POP());
            set_slot(&TOP(), r);
            break;
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
            if (a.type == T_INT && a.i != (in->op == OP_INC ? INT64_MAX : INT64_MIN)) {
                TOP().i += in->op == OP_INC ? 1 : -1;
                break;
            }
            if (!step_value(a, in->op == OP_INC, &r)) goto error;
            set_slot(&TOP(), r);
            break;
        case OP_NO_MATCH: {
            a = TOP();
            Buf m = {0};
            buf_adds(&m, "No arm matches ");
            if (a.type == T_STRING) quote(a.s, &m);
            else if (a.type == T_INT || a.type == T_FLOAT || a.type == T_BOOL || a.type == T_NULL || a.type == T_CLASS
                     || a.type == T_FUNCTION) append_string(a, &m);
            else buf_adds(&m, type_name(a));
            raise_str(buf_to_str(&m));
            goto error;
        }
        case OP_NO_CONDITION:
            raise("No arm matched");
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
            vm_sp = sp;
            return true;

        case OP_NEW_ARRAY:
            PUSH(v_list(list_new(0)));
            break;
        case OP_ARRAY_PUSH:
            list_push(list_unique(&sp[-2]), sp[-1]);
            sp--;
            break;
        case OP_ARRAY_EXTEND: {
            a = TOP();
            if (a.type != T_LIST) {
                raise("Cannot spread %s: only a list can be", type_name(a));
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
        case OP_MAP_SET:
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
                raise("foreach expects a list or map, got %s", type_name(TOP()));
                goto error;
            }
            break;
        case OP_DESTRUCTURE:
            a = TOP();
            if (a.type != T_LIST) {
                raise("Cannot destructure %s: only a list can be", type_name(a));
                goto error;
            }
            if (a.l->len != (size_t)in->a) {
                raise("Cannot destructure a list of %zu %s into %d", a.l->len, a.l->len == 1 ? "element" : "elements", in->a);
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
            sp--;
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
        case OP_SET_PATH_THIS: {
            Path *path = in->p;
            Value *keys = sp - 1 - path->nkeys;
            bool ok;
            if (in->op == OP_SET_PATH) {
                ok = store_path(&fp->base[in->a], fp->block->locals[in->a], path, keys, TOP());
            } else if (in->op == OP_SET_PATH_GLOBAL) {
                ok = store_path(&globals[in->a], program->globals[in->a], path, keys, TOP());
            } else if (in->op == OP_SET_PATH_CAPTURED) {
                ok = store_path(&fp->closure->captured[in->a], fp->closure->lambda->block->captures[in->a], path, keys, TOP());
            } else {
                /* The object is a handle, so writing through a copy of it writes the object */
                Value self = fp->receiver ? v_object(fp->receiver) : v_null();
                ok = store_path(&self, str_intern("#", 1), path, keys, TOP());
            }
            if (!ok) goto error;
            for (int i = 0; i < path->nkeys; i++) decref(keys[i]);
            keys[0] = TOP();
            sp = keys + 1;
            break;
        }
        case OP_DELETE_PATH:
        case OP_DELETE_PATH_GLOBAL:
        case OP_DELETE_PATH_CAPTURED:
        case OP_DELETE_PATH_THIS: {
            Path *path = in->p;
            Value *keys = sp - path->nkeys;
            bool ok;
            if (in->op == OP_DELETE_PATH) {
                ok = remove_path(&fp->base[in->a], fp->block->locals[in->a], path, keys);
            } else if (in->op == OP_DELETE_PATH_GLOBAL) {
                ok = remove_path(&globals[in->a], program->globals[in->a], path, keys);
            } else if (in->op == OP_DELETE_PATH_CAPTURED) {
                ok = remove_path(&fp->closure->captured[in->a], fp->closure->lambda->block->captures[in->a], path, keys);
            } else {
                Value self = fp->receiver ? v_object(fp->receiver) : v_null();
                ok = remove_path(&self, str_intern("#", 1), path, keys);
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
        case OP_PUSH_CLASS:
            PUSH(v_class(in->p));
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
            int f = o ? class_field(o->cls, in->p) : -1;
            if (f >= 0 && o->fields[f].type != T_UNSET) {
                r = o->fields[f];
                incref(r);
            } else if (!property(o ? v_object(o) : v_null(), in->p, false, &r)) {
                goto error;
            }
            PUSH(r);
            break;
        }
        case OP_SET_FIELD: {
            Object *o = fp->receiver;
            int f = o ? class_field(o->cls, in->p) : -1;
            if (f < 0) {
                raise("Cannot set %s here", ((Str *)in->p)->data);
                goto error;
            }
            incref(TOP());
            set_slot(&o->fields[f], TOP());
            break;
        }
        case OP_GET_PROPERTY:
            if (!property(TOP(), in->p, false, &r)) goto error;
            set_slot(&TOP(), r);
            break;
        case OP_GET_PROPERTY_QUIET:
            if (TOP().type == T_NULL) break;
            if (!property(TOP(), in->p, true, &r)) goto error;
            set_slot(&TOP(), r);
            break;
        case OP_GET_PROPERTY_EXISTING:
            if (!property_existing(TOP(), in->p, &r)) goto error;
            set_slot(&TOP(), r);
            break;
        case OP_GET_METHOD: {
            a = TOP();
            Str *name = in->p;
            if (a.type == T_OBJECT && !(name->len == 1 && name->data[0] == '_')) {
                int m = class_method(a.o->cls, name);
                if (m >= 0) {
                    PUSH(((Value){.type = T_ENTRY, .entry = &a.o->cls->entries[m]}));
                    break;
                }
            }
            if (!property(a, name, false, &r)) goto error;
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
            Class *c = in->p;
            if (fp - frames == MAX_CALL_DEPTH) {
                raise_depth(c->name->data);
                goto error;
            }
            if (!push_frame(c->block, &sp, in->a, pc, NULL, object_new(c))) goto error;
            pc = code + c->block->entry;
            break;
        }
        case OP_CALL_CONSTRUCTOR: {
            Class *c = in->p;
            Function *f = method_function(c, str_intern("_", 1));
            if (fp - frames == MAX_CALL_DEPTH) {
                /* Where the object is being made, as in the interpreter, not in the initialiser */
                Buf what = {0};
                buf_add_str(&what, c->name);
                buf_adds(&what, "._");
                raise_depth(what.data);
                free(what.data);
                Instr *at = fp->ret - 1;
                vm_error->gaz = true;
                vm_error->path = at->file;
                vm_error->line = at->line;
                vm_error->trace = build_trace(at, first);
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
            if (callee.type == T_CLASS) {
                Class *c = callee.c;
                if (c->abstract) {
                    raise("Cannot construct abstract class %s", c->name->data);
                    goto error;
                }
                if (!arity_fits(c->lo, c->hi, argc)) {
                    Buf what = {0};
                    buf_adds(&what, "Class ");
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
                raise("Cannot call %s", type_name(callee));
                goto error;
            }
            Func *fn = callee.fn;
            int lo, hi;
            Block *block = NULL;
            if (fn->kind == F_CLOSURE) {
                block = fn->lambda->block;
                lo = block->lo, hi = block->hi;
            } else if (fn->kind == F_BOUND) {
                Function *f = method_function(fn->cls, fn->name);
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
            r = caught(TOP().e);
            set_slot(&TOP(), r);
            break;
        case OP_CATCH_MATCH:
            r = caught(TOP().e);
            if (r.type == T_OBJECT && class_is_a(r.o->cls, in->p)) {
                set_slot(&TOP(), r);
            } else {
                decref(r);
                pc = code + in->a;
            }
            break;
        case OP_RETHROW:
            if (vm_error) decref((Value){.type = T_ERROR, .e = vm_error});
            vm_error = POP().e;
            goto error;

        default:
            raise("Unknown instruction %d", in->op);
            goto error;
        }
        continue;

    error:
        locate(pc - 1, first);
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

/* The program, from its first instruction to its exit code: the thread main() starts */
static void *run(void *arg) {
    int *exit_code = arg;
    size_t capacity = (size_t)(MAX_CALL_DEPTH + 4) * (size_t)(program->max_frame + 8) + 1024;
    stack = xcalloc(capacity, sizeof(Value));
    stack_end = stack + capacity;
    frames = xcalloc(MAX_CALL_DEPTH + 4, sizeof(Frame));
    globals = xcalloc((size_t)program->nglobals + 1, sizeof(Value));

    Block *top = program->blocks[0];
    fp = frames;
    fp->block = top;
    fp->base = stack;
    for (int i = 0; i < top->nlocals; i++) stack[i] = v_unset();

    Value ignored;
    if (!execute(program->code, frames, &ignored)) {
        Error *e = vm_error;
        if (e->has_value) {
            /* Nothing caught it: only now is a thrown value turned into text, which can run
               its to_string() (and fail, which is then the error reported) */
            vm_error = NULL;
            fp = frames;
            vm_sp = stack + top->nlocals;
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
        *exit_code = 1;
        return NULL;
    }
    flush_output();
    /* GAZVM_STATS: how much is still alive once the program is done and cycles are collected,
       which is how the tests see the collector free what reference counting can't */
    if (getenv("GAZVM_STATS")) {
        gc_collect();
        fprintf(stderr, "gazvm: %lld lists, maps, objects and functions alive at the end, at most %lld at once\n",
                (long long)live, (long long)peak);
    }
    *exit_code = 0;
    return NULL;
}

int main(int argc, char **argv) {
    if (argc < 2) {
        fputs("Usage: gazvm program.gzb [arguments...]\n", stderr);
        return 1;
    }
    size_t len;
    char *text = read_all(argv[1], &len);
    if (!text) {
        fprintf(stderr, "Error: Cannot read file: %s\n", argv[1]);
        return 1;
    }
    program_argc = argc - 2;
    program_argv = argv + 2;

    program = load(text, len, argv[1]);
    free(text);
    if (!program) {
        report(vm_error);
        return 1;
    }

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
    int exit_code = 1;
    if (pthread_create(&thread, &attr, run, &exit_code) != 0) {
        fputs("Error: cannot start the VM's thread\n", stderr);
        return 1;
    }
    pthread_join(thread, NULL);
    return exit_code;
}
