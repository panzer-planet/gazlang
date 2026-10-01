/* SipHash-1-3, a keyed 64-bit hash (Aumasson and Bernstein, "SipHash: a fast short-input PRF",
   with one compression round and three finalisation rounds). It hashes the names and keys a
   program is given, and the key is drawn for each process, so someone who chooses keys can't
   know which ones land in the same place in a map and make it slow. It is not for secrets, and
   not for anything that must stay the same between runs: the key changes every time. Bytes are
   loaded into words one at a time, so the host's byte order never matters. */
#include "gazvm.h"

#define ROTL(x, b) (((x) << (b)) | ((x) >> (64 - (b))))

#define SIPROUND                                                                              \
    do {                                                                                      \
        v0 += v1; v1 = ROTL(v1, 13); v1 ^= v0; v0 = ROTL(v0, 32);                             \
        v2 += v3; v3 = ROTL(v3, 16); v3 ^= v2;                                                \
        v0 += v3; v3 = ROTL(v3, 21); v3 ^= v0;                                                \
        v2 += v1; v1 = ROTL(v1, 17); v1 ^= v2; v2 = ROTL(v2, 32);                             \
    } while (0)

uint64_t siphash13(const unsigned char *data, size_t len, uint64_t k0, uint64_t k1) {
    uint64_t v0 = k0 ^ 0x736f6d6570736575ULL;
    uint64_t v1 = k1 ^ 0x646f72616e646f6dULL;
    uint64_t v2 = k0 ^ 0x6c7967656e657261ULL;
    uint64_t v3 = k1 ^ 0x7465646279746573ULL;
    size_t whole = len - len % 8;

    for (size_t i = 0; i < whole; i += 8) {
        uint64_t m = 0;
        for (int b = 7; b >= 0; b--) m = m << 8 | data[i + b];
        v3 ^= m;
        SIPROUND;
        v0 ^= m;
    }

    /* The last block: what is left over, and the length's low byte on top */
    uint64_t last = (uint64_t)len << 56;
    for (size_t b = 0; b < len % 8; b++) last |= (uint64_t)data[whole + b] << (8 * b);
    v3 ^= last;
    SIPROUND;
    v0 ^= last;

    v2 ^= 0xff;
    SIPROUND;
    SIPROUND;
    SIPROUND;
    return v0 ^ v1 ^ v2 ^ v3;
}
