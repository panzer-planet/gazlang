/*
 * Unicode case mapping for utf8_upper(), utf8_lower() and utf8_fold(): the full mappings of the
 * Unicode Character Database that hold in every language, from tables tools/unicode.php writes
 * (unicode_tables.h), never the C library's, whose answer depends on the locale. Full means a
 * character can become more than one: ß uppercases to SS and folds to ss, İ lowercases to i and
 * a combining dot. The one rule that looks at the text around a character is the final sigma:
 * a capital sigma that ends a word lowercases to ς, anywhere else to σ.
 */

#include "gazvm.h"

/* A character a mapping changes and what it becomes: up to three characters, the unused ones 0 */
typedef struct { uint32_t from; uint32_t to[3]; } CaseMapping;
/* The code points first to last, both included, that have a property */
typedef struct { uint32_t first, last; } CodeRange;

#include "unicode_tables.h"

#define COUNT(table) (sizeof (table) / sizeof (table)[0])
#define CAPITAL_SIGMA 0x03A3
#define FINAL_SIGMA 0x03C2

/* What a mapping does to a code point, or NULL when it leaves it alone; the table is in order */
static const CaseMapping *find_mapping(const CaseMapping *table, size_t count, uint32_t code) {
    size_t low = 0, high = count;
    while (low < high) {
        size_t middle = low + (high - low) / 2;
        if (table[middle].from == code) return &table[middle];
        if (table[middle].from < code) low = middle + 1;
        else high = middle;
    }
    return NULL;
}

static bool in_ranges(const CodeRange *ranges, size_t count, uint32_t code) {
    size_t low = 0, high = count;
    while (low < high) {
        size_t middle = low + (high - low) / 2;
        if (code < ranges[middle].first) high = middle;
        else if (code > ranges[middle].last) low = middle + 1;
        else return true;
    }
    return false;
}

static bool is_cased(uint32_t code) {
    return in_ranges(cased_ranges, COUNT(cased_ranges), code);
}

static bool is_case_ignorable(uint32_t code) {
    return in_ranges(case_ignorable_ranges, COUNT(case_ignorable_ranges), code);
}

/* The code point at text[*at], moving *at past it. The text is well formed, so the lead byte
   says how many continuation bytes follow, and each gives six bits */
static uint32_t decode(const unsigned char *text, size_t *at) {
    unsigned char lead = text[*at];
    if (lead < 0x80) {
        (*at)++;
        return lead;
    }
    size_t followers = lead >= 0xF0 ? 3 : lead >= 0xE0 ? 2 : 1;
    uint32_t code = lead & (0x3F >> followers);   /* 110xxxxx, 1110xxxx or 11110xxx */
    for (size_t i = 1; i <= followers; i++) code = code << 6 | (text[*at + i] & 0x3F);
    *at += followers + 1;
    return code;
}

/* The code point that ends just before text[*at], moving *at back to where it starts: back over
   continuation bytes (10xxxxxx) to the lead */
static uint32_t decode_before(const unsigned char *text, size_t *at) {
    size_t start = *at - 1;
    while ((text[start] & 0xC0) == 0x80) start--;
    *at = start;
    size_t next = start;
    return decode(text, &next);
}

static void encode(Buf *b, uint32_t code) {
    if (code < 0x80) {
        buf_addc(b, (char)code);
    } else if (code < 0x800) {
        char bytes[] = {(char)(0xC0 | code >> 6), (char)(0x80 | (code & 0x3F))};
        buf_add(b, bytes, sizeof bytes);
    } else if (code < 0x10000) {
        char bytes[] = {(char)(0xE0 | code >> 12), (char)(0x80 | (code >> 6 & 0x3F)), (char)(0x80 | (code & 0x3F))};
        buf_add(b, bytes, sizeof bytes);
    } else {
        char bytes[] = {(char)(0xF0 | code >> 18), (char)(0x80 | (code >> 12 & 0x3F)),
                        (char)(0x80 | (code >> 6 & 0x3F)), (char)(0x80 | (code & 0x3F))};
        buf_add(b, bytes, sizeof bytes);
    }
}

/*
 * Whether the capital sigma from text[at] to text[after] ends a word (Final_Sigma in
 * SpecialCasing.txt): a cased character comes before it and none after, case-ignorable
 * characters (apostrophes, combining marks) skipped on both sides. A character that is both
 * cased and case-ignorable, such as a modifier letter ʰ, is skipped. Each side stops at the first
 * character it doesn't skip, so the text between two sigmas is read at most twice.
 */
static bool ends_word(const unsigned char *text, size_t len, size_t at, size_t after) {
    bool cased_before = false;
    while (at > 0) {
        uint32_t code = decode_before(text, &at);
        if (is_case_ignorable(code)) continue;
        cased_before = is_cased(code);
        break;
    }
    if (!cased_before) return false;
    while (after < len) {
        uint32_t code = decode(text, &after);
        if (is_case_ignorable(code)) continue;
        return !is_cased(code);
    }
    return true;
}

static uint32_t ascii_case(uint32_t code, CaseMap map) {
    if (map == CASE_UPPER) return code >= 'a' && code <= 'z' ? code - 32 : code;
    return code >= 'A' && code <= 'Z' ? code + 32 : code;
}

/* Each map's table, by CaseMap */
static const struct { const CaseMapping *mappings; size_t count; } tables[] = {
    [CASE_UPPER] = {upper_mappings, COUNT(upper_mappings)},
    [CASE_LOWER] = {lower_mappings, COUNT(lower_mappings)},
    [CASE_FOLD] = {fold_mappings, COUNT(fold_mappings)},
};

Str *utf8_case(const Str *s, CaseMap map) {
    const unsigned char *text = (const unsigned char *)s->data;
    Buf b = {0};
    size_t at = 0;
    while (at < s->len) {
        size_t start = at;
        uint32_t code = decode(text, &at);
        /* ASCII is most text, and no ASCII letter's mapping leaves ASCII */
        if (code < 0x80) {
            buf_addc(&b, (char)ascii_case(code, map));
            continue;
        }
        if (map == CASE_LOWER && code == CAPITAL_SIGMA && ends_word(text, s->len, start, at)) {
            encode(&b, FINAL_SIGMA);
            continue;
        }
        const CaseMapping *mapping = find_mapping(tables[map].mappings, tables[map].count, code);
        if (!mapping) {
            buf_add(&b, s->data + start, at - start);
            continue;
        }
        for (size_t i = 0; i < 3 && mapping->to[i] != 0; i++) encode(&b, mapping->to[i]);
    }
    return buf_to_str(&b);
}
