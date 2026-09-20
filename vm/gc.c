/*
 * The cycle collector
 *
 * Reference counting frees a value the moment nothing holds it, which is almost always. It
 * can't free a cycle: an object whose field holds itself, a closure that captured itself
 * ($f = $n -> $f($n - 1)), two objects holding each other through a list. Every value a cycle
 * can go through is a list, map, object or function, and each of those is linked into one list
 * here while it lives (the Gc header at its start).
 *
 * Collecting is CPython's trial deletion, which needs no list of roots:
 *   1. Copy each tracked value's count into its scratch count.
 *   2. For every reference one tracked value holds to another, take one off the other's scratch
 *      count. What is left is how many references come from outside the tracked values: the
 *      stack, globals, frames, the C code running.
 *   3. Everything with some left is reachable, and so is everything it holds.
 *   4. The rest is garbage, only held by other garbage: empty each one out, then free it.
 * GazLang has no destructors, so freeing garbage runs no program code and can bring nothing
 * back to life.
 *
 * It runs only where the dispatch loop asks (a backward jump or a call), never in the middle of
 * an instruction, and only after as many new tracked values as there were live after the last
 * collection (at least GC_MINIMUM), so its cost stays proportional to the work the program does.
 */
#include "gazvm.h"

#include <stdlib.h>

/* The test build sets this far lower, so every program in the harness collects often */
#ifndef GC_MINIMUM
#define GC_MINIMUM 10000
#endif

static Gc tracked = {.prev = &tracked, .next = &tracked};  /* a circular list with a dummy head */
int64_t live, peak;
static int64_t made;
static int64_t threshold = GC_MINIMUM;
bool gc_wanted;

void gc_track(Gc *g, Type type) {
    counted++;
    g->rc = 1;
    g->type = type;
    g->prev = tracked.prev;
    g->next = &tracked;
    tracked.prev->next = g;
    tracked.prev = g;
    if (++live > peak) peak = live;
    if (++made >= threshold) gc_wanted = true;
}

void gc_untrack(Gc *g) {
    if (!g->next) return;
    g->prev->next = g->next;
    g->next->prev = g->prev;
    g->prev = g->next = NULL;
    live--;
}

/* The tracked value a slot holds, or NULL */
static Gc *container(Value v) {
    if (v.type < T_LIST || v.type > T_OBJECT) return NULL;
    Gc *g = (Gc *)v.rc;
    return g->next ? g : NULL;
}

/* Call visit on every slot a tracked value holds: the references it can be part of a cycle through */
static void each_child(Gc *g, void (*visit)(Value *slot)) {
    switch (g->type) {
    case T_LIST: {
        List *l = (List *)g;
        for (size_t i = 0; i < l->len; i++) visit(&l->items[i]);
        break;
    }
    case T_MAP: {
        Map *m = (Map *)g;
        for (size_t i = 0; i < m->used; i++) visit(&m->entries[i].value);
        break;
    }
    case T_OBJECT: {
        Object *o = (Object *)g;
        for (int i = 0; i < o->kind->nfields; i++) visit(&o->fields[i]);
        break;
    }
    case T_FUNCTION: {
        Func *f = (Func *)g;
        if (f->captured) {
            for (int i = 0; i < f->lambda->block->ncaptures; i++) visit(&f->captured[i]);
        }
        if (f->receiver) {
            Value receiver = v_object(f->receiver);
            visit(&receiver);
        }
        break;
    }
    default:
        break;
    }
}

static void subtract(Value *slot) {
    Gc *c = container(*slot);
    if (c) c->refs--;
}

/* Reachable values are marked with a negative scratch count */
static Gc **stack;
static size_t nstack, capstack;

static void reach(Value *slot) {
    Gc *c = container(*slot);
    if (!c || c->refs < 0) return;
    c->refs = -1;
    if (nstack == capstack) {
        capstack = capstack ? capstack * 2 : 1024;
        stack = xrealloc(stack, capstack * sizeof(Gc *));
    }
    stack[nstack++] = c;
}

/* Empty a garbage value's slots, dropping what they held */
static void clear(Value *slot) {
    Value v = *slot;
    *slot = v_unset();
    decref(v);
}

void gc_collect(void) {
    gc_wanted = false;
    for (Gc *g = tracked.next; g != &tracked; g = g->next) g->refs = g->rc;
    for (Gc *g = tracked.next; g != &tracked; g = g->next) each_child(g, subtract);

    nstack = 0;
    for (Gc *g = tracked.next; g != &tracked; g = g->next) {
        if (g->refs > 0) {
            Value v = {.type = g->type, .rc = &g->rc};
            reach(&v);
        }
    }
    while (nstack) each_child(stack[--nstack], reach);

    /* What is left unmarked is garbage: gather it, keep it alive while it is emptied (so
       emptying one can't free another under our feet), then let it go */
    size_t ngarbage = 0;
    for (Gc *g = tracked.next; g != &tracked; g = g->next) {
        if (g->refs >= 0) {
            if (ngarbage == capstack) {
                capstack = capstack ? capstack * 2 : 1024;
                stack = xrealloc(stack, capstack * sizeof(Gc *));
            }
            stack[ngarbage++] = g;
        }
    }
    for (size_t i = 0; i < ngarbage; i++) stack[i]->rc++;
    for (size_t i = 0; i < ngarbage; i++) {
        Gc *g = stack[i];
        if (g->type != T_FUNCTION) {
            each_child(g, clear);
            continue;
        }
        /* each_child() shows a function's receiver through a copy, so it is emptied here */
        Func *f = (Func *)g;
        if (f->captured) {
            for (int c = 0; c < f->lambda->block->ncaptures; c++) clear(&f->captured[c]);
        }
        if (f->receiver) {
            Object *receiver = f->receiver;
            f->receiver = NULL;
            decref(v_object(receiver));
        }
    }
    for (size_t i = 0; i < ngarbage; i++) {
        Gc *g = stack[i];
        Value v = {.type = g->type, .rc = &g->rc};
        decref(v);
    }

    made = 0;
#ifdef GC_STRESS
    /* For testing the collector: collect at every chance, whatever it costs */
    threshold = 1;
#else
    threshold = live > GC_MINIMUM ? live : GC_MINIMUM;
#endif
}
