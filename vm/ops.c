/*
 * Operators, indexing, members and write paths
 *
 * The tests compare every message here word for word, so a change to one is a change to the
 * language.
 */
#include "gazvm.h"

#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

/* The symbol an operator instruction's errors name */
static const char *symbol(int op) {
    switch (op) {
    case OP_ADD: return "+";
    case OP_SUB: return "-";
    case OP_MUL: return "*";
    case OP_DIV: return "/";
    case OP_MOD: return "%";
    case OP_BIT_AND: return "&";
    case OP_BIT_OR: return "|";
    case OP_BIT_XOR: return "^";
    case OP_SHL: return "<<";
    case OP_SHR: return ">>";
    case OP_CONCAT: return "..";
    case OP_EQUALS: return "==";
    case OP_NOT_EQUALS: return "!=";
    case OP_LT: return "<";
    case OP_LE: return "<=";
    case OP_GT: return ">";
    case OP_GE: return ">=";
    case OP_CMP: return "<=>";
    default: return "?";
    }
}

static bool is_scalar(Value v) {
    return v.type == T_INT || v.type == T_FLOAT || v.type == T_STRING || v.type == T_BOOL;
}

/* + - * / % on two numbers, neither a string */
static bool arithmetic(int op, Value l, Value r, Value *out) {
    if (l.type == T_STRING || r.type == T_STRING) return raisef("Cannot use %s on string", symbol(op));
    if (op == OP_MOD && (l.type == T_FLOAT || r.type == T_FLOAT)) return raisef("Cannot use %% on float");
    bool zero = r.type == T_INT ? r.i == 0 : r.f == 0.0;
    if (zero && op == OP_DIV) return raisef("Division by zero");
    if (zero && op == OP_MOD) return raisef("Modulo by zero");

    if (l.type == T_INT && r.type == T_INT && op != OP_DIV) {
        int64_t result;
        bool overflow = false;
        switch (op) {
        case OP_ADD: overflow = __builtin_add_overflow(l.i, r.i, &result); break;
        case OP_SUB: overflow = __builtin_sub_overflow(l.i, r.i, &result); break;
        case OP_MUL: overflow = __builtin_mul_overflow(l.i, r.i, &result); break;
        default:
            /* % takes its sign from the left, as in C; the smallest int % -1 is 0, as in PHP,
               where C would crash */
            result = r.i == -1 ? 0 : l.i % r.i;
            break;
        }
        if (overflow) return raisef("Integer overflow");
        *out = v_int(result);
        return true;
    }

    double a = l.type == T_INT ? (double)l.i : l.f;
    double b = r.type == T_INT ? (double)r.i : r.f;
    double result;
    switch (op) {
    case OP_ADD: result = a + b; break;
    case OP_SUB: result = a - b; break;
    case OP_MUL: result = a * b; break;
    default: result = a / b; break;
    }
    if (!isfinite(result)) return raisef("Float overflow");
    *out = v_float(result);
    return true;
}

/* & | ^ << >> on two ints */
static bool bitwise(int op, Value l, Value r, Value *out) {
    if (l.type != T_INT || r.type != T_INT) {
        return raisef("Cannot use %s on %s", symbol(op), type_name(l.type == T_INT ? r : l));
    }
    if ((op == OP_SHL || op == OP_SHR) && (r.i < 0 || r.i > 63)) {
        return raisef("Shift count must be between 0 and 63, got %lld", (long long)r.i);
    }
    switch (op) {
    case OP_BIT_AND: *out = v_int(l.i & r.i); break;
    case OP_BIT_OR: *out = v_int(l.i | r.i); break;
    case OP_BIT_XOR: *out = v_int(l.i ^ r.i); break;
    /* Shifting a negative number left is undefined in C, so shift the bits as unsigned: the
       bits shifted off the top are gone and the result wraps, as the language says */
    case OP_SHL: *out = v_int((int64_t)((uint64_t)l.i << r.i)); break;
    /* >> on a negative number keeps the sign in every compiler this is built with */
    default: *out = v_int(l.i >> r.i); break;
    }
    return true;
}

/* Any binary operator but && and ||, which short-circuit in the code */
bool binary_op(int op, Value l, Value r, Value *out) {
    if (op == OP_CONCAT) {
        Buf b = {0};
        if (!append_string(l, &b) || !append_string(r, &b)) {
            free(b.data);
            return false;
        }
        *out = v_str(buf_to_str(&b));
        return true;
    }
    if (op == OP_EQUALS || op == OP_NOT_EQUALS) {
        *out = v_bool(values_equal(l, r) == (op == OP_EQUALS));
        return true;
    }
    if (!is_scalar(l) || !is_scalar(r)) {
        return raisef("Cannot use %s on %s", symbol(op), type_name(is_scalar(l) ? r : l));
    }
    if (l.type == T_BOOL || r.type == T_BOOL) return raisef("Cannot use %s on bool", symbol(op));
    if (op == OP_ADD || op == OP_SUB || op == OP_MUL || op == OP_DIV || op == OP_MOD) {
        return arithmetic(op, l, r, out);
    }
    if (op == OP_BIT_AND || op == OP_BIT_OR || op == OP_BIT_XOR || op == OP_SHL || op == OP_SHR) {
        return bitwise(op, l, r, out);
    }
    if ((l.type == T_STRING) != (r.type == T_STRING)) {
        return raisef("Cannot use %s on string and %s", symbol(op), type_name(l.type == T_STRING ? r : l));
    }
    int c = l.type == T_STRING ? str_cmp(l.s, r.s) : compare_numbers(l, r);
    switch (op) {
    case OP_LT: *out = v_bool(c < 0); break;
    case OP_LE: *out = v_bool(c <= 0); break;
    case OP_GT: *out = v_bool(c > 0); break;
    case OP_GE: *out = v_bool(c >= 0); break;
    default: *out = v_int((c > 0) - (c < 0)); break;
    }
    return true;
}

bool negate(Value v, Value *out) {
    if (v.type == T_FLOAT) {
        *out = v_float(-v.f);
        return true;
    }
    if (v.type != T_INT) return raisef("Cannot use - on %s", type_name(v));
    if (v.i == INT64_MIN) return raisef("Integer overflow");
    *out = v_int(-v.i);
    return true;
}

bool bitwise_not(Value v, Value *out) {
    if (v.type != T_INT) return raisef("Cannot use ~ on %s", type_name(v));
    *out = v_int(~v.i);
    return true;
}

/* ++ and -- */
bool step_value(Value v, bool up, Value *out) {
    if (v.type != T_INT && v.type != T_FLOAT) return raisef("Cannot use %s on %s", up ? "++" : "--", type_name(v));
    return binary_op(up ? OP_ADD : OP_SUB, v, v_int(1), out);
}

bool array_key(Value k) {
    if (k.type == T_INT || k.type == T_STRING) return true;
    return raisef("Keys must be int or string, got %s", type_name(k));
}

bool raise_undefined_key(Value key) {
    if (key.type == T_STRING) {
        Buf b = {0};
        buf_adds(&b, "Undefined key: ");
        quote(key.s, &b);
        return raise_str(buf_to_str(&b));
    }
    return raisef("Undefined key: %lld", (long long)key.i);
}

/* $target[$index]; quiet, on the left of ??, reads what is missing as null */
bool index_value(Value target, Value index, bool quiet, Value *out) {
    if (target.type == T_LIST) {
        if (index.type != T_INT) {
            if (!array_key(index)) return false;
            return raisef("List indexes must be int, got %s", type_name(index));
        }
        if (index.i >= 0 && (uint64_t)index.i < target.l->len) {
            *out = target.l->items[index.i];
            incref(*out);
            return true;
        }
        if (quiet) {
            *out = v_null();
            return true;
        }
        return raisef("Index out of range: %lld", (long long)index.i);
    }
    if (target.type == T_MAP) {
        if (!array_key(index)) return false;
        Value *found = map_find(target.m, index);
        if (found) {
            *out = *found;
            incref(*out);
            return true;
        }
        if (quiet) {
            *out = v_null();
            return true;
        }
        return raise_undefined_key(index);
    }
    if (target.type == T_STRING) {
        if (index.type != T_INT) return raisef("String positions must be int, got %s", type_name(index));
        if (index.i >= 0 && (uint64_t)index.i < target.s->len) {
            *out = v_str(str_byte((unsigned char)target.s->data[index.i]));
            return true;
        }
        if (quiet) {
            *out = v_null();
            return true;
        }
        return raisef("Index out of range: %lld", (long long)index.i);
    }
    return raisef("Cannot use [] on %s", type_name(target));
}

/* An element a compound update reads, which must exist in a list or map */
bool index_existing(Value target, Value index, Value *out) {
    if (target.type != T_LIST && target.type != T_MAP) return raisef("Cannot use [] on %s", type_name(target));
    return index_value(target, index, false, out);
}

/* ---- Kinds and members --------------------------------------------------------------- */

static Kind initialiser_asks;
Kind *const KIND_INITIALISER = &initialiser_asks;

/* Whether a member declared with this visibility answers the kind asking for it */
bool member_escapes(Vis vis, Kind *declarer, Kind *asking) {
    if (vis == V_PUB || asking == KIND_INITIALISER) return true;
    if (!asking) return false;
    if (vis == V_KIN) return kind_is_a(asking, declarer);
    return asking == declarer;
}

/* Names are interned, so a pointer comparison finds them. A kind sees its own members and
   what its parents let escape, so at most one member of a name answers any one asker. */
int kind_field(Kind *c, Str *name, Kind *asking) {
    for (int i = 0; i < c->nfields; i++) {
        if (c->fields[i] != name) continue;
        if (member_escapes(c->field_vis[i], c->field_declarers[i], asking)) return i;
    }
    return -1;
}

int kind_method(Kind *c, Str *name, Kind *asking) {
    for (int i = 0; i < c->nmethods; i++) {
        if (c->methods[i] != name) continue;
        if (member_escapes(c->method_vis[i], c->method_declarers[i], asking)) return i;
    }
    return -1;
}

/* The kind and level of a member of that name, whatever it escapes, or NULL when the kind has
   none: what tells "isn't yours" from "has no member" */
static Kind *kind_hides(Kind *c, Str *name, Vis *vis) {
    for (int i = 0; i < c->nfields; i++) {
        if (c->fields[i] == name) return *vis = c->field_vis[i], c->field_declarers[i];
    }
    for (int i = 0; i < c->nmethods; i++) {
        if (c->methods[i] == name) return *vis = c->method_vis[i], c->method_declarers[i];
    }
    return NULL;
}

bool kind_is_a(Kind *c, Kind *ancestor) {
    for (; c; c = c->parent) {
        if (c == ancestor) return true;
    }
    return false;
}

Func *bound_method(Object *o, Kind *definer, Str *name) {
    Func *f = xcalloc(1, sizeof(Func));
    gc_track(&f->gc, T_FUNCTION);
    f->kind = F_BOUND;
    f->name = name;
    f->definer = definer;
    f->receiver = o;
    incref(v_object(o));
    return f;
}

static bool raise_undefined_member(Kind *c, Str *name) {
    if (name->len == 1 && name->data[0] == '_' && kind_method(c, name, c) >= 0) {
        return raisef("Cannot use the constructor of %s as a member", c->name->data);
    }
    /* It is there, but not for this asker: say so, and which level kept it, rather than deny
       that it exists */
    Vis vis = V_OWN;
    Kind *declarer = kind_hides(c, name, &vis);
    if (declarer) {
        if (vis == V_KIN) {
            return raisef("%s.%s is kin, so only %s and what extends it can use it",
                          c->name->data, name->data, declarer->name->data);
        }
        return raisef("%s.%s is not pub, so only %s can use it",
                      c->name->data, name->data, declarer->name->data);
    }
    return raisef("%s has no member %s", c->name->data, name->data);
}

static bool raise_not_set(Object *o, Str *name) {
    return raisef("Property %s of %s is not set", name->data, o->kind->name->data);
}

static bool is_constructor(Str *name) { return name->len == 1 && name->data[0] == '_'; }

/* $obj.name: a field's value or a bound method; quiet reads an unset field as null */
bool property(Value target, Str *name, bool quiet, Kind *asking, Value *out) {
    if (target.type != T_OBJECT) return raisef("Cannot use . on %s", type_name(target));
    Object *o = target.o;
    int f = kind_field(o->kind, name, asking);
    if (f >= 0) {
        if (o->fields[f].type != T_UNSET) {
            *out = o->fields[f];
            incref(*out);
            return true;
        }
        if (quiet) {
            *out = v_null();
            return true;
        }
        return raise_not_set(o, name);
    }
    int m = kind_method(o->kind, name, asking);
    if (m >= 0 && !is_constructor(name)) {
        *out = v_func(bound_method(o, o->kind->definers[m], name));
        return true;
    }
    return raise_undefined_member(o->kind, name);
}

/* The field a write path goes through: a declared field, not a method */
static int check_field(Object *o, Str *name, Kind *asking) {
    int f = kind_field(o->kind, name, asking);
    if (f >= 0) return f;
    int m = kind_method(o->kind, name, asking);
    if (!is_constructor(name) && m >= 0) {
        raisef("Cannot assign to method %s.%s", o->kind->definers[m]->name->data, name->data);
    } else {
        raise_undefined_member(o->kind, name);
    }
    return -1;
}

/* A field a compound update reads, which must be set */
bool property_existing(Value target, Str *name, Kind *asking, Value *out) {
    if (target.type != T_OBJECT) return raisef("Cannot use . on %s", type_name(target));
    int f = check_field(target.o, name, asking);
    if (f < 0) return false;
    if (target.o->fields[f].type == T_UNSET) return raise_not_set(target.o, name);
    *out = target.o->fields[f];
    incref(*out);
    return true;
}

/* ---- Write paths ----------------------------------------------------------------------- */

/*
 * $a[k].x[] = value, without a compound operator: write through a variable along its steps.
 * The variable must exist, a list index must exist, a map may gain its last key and an object
 * its last field; anything missing along the way is an error. Lists and maps are copied first
 * when shared (copy on write), objects written in place. The value is borrowed.
 */
bool store_path(Value *slot, Str *var_name, Path *path, Value *keys, Value value, Kind *asking) {
    if (slot->type == T_UNSET) return raisef("Undefined variable: %s", var_name->data);
    Value *cur = slot;
    int k = 0;
    /* What the last step found missing: a map and its key, or an object's field */
    bool exists = true;
    Map *missing_map = NULL;
    Value missing_key = v_null();
    Object *missing_object = NULL;
    Str *missing_field = NULL;

    for (int s = 0; s < path->nsteps; s++) {
        PathStep *step = &path->steps[s];
        if (!exists) return missing_object ? raise_not_set(missing_object, missing_field) : raise_undefined_key(missing_key);
        if (step->kind == S_FIELD) {
            if (cur->type != T_OBJECT) return raisef("Cannot use . on %s", type_name(*cur));
            Object *o = cur->o;
            int f = check_field(o, step->name, asking);
            if (f < 0) return false;
            cur = &o->fields[f];
            if (cur->type == T_UNSET) {
                exists = false;
                missing_object = o;
                missing_field = step->name;
            }
            continue;
        }
        if (cur->type == T_LIST) {
            if (step->kind == S_APPEND) {
                List *l = list_unique(cur);
                incref(value);
                list_push(l, value);
                return true;
            }
            Value key = keys[k++];
            if (key.type != T_INT) return raisef("List indexes must be int, got %s", type_name(key));
            if (key.i < 0 || (uint64_t)key.i >= cur->l->len) return raisef("Index out of range: %lld", (long long)key.i);
            List *l = list_unique(cur);
            cur = &l->items[key.i];
        } else if (cur->type == T_MAP) {
            if (step->kind == S_APPEND) return raisef("Cannot append to a map");
            Value key = keys[k++];
            Map *m = map_unique(cur);
            Value *found = map_find(m, key);
            if (found) {
                cur = found;
            } else {
                exists = false;
                missing_map = m;
                missing_key = key;
                missing_object = NULL;
            }
        } else {
            return raisef("Cannot use [] on %s", type_name(*cur));
        }
    }

    incref(value);
    if (!exists && !missing_object) {
        map_set(missing_map, missing_key, value);
    } else {
        set_slot(cur, value);
    }
    return true;
}

/* delete $a[k]...[k]: every step must exist, the last one included */
bool remove_path(Value *slot, Str *var_name, Path *path, Value *keys, Kind *asking) {
    if (slot->type == T_UNSET) return raisef("Undefined variable: %s", var_name->data);
    Value *cur = slot;
    int k = 0;
    for (int s = 0; s < path->nsteps; s++) {
        PathStep *step = &path->steps[s];
        bool last = s == path->nsteps - 1;
        if (step->kind == S_FIELD) {
            if (cur->type != T_OBJECT) return raisef("Cannot use . on %s", type_name(*cur));
            Object *o = cur->o;
            int f = check_field(o, step->name, asking);
            if (f < 0) return false;
            if (o->fields[f].type == T_UNSET) return raise_not_set(o, step->name);
            cur = &o->fields[f];
            continue;
        }
        Value key = keys[k++];
        if (cur->type == T_LIST) {
            if (key.type != T_INT) return raisef("List indexes must be int, got %s", type_name(key));
            if (key.i < 0 || (uint64_t)key.i >= cur->l->len) return raisef("Index out of range: %lld", (long long)key.i);
            List *l = list_unique(cur);
            if (last) {
                /* The later elements move down: a list's indexes are 0 to len - 1 */
                Value gone = l->items[key.i];
                memmove(&l->items[key.i], &l->items[key.i + 1], (l->len - key.i - 1) * sizeof(Value));
                l->len--;
                decref(gone);
                return true;
            }
            cur = &l->items[key.i];
        } else if (cur->type == T_MAP) {
            Map *m = map_unique(cur);
            Value *found = map_find(m, key);
            if (!found) return raise_undefined_key(key);
            if (last) {
                map_remove(m, key);
                return true;
            }
            cur = found;
        } else {
            return raisef("Cannot use [] on %s", type_name(*cur));
        }
    }
    return true;
}

/*
 * $s ..= v: a string is appended to in place, which is what makes a loop of appends linear;
 * anything else is joined as .. joins. The slot must be set. out gets the new value, counted.
 */
bool concat_assign(Value *slot, Value v, Value *out) {
    if (slot->type == T_STRING) {
        Str *text;
        if (!to_string(v, &text)) return false;
        /* to_string() may have run a method that changed the variable */
        if (slot->type == T_STRING) {
            if (slot->s->rc == 1) {
                slot->s = str_append(slot->s, text->data, text->len);
            } else {
                Str *joined = str_empty(slot->s->len + text->len);
                joined = str_append(joined, slot->s->data, slot->s->len);
                joined = str_append(joined, text->data, text->len);
                set_slot(slot, v_str(joined));
            }
            decref(v_str(text));
            *out = *slot;
            incref(*out);
            return true;
        }
        Value result;
        bool ok = binary_op(OP_CONCAT, *slot, v_str(text), &result);
        decref(v_str(text));
        if (!ok) return false;
        set_slot(slot, result);
        *out = result;
        incref(result);
        return true;
    }
    Value result;
    if (!binary_op(OP_CONCAT, *slot, v, &result)) return false;
    set_slot(slot, result);
    *out = result;
    incref(result);
    return true;
}
