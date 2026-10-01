/* Prints ip_text() of each address given as FAMILY:HEX (4 or 6, then the address's bytes in hex), one
   per line: IpTextTest compares them with Python's ipaddress module. */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "gazvm.h"

int main(int argc, char **argv) {
    for (int i = 1; i < argc; i++) {
        int family = atoi(argv[i]);
        const char *hex = strchr(argv[i], ':') + 1;
        unsigned char bytes[16];
        size_t n = strlen(hex) / 2;
        for (size_t b = 0; b < n; b++) {
            unsigned value;
            sscanf(hex + 2 * b, "%2x", &value);
            bytes[b] = (unsigned char)value;
        }
        char text[IP_TEXT_MAX];
        ip_text(family == 4 ? IP_V4 : IP_V6, bytes, text);
        puts(text);
    }
    return 0;
}
