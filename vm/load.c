/*
 * Reading a bytecode file (CodeGenerator\BytecodeReader, docs/bytecode.md)
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

/* ---- The instruction table (Program::INSTRUCTIONS) -------------------------------------- */

/* Argument kinds */
enum { K_LABEL, K_VALUE, K_SLOT, K_GLOBAL, K_CAPTURE, K_COUNT, K_LAMBDA, K_FUNCTION, K_CALLABLE,
       K_BUILTIN, K_CLASS, K_MEMBER, K_PATH, K_ELEMENT_PATH };

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
    [OP_SET_PATH_CAPTURED] = {"SET_PATH_CAPTURED", 2, {K_PATH, K_CAPTURE}, POPS_PATH, 1},
    [OP_SET_PATH_THIS] = {"SET_PATH_THIS", 1, {K_PATH}, POPS_PATH, 1},
    [OP_DELETE_PATH] = {"DELETE_PATH", 2, {K_ELEMENT_PATH, K_SLOT}, POPS_KEYS, 0},
    [OP_DELETE_PATH_GLOBAL] = {"DELETE_PATH_GLOBAL", 2, {K_ELEMENT_PATH, K_GLOBAL}, POPS_KEYS, 0},
    [OP_DELETE_PATH_CAPTURED] = {"DELETE_PATH_CAPTURED", 2, {K_ELEMENT_PATH, K_CAPTURE}, POPS_KEYS, 0},
    [OP_DELETE_PATH_THIS] = {"DELETE_PATH_THIS", 1, {K_ELEMENT_PATH}, POPS_KEYS, 0},
    [OP_CALL] = {"CALL", 2, {K_FUNCTION, K_COUNT}, POPS_COUNT, 1},
    [OP_CALL_BUILTIN] = {"CALL_BUILTIN", 2, {K_BUILTIN, K_COUNT}, POPS_COUNT, 1},
    [OP_CALL_VALUE] = {"CALL_VALUE", 1, {K_COUNT}, POPS_COUNT1, 1},
    [OP_ARGC] = {"ARGC", 0, {0}, 0, 1},
    [OP_RET] = {"RET", 0, {0}, 1, 0},
    [OP_PUSH_FN] = {"PUSH_FN", 1, {K_CALLABLE}, 0, 1},
    [OP_MAKE_CLOSURE] = {"MAKE_CLOSURE", 1, {K_LAMBDA}, 0, 1},
    [OP_PUSH_CLASS] = {"PUSH_CLASS", 1, {K_CLASS}, 0, 1},
    [OP_NEW] = {"NEW", 2, {K_CLASS, K_COUNT}, POPS_COUNT, 1},
    [OP_CALL_CONSTRUCTOR] = {"CALL_CONSTRUCTOR", 1, {K_CLASS}, 0, 1},
    [OP_CALL_PARENT] = {"CALL_PARENT", 3, {K_CLASS, K_MEMBER, K_COUNT}, POPS_COUNT, 1},
    [OP_BIND_PARENT] = {"BIND_PARENT", 2, {K_CLASS, K_MEMBER}, 0, 1},
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
    [OP_CATCH_MATCH] = {"CATCH_MATCH", 2, {K_CLASS, K_LABEL}, 1, 1},
    [OP_CATCH_VALUE] = {"CATCH_VALUE", 0, {0}, 1, 1},
    [OP_RETHROW] = {"RETHROW", 0, {0}, 1, 0},
    [OP_HALT] = {"HALT", 0, {0}, 0, 0},
};

/* An instruction as read, before its names are resolved */
typedef struct RawInstr {
    int op;
    int ints[3];        /* slot, count and lambda arguments */
    Str *names[3];      /* label, function, class, member... arguments, interned */
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
        if (n < INT_MAX) n = n * 10 + (*p - '0');
    }
    return n > INT_MAX ? INT_MAX : (int)n;
}

static Str *intern(const char *s) { return str_intern(s, strlen(s)); }

/* ---- Literals ------------------------------------------------------------------------- */

/*
 * A PUSH value or an @ line's file is a GazLang literal, read with the part of GazLang's lexer
 * that literals use. Every problem inside one is reported as the PHP reader reports it: "Bad
 * value '<the literal>': <reason>", the reason being the lexer's message, or the reader's own
 * ("Bad value: expected '=>'"), wrapped a second time because the PHP catches its own too.
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
    const char *type;       /* L_OTHER: the token type the PHP lexer would give it */
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

/* Token types for what a literal can't hold, longest match first, as the PHP lexer names them */
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
    {"include", "INCLUDE"}, {"try", "TRY"}, {"catch", "CATCH"}, {"finally", "FINALLY"}, {"class", "CLASS"},
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

    /* Anything else is a token no literal holds: name it as the PHP lexer would */
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
    if (c == '-') {
        lx->p += 2;
        t.type = lx->p[-1] == '=' ? "MINUS_ASSIGN" : lx->p[-1] == '-' ? "DECREMENT" : "ARROW";
        return t;
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

Str *absolute_path(const char *path) {
    /* Made absolute and normalised as text: the file may not exist (Program::absolute) */
    Buf full = {0};
    if (path[0] != '/') {
        char cwd[PATH_MAX];
        if (getcwd(cwd, sizeof cwd)) buf_adds(&full, cwd);
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

/* A source path as the parser shows it: relative to the working directory when it is under it */
static Str *display_path(Str *file) {
    Buf joined = {0};
    if (file->data[0] != '/') {
        buf_add_str(&joined, base_dir);
        buf_addc(&joined, '/');
    }
    buf_add_str(&joined, file);
    Str *path = absolute_path(joined.data);
    free(joined.data);
    char cwd[PATH_MAX];
    Str *shown = path;
    if (getcwd(cwd, sizeof cwd)) {
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

static int op_find(const char *name) {
    for (int op = 0; op < OP_COUNT; op++) {
        if (!strcmp(INFO[op].name, name)) return op;
    }
    return -1;
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
            case K_SLOT: case K_GLOBAL: case K_CAPTURE: case K_COUNT: case K_LAMBDA:
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
    const char *kind = w.w[0];
    char key[64];
    if (!strcmp(kind, "top")) {
        b->kind = B_TOP;
        if (w.n != 1) fail("Expected 'top' on its own");
        b->key = intern("");
    } else if (!strcmp(kind, "fn")) {
        b->kind = B_FN;
        b->name = intern(w.n > 1 ? w.w[1] : "");
        read_arity(&w, 2, &b->lo, &b->hi);
        b->key = b->name;
    } else if (!strcmp(kind, "class") || !strcmp(kind, "abstract")) {
        b->kind = B_CLASS;
        b->is_abstract = !strcmp(kind, "abstract");
        int from = 1;
        if (b->is_abstract) {
            if (w.n < 2 || strcmp(w.w[1], "class")) fail("Expected 'abstract class'");
            from = 2;
        }
        int rest = w.n - from;
        if (rest != 0 && rest != 1 && !(rest == 3 && !strcmp(w.w[from + 1], "extends"))) {
            fail("Expected 'class Name' or 'class Name extends Parent'");
        }
        if (rest == 0) fail("Unexpected end of line");
        b->name = intern(w.w[from]);
        b->parent = rest == 3 ? intern(w.w[from + 2]) : NULL;
        Buf k = {0};
        buf_adds(&k, "new ");
        buf_add_str(&k, b->name);
        b->key = str_intern(k.data, k.len);
        free(k.data);
    } else if (!strcmp(kind, "lambda")) {
        b->kind = B_LAMBDA;
        b->index = count(w.n > 1 ? w.w[1] : "");
        read_arity(&w, 2, &b->lo, &b->hi);
        snprintf(key, sizeof key, "->%d", b->index);
        b->key = intern(key);
    } else {
        fail("Unknown block '%s'", kind);
    }
    free_words(&w);

    /* The record lines a class or lambda block carries, then its locals */
    for (;;) {
        char *line = next_line();
        if (!line) fail("Expected 'locals'");
        split_words(line, &w);
        const char *first = w.w[0];
        if (!strcmp(first, "field") && b->kind == B_CLASS) {
            Str *name = intern(word(&w, 1)), *declarer = intern(word(&w, 2));
            int n = b->nfields;
            b->field_names = push_name(b->field_names, &n, name);
            b->field_declarers = push_name(b->field_declarers, &b->nfields, declarer);
        } else if (!strcmp(first, "method") && b->kind == B_CLASS) {
            Str *name = intern(word(&w, 1)), *definer = intern(word(&w, 2));
            int n = b->nmethods;
            b->method_names = push_name(b->method_names, &n, name);
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

static Class *find_class(Str *name) {
    for (int i = prog->nclasses - 1; i >= 0; i--) {
        if (prog->classes[i].name == name) return &prog->classes[i];
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

/* A class's record: its parent, the class each field is declared by, and the block each method runs */
static void check_record(Block *b) {
    const char *name = b->name->data;
    if (b->parent && !find_class(b->parent)) fail_at(false, "Undefined class '%s' in class %s", b->parent->data, name);
    for (int i = 0; i < b->nfields; i++) {
        if (!find_class(b->field_declarers[i])) {
            fail_at(false, "Field %s is declared by undefined class '%s' in class %s", b->field_names[i]->data, b->field_declarers[i]->data, name);
        }
    }
    for (int i = 0; i < b->nmethods; i++) {
        if (!find_class(b->method_definers[i])) {
            fail_at(false, "Method %s runs undefined class '%s' in class %s", b->method_names[i]->data, b->method_definers[i]->data, name);
        } else if (!find_function(method_key(b->method_definers[i], b->method_names[i]))) {
            fail_at(false, "Method %s has no block %s.%s in class %s", b->method_names[i]->data, b->method_definers[i]->data, b->method_names[i]->data, name);
        }
    }
}

typedef struct { int position, height; } Work;

static int find_label(Block *b, Str *name) {
    int found = -1;
    for (int i = 0; i < b->nraw; i++) {
        if (b->raw[i].op == OP_LABEL && b->raw[i].names[0] == name) found = i;
    }
    return found;
}

/*
 * Check a block: every name it uses exists, every label is defined, and its stack balances.
 * The stack is walked from the top of the block, following jumps, in the order the PHP reader
 * walks it (so the same problem gives the same message).
 */
static void check_block(Block *b) {
    char where[300];
    if (b->kind == B_TOP) snprintf(where, sizeof where, "the top level");
    else snprintf(where, sizeof where, "'%s'", b->key->data);
#define FAIL(fmt, ...) fail_at(false, fmt " in %s", __VA_ARGS__, where)

    int *heights = xmalloc((size_t)(b->nraw + 1) * sizeof(int));
    for (int i = 0; i < b->nraw; i++) heights[i] = -1;
    int nwork = 1, capwork = 16;
    Work *work = xmalloc((size_t)capwork * sizeof(Work));
    work[0] = (Work){0, 0};
    int max = 0;

    while (nwork) {
        Work item = work[--nwork];
        int position = item.position, height = item.height;
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
                break;
            }
            heights[position] = height;
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
                case K_CLASS:
                    if (!find_class(name)) FAIL("Undefined class '%s'", name->data);
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
                case K_CAPTURE:
                    if (n >= b->ncaptures) FAIL("Capture %d is not one of the block's %d captured variables", n, b->ncaptures);
                    break;
                }
            }

            int pops = info->pops;
            switch (pops) {
            case POPS_PATH: pops = r->path->nkeys + 1; break;
            case POPS_KEYS: pops = r->path->nkeys; break;
            case POPS_COUNT:
            case POPS_COUNT1:
            case POPS_COUNT2: {
                int at = 0;
                while (info->kinds[at] != K_COUNT) at++;
                pops = r->ints[at] + (info->pops == POPS_COUNT ? 0 : info->pops == POPS_COUNT1 ? 1 : 2);
                break;
            }
            }
            if (height < pops) {
                FAIL("%s needs %d value%s but the stack is %d deep at instruction %d", info->name, pops, pops == 1 ? "" : "s", height, position);
            }
            height += info->pushes - pops;
            if (height > max) max = height;

            /* A jump reaches its label with the stack as it is here; JNN keeps the value it
               tested, and a handler starts one deeper, holding the error */
            int target = -1, target_height = height;
            if (r->op == OP_JMP || r->op == OP_JZ || r->op == OP_JNN || r->op == OP_TRY) {
                target = find_label(b, r->names[0]);
                if (r->op == OP_JNN || r->op == OP_TRY) target_height = height + 1;
            } else if (r->op == OP_CATCH_MATCH) {
                target = find_label(b, r->names[1]);
            }
            if (target >= 0) {
                if (target_height > max) max = target_height;
                if (nwork == capwork) work = xrealloc(work, (size_t)(capwork *= 2) * sizeof(Work));
                work[nwork++] = (Work){target, target_height};
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
    free(work);
#undef FAIL
}

/* ---- Linking --------------------------------------------------------------------------- */

static void build_classes(void) {
    int n = 0;
    for (int i = 0; i < prog->nblocks; i++) {
        Block *b = prog->blocks[i];
        if (b->kind != B_CLASS) continue;
        Class *c = &prog->classes[n++];
        c->name = b->name;
        c->abstract = b->is_abstract;
        c->block = b;
        c->nfields = b->nfields;
        c->fields = b->field_names;
    }
    for (int i = 0; i < prog->nclasses; i++) {
        Class *c = &prog->classes[i];
        Block *b = c->block;
        c->parent = b->parent ? find_class(b->parent) : NULL;
        c->nmethods = b->nmethods;
        c->methods = b->method_names;
        c->definers = xmalloc((size_t)b->nmethods * sizeof(Class *) + 1);
        c->entries = xcalloc((size_t)b->nmethods + 1, sizeof(Entry));
        for (int m = 0; m < b->nmethods; m++) {
            c->definers[m] = find_class(b->method_definers[m]);
            Str *key = method_key(b->method_definers[m], b->method_names[m]);
            c->entries[m] = (Entry){b->method_names[m], key, find_function(key)};
            if (b->method_names[m]->len == 1 && b->method_names[m]->data[0] == '_') {
                c->lo = c->entries[m].function->lo;
                c->hi = c->entries[m].function->hi;
            }
        }
        if (!strcmp(c->name->data, "Error")) prog->error_class = c;
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
        if (b->kind == B_CLASS || b->kind == B_TOP) frame += 0;
        if (frame > prog->max_frame) prog->max_frame = frame;

        /* Where each raw instruction lands, labels being positions rather than instructions */
        positions = xrealloc(positions, (size_t)(b->nraw + 1) * sizeof(int));
        int at = prog->ncode;
        for (int j = 0; j < b->nraw; j++) {
            positions[j] = at;
            if (b->raw[j].op != OP_LABEL) at++;
        }
        for (int j = 0; j < b->nraw; j++) {
            RawInstr *r = &b->raw[j];
            if (r->op == OP_LABEL) continue;
            Instr *in = &prog->code[prog->ncode++];
            in->op = (uint8_t)r->op;
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
                in->p = find_class(r->names[0]);
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
            case OP_PUSH_CLASS: case OP_CALL_CONSTRUCTOR:
                in->p = find_class(r->names[0]);
                break;
            case OP_NEW:
                in->p = find_class(r->names[0]);
                in->a = r->ints[1];
                break;
            case OP_CALL_PARENT:
                in->p = find_function(method_key(r->names[0], r->names[1]));
                in->a = r->ints[2];
                break;
            case OP_BIND_PARENT:
                in->p = find_class(r->names[0]);
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
            case OP_DELETE_PATH: case OP_DELETE_PATH_GLOBAL: case OP_DELETE_PATH_CAPTURED: case OP_DELETE_PATH_THIS:
                in->p = r->path;
                in->a = r->ints[1];
                break;
            }
        }
        if (b->kind == B_TOP) {
            prog->code[prog->ncode++].op = OP_HALT;
        }
        free(b->raw);
        b->raw = NULL;
    }
    free(positions);
}

/* ---- The file -------------------------------------------------------------------------- */

Program *load(const char *text, size_t len, const char *path) {
    file_path = str_intern(path, strlen(path));
    {
        char *dir = strdup(path);
        char *slash = strrchr(dir, '/');
        if (!slash) strcpy(dir, ".");
        else if (slash == dir) dir[1] = '\0';
        else *slash = '\0';
        base_dir = absolute_path(dir);
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
    if (strcmp(w.w[2], "1")) fail("Bytecode version %s, but this is GazLang bytecode 1", w.w[2]);
    free_words(&w);

    prog = xcalloc(1, sizeof(Program));
    line = next_line();
    split_words(line ? line : "", &w);
    if (strcmp(w.w[0], "globals")) fail("Expected 'globals'");
    for (int i = 1; i < w.n; i++) prog->globals = push_name(prog->globals, &prog->nglobals, intern(w.w[i]));
    free_words(&w);

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
        if (b->kind == B_CLASS) prog->nclasses++;
        if (b->kind == B_LAMBDA) prog->nlambdas++;
    }
    prog->functions = xcalloc((size_t)prog->nfunctions + 1, sizeof(Function));
    prog->classes = xcalloc((size_t)prog->nclasses + 1, sizeof(Class));
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
            f->value->rc = INT64_MAX / 2;
            f->value->kind = F_NAMED;
            f->value->name = b->name;
            f->value->function = f;
        } else if (b->kind == B_LAMBDA) {
            prog->lambdas[nl++] = (Lambda){b->index, b};
        }
    }
    int nc = prog->nclasses;
    prog->nclasses = 0;
    for (int i = 0; i < prog->nblocks; i++) {
        if (prog->blocks[i]->kind == B_CLASS) prog->classes[prog->nclasses++].name = prog->blocks[i]->name;
    }
    (void)nc;
    for (int i = 0; i < prog->nblocks; i++) {
        if (prog->blocks[i]->kind == B_CLASS) check_record(prog->blocks[i]);
    }
    for (int i = 0; i < prog->nblocks; i++) check_block(prog->blocks[i]);

    build_classes();
    link_program();
    free(lines);
    free(copy);
    return prog;
}
