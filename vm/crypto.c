/*
 * Cryptography: random bytes from the operating system, SHA-256 (FIPS 180-4), HMAC-SHA256
 * (RFC 2104), and three password hashes, PBKDF2-HMAC-SHA256 (RFC 8018), scrypt (RFC 7914) and
 * Argon2id (RFC 9106, on BLAKE2b from RFC 7693).
 *
 * Written out here from the specifications rather than taken from OpenSSL, so a build without it
 * (make TLS=0) has them too, and every platform gives the same bytes. tests/CryptoTest.php checks
 * each one against the specifications' own test vectors and against independent implementations
 * (PHP's hash extension and libargon2, Python's hashlib, OpenSSL's command line).
 *
 * Secrets (a password, a key, a password hash's working memory) are wiped once used, by wipe():
 * a plain memset of memory that is about to be freed or go out of scope is something the compiler
 * may leave out, since nothing reads what it wrote.
 *
 * Bytes are read and written one at a time into words of a stated order (big-endian for SHA-256,
 * little-endian for the rest), so the host's own byte order never matters.
 */
#include "gazvm.h"

#include <errno.h>
#include <stdlib.h>
#include <string.h>
#include <sys/random.h>
#include <unistd.h>

/* The most bytes random_bytes() and a key derivation give at once: a key, a salt or a token is
   tens of bytes, so more than this is a mistake, and an error says so rather than the program
   running out of memory */
#define MAX_BYTES (1 << 20)
/* The most memory scrypt() and argon2id() may use, 4 GiB: enough for RFC 9106's first
   recommendation (2 GiB), so a stored hash's parameters can't make one ask for terabytes */
#define MAX_MEMORY ((uint64_t)4 << 30)

/* memset called through a volatile pointer: the compiler can't know it is memset, so it can't
   decide the call does nothing useful and drop it */
static void *(*const volatile wipe_memset)(void *, int, size_t) = memset;

static void wipe(void *p, size_t n) { wipe_memset(p, 0, n); }

static uint32_t load32_be(const uint8_t *p) {
    return (uint32_t)p[0] << 24 | (uint32_t)p[1] << 16 | (uint32_t)p[2] << 8 | p[3];
}

static void store32_be(uint8_t *p, uint32_t v) {
    p[0] = (uint8_t)(v >> 24);
    p[1] = (uint8_t)(v >> 16);
    p[2] = (uint8_t)(v >> 8);
    p[3] = (uint8_t)v;
}

static uint32_t load32_le(const uint8_t *p) {
    return (uint32_t)p[0] | (uint32_t)p[1] << 8 | (uint32_t)p[2] << 16 | (uint32_t)p[3] << 24;
}

static void store32_le(uint8_t *p, uint32_t v) {
    p[0] = (uint8_t)v;
    p[1] = (uint8_t)(v >> 8);
    p[2] = (uint8_t)(v >> 16);
    p[3] = (uint8_t)(v >> 24);
}

static uint64_t load64_le(const uint8_t *p) { return (uint64_t)load32_le(p) | (uint64_t)load32_le(p + 4) << 32; }

static void store64_le(uint8_t *p, uint64_t v) {
    store32_le(p, (uint32_t)v);
    store32_le(p + 4, (uint32_t)(v >> 32));
}

static uint32_t rotr32(uint32_t x, int n) { return (x >> n) | (x << (32 - n)); }
static uint32_t rotl32(uint32_t x, int n) { return (x << n) | (x >> (32 - n)); }
static uint64_t rotr64(uint64_t x, int n) { return (x >> n) | (x << (64 - n)); }

/* A new string of `len` bytes for a digest or key to be written into */
static Str *bytes_new(size_t len) {
    Str *s = str_empty(len);
    s->len = len;
    s->data[len] = '\0';
    return s;
}

/* "%s() expects a length from 1 to 1048576, got 0" */
static bool check_length(const char *name, int64_t length, int64_t min) {
    if (length >= min && length <= MAX_BYTES) return true;
    return raisef("%s() expects a length from %lld to %d, got %lld", name, (long long)min, MAX_BYTES, (long long)length);
}

/* ---- Random bytes ---------------------------------------------------------------------- */

/* random_bytes($length): from getentropy(), the system's generator, which gives 256 bytes at most
   a call */
bool crypto_random_bytes(int64_t length, Value *out) {
    if (length < 0 || length > MAX_BYTES) {
        return raisef("random_bytes() expects a length from 0 to %d, got %lld", MAX_BYTES, (long long)length);
    }
    Str *s = bytes_new((size_t)length);
    for (size_t done = 0; done < s->len; done += 256) {
        size_t n = s->len - done < 256 ? s->len - done : 256;
        if (getentropy(s->data + done, n) != 0) {
            int err = errno;
            decref(v_str(s));
            return raisef("random_bytes() cannot get random bytes: %s", strerror(err));
        }
    }
    *out = v_str(s);
    return true;
}

/* ---- SHA-256 (FIPS 180-4) -------------------------------------------------------------- */

typedef struct {
    uint32_t h[8];          /* the state */
    uint64_t length;        /* bytes taken in so far */
    uint8_t block[64];      /* a block being filled */
    size_t used;            /* how much of it */
} Sha256;

/* The first 32 bits of the fractional parts of the cube roots of the first 64 primes */
static const uint32_t SHA256_K[64] = {
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
};

static void sha256_compress(uint32_t h[8], const uint8_t block[64]) {
    uint32_t w[64];
    for (int i = 0; i < 16; i++) w[i] = load32_be(block + 4 * i);
    for (int i = 16; i < 64; i++) {
        uint32_t s0 = rotr32(w[i - 15], 7) ^ rotr32(w[i - 15], 18) ^ (w[i - 15] >> 3);
        uint32_t s1 = rotr32(w[i - 2], 17) ^ rotr32(w[i - 2], 19) ^ (w[i - 2] >> 10);
        w[i] = w[i - 16] + s0 + w[i - 7] + s1;
    }
    uint32_t a = h[0], b = h[1], c = h[2], d = h[3], e = h[4], f = h[5], g = h[6], hh = h[7];
    for (int i = 0; i < 64; i++) {
        uint32_t s1 = rotr32(e, 6) ^ rotr32(e, 11) ^ rotr32(e, 25);
        uint32_t choice = (e & f) ^ (~e & g);
        uint32_t t1 = hh + s1 + choice + SHA256_K[i] + w[i];
        uint32_t s0 = rotr32(a, 2) ^ rotr32(a, 13) ^ rotr32(a, 22);
        uint32_t majority = (a & b) ^ (a & c) ^ (b & c);
        uint32_t t2 = s0 + majority;
        hh = g;
        g = f;
        f = e;
        e = d + t1;
        d = c;
        c = b;
        b = a;
        a = t1 + t2;
    }
    h[0] += a;
    h[1] += b;
    h[2] += c;
    h[3] += d;
    h[4] += e;
    h[5] += f;
    h[6] += g;
    h[7] += hh;
}

static void sha256_init(Sha256 *c) {
    static const uint32_t start[8] = {
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
    };
    memcpy(c->h, start, sizeof start);
    c->length = 0;
    c->used = 0;
}

static void sha256_update(Sha256 *c, const uint8_t *data, size_t len) {
    c->length += len;
    while (len > 0) {
        size_t n = 64 - c->used < len ? 64 - c->used : len;
        memcpy(c->block + c->used, data, n);
        c->used += n;
        data += n;
        len -= n;
        if (c->used == 64) {
            sha256_compress(c->h, c->block);
            c->used = 0;
        }
    }
}

/* The padding: a 1 bit, zeros up to 8 bytes short of a block, then the length in bits */
static void sha256_final(Sha256 *c, uint8_t out[32]) {
    uint64_t bits = c->length * 8;
    c->block[c->used++] = 0x80;
    if (c->used > 56) {
        memset(c->block + c->used, 0, 64 - c->used);
        sha256_compress(c->h, c->block);
        c->used = 0;
    }
    memset(c->block + c->used, 0, 56 - c->used);
    store32_be(c->block + 56, (uint32_t)(bits >> 32));
    store32_be(c->block + 60, (uint32_t)bits);
    sha256_compress(c->h, c->block);
    for (int i = 0; i < 8; i++) store32_be(out + 4 * i, c->h[i]);
    wipe(c, sizeof *c);
}

Value crypto_sha256(Str *data) {
    Sha256 c;
    Str *s = bytes_new(32);
    sha256_init(&c);
    sha256_update(&c, (const uint8_t *)data->data, data->len);
    sha256_final(&c, (uint8_t *)s->data);
    return v_str(s);
}

/* ---- HMAC-SHA256 (RFC 2104) ------------------------------------------------------------ */

/* A keyed hash: the inner and outer hashes with the padded key already taken in, so a copy of
   one is where every message under that key starts (which is what makes PBKDF2's iterations
   two compressions each) */
typedef struct {
    Sha256 inner, outer;
} Hmac;

static void hmac_init(Hmac *m, const uint8_t *key, size_t len) {
    uint8_t block[64] = {0}, pad[64];
    if (len > 64) {
        /* A key longer than a block is hashed first */
        Sha256 c;
        sha256_init(&c);
        sha256_update(&c, key, len);
        sha256_final(&c, block);
    } else if (len > 0) {
        memcpy(block, key, len);
    }
    for (int i = 0; i < 64; i++) pad[i] = block[i] ^ 0x36;
    sha256_init(&m->inner);
    sha256_update(&m->inner, pad, 64);
    for (int i = 0; i < 64; i++) pad[i] = block[i] ^ 0x5c;
    sha256_init(&m->outer);
    sha256_update(&m->outer, pad, 64);
    wipe(block, sizeof block);
    wipe(pad, sizeof pad);
}

static void hmac_final(Hmac *m, uint8_t out[32]) {
    uint8_t inner[32];
    sha256_final(&m->inner, inner);
    sha256_update(&m->outer, inner, 32);
    sha256_final(&m->outer, out);
    wipe(inner, sizeof inner);
}

Value crypto_hmac_sha256(Str *data, Str *key) {
    Hmac m;
    Str *s = bytes_new(32);
    hmac_init(&m, (const uint8_t *)key->data, key->len);
    sha256_update(&m.inner, (const uint8_t *)data->data, data->len);
    hmac_final(&m, (uint8_t *)s->data);
    return v_str(s);
}

/* ---- PBKDF2-HMAC-SHA256 (RFC 8018, section 5.2) ---------------------------------------- */

/* Each 32 bytes of output is block i: U1 = HMAC(password, salt || i as 4 bytes big-endian), each
   next U the HMAC of the one before, and the block all of them XORed together */
static void pbkdf2(const uint8_t *password, size_t password_len, const uint8_t *salt, size_t salt_len,
                   uint64_t iterations, uint8_t *out, size_t length) {
    Hmac keyed, m;
    uint8_t u[32], t[32], counter[4];
    hmac_init(&keyed, password, password_len);
    for (uint32_t block = 1; length > 0; block++) {
        m = keyed;
        sha256_update(&m.inner, salt, salt_len);
        store32_be(counter, block);
        sha256_update(&m.inner, counter, 4);
        hmac_final(&m, u);
        memcpy(t, u, 32);
        for (uint64_t j = 1; j < iterations; j++) {
            m = keyed;
            sha256_update(&m.inner, u, 32);
            hmac_final(&m, u);
            for (int k = 0; k < 32; k++) t[k] ^= u[k];
        }
        size_t n = length < 32 ? length : 32;
        memcpy(out, t, n);
        out += n;
        length -= n;
    }
    wipe(&keyed, sizeof keyed);
    wipe(&m, sizeof m);
    wipe(u, sizeof u);
    wipe(t, sizeof t);
}

bool crypto_pbkdf2_sha256(Str *password, Str *salt, int64_t iterations, int64_t length, Value *out) {
    if (iterations < 1 || iterations > UINT32_MAX) {
        return raisef("pbkdf2_sha256() expects 1 to %lu iterations, got %lld", (unsigned long)UINT32_MAX, (long long)iterations);
    }
    if (!check_length("pbkdf2_sha256", length, 1)) return false;
    Str *s = bytes_new((size_t)length);
    pbkdf2((const uint8_t *)password->data, password->len, (const uint8_t *)salt->data, salt->len,
           (uint64_t)iterations, (uint8_t *)s->data, s->len);
    *out = v_str(s);
    return true;
}

/* ---- scrypt (RFC 7914) ----------------------------------------------------------------- */

/* Salsa20/8's core (section 3): four double rounds over sixteen words, then the input added */
static void salsa20_8(uint32_t b[16]) {
    uint32_t x[16];
    memcpy(x, b, sizeof x);
    for (int i = 0; i < 8; i += 2) {
        /* the columns */
        x[4] ^= rotl32(x[0] + x[12], 7);
        x[8] ^= rotl32(x[4] + x[0], 9);
        x[12] ^= rotl32(x[8] + x[4], 13);
        x[0] ^= rotl32(x[12] + x[8], 18);
        x[9] ^= rotl32(x[5] + x[1], 7);
        x[13] ^= rotl32(x[9] + x[5], 9);
        x[1] ^= rotl32(x[13] + x[9], 13);
        x[5] ^= rotl32(x[1] + x[13], 18);
        x[14] ^= rotl32(x[10] + x[6], 7);
        x[2] ^= rotl32(x[14] + x[10], 9);
        x[6] ^= rotl32(x[2] + x[14], 13);
        x[10] ^= rotl32(x[6] + x[2], 18);
        x[3] ^= rotl32(x[15] + x[11], 7);
        x[7] ^= rotl32(x[3] + x[15], 9);
        x[11] ^= rotl32(x[7] + x[3], 13);
        x[15] ^= rotl32(x[11] + x[7], 18);
        /* the rows */
        x[1] ^= rotl32(x[0] + x[3], 7);
        x[2] ^= rotl32(x[1] + x[0], 9);
        x[3] ^= rotl32(x[2] + x[1], 13);
        x[0] ^= rotl32(x[3] + x[2], 18);
        x[6] ^= rotl32(x[5] + x[4], 7);
        x[7] ^= rotl32(x[6] + x[5], 9);
        x[4] ^= rotl32(x[7] + x[6], 13);
        x[5] ^= rotl32(x[4] + x[7], 18);
        x[11] ^= rotl32(x[10] + x[9], 7);
        x[8] ^= rotl32(x[11] + x[10], 9);
        x[9] ^= rotl32(x[8] + x[11], 13);
        x[10] ^= rotl32(x[9] + x[8], 18);
        x[12] ^= rotl32(x[15] + x[14], 7);
        x[13] ^= rotl32(x[12] + x[15], 9);
        x[14] ^= rotl32(x[13] + x[12], 13);
        x[15] ^= rotl32(x[14] + x[13], 18);
    }
    for (int i = 0; i < 16; i++) b[i] += x[i];
}

/* scryptBlockMix (section 4): 2r blocks of 16 words in, each XORed into a running block that
   Salsa20/8 mixes; the results go out even ones first, then odd ones */
static void block_mix(const uint32_t *in, uint32_t *out, size_t r) {
    uint32_t x[16];
    memcpy(x, in + (2 * r - 1) * 16, sizeof x);
    for (size_t i = 0; i < 2 * r; i++) {
        for (size_t k = 0; k < 16; k++) x[k] ^= in[i * 16 + k];
        salsa20_8(x);
        size_t to = i % 2 == 0 ? i / 2 : r + i / 2;
        memcpy(out + to * 16, x, sizeof x);
    }
}

/* scryptROMix (section 5) on one 128r-byte block: fill v with n successive mixes, then mix n
   times more with the entry the running block's last 64-byte piece picks. n is a power of 2, so
   "mod n" is a mask. */
static void ro_mix(uint8_t *block, size_t r, uint64_t n, uint32_t *v, uint32_t *x, uint32_t *y) {
    size_t words = 32 * r;
    for (size_t k = 0; k < words; k++) x[k] = load32_le(block + 4 * k);
    for (uint64_t i = 0; i < n; i++) {
        memcpy(v + i * words, x, words * 4);
        block_mix(x, y, r);
        memcpy(x, y, words * 4);
    }
    for (uint64_t i = 0; i < n; i++) {
        /* Integerify: the last piece's first 8 bytes, little-endian */
        uint64_t j = ((uint64_t)x[(2 * r - 1) * 16] | (uint64_t)x[(2 * r - 1) * 16 + 1] << 32) & (n - 1);
        for (size_t k = 0; k < words; k++) x[k] ^= v[j * words + k];
        block_mix(x, y, r);
        memcpy(x, y, words * 4);
    }
    for (size_t k = 0; k < words; k++) store32_le(block + 4 * k, x[k]);
}

/* scrypt($password, $salt, $cost, $block_size, $parallelism, $length): PBKDF2 with one iteration
   spreads the password over p blocks of 128r bytes, ROMix works each through 128rN bytes of
   memory, and PBKDF2 with one iteration again, salted with the result, gives the key */
bool crypto_scrypt(Str *password, Str *salt, int64_t n, int64_t r, int64_t p, int64_t length, Value *out) {
    if (n < 2 || (n & (n - 1)) != 0) {
        return raisef("scrypt() expects a cost that is a power of 2 greater than 1, got %lld", (long long)n);
    }
    if (r < 1) return raisef("scrypt() expects a block size of 1 or more, got %lld", (long long)r);
    if (p < 1) return raisef("scrypt() expects a parallelism of 1 or more, got %lld", (long long)p);
    /* RFC 7914's N < 2^(128 * r / 8), which only a block size below 4 can reach */
    if (r < 4 && (uint64_t)n >= (uint64_t)1 << (16 * r)) {
        return raisef("scrypt() expects a cost below 2 ** (16 * block size), got %lld with a block size of %lld", (long long)n, (long long)r);
    }
    /* 128r bytes for each of the n entries of v and each of the p blocks, and two for x and y;
       each step is checked before it is multiplied, so nothing wraps around */
    uint64_t block = 128 * (uint64_t)r;
    if ((uint64_t)r > MAX_MEMORY / 128 || (uint64_t)n + (uint64_t)p + 2 > MAX_MEMORY / block) {
        return raisef("scrypt() would use more than %llu bytes of memory: 128 * block size * (cost + parallelism + 2)",
                      (unsigned long long)MAX_MEMORY);
    }
    if (!check_length("scrypt", length, 1)) return false;
    /* Below MAX_MEMORY, but a 32-bit size_t can't hold 4 GiB: the sizes are checked against it
       too, rather than cast down and wrapped to a small allocation that is then overrun */
    uint64_t total = block * ((uint64_t)n + (uint64_t)p + 2);
    if (total > SIZE_MAX) {
        return raisef("scrypt() would use %llu bytes of memory, more than this system can address", (unsigned long long)total);
    }
    size_t v_size = (size_t)(block * (uint64_t)n), b_size = (size_t)(block * (uint64_t)p), xy_size = (size_t)(2 * block);
    uint32_t *v = malloc(v_size);
    uint8_t *b = malloc(b_size);
    uint32_t *xy = malloc(xy_size);
    if (!v || !b || !xy) {
        free(v);
        free(b);
        free(xy);
        return raisef("scrypt() cannot allocate %llu bytes of memory", (unsigned long long)total);
    }
    const uint8_t *pw = (const uint8_t *)password->data;
    pbkdf2(pw, password->len, (const uint8_t *)salt->data, salt->len, 1, b, b_size);
    for (int64_t i = 0; i < p; i++) ro_mix(b + (size_t)i * block, (size_t)r, (uint64_t)n, v, xy, xy + block / 4);
    Str *s = bytes_new((size_t)length);
    pbkdf2(pw, password->len, b, b_size, 1, (uint8_t *)s->data, s->len);
    wipe(v, v_size);
    wipe(b, b_size);
    wipe(xy, xy_size);
    free(v);
    free(b);
    free(xy);
    *out = v_str(s);
    return true;
}

/* ---- BLAKE2b (RFC 7693), for Argon2 ---------------------------------------------------- */

typedef struct {
    uint64_t h[8];
    uint64_t count;         /* bytes compressed so far (the low half of the RFC's 128-bit t) */
    uint8_t block[128];
    size_t used;
    size_t out_len;
} Blake2b;

/* SHA-512's starting state, which BLAKE2b shares */
static const uint64_t BLAKE2B_IV[8] = {
    0x6a09e667f3bcc908, 0xbb67ae8584caa73b, 0x3c6ef372fe94f82b, 0xa54ff53a5f1d36f1,
    0x510e527fade682d1, 0x9b05688c2b3e6c1f, 0x1f83d9abfb41bd6b, 0x5be0cd19137e2179,
};

/* Which message words each round's G steps take; rounds 10 and 11 repeat 0 and 1 */
static const uint8_t BLAKE2B_SIGMA[12][16] = {
    {0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15},
    {14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3},
    {11, 8, 12, 0, 5, 2, 15, 13, 10, 14, 3, 6, 7, 1, 9, 4},
    {7, 9, 3, 1, 13, 12, 11, 14, 2, 6, 5, 10, 4, 0, 15, 8},
    {9, 0, 5, 7, 2, 4, 10, 15, 14, 1, 11, 12, 6, 8, 3, 13},
    {2, 12, 6, 10, 0, 11, 8, 3, 4, 13, 7, 5, 15, 14, 1, 9},
    {12, 5, 1, 15, 14, 13, 4, 10, 0, 7, 6, 3, 9, 2, 8, 11},
    {13, 11, 7, 14, 12, 1, 3, 9, 5, 0, 15, 4, 8, 6, 2, 10},
    {6, 15, 14, 9, 11, 3, 0, 8, 12, 2, 13, 7, 1, 4, 10, 5},
    {10, 2, 8, 4, 7, 6, 1, 5, 15, 11, 9, 14, 3, 12, 13, 0},
    {0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15},
    {14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3},
};

/* The mixing function G (section 3.1) on four of v's words and two message words */
static void blake2b_g(uint64_t *v, int a, int b, int c, int d, uint64_t x, uint64_t y) {
    v[a] = v[a] + v[b] + x;
    v[d] = rotr64(v[d] ^ v[a], 32);
    v[c] = v[c] + v[d];
    v[b] = rotr64(v[b] ^ v[c], 24);
    v[a] = v[a] + v[b] + y;
    v[d] = rotr64(v[d] ^ v[a], 16);
    v[c] = v[c] + v[d];
    v[b] = rotr64(v[b] ^ v[c], 63);
}

static void blake2b_compress(Blake2b *c, bool last) {
    uint64_t v[16], m[16];
    for (int i = 0; i < 8; i++) {
        v[i] = c->h[i];
        v[i + 8] = BLAKE2B_IV[i];
    }
    v[12] ^= c->count;
    if (last) v[14] = ~v[14];
    for (int i = 0; i < 16; i++) m[i] = load64_le(c->block + 8 * i);
    for (int round = 0; round < 12; round++) {
        const uint8_t *s = BLAKE2B_SIGMA[round];
        blake2b_g(v, 0, 4, 8, 12, m[s[0]], m[s[1]]);
        blake2b_g(v, 1, 5, 9, 13, m[s[2]], m[s[3]]);
        blake2b_g(v, 2, 6, 10, 14, m[s[4]], m[s[5]]);
        blake2b_g(v, 3, 7, 11, 15, m[s[6]], m[s[7]]);
        blake2b_g(v, 0, 5, 10, 15, m[s[8]], m[s[9]]);
        blake2b_g(v, 1, 6, 11, 12, m[s[10]], m[s[11]]);
        blake2b_g(v, 2, 7, 8, 13, m[s[12]], m[s[13]]);
        blake2b_g(v, 3, 4, 9, 14, m[s[14]], m[s[15]]);
    }
    for (int i = 0; i < 8; i++) c->h[i] ^= v[i] ^ v[i + 8];
    wipe(m, sizeof m);
}

/* Unkeyed, with an output of 1 to 64 bytes, which the parameter block's first word carries */
static void blake2b_init(Blake2b *c, size_t out_len) {
    memcpy(c->h, BLAKE2B_IV, sizeof c->h);
    c->h[0] ^= 0x01010000 ^ (uint64_t)out_len;
    c->count = 0;
    c->used = 0;
    c->out_len = out_len;
}

/* A full block is compressed only once more input comes, since the last block, full or not, is
   compressed with the final flag */
static void blake2b_update(Blake2b *c, const uint8_t *data, size_t len) {
    while (len > 0) {
        if (c->used == 128) {
            c->count += 128;
            blake2b_compress(c, false);
            c->used = 0;
        }
        size_t n = 128 - c->used < len ? 128 - c->used : len;
        memcpy(c->block + c->used, data, n);
        c->used += n;
        data += n;
        len -= n;
    }
}

static void blake2b_final(Blake2b *c, uint8_t *out) {
    uint8_t h[64];
    c->count += c->used;
    memset(c->block + c->used, 0, 128 - c->used);
    blake2b_compress(c, true);
    for (int i = 0; i < 8; i++) store64_le(h + 8 * i, c->h[i]);
    memcpy(out, h, c->out_len);
    wipe(h, sizeof h);
    wipe(c, sizeof *c);
}

static void blake2b(uint8_t *out, size_t out_len, const uint8_t *in, size_t in_len) {
    Blake2b c;
    blake2b_init(&c, out_len);
    blake2b_update(&c, in, in_len);
    blake2b_final(&c, out);
}

/* The variable-length hash H' (RFC 9106, section 3.3): up to 64 bytes it is BLAKE2b of the length
   and the input; longer, a chain of 64-byte hashes, each giving its first 32 bytes, and a last
   one as long as what is left */
static void blake2b_long(uint8_t *out, size_t out_len, const uint8_t *in, size_t in_len) {
    uint8_t prefix[4], v[64];
    Blake2b c;
    store32_le(prefix, (uint32_t)out_len);
    blake2b_init(&c, out_len <= 64 ? out_len : 64);
    blake2b_update(&c, prefix, 4);
    blake2b_update(&c, in, in_len);
    if (out_len <= 64) {
        blake2b_final(&c, out);
        return;
    }
    blake2b_final(&c, v);
    memcpy(out, v, 32);
    out += 32;
    size_t left = out_len - 32;
    while (left > 64) {
        blake2b(v, 64, v, 64);
        memcpy(out, v, 32);
        out += 32;
        left -= 32;
    }
    blake2b(out, left, v, 64);
    wipe(v, sizeof v);
}

/* ---- Argon2id (RFC 9106) --------------------------------------------------------------- */

#define ARGON2_BLOCK_WORDS 128      /* a block is 1 KiB: 128 words of 64 bits */
#define ARGON2_SYNC_POINTS 4        /* slices per pass: lanes only reference finished slices */
#define ARGON2_VERSION 0x13
#define ARGON2_ID 2                 /* the type y: 0 is Argon2d, 1 Argon2i, 2 Argon2id */

typedef struct {
    uint64_t v[ARGON2_BLOCK_WORDS];
} ArgonBlock;

/* Everything a segment's filling needs to know */
typedef struct {
    ArgonBlock *memory;
    uint32_t passes, lanes, lane_length, segment_length, blocks;
} Argon2;

static uint64_t blamka(uint64_t x, uint64_t y) { return x + y + 2 * (uint64_t)(uint32_t)x * (uint32_t)y; }

/* GB (section 3.6): BLAKE2b's G with the additions made harder by a multiplication, and no
   message words */
static void argon2_gb(uint64_t *v, int a, int b, int c, int d) {
    v[a] = blamka(v[a], v[b]);
    v[d] = rotr64(v[d] ^ v[a], 32);
    v[c] = blamka(v[c], v[d]);
    v[b] = rotr64(v[b] ^ v[c], 24);
    v[a] = blamka(v[a], v[b]);
    v[d] = rotr64(v[d] ^ v[a], 16);
    v[c] = blamka(v[c], v[d]);
    v[b] = rotr64(v[b] ^ v[c], 63);
}

/* The permutation P on sixteen of a block's words, found at the positions `at` gives */
static void argon2_permute(uint64_t *block, const int at[16]) {
    uint64_t v[16];
    for (int i = 0; i < 16; i++) v[i] = block[at[i]];
    argon2_gb(v, 0, 4, 8, 12);
    argon2_gb(v, 1, 5, 9, 13);
    argon2_gb(v, 2, 6, 10, 14);
    argon2_gb(v, 3, 7, 11, 15);
    argon2_gb(v, 0, 5, 10, 15);
    argon2_gb(v, 1, 6, 11, 12);
    argon2_gb(v, 2, 7, 8, 13);
    argon2_gb(v, 3, 4, 9, 14);
    for (int i = 0; i < 16; i++) block[at[i]] = v[i];
}

/* The compression function G (section 3.5): R = X xor Y seen as an 8 by 8 matrix of 16-byte
   registers, P applied to each row and then each column, and the result XORed with R. From the
   second pass on (version 0x13) the new block is also XORed into the one it overwrites. */
static void argon2_fill(const ArgonBlock *prev, const ArgonBlock *ref, ArgonBlock *next, bool with_xor) {
    ArgonBlock r, keep;
    int at[16];
    for (int i = 0; i < ARGON2_BLOCK_WORDS; i++) r.v[i] = prev->v[i] ^ ref->v[i];
    keep = r;
    if (with_xor) {
        for (int i = 0; i < ARGON2_BLOCK_WORDS; i++) keep.v[i] ^= next->v[i];
    }
    /* Row i is words 16i to 16i + 15 */
    for (int row = 0; row < 8; row++) {
        for (int k = 0; k < 16; k++) at[k] = 16 * row + k;
        argon2_permute(r.v, at);
    }
    /* Column i is the register at 2i (two words) in each of the eight rows */
    for (int col = 0; col < 8; col++) {
        for (int k = 0; k < 16; k++) at[k] = 2 * col + (k / 2) * 16 + k % 2;
        argon2_permute(r.v, at);
    }
    for (int i = 0; i < ARGON2_BLOCK_WORDS; i++) next->v[i] = keep.v[i] ^ r.v[i];
}

/* Data-independent addressing (section 3.4.1.2): the next 128 pseudo-random words, G applied
   twice to the zero block and the counter block */
static void argon2_next_addresses(ArgonBlock *addresses, ArgonBlock *input, const ArgonBlock *zero) {
    input->v[6]++;
    argon2_fill(zero, input, addresses, false);
    argon2_fill(zero, addresses, addresses, false);
}

/* Which block of the reference lane a pseudo-random 32 bits pick (section 3.4.2): from the
   blocks that are finished and aren't the one just before, the most recent ones likeliest */
static uint32_t argon2_reference_index(const Argon2 *a, uint32_t pass, uint32_t slice, uint32_t index,
                                       uint32_t random, bool same_lane) {
    uint32_t area;
    if (pass == 0) {
        if (slice == 0) area = index - 1;
        else if (same_lane) area = slice * a->segment_length + index - 1;
        else area = slice * a->segment_length - (index == 0 ? 1 : 0);
    } else {
        if (same_lane) area = a->lane_length - a->segment_length + index - 1;
        else area = a->lane_length - a->segment_length - (index == 0 ? 1 : 0);
    }
    uint64_t x = (uint64_t)random * random >> 32;
    uint64_t relative = area - 1 - ((uint64_t)area * x >> 32);
    uint32_t start = 0;
    if (pass != 0 && slice != ARGON2_SYNC_POINTS - 1) start = (slice + 1) * a->segment_length;
    return (uint32_t)((start + relative) % a->lane_length);
}

/* One lane's segment of one slice of one pass. Argon2id takes its references from the address
   blocks (as Argon2i) in the first half of the first pass, and from the previous block's first
   word (as Argon2d) after that. */
static void argon2_fill_segment(Argon2 *a, uint32_t pass, uint32_t slice, uint32_t lane) {
    bool independent = pass == 0 && slice < ARGON2_SYNC_POINTS / 2;
    ArgonBlock zero = {{0}}, input = {{0}}, addresses = {{0}};
    if (independent) {
        input.v[0] = pass;
        input.v[1] = lane;
        input.v[2] = slice;
        input.v[3] = a->blocks;
        input.v[4] = a->passes;
        input.v[5] = ARGON2_ID;
    }
    /* The first two blocks of each lane were made from H0 */
    uint32_t start = 0;
    if (pass == 0 && slice == 0) {
        start = 2;
        if (independent) argon2_next_addresses(&addresses, &input, &zero);
    }
    uint32_t current = lane * a->lane_length + slice * a->segment_length + start;
    uint32_t previous = current % a->lane_length == 0 ? current + a->lane_length - 1 : current - 1;
    for (uint32_t i = start; i < a->segment_length; i++, current++, previous++) {
        if (current % a->lane_length == 1) previous = current - 1;
        uint64_t random;
        if (independent) {
            if (i % ARGON2_BLOCK_WORDS == 0) argon2_next_addresses(&addresses, &input, &zero);
            random = addresses.v[i % ARGON2_BLOCK_WORDS];
        } else {
            random = a->memory[previous].v[0];
        }
        uint32_t ref_lane = (uint32_t)((random >> 32) % a->lanes);
        if (pass == 0 && slice == 0) ref_lane = lane;
        uint32_t ref_index = argon2_reference_index(a, pass, slice, i, (uint32_t)random, ref_lane == lane);
        argon2_fill(&a->memory[previous], &a->memory[ref_lane * a->lane_length + ref_index], &a->memory[current], pass > 0);
    }
    wipe(&addresses, sizeof addresses);
}

static void argon2_block_from_bytes(ArgonBlock *b, const uint8_t *bytes) {
    for (int i = 0; i < ARGON2_BLOCK_WORDS; i++) b->v[i] = load64_le(bytes + 8 * i);
}

static void argon2_block_to_bytes(uint8_t *bytes, const ArgonBlock *b) {
    for (int i = 0; i < ARGON2_BLOCK_WORDS; i++) store64_le(bytes + 8 * i, b->v[i]);
}

static void add_le32(Blake2b *c, uint32_t n) {
    uint8_t bytes[4];
    store32_le(bytes, n);
    blake2b_update(c, bytes, 4);
}

/* A length and the bytes, as H0 takes each variable-length input */
static void add_counted(Blake2b *c, const Str *s) {
    add_le32(c, (uint32_t)s->len);
    blake2b_update(c, (const uint8_t *)s->data, s->len);
}

/* The most memory argon2id() may use, in KiB, and so the most lanes: each needs 8 KiB (RFC 9106
   allows 2^24 - 1 lanes, but only this many can be given their memory under the cap) */
#define ARGON2_MOST_MEMORY ((int64_t)(MAX_MEMORY / 1024))
#define ARGON2_MOST_LANES (ARGON2_MOST_MEMORY / 8)

/* argon2id($password, $salt, $passes, $memory, $lanes, $length, $secret, $data), with the checks
   of section 3.1; the memory is in KiB, rounded down to a multiple of 4 * lanes */
bool crypto_argon2id(Str *password, Str *salt, int64_t passes, int64_t memory, int64_t lanes, int64_t length,
                     Str *secret, Str *data, Value *out) {
    if (password->len > UINT32_MAX || secret->len > UINT32_MAX || data->len > UINT32_MAX) {
        return raisef("argon2id() expects a password, secret and data of at most %lu bytes each", (unsigned long)UINT32_MAX);
    }
    if (salt->len < 8 || salt->len > UINT32_MAX) {
        return raisef("argon2id() expects a salt of 8 bytes or more, got %llu", (unsigned long long)salt->len);
    }
    if (passes < 1 || passes > UINT32_MAX) {
        return raisef("argon2id() expects 1 to %lu passes, got %lld", (unsigned long)UINT32_MAX, (long long)passes);
    }
    if (lanes < 1 || lanes > ARGON2_MOST_LANES) {
        return raisef("argon2id() expects 1 to %lld lanes, got %lld", (long long)ARGON2_MOST_LANES, (long long)lanes);
    }
    if (memory < 8 * lanes || memory > ARGON2_MOST_MEMORY) {
        return raisef("argon2id() expects a memory from %lld KiB (8 per lane) to %lld KiB, got %lld",
                      (long long)(8 * lanes), (long long)ARGON2_MOST_MEMORY, (long long)memory);
    }
    if (!check_length("argon2id", length, 4)) return false;

    Argon2 a;
    a.passes = (uint32_t)passes;
    a.lanes = (uint32_t)lanes;
    a.segment_length = (uint32_t)memory / (ARGON2_SYNC_POINTS * a.lanes);
    a.lane_length = a.segment_length * ARGON2_SYNC_POINTS;
    a.blocks = a.lane_length * a.lanes;
    /* 4 GiB is 2^22 blocks, whose bytes a 32-bit size_t can't count */
    uint64_t memory_bytes = (uint64_t)a.blocks * sizeof(ArgonBlock);
    if (memory_bytes > SIZE_MAX) {
        return raisef("argon2id() would use %llu bytes of memory, more than this system can address", (unsigned long long)memory_bytes);
    }
    size_t size = (size_t)memory_bytes;
    a.memory = malloc(size);
    if (!a.memory) {
        return raisef("argon2id() cannot allocate %llu bytes of memory", (unsigned long long)memory_bytes);
    }

    /* H0 (section 3.2): every parameter and input, hashed to 64 bytes, then 8 more bytes for the
       block number and lane that each lane's first two blocks append */
    uint8_t h0[72], bytes[ARGON2_BLOCK_WORDS * 8];
    Blake2b c;
    blake2b_init(&c, 64);
    add_le32(&c, a.lanes);
    add_le32(&c, (uint32_t)length);
    add_le32(&c, (uint32_t)memory);
    add_le32(&c, a.passes);
    add_le32(&c, ARGON2_VERSION);
    add_le32(&c, ARGON2_ID);
    add_counted(&c, password);
    add_counted(&c, salt);
    add_counted(&c, secret);
    add_counted(&c, data);
    blake2b_final(&c, h0);
    for (uint32_t lane = 0; lane < a.lanes; lane++) {
        for (uint32_t j = 0; j < 2; j++) {
            store32_le(h0 + 64, j);
            store32_le(h0 + 68, lane);
            blake2b_long(bytes, sizeof bytes, h0, sizeof h0);
            argon2_block_from_bytes(&a.memory[lane * a.lane_length + j], bytes);
        }
    }

    /* Slice by slice, each lane's segment in turn: a lane only reads other lanes' finished
       slices, so this order gives what lanes filled at once would */
    for (uint32_t pass = 0; pass < a.passes; pass++) {
        for (uint32_t slice = 0; slice < ARGON2_SYNC_POINTS; slice++) {
            for (uint32_t lane = 0; lane < a.lanes; lane++) argon2_fill_segment(&a, pass, slice, lane);
        }
    }

    /* The tag: H' of every lane's last block XORed together */
    ArgonBlock last = a.memory[a.lane_length - 1];
    for (uint32_t lane = 1; lane < a.lanes; lane++) {
        const ArgonBlock *b = &a.memory[lane * a.lane_length + a.lane_length - 1];
        for (int i = 0; i < ARGON2_BLOCK_WORDS; i++) last.v[i] ^= b->v[i];
    }
    argon2_block_to_bytes(bytes, &last);
    Str *s = bytes_new((size_t)length);
    blake2b_long((uint8_t *)s->data, s->len, bytes, sizeof bytes);

    wipe(a.memory, size);
    free(a.memory);
    wipe(h0, sizeof h0);
    wipe(bytes, sizeof bytes);
    wipe(&last, sizeof last);
    *out = v_str(s);
    return true;
}
