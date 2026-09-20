/*
 * Reading a bytecode file (docs/bytecode.md)
 *
 * Nothing is trusted: every instruction, argument, label and name is checked, and each block's
 * stack is walked to prove it balances, so a file that loads is one the VM can run. The walk
 * also gives each block's greatest stack depth, which sizes its frame.
 *
 * The loader gives up at the first problem, so unlike the rest of the VM it leaves with
 * longjmp (a jump straight back to load(), skipping every function in between): nothing it
 * has built is needed once the file is refused, and the program ends then anyway.
 */
#include "gazvm.h"

#include <limits.h>
#include <setjmp.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

/* ---- The instruction table ------------------------------------------------------------ */

/* Argument kinds */
enum { K_LABEL, K_VALUE, K_SLOT, K_GLOBAL, K_STATIC, K_CAPTURE, K_COUNT, K_LAMBDA, K_FUNCTION,
       K_CALLABLE, K_BUILTIN, K_KIND, K_MEMBER, K_PATH, K_ELEMENT_PATH };

/* A pops count that depends on the arguments */
enum { POPS_PATH = -1, POPS_KEYS = -2, POPS_COUNT = -3, POPS_COUNT1 = -4, POPS_COUNT2 = -5 };

typedef struct {
    const char *name;
    int nargs;
    int kinds[3];
    int pops, pushes;
} InstrInfo;

static const InstrInfo INFO[OP_COUNT] = {
    [OP_LABEL] = {"LABEL", 1, {K_LABEL}, 0, 0},
    [OP_PUSH] = {"PUSH", 1, {K_VALUE}, 0, 1},
    [OP_POP] = {"POP", 0, {0}, 1, 0},
    [OP_PRINT] = {"PRINT", 0, {0}, 1, 0},
    [OP_LOAD] = {"LOAD", 1, {K_SLOT}, 0, 1},
    [OP_LOAD_QUIET] = {"LOAD_QUIET", 1, {K_SLOT}, 0, 1},
    [OP_STORE] = {"STORE", 1, {K_SLOT}, 1, 0},
    [OP_LOAD_GLOBAL] = {"LOAD_GLOBAL", 1, {K_GLOBAL}, 0, 1},
    [OP_LOAD_QUIET_GLOBAL] = {"LOAD_QUIET_GLOBAL", 1, {K_GLOBAL}, 0, 1},
    [OP_STORE_GLOBAL] = {"STORE_GLOBAL", 1, {K_GLOBAL}, 1, 0},
    [OP_LOAD_CAPTURED] = {"LOAD_CAPTURED", 1, {K_CAPTURE}, 0, 1},
    [OP_LOAD_QUIET_CAPTURED] = {"LOAD_QUIET_CAPTURED", 1, {K_CAPTURE}, 0, 1},
    [OP_STORE_CAPTURED] = {"STORE_CAPTURED", 1, {K_CAPTURE}, 1, 0},
    [OP_LOAD_STATIC] = {"LOAD_STATIC", 1, {K_STATIC}, 0, 1},
    [OP_STORE_STATIC] = {"STORE_STATIC", 1, {K_STATIC}, 1, 0},
    [OP_ADD] = {"ADD", 0, {0}, 2, 1},
    [OP_SUB] = {"SUB", 0, {0}, 2, 1},
    [OP_MUL] = {"MUL", 0, {0}, 2, 1},
    [OP_DIV] = {"DIV", 0, {0}, 2, 1},
    [OP_MOD] = {"MOD", 0, {0}, 2, 1},
    [OP_BIT_AND] = {"BIT_AND", 0, {0}, 2, 1},
    [OP_BIT_OR] = {"BIT_OR", 0, {0}, 2, 1},
    [OP_BIT_XOR] = {"BIT_XOR", 0, {0}, 2, 1},
    [OP_SHL] = {"SHL", 0, {0}, 2, 1},
    [OP_SHR] = {"SHR", 0, {0}, 2, 1},
    [OP_BIT_NOT] = {"BIT_NOT", 0, {0}, 1, 1},
    [OP_CONCAT] = {"CONCAT", 0, {0}, 2, 1},
    [OP_EQUALS] = {"EQUALS", 0, {0}, 2, 1},
    [OP_NOT_EQUALS] = {"NOT_EQUALS", 0, {0}, 2, 1},
    [OP_LT] = {"LT", 0, {0}, 2, 1},
    [OP_LE] = {"LE", 0, {0}, 2, 1},
    [OP_GT] = {"GT", 0, {0}, 2, 1},
    [OP_GE] = {"GE", 0, {0}, 2, 1},
    [OP_CMP] = {"CMP", 0, {0}, 2, 1},
    [OP_NOT] = {"NOT", 0, {0}, 1, 1},
    [OP_NO_MATCH] = {"NO_MATCH", 0, {0}, 1, 0},
    [OP_NO_CONDITION] = {"NO_CONDITION", 0, {0}, 0, 0},
    [OP_CONCAT_ASSIGN] = {"CONCAT_ASSIGN", 1, {K_SLOT}, 1, 1},
    [OP_CONCAT_ASSIGN_GLOBAL] = {"CONCAT_ASSIGN_GLOBAL", 1, {K_GLOBAL}, 1, 1},
    [OP_CONCAT_ASSIGN_CAPTURED] = {"CONCAT_ASSIGN_CAPTURED", 1, {K_CAPTURE}, 1, 1},
    [OP_NEG] = {"NEG", 0, {0}, 1, 1},
    [OP_INC] = {"INC", 0, {0}, 1, 1},
    [OP_DEC] = {"DEC", 0, {0}, 1, 1},
    [OP_JMP] = {"JMP", 1, {K_LABEL}, 0, 0},
    [OP_JZ] = {"JZ", 1, {K_LABEL}, 1, 0},
    [OP_JNN] = {"JNN", 1, {K_LABEL}, 1, 0},
    [OP_NEW_ARRAY] = {"NEW_ARRAY", 0, {0}, 0, 1},
    [OP_ARRAY_PUSH] = {"ARRAY_PUSH", 0, {0}, 2, 1},
    [OP_ARRAY_EXTEND] = {"ARRAY_EXTEND", 0, {0}, 2, 1},
    [OP_NEW_MAP] = {"NEW_MAP", 0, {0}, 0, 1},
    [OP_MAP_SET] = {"MAP_SET", 0, {0}, 3, 1},
    [OP_KEY_CHECK] = {"KEY_CHECK", 0, {0}, 1, 1},
    [OP_FOREACH_CHECK] = {"FOREACH_CHECK", 0, {0}, 1, 1},
    [OP_DESTRUCTURE] = {"DESTRUCTURE", 1, {K_COUNT}, 1, 1},
    [OP_INDEX_GET] = {"INDEX_GET", 0, {0}, 2, 1},
    [OP_INDEX_GET_QUIET] = {"INDEX_GET_QUIET", 0, {0}, 2, 1},
    [OP_INDEX_GET_EXISTING] = {"INDEX_GET_EXISTING", 0, {0}, 2, 1},
    [OP_SET_PATH] = {"SET_PATH", 2, {K_PATH, K_SLOT}, POPS_PATH, 1},
    [OP_SET_PATH_GLOBAL] = {"SET_PATH_GLOBAL", 2, {K_PATH, K_GLOBAL}, POPS_PATH, 1},
    [OP_SET_PATH_STATIC] = {"SET_PATH_STATIC", 2, {K_PATH, K_STATIC}, POPS_PATH, 1},
    [OP_SET_PATH_CAPTURED] = {"SET_PATH_CAPTURED", 2, {K_PATH, K_CAPTURE}, POPS_PATH, 1},
    [OP_SET_PATH_THIS] = {"SET_PATH_THIS", 1, {K_PATH}, POPS_PATH, 1},
    [OP_DELETE_PATH] = {"DELETE_PATH", 2, {K_ELEMENT_PATH, K_SLOT}, POPS_KEYS, 0},
    [OP_DELETE_PATH_GLOBAL] = {"DELETE_PATH_GLOBAL", 2, {K_ELEMENT_PATH, K_GLOBAL}, POPS_KEYS, 0},
    [OP_DELETE_PATH_STATIC] = {"DELETE_PATH_STATIC", 2, {K_ELEMENT_PATH, K_STATIC}, POPS_KEYS, 0},
    [OP_DELETE_PATH_CAPTURED] = {"DELETE_PATH_CAPTURED", 2, {K_ELEMENT_PATH, K_CAPTURE}, POPS_KEYS, 0},
    [OP_DELETE_PATH_THIS] = {"DELETE_PATH_THIS", 1, {K_ELEMENT_PATH}, POPS_KEYS, 0},
    [OP_CALL] = {"CALL", 2, {K_FUNCTION, K_COUNT}, POPS_COUNT, 1},
    [OP_CALL_BUILTIN] = {"CALL_BUILTIN", 2, {K_BUILTIN, K_COUNT}, POPS_COUNT, 1},
    [OP_CALL_VALUE] = {"CALL_VALUE", 1, {K_COUNT}, POPS_COUNT1, 1},
    [OP_ARGC] = {"ARGC", 0, {0}, 0, 1},
    [OP_RET] = {"RET", 0, {0}, 1, 0},
    [OP_PUSH_FN] = {"PUSH_FN", 1, {K_CALLABLE}, 0, 1},
    [OP_MAKE_CLOSURE] = {"MAKE_CLOSURE", 1, {K_LAMBDA}, 0, 1},
    [OP_PUSH_KIND] = {"PUSH_KIND", 1, {K_KIND}, 0, 1},
    [OP_NEW] = {"NEW", 2, {K_KIND, K_COUNT}, POPS_COUNT, 1},
    [OP_CALL_CONSTRUCTOR] = {"CALL_CONSTRUCTOR", 1, {K_KIND}, 0, 1},
    [OP_CALL_PARENT] = {"CALL_PARENT", 3, {K_KIND, K_MEMBER, K_COUNT}, POPS_COUNT, 1},
    [OP_BIND_PARENT] = {"BIND_PARENT", 2, {K_KIND, K_MEMBER}, 0, 1},
    [OP_LOAD_THIS] = {"LOAD_THIS", 0, {0}, 0, 1},
    [OP_LOAD_FIELD] = {"LOAD_FIELD", 1, {K_MEMBER}, 0, 1},
    [OP_SET_FIELD] = {"SET_FIELD", 1, {K_MEMBER}, 1, 1},
    [OP_GET_PROPERTY] = {"GET_PROPERTY", 1, {K_MEMBER}, 1, 1},
    [OP_GET_PROPERTY_QUIET] = {"GET_PROPERTY_QUIET", 1, {K_MEMBER}, 1, 1},
    [OP_GET_PROPERTY_EXISTING] = {"GET_PROPERTY_EXISTING", 1, {K_MEMBER}, 1, 1},
    [OP_GET_METHOD] = {"GET_METHOD", 1, {K_MEMBER}, 1, 2},
    [OP_CALL_METHOD] = {"CALL_METHOD", 2, {K_COUNT, K_MEMBER}, POPS_COUNT2, 1},
    [OP_TRY] = {"TRY", 1, {K_LABEL}, 0, 0},
    [OP_END_TRY] = {"END_TRY", 0, {0}, 0, 0},
    [OP_CATCH_MATCH] = {"CATCH_MATCH", 2, {K_KIND, K_LABEL}, 1, 1},
    [OP_CATCH_VALUE] = {"CATCH_VALUE", 0, {0}, 1, 1},
    [OP_RETHROW] = {"RETHROW", 0, {0}, 1, 0},
    [OP_HALT] = {"HALT", 0, {0}, 0, 0},
};

/* An instruction as read, before its names are resolved */
typedef struct RawInstr {
    int op;
    int ints[3];        /* slot, count and lambda arguments */
    Str *names[3];      /* label, function, kind, member... arguments, interned */
    Str *words[4];      /* the words as written, for messages */
    int nwords;
    Value value;        /* PUSH's value */
    Path *path;
    Str *file;
    int line;
} RawInstr;

/* ---- Reading lines --------------------------------------------------------------------- */

static jmp_buf failed;
static Str *file_path;      /* the bytecode file, as the user named it */
static Str *base_dir;       /* the directory source paths are resolved against */
static char **lines;
static int nlines, at_line; /* at_line: the line just read, counting from 1 */

static _Noreturn void fail_at(bool locate, const char *fmt, ...) __attribute__((format(printf, 2, 3)));
static _Noreturn void fail_at(bool locate, const char *fmt, ...) {
    Buf b = {0};
    char small[1024];
    va_list args;
    va_start(args, fmt);
    vsnprintf(small, sizeof small, fmt, args);
    va_end(args);
    buf_adds(&b, small);
    vm_error = error_new(buf_to_str(&b), file_path, locate ? at_line : 0, locate);
    longjmp(failed, 1);
}
#define fail(...) fail_at(true, __VA_ARGS__)

static bool is_space(char c) { return c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == '\v' || c == '\f' || c == '\0'; }

/* The next line with anything on it, trimmed, or NULL at the end of the file */
static char *next_line(void) {
    while (at_line < nlines) {
        char *line = lines[at_line++];
        while (*line && is_space(*line)) line++;
        size_t len = strlen(line);
        while (len && is_space(line[len - 1])) line[--len] = '\0';
        if (len) return line;
    }
    return NULL;
}

/* A line's words, split on runs of whitespace; w[] points into a copy, both freed by free_words() */
typedef struct { char *copy; char **w; int n; } Words;

static void split_words(const char *line, Words *out) {
    out->copy = strdup(line);
    out->w = xmalloc((strlen(line) / 2 + 2) * sizeof(char *));   /* never more words than that */
    out->n = 0;
    char *p = out->copy;
    while (*p) {
        while (*p && is_space(*p)) p++;
        if (!*p) break;
        out->w[out->n++] = p;
        while (*p && !is_space(*p)) p++;
        if (*p) *p++ = '\0';
    }
    if (out->n == 0) out->w[out->n++] = p;
}

static void free_words(Words *w) {
    free(w->copy);
    free(w->w);
}

static const char *word(Words *w, int i) {
    if (i >= w->n) fail("Unexpected end of line");
    return w->w[i];
}

/* A count: a whole number written in decimal */
static int count(const char *word) {
    if (!*word) fail("Expected a number but found '%s'", word);
    long long n = 0;
    for (const char *p = word; *p; p++) {
        if (*p < '0' || *p > '9') fail("Expected a number but found '%s'", word);
        /* Refused rather than clamped: a count that doesn't fit is a count nothing meant */
        if (n > INT_MAX) fail("Number too large: %s", word);
        n = n * 10 + (*p - '0');
    }
    if (n > INT_MAX) fail("Number too large: %s", word);
    return (int)n;
}

static Str *intern(const char *s) { return str_intern(s, strlen(s)); }

/* ---- Literals ------------------------------------------------------------------------- */

/*
 * A PUSH value or an @ line's file is a GazLang literal, read with the part of GazLang's lexer
 * that literals use. Every problem inside one is reported as "Bad value '<the literal>':
 * <reason>", the reason being the lexer's message, or the reader's own, which is itself
 * worded as a bad value ("Bad value: expected '=>'"), so those say "Bad value" twice.
 */

typedef enum { L_EOF, L_MINUS, L_LBRACKET, L_RBRACKET, L_LBRACE, L_RBRACE, L_COMMA, L_ARROW,
               L_INT, L_FLOAT, L_STRING, L_TRUE, L_FALSE, L_NULL, L_OTHER } LitKind;

typedef struct {
    const char *text;   /* the whole literal, for messages */
    const char *p;
} LitLexer;

typedef struct {
    LitKind kind;
    Value value;
    bool too_large;         /* the digits of the smallest int, which only fit after a minus */
    const char *type;       /* L_OTHER: the token type the lexer would give it */
} Lit;

static _Noreturn void bad_value(LitLexer *lx, const char *fmt, ...) __attribute__((format(printf, 2, 3)));
static _Noreturn void bad_value(LitLexer *lx, const char *fmt, ...) {
    char reason[512];
    va_list args;
    va_start(args, fmt);
    vsnprintf(reason, sizeof reason, fmt, args);
    va_end(args);
    fail("Bad value '%s': %s", lx->text, reason);
}

static int hex_digit(char c) {
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    return -1;
}

static bool is_alpha(char c) { return (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z'); }
static bool is_digit(char c) { return c >= '0' && c <= '9'; }

static void add_utf8(Buf *b, uint32_t cp) {
    if (cp < 0x80) {
        buf_addc(b, (char)cp);
    } else if (cp < 0x800) {
        buf_addc(b, (char)(0xC0 | (cp >> 6)));
        buf_addc(b, (char)(0x80 | (cp & 0x3F)));
    } else if (cp < 0x10000) {
        buf_addc(b, (char)(0xE0 | (cp >> 12)));
        buf_addc(b, (char)(0x80 | ((cp >> 6) & 0x3F)));
        buf_addc(b, (char)(0x80 | (cp & 0x3F)));
    } else {
        buf_addc(b, (char)(0xF0 | (cp >> 18)));
        buf_addc(b, (char)(0x80 | ((cp >> 12) & 0x3F)));
        buf_addc(b, (char)(0x80 | ((cp >> 6) & 0x3F)));
        buf_addc(b, (char)(0x80 | (cp & 0x3F)));
    }
}

/* Token types for what a literal can't hold, longest match first, as the lexer names them */
static const char *OPERATORS[][2] = {
    {"<=>", "SPACESHIP"}, {"<<=", "SHIFT_LEFT_ASSIGN"}, {">>=", "SHIFT_RIGHT_ASSIGN"}, {"..=", "CONCAT_ASSIGN"},
    {"...", "SPREAD"}, {"?\?=", "COALESCE_ASSIGN"},
    {"+=", "PLUS_ASSIGN"}, {"++", "INCREMENT"}, {"-=", "MINUS_ASSIGN"}, {"--", "DECREMENT"}, {"->", "ARROW"},
    {"*=", "MULTIPLY_ASSIGN"}, {"/=", "DIVIDE_ASSIGN"}, {"%=", "MODULO_ASSIGN"}, {"&=", "BIT_AND_ASSIGN"},
    {"&&", "AND"}, {"|=", "BIT_OR_ASSIGN"}, {"||", "OR"}, {"^=", "BIT_XOR_ASSIGN"}, {"<=", "LESS_EQUALS"},
    {"<<", "SHIFT_LEFT"}, {">=", "GREATER_EQUALS"}, {">>", "SHIFT_RIGHT"}, {"==", "EQUALS"}, {"!=", "NOT_EQUALS"},
    {"..", "CONCAT"}, {"??", "COALESCE"},
    {"+", "PLUS"}, {"*", "MULTIPLY"}, {"/", "DIVIDE"}, {"%", "MODULO"}, {"&", "BIT_AND"}, {"|", "BIT_OR"},
    {"^", "BIT_XOR"}, {"<", "LESS_THAN"}, {">", "GREATER_THAN"}, {"=", "ASSIGN"}, {"!", "NOT"}, {"?", "QUESTION"},
    {"(", "LEFT_PAREN"}, {")", "RIGHT_PAREN"}, {";", "SEMICOLON"}, {":", "COLON"}, {"~", "BIT_NOT"},
};
static const char *KEYWORDS[][2] = {
    {"echo", "ECHO"}, {"if", "IF"}, {"else", "ELSE"}, {"while", "WHILE"}, {"for", "FOR"}, {"foreach", "FOREACH"},
    {"as", "AS"}, {"break", "BREAK"}, {"continue", "CONTINUE"}, {"fn", "FN"}, {"function", "FUNCTION"},
    {"return", "RETURN"}, {"delete", "DELETE"}, {"match", "MATCH"}, {"default", "DEFAULT"}, {"const", "CONST"},
    {"include", "INCLUDE"}, {"try", "TRY"}, {"catch", "CATCH"}, {"finally", "FINALLY"}, {"kind", "KIND"}, {"class", "CLASS"},
    {"extends", "EXTENDS"}, {"abstract", "ABSTRACT"}, {"interface", "INTERFACE"}, {"implements", "IMPLEMENTS"},
    {"final", "FINAL"}, {"public", "PUBLIC"}, {"private", "PRIVATE"}, {"protected", "PROTECTED"},
};

static bool is_word(char c) { return is_alpha(c) || is_digit(c) || c == '_'; }

/* One token of a literal */
static Lit lit_next(LitLexer *lx) {
    Lit t = {L_EOF, {0}, false, NULL};
    for (;;) {
        while (*lx->p == ' ' || *lx->p == '\t' || *lx->p == '\n' || *lx->p == '\r') lx->p++;
        if (lx->p[0] == '/' && lx->p[1] == '/') {
            while (*lx->p) lx->p++;
        } else if (lx->p[0] == '/' && lx->p[1] == '*') {
            const char *end = strstr(lx->p + 2, "*/");
            if (!end) bad_value(lx, "Unterminated block comment");
            lx->p = end + 2;
        } else {
            break;
        }
    }
    char c = *lx->p;
    if (!c) return t;
    if (c == '[') { lx->p++; t.kind = L_LBRACKET; return t; }
    if (c == ']') { lx->p++; t.kind = L_RBRACKET; return t; }
    if (c == '{') { lx->p++; t.kind = L_LBRACE; return t; }
    if (c == '}') { lx->p++; t.kind = L_RBRACE; return t; }
    if (c == ',') { lx->p++; t.kind = L_COMMA; return t; }
    if (c == '=' && lx->p[1] == '>') { lx->p += 2; t.kind = L_ARROW; return t; }
    if (c == '-' && lx->p[1] != '=' && lx->p[1] != '-' && lx->p[1] != '>') { lx->p++; t.kind = L_MINUS; return t; }

    if (is_digit(c)) {
        const char *start = lx->p;
        if (c == '0' && (lx->p[1] == 'x' || lx->p[1] == 'X')) {
            lx->p += 2;
            int64_t n = 0;
            bool any = false, overflow = false;
            for (int d; (d = hex_digit(*lx->p)) >= 0; lx->p++) {
                any = true;
                if (__builtin_mul_overflow(n, 16, &n) || __builtin_add_overflow(n, d, &n)) overflow = true;
            }
            if (!any) bad_value(lx, "Invalid number literal: %.*s", (int)(lx->p - start), start);
            if (overflow) bad_value(lx, "Integer literal too large: %.*s", (int)(lx->p - start), start);
            t.kind = L_INT;
            t.value = v_int(n);
            return t;
        }
        while (is_digit(*lx->p)) lx->p++;
        bool is_float = false;
        if (*lx->p == '.' && is_digit(lx->p[1])) {
            is_float = true;
            lx->p++;
            while (is_digit(*lx->p)) lx->p++;
        }
        if (*lx->p == 'e' || *lx->p == 'E') {
            const char *q = lx->p + 1;
            if (*q == '+' || *q == '-') q++;
            if (is_digit(*q)) {
                is_float = true;
                lx->p = q;
                while (is_digit(*lx->p)) lx->p++;
            }
        }
        int n = (int)(lx->p - start);
        Value v;
        if (!parse_number(start, (size_t)n, &v)) {
            if (is_float) bad_value(lx, "Float literal too large: %.*s", n, start);
            /* The smallest int's digits alone don't fit an int: the caller reads - and them as one */
            if (n == 19 && strncmp(start, "9223372036854775808", 19) == 0) {
                t.too_large = true;
                t.kind = L_INT;
                return t;
            }
            bad_value(lx, "Integer literal too large: %.*s", n, start);
        }
        t.kind = v.type == T_INT ? L_INT : L_FLOAT;
        t.value = v;
        return t;
    }

    if (c == '\'') {
        /* Single-quoted: raw, with only \' and \\ as escapes */
        lx->p++;
        Buf b = {0};
        while (*lx->p != '\'') {
            if (!*lx->p) bad_value(lx, "Unterminated string");
            if (*lx->p == '\\' && (lx->p[1] == '\'' || lx->p[1] == '\\')) lx->p++;
            buf_addc(&b, *lx->p++);
        }
        lx->p++;
        t.kind = L_STRING;
        t.value = v_str(buf_to_str(&b));
        return t;
    }
    if (c == '"') {
        lx->p++;
        Buf b = {0};
        while (*lx->p != '"') {
            if (!*lx->p) bad_value(lx, "Unterminated string");
            if ((*lx->p == '$' && (is_alpha(lx->p[1]) || lx->p[1] == '_'))
                || (*lx->p == '{' && (lx->p[1] == '$' || lx->p[1] == '@' || lx->p[1] == '#'))) {
                /* An interpolation: a string start, which is no value */
                free(b.data);
                bad_value(lx, "Bad value: unexpected STRING_START");
            }
            if (*lx->p != '\\') {
                buf_addc(&b, *lx->p++);
                continue;
            }
            char e = *++lx->p;
            if (!e) bad_value(lx, "Unterminated string");
            lx->p++;
            switch (e) {
            case 'n': buf_addc(&b, '\n'); break;
            case 't': buf_addc(&b, '\t'); break;
            case 'r': buf_addc(&b, '\r'); break;
            case 'v': buf_addc(&b, '\v'); break;
            case 'f': buf_addc(&b, '\f'); break;
            case 'e': buf_addc(&b, 0x1b); break;
            case '\\': case '"': case '$': case '{': buf_addc(&b, e); break;
            case '0':
                if (is_digit(*lx->p)) bad_value(lx, "Octal escapes are not supported: \\0%c (use \\x)", *lx->p);
                buf_addc(&b, '\0');
                break;
            case 'x': {
                int n = 0;
                while (n < 2 && hex_digit(lx->p[n]) >= 0) n++;
                if (n != 2) bad_value(lx, "Invalid escape \\x%.*s: expected two hex digits", n, lx->p);
                buf_addc(&b, (char)(hex_digit(lx->p[0]) * 16 + hex_digit(lx->p[1])));
                lx->p += 2;
                break;
            }
            case 'u': {
                const char *digits = lx->p + 1;
                int n = 0;
                if (*lx->p == '{') {
                    while (n < 7 && hex_digit(digits[n]) >= 0) n++;
                }
                if (*lx->p != '{' || digits[n] != '}' || n == 0 || n > 6) {
                    bad_value(lx, "Invalid escape \\u: expected \\u{...} with 1 to 6 hex digits");
                }
                uint32_t cp = 0;
                for (int i = 0; i < n; i++) cp = cp * 16 + (uint32_t)hex_digit(digits[i]);
                if (cp > 0x10FFFF || (cp >= 0xD800 && cp <= 0xDFFF)) {
                    bad_value(lx, "Invalid escape \\u{%.*s}: not a Unicode code point", n, digits);
                }
                lx->p = digits + n + 1;
                add_utf8(&b, cp);
                break;
            }
            default:
                bad_value(lx, "Unknown escape sequence \\%c in string", e);
            }
        }
        lx->p++;
        t.kind = L_STRING;
        t.value = v_str(buf_to_str(&b));
        return t;
    }

    /* Anything else is a token no literal holds: name it as the lexer would */
    t.kind = L_OTHER;
    if (is_alpha(c) || c == '_') {
        const char *start = lx->p;
        while (is_word(*lx->p)) lx->p++;
        size_t n = (size_t)(lx->p - start);
        if (n == 4 && !strncmp(start, "true", 4)) t.kind = L_TRUE;
        else if (n == 5 && !strncmp(start, "false", 5)) t.kind = L_FALSE;
        else if (n == 4 && !strncmp(start, "null", 4)) t.kind = L_NULL;
        t.type = "IDENTIFIER";
        for (size_t i = 0; i < sizeof KEYWORDS / sizeof KEYWORDS[0]; i++) {
            if (strlen(KEYWORDS[i][0]) == n && !strncmp(KEYWORDS[i][0], start, n)) t.type = KEYWORDS[i][1];
        }
        return t;
    }
    if (c == '$' || c == '@') {
        if (!is_alpha(lx->p[1]) && lx->p[1] != '_') bad_value(lx, "Invalid variable name: %c", c);
        lx->p++;
        while (is_word(*lx->p)) lx->p++;
        t.type = c == '$' ? "VAR_IDENTIFIER" : "GLOBAL_VAR_IDENTIFIER";
        return t;
    }
    if (c == '#') {
        lx->p++;
        if (*lx->p == '#') {
            lx->p++;
            t.type = "PARENT";
        } else if (is_alpha(*lx->p) || *lx->p == '_') {
            while (is_word(*lx->p)) lx->p++;
            t.type = "HASH_IDENTIFIER";
        } else {
            t.type = "HASH";
        }
        return t;
    }
    if (c == '.' && (is_alpha(lx->p[1]) || lx->p[1] == '_')) {
        lx->p++;
        while (is_word(*lx->p)) lx->p++;
        t.type = "PROPERTY";
        return t;
    }
    for (size_t i = 0; i < sizeof OPERATORS / sizeof OPERATORS[0]; i++) {
        size_t n = strlen(OPERATORS[i][0]);
        if (!strncmp(lx->p, OPERATORS[i][0], n)) {
            lx->p += n;
            t.type = OPERATORS[i][1];
            return t;
        }
    }
    bad_value(lx, "Unexpected character '%c'", c);
}

static Value literal(LitLexer *lx, Lit t);

/* The items of a list or map literal, up to its closing bracket */
static Value items(LitLexer *lx, bool is_map) {
    LitKind end = is_map ? L_RBRACE : L_RBRACKET;
    List *l = is_map ? NULL : list_new(0);
    Map *m = is_map ? map_new() : NULL;
    for (Lit t = lit_next(lx); t.kind != end; t = lit_next(lx)) {
        Value v = literal(lx, t);
        if (is_map) {
            if (lit_next(lx).kind != L_ARROW) bad_value(lx, "Bad value: expected '=>'");
            if (v.type != T_INT && v.type != T_STRING) bad_value(lx, "Bad value: a key must be an int or a string");
            map_set(m, v, literal(lx, lit_next(lx)));
            decref(v);
        } else {
            list_push(l, v);
        }
        t = lit_next(lx);
        if (t.kind == end) break;
        if (t.kind != L_COMMA) bad_value(lx, "Bad value: expected ',' or the end of the literal");
    }
    return is_map ? v_map(m) : v_list(l);
}

static const char *LIT_TYPES[] = {"EOF", "MINUS", "LEFT_BRACKET", "RIGHT_BRACKET", "LEFT_BRACE",
    "RIGHT_BRACE", "COMMA", "DOUBLE_ARROW", "INTEGER", "FLOAT", "STRING", "TRUE", "FALSE", "NULL"};

static Value literal(LitLexer *lx, Lit t) {
    if (t.kind == L_MINUS) {
        Lit number = lit_next(lx);
        if (number.too_large) return v_int(INT64_MIN);
        Value v = literal(lx, number);
        if (v.type == T_INT) return v_int(-v.i);   /* never the smallest int: its digits don't fit */
        if (v.type == T_FLOAT) return v_float(-v.f);
        bad_value(lx, "Bad value: - takes a number");
    }
    if (t.too_large) bad_value(lx, "Integer literal too large: 9223372036854775808");
    switch (t.kind) {
    case L_LBRACKET: return items(lx, false);
    case L_LBRACE: return items(lx, true);
    case L_INT:
    case L_FLOAT:
    case L_STRING: return t.value;
    case L_TRUE: return v_bool(true);
    case L_FALSE: return v_bool(false);
    case L_NULL: return v_null();
    case L_OTHER: bad_value(lx, "Bad value: unexpected %s", t.type);
    default: bad_value(lx, "Bad value: unexpected %s", LIT_TYPES[t.kind]);
    }
}

/* A value written as a GazLang literal: the whole of text */
static Value read_value(const char *text) {
    LitLexer lx = {text, text};
    Value v = literal(&lx, lit_next(&lx));
    if (lit_next(&lx).kind != L_EOF) bad_value(&lx, "Bad value '%s'", text);
    return v;
}

/* ---- Paths ----------------------------------------------------------------------------- */

/* The working directory, read once per load: getcwd() walks the file system each time, and
   doing it for every @ line was nearly all of the time loading the compiler took */
static char cwd[PATH_MAX];
static bool have_cwd;

static Str *absolute_path(const char *path) {
    /* Made absolute and normalised as text: the file may not exist */
    Buf full = {0};
    if (path[0] != '/') {
        if (have_cwd) buf_adds(&full, cwd);
        buf_addc(&full, '/');
    }
    buf_adds(&full, path);
    char **parts = xmalloc((full.len / 2 + 2) * sizeof(char *));
    int n = 0;
    for (char *p = strtok(full.data, "/"); p; p = strtok(NULL, "/")) {
        if (!strcmp(p, "..")) {
            if (n) n--;
        } else if (strcmp(p, ".")) {
            parts[n++] = p;
        }
    }
    Buf out = {0};
    if (n == 0) buf_addc(&out, '/');
    for (int i = 0; i < n; i++) {
        buf_addc(&out, '/');
        buf_adds(&out, parts[i]);
    }
    free(parts);
    free(full.data);
    return buf_to_str(&out);
}

/* A source path as the parser shows it: relative to the working directory when it is under it.
   Consecutive @ lines nearly always name the same file, so the last answer is kept. */
static Str *last_file, *last_shown;
static Str *display_path_of(Str *file);

static Str *display_path(Str *file) {
    if (last_file && last_file->len == file->len && memcmp(last_file->data, file->data, file->len) == 0) return last_shown;
    Str *shown = display_path_of(file);
    last_file = str_intern(file->data, file->len);
    last_shown = shown;
    return shown;
}

static Str *display_path_of(Str *file) {
    Buf joined = {0};
    if (file->data[0] != '/') {
        buf_add_str(&joined, base_dir);
        buf_addc(&joined, '/');
    }
    buf_add_str(&joined, file);
    Str *path = absolute_path(joined.data);
    free(joined.data);
    Str *shown = path;
    if (have_cwd) {
        size_t n = strlen(cwd);
        if (path->len > n + 1 && !strncmp(path->data, cwd, n) && path->data[n] == '/') {
            shown = str_intern(path->data + n + 1, path->len - n - 1);
        }
    }
    if (shown == path) shown = str_intern(path->data, path->len);
    decref(v_str(path));
    return shown;
}

static bool is_word_char(char c) {
    return (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '_';
}

/* A write path like [k].total[]; an element path must end at [k] and has no [] */
static Path *read_path(const char *text, bool element) {
    Path *path = xcalloc(1, sizeof(Path) + (strlen(text) + 1) * sizeof(PathStep));
    const char *p = text;
    while (*p) {
        PathStep *step = &path->steps[path->nsteps];
        if (!strncmp(p, "[k]", 3)) {
            step->kind = S_KEY;
            path->nkeys++;
            p += 3;
        } else if (!strncmp(p, "[]", 2) && !element) {
            step->kind = S_APPEND;
            p += 2;
        } else if (*p == '.' && is_word_char(p[1])) {
            const char *start = ++p;
            while (is_word_char(*p)) p++;
            step->kind = S_FIELD;
            step->name = str_intern(start, (size_t)(p - start));
        } else {
            fail("Bad path '%s'", text);
        }
        path->nsteps++;
    }
    if (path->nsteps == 0 || (element && path->steps[path->nsteps - 1].kind != S_KEY)) fail("Bad path '%s'", text);
    return path;
}

/* ---- Blocks ---------------------------------------------------------------------------- */

/* An instruction by name, or -1: a binary search of the names in order, sorted the first time */
static int by_name[OP_COUNT];

static int compare_ops(const void *a, const void *b) {
    return strcmp(INFO[*(const int *)a].name, INFO[*(const int *)b].name);
}

static int compare_name(const void *name, const void *op) {
    return strcmp(name, INFO[*(const int *)op].name);
}

static int op_find(const char *name) {
    static bool sorted;
    if (!sorted) {
        for (int op = 0; op < OP_COUNT; op++) by_name[op] = op;
        qsort(by_name, OP_COUNT, sizeof(int), compare_ops);
        sorted = true;
    }
    const int *found = bsearch(name, by_name, OP_COUNT, sizeof(int), compare_name);
    return found ? *found : -1;
}

/* An @ line: the file as it is shown, and the line */
static void read_location(Words *w, Str **file, int *line) {
    if (w->n == 2) {
        *file = NULL;
        *line = count(w->w[1]);
        return;
    }
    if (w->n != 3 || w->w[1][0] != '"') fail("Expected @ \"file\" line or @ line");
    Value v = read_value(w->w[1]);
    if (v.type != T_STRING) fail("Expected @ \"file\" line");
    *file = v.s->data[0] == '<' ? str_intern(v.s->data, v.s->len) : display_path(v.s);
    decref(v);
    *line = count(w->w[2]);
}

/* The instructions of a block, up to the next block or the end of the file */
static void read_code(Block *b) {
    int cap = 16;
    b->raw = xmalloc(cap * sizeof(RawInstr));
    Str *file = NULL;
    int line = 0;
    char *text;
    while ((text = next_line())) {
        Words w;
        split_words(text, &w);
        if (!strcmp(w.w[0], "@")) {
            read_location(&w, &file, &line);
            free_words(&w);
            continue;
        }
        int op = op_find(w.w[0]);
        if (op < 0) {
            /* A lowercase word starts the next block; anything else is meant to be an instruction */
            if (w.w[0][0] >= 'a' && w.w[0][0] <= 'z') {
                at_line--;
                free_words(&w);
                return;
            }
            fail("Unknown instruction '%s'", w.w[0]);
        }
        const InstrInfo *info = &INFO[op];
        RawInstr r = {0};
        r.op = op;
        r.file = file;
        r.line = line;
        bool has_value = false;
        for (int i = 0; i < info->nargs; i++) {
            const char *arg = word(&w, i + 1);
            switch (info->kinds[i]) {
            case K_SLOT: case K_GLOBAL: case K_STATIC: case K_CAPTURE: case K_COUNT: case K_LAMBDA:
                r.ints[i] = count(arg);
                break;
            case K_VALUE:
                has_value = true;
                r.value = read_value(strchr(text, ' ') ? strchr(text, ' ') + 1 : text + 1);
                break;
            case K_PATH:
                r.path = read_path(arg, false);
                break;
            case K_ELEMENT_PATH:
                r.path = read_path(arg, true);
                break;
            default:
                r.names[i] = intern(arg);
            }
        }
        if (w.n != info->nargs + 1 && !has_value) {
            fail("%s takes %d argument%s", info->name, info->nargs, info->nargs == 1 ? "" : "s");
        }
        for (int i = 0; i < w.n && i < 4; i++) r.words[i] = intern(w.w[i]);
        r.nwords = w.n < 4 ? w.n : 4;
        free_words(&w);
        if (b->nraw == cap) b->raw = xrealloc(b->raw, (cap *= 2) * sizeof(RawInstr));
        b->raw[b->nraw++] = r;
    }
}

static void read_arity(Words *w, int from, int *lo, int *hi) {
    if (w->n - from != 2) fail("Expected an arity, written as fewest then most");
    *lo = count(w->w[from]);
    *hi = count(w->w[from + 1]);
    if (*lo > *hi) fail("An arity of %d to %d takes nothing", *lo, *hi);
}

/* What a member escapes, from the word after its declarer: nothing said means its kind's own */
static Vis read_vis(Words *w, int at) {
    if (w->n <= at) return V_OWN;
    if (!strcmp(w->w[at], "pub")) return V_PUB;
    if (!strcmp(w->w[at], "kin")) return V_KIN;
    fail("Expected 'pub' or 'kin' but found '%s'", w->w[at]);
    return V_OWN;
}

static Vis *push_vis(Vis *vis, int *n, Vis one) {
    vis = xrealloc(vis, (size_t)(*n + 1) * sizeof(Vis));
    vis[(*n)++] = one;
    return vis;
}

static Str **push_name(Str **names, int *n, Str *name) {
    names = xrealloc(names, (size_t)(*n + 1) * sizeof(Str *));
    names[(*n)++] = name;
    return names;
}

static Block *read_block(const char *header) {
    Words w;
    split_words(header, &w);
    Block *b = xcalloc(1, sizeof(Block));
    b->self = -1;
    b->line_no = at_line;
    const char *block_word = w.w[0];
    char key[64];
    /* "in Kind" says which kind the code is written in, where the block's own name can't:
       take it off before the checks below, which count the words a header has */
    if (w.n >= 3 && !strcmp(w.w[w.n - 2], "in")) {
        b->owner_name = intern(w.w[w.n - 1]);
        w.n -= 2;
    }
    if (!strcmp(block_word, "top")) {
        b->kind = B_TOP;
        if (w.n != 1) fail("Expected 'top' on its own");
        b->key = intern("");
    } else if (!strcmp(block_word, "fn")) {
        b->kind = B_FN;
        b->name = intern(w.n > 1 ? w.w[1] : "");
        read_arity(&w, 2, &b->lo, &b->hi);
        b->key = b->name;
        if (!b->owner_name) {
            const char *dot = strrchr(b->name->data, '.');
            if (dot) b->owner_name = str_intern(b->name->data, (size_t)(dot - b->name->data));
        }
    } else if (!strcmp(block_word, "kind") || !strcmp(block_word, "abstract")) {
        b->kind = B_KIND;
        b->is_abstract = !strcmp(block_word, "abstract");
        int from = 1;
        if (b->is_abstract) {
            if (w.n < 2 || strcmp(w.w[1], "kind")) fail("Expected 'abstract kind'");
            from = 2;
        }
        int rest = w.n - from;
        if (rest != 0 && rest != 1 && !(rest == 3 && !strcmp(w.w[from + 1], "extends"))) {
            fail("Expected 'kind Name' or 'kind Name extends Parent'");
        }
        if (rest == 0) fail("Unexpected end of line");
        b->name = intern(w.w[from]);
        b->parent = rest == 3 ? intern(w.w[from + 2]) : NULL;
        Buf k = {0};
        buf_adds(&k, "new ");
        buf_add_str(&k, b->name);
        b->key = str_intern(k.data, k.len);
        free(k.data);
    } else if (!strcmp(block_word, "lambda")) {
        b->kind = B_LAMBDA;
        b->index = count(w.n > 1 ? w.w[1] : "");
        read_arity(&w, 2, &b->lo, &b->hi);
        snprintf(key, sizeof key, "->%d", b->index);
        b->key = intern(key);
    } else {
        fail("Unknown block '%s'", block_word);
    }
    free_words(&w);

    /* The record lines a kind or lambda block carries, then its locals */
    for (;;) {
        char *line = next_line();
        if (!line) fail("Expected 'locals'");
        split_words(line, &w);
        const char *first = w.w[0];
        if (!strcmp(first, "field") && b->kind == B_KIND) {
            Str *name = intern(word(&w, 1)), *declarer = intern(word(&w, 2));
            int n = b->nfields;
            b->field_names = push_name(b->field_names, &n, name);
            n = b->nfields;
            b->field_vis = push_vis(b->field_vis, &n, read_vis(&w, 3));
            b->field_declarers = push_name(b->field_declarers, &b->nfields, declarer);
        } else if (!strcmp(first, "method") && b->kind == B_KIND) {
            Str *name = intern(word(&w, 1)), *definer = intern(word(&w, 2));
            /* The declarer, when an override made it differ from the definer */
            Str *declarer = w.n > 4 ? intern(w.w[4]) : definer;
            int n = b->nmethods;
            b->method_names = push_name(b->method_names, &n, name);
            n = b->nmethods;
            b->method_vis = push_vis(b->method_vis, &n, read_vis(&w, 3));
            n = b->nmethods;
            b->method_declarers = push_name(b->method_declarers, &n, declarer);
            b->method_definers = push_name(b->method_definers, &b->nmethods, definer);
        } else if (!strcmp(first, "capture") && b->kind == B_LAMBDA) {
            const char *where = word(&w, 2);
            if (strcmp(where, "local") && strcmp(where, "captured")) fail("Expected 'local' or 'captured'");
            b->captures = push_name(b->captures, &b->ncaptures, intern(word(&w, 1)));
            b->map = xrealloc(b->map, (size_t)(b->nmap + 1) * sizeof(*b->map));
            b->map[b->nmap].from_closure = !strcmp(where, "captured");
            b->map[b->nmap].outer = count(word(&w, 3));
            b->map[b->nmap].inner = b->ncaptures - 1;
            b->nmap++;
        } else if (!strcmp(first, "self") && b->kind == B_LAMBDA) {
            Str *name = intern(word(&w, 1));
            b->self = -1;
            for (int i = 0; i < b->ncaptures; i++) {
                if (b->captures[i] == name) {
                    b->self = i;
                    break;
                }
            }
            if (b->self < 0) fail("%s is not captured", name->data);
        } else if (!strcmp(first, "locals")) {
            for (int i = 1; i < w.n; i++) b->locals = push_name(b->locals, &b->nlocals, intern(w.w[i]));
            free_words(&w);
            break;
        } else {
            fail("Expected 'locals'");
        }
        free_words(&w);
    }

    read_code(b);
    return b;
}

/* ---- Checking -------------------------------------------------------------------------- */

static Program *prog;

static Function *find_function(Str *name) {
    for (int i = prog->nfunctions - 1; i >= 0; i--) {
        if (prog->functions[i].name == name) return &prog->functions[i];
    }
    return NULL;
}

static Kind *find_kind(Str *name) {
    for (int i = prog->nkinds - 1; i >= 0; i--) {
        if (prog->kinds[i].name == name) return &prog->kinds[i];
    }
    return NULL;
}

static Lambda *find_lambda(int index) {
    for (int i = prog->nlambdas - 1; i >= 0; i--) {
        if (prog->lambdas[i].index == index) return &prog->lambdas[i];
    }
    return NULL;
}

static Str *method_key(Str *cls, Str *method) {
    Buf k = {0};
    buf_add_str(&k, cls);
    buf_addc(&k, '.');
    buf_add_str(&k, method);
    Str *key = str_intern(k.data, k.len);
    free(k.data);
    return key;
}

/* A kind's record: its parent, the kind each field is declared by, and the block each method runs */
static void check_record(Block *b) {
    const char *name = b->name->data;
    if (b->parent && !find_kind(b->parent)) fail_at(false, "Undefined kind '%s' in kind %s", b->parent->data, name);
    for (int i = 0; i < b->nfields; i++) {
        if (!find_kind(b->field_declarers[i])) {
            fail_at(false, "Field %s is declared by undefined kind '%s' in kind %s", b->field_names[i]->data, b->field_declarers[i]->data, name);
        }
    }
    for (int i = 0; i < b->nmethods; i++) {
        if (!find_kind(b->method_definers[i])) {
            fail_at(false, "Method %s runs undefined kind '%s' in kind %s", b->method_names[i]->data, b->method_definers[i]->data, name);
        } else if (!find_function(method_key(b->method_definers[i], b->method_names[i]))) {
            fail_at(false, "Method %s has no block %s.%s in kind %s", b->method_names[i]->data, b->method_definers[i]->data, b->method_names[i]->data, name);
        }
        if (!find_kind(b->method_declarers[i])) {
            fail_at(false, "Method %s is declared by undefined kind '%s' in kind %s", b->method_names[i]->data, b->method_declarers[i]->data, name);
        }
    }
}

/* The last block with that key: a later block with a key replaces an earlier one */
static Block *block_by_key(Str *key) {
    for (int i = prog->nblocks - 1; i >= 0; i--) {
        if (prog->blocks[i]->key == key) return prog->blocks[i];
    }
    return NULL;
}

/* Mark the blocks that can run without an object: the top level, the functions, a method called
   or pushed as a function, and a lambda made in any of these. A key is marked through its last
   block, then every block with it. */
static void mark_objectless(void) {
    int nmethods = 0;
    for (int i = 0; i < prog->nblocks; i++) nmethods += prog->blocks[i]->nmethods;
    Str **methods = xmalloc((size_t)nmethods * sizeof(Str *) + 1);
    nmethods = 0;
    for (int i = 0; i < prog->nblocks; i++) {
        Block *b = prog->blocks[i];
        for (int m = 0; m < b->nmethods; m++) methods[nmethods++] = method_key(b->method_definers[m], b->method_names[m]);
    }

    /* Each key is pushed once, when it is marked, so the list never holds more than the blocks */
    Block **work = xmalloc((size_t)prog->nblocks * sizeof(Block *));
    int nwork = 0;
    for (int i = 0; i < prog->nblocks; i++) {
        Block *b = prog->blocks[i];
        bool root = b->kind == B_TOP || b->kind == B_FN;
        for (int m = 0; root && b->kind == B_FN && m < nmethods; m++) root = methods[m] != b->name;
        b = root ? block_by_key(b->key) : NULL;
        if (b && !b->objectless) b->objectless = true, work[nwork++] = b;
    }
    while (nwork) {
        Block *b = work[--nwork];
        for (int i = 0; i < b->nraw; i++) {
            RawInstr *r = &b->raw[i];
            Block *reached = NULL;
            if (r->op == OP_CALL || r->op == OP_PUSH_FN) {
                Function *f = find_function(r->names[0]);
                reached = f ? f->block : NULL;
            } else if (r->op == OP_MAKE_CLOSURE) {
                Lambda *l = find_lambda(r->ints[0]);
                reached = l ? l->block : NULL;
            }
            if (reached && !reached->objectless) reached->objectless = true, work[nwork++] = reached;
        }
    }
    for (int i = 0; i < prog->nblocks; i++) prog->blocks[i]->objectless = block_by_key(prog->blocks[i]->key)->objectless;
    free(methods);
    free(work);
}

/* A path to walk: where, how deep the stack is, and how many try handlers are open */
typedef struct { int position, height, tries; } Work;

/* A label's position in a block's raw instructions, its last definition if it has several, or
   -1. Names are interned, so the table is keyed by the pointer. Built on the first lookup: a
   search of the whole block per jump was most of what loading the compiler took. */
static int find_label(Block *b, Str *name) {
    if (!b->label_cap) {
        int n = 0;
        for (int i = 0; i < b->nraw; i++) n += b->raw[i].op == OP_LABEL;
        b->label_cap = 8;
        while (b->label_cap < n * 2) b->label_cap *= 2;
        b->label_names = xcalloc((size_t)b->label_cap, sizeof(Str *));
        b->label_at = xmalloc((size_t)b->label_cap * sizeof(int));
        for (int i = 0; i < b->nraw; i++) {
            if (b->raw[i].op != OP_LABEL) continue;
            Str *label = b->raw[i].names[0];
            size_t j = ((uintptr_t)label >> 4) & (size_t)(b->label_cap - 1);
            while (b->label_names[j] && b->label_names[j] != label) j = (j + 1) & (size_t)(b->label_cap - 1);
            b->label_names[j] = label;
            b->label_at[j] = i;
        }
    }
    size_t j = ((uintptr_t)name >> 4) & (size_t)(b->label_cap - 1);
    for (; b->label_names[j]; j = (j + 1) & (size_t)(b->label_cap - 1)) {
        if (b->label_names[j] == name) return b->label_at[j];
    }
    return -1;
}

/*
 * Check a block: every name it uses exists, every label is defined, and its stack balances.
 * The stack is walked from the top of the block, following jumps, always in the same order
 * (so the same problem always gives the same message).
 */
static void check_block(Block *b) {
    char where[300];
    if (b->kind == B_TOP) snprintf(where, sizeof where, "the top level");
    else snprintf(where, sizeof where, "'%s'", b->key->data);
#define FAIL(fmt, ...) fail_at(false, fmt " in %s", __VA_ARGS__, where)

    int *heights = xmalloc((size_t)(b->nraw + 1) * sizeof(int));
    int *opens = xmalloc((size_t)(b->nraw + 1) * sizeof(int));
    for (int i = 0; i < b->nraw; i++) heights[i] = -1;
    int nwork = 1, capwork = 16;
    Work *work = xmalloc((size_t)capwork * sizeof(Work));
    work[0] = (Work){0, 0, 0};
    int max = 0;

    while (nwork) {
        Work item = work[--nwork];
        int position = item.position, height = item.height, tries = item.tries;
        while (position < b->nraw) {
            RawInstr *r = &b->raw[position];
            if (heights[position] >= 0) {
                if (heights[position] != height) {
                    Buf what = {0};
                    for (int i = 0; i < r->nwords; i++) {
                        if (i) buf_addc(&what, ' ');
                        buf_add_str(&what, r->words[i]);
                    }
                    FAIL("The stack is %d deep at %s (instruction %d), but %d on another path", height, what.data, position, heights[position]);
                }
                /* A try's handler is the VM's, not a value on the stack, so where it is open
                   must be the same on every path, as the depth must */
                if (opens[position] != tries) {
                    FAIL("%d try handlers are open at instruction %d, but %d on another path", tries, position, opens[position]);
                }
                break;
            }
            heights[position] = height;
            opens[position] = tries;
            const InstrInfo *info = &INFO[r->op];

            for (int i = 0; i < info->nargs; i++) {
                Str *name = r->names[i];
                int n = r->ints[i];
                switch (info->kinds[i]) {
                case K_LABEL:
                    if (find_label(b, name) < 0) FAIL("Undefined label '%s'", name->data);
                    break;
                case K_FUNCTION:
                    if (!find_function(name)) FAIL("Undefined function '%s'", name->data);
                    break;
                case K_CALLABLE:
                    if (!find_function(name) && builtin_find(name->data, name->len) < 0) FAIL("Undefined function '%s'", name->data);
                    break;
                case K_BUILTIN:
                    if (builtin_find(name->data, name->len) < 0) FAIL("Undefined builtin '%s'", name->data);
                    break;
                case K_KIND:
                    if (!find_kind(name)) FAIL("Undefined kind '%s'", name->data);
                    break;
                case K_LAMBDA:
                    if (!find_lambda(n)) FAIL("Undefined lambda %d", n);
                    break;
                case K_SLOT:
                    if (n >= b->nlocals) FAIL("Slot %d is not one of the block's %d locals", n, b->nlocals);
                    break;
                case K_GLOBAL:
                    if (n >= prog->nglobals) FAIL("Global slot %d is not one of the program's %d globals", n, prog->nglobals);
                    break;
                case K_STATIC:
                    if (n >= prog->nstatics) FAIL("Static slot %d is not one of the program's %d static fields", n, prog->nstatics);
                    break;
                case K_CAPTURE:
                    if (n >= b->ncaptures) FAIL("Capture %d is not one of the block's %d captured variables", n, b->ncaptures);
                    break;
                }
            }
            if (b->objectless && (r->op == OP_LOAD_FIELD || r->op == OP_SET_FIELD || r->op == OP_CALL_PARENT ||
                                  r->op == OP_BIND_PARENT || r->op == OP_CALL_CONSTRUCTOR)) {
                FAIL("%s can run without an object", info->name);
            }

            /* Wide, since a file can give a count as large as an int holds */
            int64_t pops = info->pops;
            switch (pops) {
            case POPS_PATH: pops = r->path->nkeys + 1; break;
            case POPS_KEYS: pops = r->path->nkeys; break;
            case POPS_COUNT:
            case POPS_COUNT1:
            case POPS_COUNT2: {
                int at = 0;
                while (info->kinds[at] != K_COUNT) at++;
                pops = (int64_t)r->ints[at] + (info->pops == POPS_COUNT ? 0 : info->pops == POPS_COUNT1 ? 1 : 2);
                break;
            }
            }
            if (height < pops) {
                FAIL("%s needs %lld value%s but the stack is %d deep at instruction %d", info->name, (long long)pops, pops == 1 ? "" : "s", height, position);
            }
            /* The loader puts one at the end of the top level itself; anywhere else it would
               end a call's run, leaving its caller in C without a value or its frames */
            if (r->op == OP_HALT && b->kind != B_TOP) {
                FAIL("%s", "HALT ends the program, so it belongs to the top level");
            }
            if (r->op == OP_END_TRY && tries == 0) {
                FAIL("END_TRY at instruction %d closes a try that no TRY opened", position);
            }
            height += info->pushes - (int)pops;
            if (height > max) max = height;

            /* A jump reaches its label with the stack as it is here; JNN keeps the value it
               tested, and a handler starts one deeper, holding the error */
            int target = -1, target_height = height, target_tries = tries;
            if (r->op == OP_JMP || r->op == OP_JZ || r->op == OP_JNN || r->op == OP_TRY) {
                target = find_label(b, r->names[0]);
                if (r->op == OP_JNN || r->op == OP_TRY) target_height = height + 1;
            } else if (r->op == OP_CATCH_MATCH) {
                /* Its kind didn't match: the next catch is tried with the error still on top */
                target = find_label(b, r->names[1]);
            }
            /* A try's handler is open from TRY until END_TRY, or until its catch runs */
            if (r->op == OP_TRY) tries++;
            if (r->op == OP_END_TRY) tries--;
            if (target >= 0) {
                if (target_height > max) max = target_height;
                if (nwork == capwork) work = xrealloc(work, (size_t)(capwork *= 2) * sizeof(Work));
                work[nwork++] = (Work){target, target_height, target_tries};
            }
            if (r->op == OP_JMP || r->op == OP_RET || r->op == OP_RETHROW || r->op == OP_HALT) break;
            position++;
        }
        /* Only the top level may end by running out of instructions, which ends the program */
        if (position >= b->nraw && b->kind != B_TOP) {
            FAIL("%s", "The code runs off the end of the block, which must end in RET");
        }
    }
    b->max_stack = max;
    free(heights);
    free(opens);
    free(work);
#undef FAIL
}

/* ---- Linking --------------------------------------------------------------------------- */

static void build_kinds(void) {
    int n = 0;
    for (int i = 0; i < prog->nblocks; i++) {
        Block *b = prog->blocks[i];
        b->owner = b->owner_name ? find_kind(b->owner_name) : NULL;
        /* A kind block is the initialiser, which sets every slot of the object it builds */
        if (b->kind == B_KIND) b->owner = KIND_INITIALISER;
    }
    for (int i = 0; i < prog->nblocks; i++) {
        Block *b = prog->blocks[i];
        if (b->kind != B_KIND) continue;
        Kind *c = &prog->kinds[n++];
        c->name = b->name;
        c->abstract = b->is_abstract;
        c->block = b;
        c->nfields = b->nfields;
        c->fields = b->field_names;
        c->field_vis = b->field_vis;
    }
    for (int i = 0; i < prog->nkinds; i++) {
        Kind *c = &prog->kinds[i];
        Block *b = c->block;
        c->parent = b->parent ? find_kind(b->parent) : NULL;
        c->field_declarers = xmalloc((size_t)b->nfields * sizeof(Kind *) + 1);
        for (int f = 0; f < b->nfields; f++) c->field_declarers[f] = find_kind(b->field_declarers[f]);
        c->nmethods = b->nmethods;
        c->methods = b->method_names;
        c->method_vis = b->method_vis;
        c->method_declarers = xmalloc((size_t)b->nmethods * sizeof(Kind *) + 1);
        for (int m = 0; m < b->nmethods; m++) c->method_declarers[m] = find_kind(b->method_declarers[m]);
        c->definers = xmalloc((size_t)b->nmethods * sizeof(Kind *) + 1);
        c->entries = xcalloc((size_t)b->nmethods + 1, sizeof(Entry));
        for (int m = 0; m < b->nmethods; m++) {
            c->definers[m] = find_kind(b->method_definers[m]);
            Str *key = method_key(b->method_definers[m], b->method_names[m]);
            c->entries[m] = (Entry){b->method_names[m], key, find_function(key)};
            if (b->method_names[m]->len == 1 && b->method_names[m]->data[0] == '_') {
                c->lo = c->entries[m].function->lo;
                c->hi = c->entries[m].function->hi;
            }
        }
        if (!strcmp(c->name->data, "Error")) {
            /* The VM fills these in for an error the program didn't throw itself (caught() in
               vm.c), so a kind named Error that lacks one would be written outside its fields */
            static const char *const needed[] = {"message", "file", "line", "trace"};
            for (size_t f = 0; f < sizeof needed / sizeof *needed; f++) {
                if (kind_field(c, str_intern(needed[f], strlen(needed[f])), NULL) < 0) {
                    fail_at(false, "kind Error must declare pub %s, which a caught error is given", needed[f]);
                }
            }
            prog->error_kind = c;
        }
    }
}

/* The operators quick_binary() in vm.c can do; the comparisons among them give a bool */
static bool quick_operator(int op) {
    switch (op) {
    case OP_ADD: case OP_SUB: case OP_MUL: case OP_MOD:
    case OP_EQUALS: case OP_NOT_EQUALS: case OP_LT: case OP_LE: case OP_GT: case OP_GE:
        return true;
    }
    return false;
}
static bool comparison(int op) {
    return op == OP_EQUALS || op == OP_NOT_EQUALS || op == OP_LT || op == OP_LE || op == OP_GT || op == OP_GE;
}

/*
 * Superinstructions: where a sequence the VM runs often starts, its first instruction becomes
 * one that does the whole sequence in one dispatch. The sequence's instructions stay where they
 * are, as orig says they were, and the superinstruction reads its operands from them. So a jump
 * into the middle of a sequence runs the rest of it as before, and one sequence can start
 * inside another. A superinstruction takes its quick path only when nothing in the sequence
 * can fail or run program code; otherwise it runs its first instruction as that alone, and the
 * rest follow one at a time, so errors and their locations are exactly those of the sequence.
 */
static void fuse(Instr *in, Instr *end) {
    for (; in + 1 < end; in++) {
        Instr *next = in + 1, *third = in + 2 < end ? in + 2 : NULL;
        switch (in->orig) {
        case OP_NOT:
            if (next->orig == OP_JZ) in->op = OP_NOT_JZ;
            break;
        case OP_SET_FIELD:
            if (next->orig == OP_POP) in->op = OP_SET_FIELD_POP;
            break;
        case OP_LOAD:
            if (!third) break;
            if (next->orig == OP_PUSH && quick_operator(third->orig)) {
                in->op = comparison(third->orig) && in + 3 < end && in[3].orig == OP_JZ ? OP_LOAD_PUSH_OP_JZ : OP_LOAD_PUSH_OP;
            } else if (next->orig == OP_LOAD && quick_operator(third->orig)) {
                in->op = OP_LOAD_LOAD_OP;
            } else if (next->orig == OP_LOAD && third->orig == OP_INDEX_GET) {
                in->op = OP_LOAD_LOAD_INDEX;
            } else if ((next->orig == OP_INC || next->orig == OP_DEC) && third->orig == OP_STORE && third->a == in->a) {
                in->op = OP_STEP_LOCAL;
            }
            break;
        }
    }
}

static void link_program(void) {
    int total = 0;
    for (int i = 0; i < prog->nblocks; i++) total += prog->blocks[i]->nraw + 1;
    prog->code = xcalloc((size_t)total, sizeof(Instr));
    int *positions = NULL;

    for (int i = 0; i < prog->nblocks; i++) {
        Block *b = prog->blocks[i];
        b->entry = prog->ncode;
        int frame = b->nlocals + b->max_stack;
        if (b->kind == B_KIND || b->kind == B_TOP) frame += 0;
        if (frame > prog->max_frame) prog->max_frame = frame;

        /* STORE x; LOAD x; POP, an assignment used as a statement, is STORE x: the LOAD and
           POP are marked dropped. A LABEL between them would be a jump into the middle, so it
           stops the match. */
        bool *dropped = xcalloc((size_t)b->nraw + 1, sizeof(bool));
        for (int j = 0; j + 2 < b->nraw; j++) {
            RawInstr *r = b->raw;
            if ((r[j].op == OP_STORE || r[j].op == OP_STORE_GLOBAL) && r[j + 1].op == (r[j].op == OP_STORE ? OP_LOAD : OP_LOAD_GLOBAL)
                && r[j + 1].ints[0] == r[j].ints[0] && r[j + 2].op == OP_POP) {
                dropped[j + 1] = dropped[j + 2] = true;
            }
        }

        /* Where each raw instruction lands, labels and dropped ones being no instruction */
        positions = xrealloc(positions, (size_t)(b->nraw + 1) * sizeof(int));
        int at = prog->ncode;
        for (int j = 0; j < b->nraw; j++) {
            positions[j] = at;
            if (b->raw[j].op != OP_LABEL && !dropped[j]) at++;
        }
        for (int j = 0; j < b->nraw; j++) {
            RawInstr *r = &b->raw[j];
            if (r->op == OP_LABEL || dropped[j]) continue;
            Instr *in = &prog->code[prog->ncode++];
            in->op = in->orig = (uint8_t)r->op;
            in->file = r->file;
            in->line = r->line;
            in->a = r->ints[0];
            in->b = r->ints[1];
            int label;
            switch (r->op) {
            case OP_PUSH:
                in->v = r->value;
                break;
            case OP_JMP: case OP_JZ: case OP_JNN: case OP_TRY:
                label = find_label(b, r->names[0]);
                in->a = label < 0 ? -1 : positions[label];
                break;
            case OP_CATCH_MATCH:
                in->p = find_kind(r->names[0]);
                label = find_label(b, r->names[1]);
                in->a = label < 0 ? -1 : positions[label];
                break;
            case OP_CALL:
                in->p = find_function(r->names[0]);
                in->a = r->ints[1];
                break;
            case OP_CALL_BUILTIN:
                in->a = builtin_find(r->names[0]->data, r->names[0]->len);
                in->b = r->ints[1];
                break;
            case OP_PUSH_FN: {
                Function *f = find_function(r->names[0]);
                int builtin = builtin_find(r->names[0]->data, r->names[0]->len);
                if (f) in->v = v_func(f->value);
                else if (builtin >= 0) in->v = v_func(builtin_value(builtin));
                break;
            }
            case OP_MAKE_CLOSURE:
                in->p = find_lambda(r->ints[0]);
                break;
            case OP_PUSH_KIND: case OP_CALL_CONSTRUCTOR:
                in->p = find_kind(r->names[0]);
                break;
            case OP_NEW:
                in->p = find_kind(r->names[0]);
                in->a = r->ints[1];
                break;
            case OP_CALL_PARENT:
                in->p = find_function(method_key(r->names[0], r->names[1]));
                in->a = r->ints[2];
                break;
            case OP_BIND_PARENT:
                in->p = find_kind(r->names[0]);
                in->v = v_str(r->names[1]);
                break;
            case OP_LOAD_FIELD: case OP_SET_FIELD: case OP_GET_PROPERTY: case OP_GET_PROPERTY_QUIET:
            case OP_GET_PROPERTY_EXISTING: case OP_GET_METHOD:
                in->p = r->names[0];
                break;
            case OP_CALL_METHOD:
                in->p = r->names[1];
                break;
            case OP_SET_PATH: case OP_SET_PATH_GLOBAL: case OP_SET_PATH_CAPTURED: case OP_SET_PATH_THIS:
            case OP_SET_PATH_STATIC:
            case OP_DELETE_PATH: case OP_DELETE_PATH_GLOBAL: case OP_DELETE_PATH_CAPTURED: case OP_DELETE_PATH_THIS:
            case OP_DELETE_PATH_STATIC:
                in->p = r->path;
                in->a = r->ints[1];
                break;
            }
        }
        if (b->kind == B_TOP) {
            prog->code[prog->ncode].op = prog->code[prog->ncode].orig = OP_HALT;
            prog->ncode++;
        }
        fuse(prog->code + b->entry, prog->code + prog->ncode);
        free(dropped);
        free(b->raw);
        free(b->label_names);
        free(b->label_at);
        b->raw = NULL;
    }
    free(positions);
}

/* ---- The file -------------------------------------------------------------------------- */

Program *load(const char *text, size_t len, const char *path) {
    have_cwd = getcwd(cwd, sizeof cwd) != NULL;
    last_file = NULL;
    /* Piped bytecode has no path: its errors say "on line N", and its paths are relative to the
       working directory */
    file_path = path ? str_intern(path, strlen(path)) : NULL;
    {
        char *dir = strdup(path ? path : ".");
        char *slash = strrchr(dir, '/');
        if (!slash) strcpy(dir, ".");
        else if (slash == dir) dir[1] = '\0';
        else *slash = '\0';
        /* Interned, since it outlives the load (paths resolve against it while the program runs) */
        Str *absolute = absolute_path(dir);
        base_dir = str_intern(absolute->data, absolute->len);
        decref(v_str(absolute));
        free(dir);
    }
    /* Split into lines in a copy; a NUL in the file ends its line early, which no valid file has */
    char *copy = xmalloc(len + 1);
    memcpy(copy, text, len);
    copy[len] = '\0';
    nlines = 1;
    for (size_t i = 0; i < len; i++) nlines += copy[i] == '\n';
    lines = xmalloc((size_t)nlines * sizeof(char *));
    nlines = 0;
    for (char *p = copy;;) {
        lines[nlines++] = p;
        char *nl = memchr(p, '\n', (size_t)(copy + len - p));
        if (!nl) break;
        *nl = '\0';
        p = nl + 1;
    }
    at_line = 0;

    if (setjmp(failed)) return NULL;

    Words w;
    char *line = next_line();
    split_words(line ? line : "", &w);
    if (w.n != 3 || strcmp(w.w[0], "GAZLANG") || strcmp(w.w[1], "BYTECODE")) fail("Not a bytecode file");
    if (strcmp(w.w[2], "2")) fail("Bytecode version %s, but this is GazLang bytecode 2", w.w[2]);
    free_words(&w);

    prog = xcalloc(1, sizeof(Program));
    line = next_line();
    split_words(line ? line : "", &w);
    if (strcmp(w.w[0], "globals")) fail("Expected 'globals'");
    for (int i = 1; i < w.n; i++) prog->globals = push_name(prog->globals, &prog->nglobals, intern(w.w[i]));
    free_words(&w);

    /* A static field is named "Kind::name", the kind being the one that declares it, so a
       child and its parent name the same slot. The line is optional, as most programs have none */
    line = next_line();
    if (line) {
        split_words(line, &w);
        if (!strcmp(w.w[0], "statics")) {
            for (int i = 1; i < w.n; i++) prog->statics = push_name(prog->statics, &prog->nstatics, intern(w.w[i]));
        } else {
            at_line--;      /* not ours, so the block reader gets this line */
        }
        free_words(&w);
    }

    int cap = 8;
    prog->blocks = xmalloc((size_t)cap * sizeof(Block *));
    while ((line = next_line())) {
        if (prog->nblocks == cap) prog->blocks = xrealloc(prog->blocks, (size_t)(cap *= 2) * sizeof(Block *));
        prog->blocks[prog->nblocks++] = read_block(line);
    }
    if (prog->nblocks == 0 || prog->blocks[0]->kind != B_TOP) fail("Expected a top block");

    for (int i = 0; i < prog->nblocks; i++) {
        Block *b = prog->blocks[i];
        if (b->kind == B_FN) prog->nfunctions++;
        if (b->kind == B_KIND) prog->nkinds++;
        if (b->kind == B_LAMBDA) prog->nlambdas++;
    }
    prog->functions = xcalloc((size_t)prog->nfunctions + 1, sizeof(Function));
    prog->kinds = xcalloc((size_t)prog->nkinds + 1, sizeof(Kind));
    prog->lambdas = xcalloc((size_t)prog->nlambdas + 1, sizeof(Lambda));
    int nf = 0, nl = 0;
    for (int i = 0; i < prog->nblocks; i++) {
        Block *b = prog->blocks[i];
        if (b->kind == B_FN) {
            Function *f = &prog->functions[nf++];
            f->name = b->name;
            f->lo = b->lo;
            f->hi = b->hi;
            f->block = b;
            f->value = xcalloc(1, sizeof(Func));
            f->value->gc.rc = INT64_MAX / 2;
            f->value->kind = F_NAMED;
            f->value->name = b->name;
            f->value->function = f;
        } else if (b->kind == B_LAMBDA) {
            prog->lambdas[nl++] = (Lambda){b->index, b};
        }
    }
    int nc = prog->nkinds;
    prog->nkinds = 0;
    for (int i = 0; i < prog->nblocks; i++) {
        if (prog->blocks[i]->kind == B_KIND) prog->kinds[prog->nkinds++].name = prog->blocks[i]->name;
    }
    (void)nc;
    for (int i = 0; i < prog->nblocks; i++) {
        if (prog->blocks[i]->kind == B_KIND) check_record(prog->blocks[i]);
        Str *owner = prog->blocks[i]->owner_name;
        if (owner && !find_kind(owner)) {
            fail_at(false, "Block %s is written in undefined kind '%s'",
                    prog->blocks[i]->key->data, owner->data);
        }
    }
    mark_objectless();
    for (int i = 0; i < prog->nblocks; i++) check_block(prog->blocks[i]);

    build_kinds();
    /* What a catch sees is made as an Error (caught() in vm.c), so a file that can catch needs
       the kind, whatever its own catch clauses name */
    if (!prog->error_kind) {
        for (int i = 0; i < prog->nblocks; i++) {
            for (int j = 0; j < prog->blocks[i]->nraw; j++) {
                int op = prog->blocks[i]->raw[j].op;
                if (op == OP_CATCH_VALUE || op == OP_CATCH_MATCH) {
                    fail_at(false, "%s needs a kind Error, which is what a caught error is made as", INFO[op].name);
                }
            }
        }
    }
    link_program();
    free(lines);
    free(copy);
    return prog;
}
