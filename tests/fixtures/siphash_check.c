/* Prints siphash13() of the first n bytes of i * 7 + 3, for each n given, under the key k0 k1
   (hex): SipHashTest compares it with CPython's, which hashes bytes the same way. */
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>

uint64_t siphash13(const unsigned char *data, size_t len, uint64_t k0, uint64_t k1);

int main(int argc, char **argv) {
    uint64_t k0 = strtoull(argv[1], NULL, 16), k1 = strtoull(argv[2], NULL, 16);
    unsigned char data[64];
    for (int i = 0; i < 64; i++) data[i] = (unsigned char)(i * 7 + 3);
    for (int i = 3; i < argc; i++) {
        size_t n = (size_t)atoi(argv[i]);
        printf("%s%llu", i > 3 ? "," : "", (unsigned long long)siphash13(data, n, k0, k1));
    }
    printf("\n");
    return 0;
}
