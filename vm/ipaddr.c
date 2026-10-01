/* An IP address as text, for socket_peer(). Written out here, not asked of inet_ntop(), whose IPv6
   spelling differs between systems, and a program that logs or rate-limits by address needs one
   spelling for one address. The rules:
     IPv4: four decimal numbers with dots (192.0.2.7).
     IPv6: eight groups of lowercase hexadecimal, without leading zeros, with the longest run of two
           or more groups that are all zero written as "::" (the first, if two are as long), as
           RFC 5952 says (2001:db8::1, ::1, ::), but a single zero group is written 0.
     IPv4-mapped IPv6 (::ffff:a.b.c.d, which is how an IPv4 client looks to a listener that takes
           both): as the IPv4 address, so a client has one spelling whichever way it came.
   It needs no more than the bytes of the address, so it is checked on its own (IpTextTest). */
#include "gazvm.h"

#include <stdio.h>
#include <string.h>

/* "192.0.2.7" for the four bytes at b; returns the length */
static size_t format_ipv4(const unsigned char *b, char *out) {
    size_t n = 0;
    for (int i = 0; i < 4; i++) {
        if (i > 0) out[n++] = '.';
        n += (size_t)snprintf(out + n, 4, "%d", b[i]);
    }
    return n;
}

/* The start and length of the longest run of two or more zero groups, or -1 and 0 when there is none */
static void longest_zero_run(const unsigned groups[8], int *start, int *length) {
    *start = -1;
    *length = 0;
    for (int i = 0; i < 8;) {
        if (groups[i] != 0) {
            i++;
            continue;
        }
        int run = 0;
        while (i + run < 8 && groups[i + run] == 0) run++;
        if (run >= 2 && run > *length) {
            *start = i;
            *length = run;
        }
        i += run;
    }
}

size_t ip_text(int family, const unsigned char *bytes, char *out) {
    if (family == IP_V4) {
        size_t n = format_ipv4(bytes, out);
        out[n] = '\0';
        return n;
    }

    static const unsigned char mapped_prefix[12] = {0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0xff, 0xff};
    if (memcmp(bytes, mapped_prefix, sizeof mapped_prefix) == 0) {
        size_t n = format_ipv4(bytes + 12, out);
        out[n] = '\0';
        return n;
    }

    unsigned groups[8];
    for (int i = 0; i < 8; i++) groups[i] = (unsigned)bytes[2 * i] << 8 | bytes[2 * i + 1];
    int start, length;
    longest_zero_run(groups, &start, &length);

    size_t n = 0;
    for (int i = 0; i < 8;) {
        if (i == start) {
            out[n++] = ':';
            out[n++] = ':';
            i += length;
            continue;
        }
        /* A colon between two groups, but not after the "::", which has one already */
        if (n > 0 && out[n - 1] != ':') out[n++] = ':';
        n += (size_t)snprintf(out + n, 5, "%x", groups[i]);
        i++;
    }
    out[n] = '\0';
    return n;
}
