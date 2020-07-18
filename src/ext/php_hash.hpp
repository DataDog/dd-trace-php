#pragma once
#include <php_version.h>
#include <stdbool.h>
#include <stddef.h>

namespace ddtrace {
#if PHP_VERSION_ID < 70000
inline constexpr static uint64_t string_hash(const char *arKey, size_t nKeyLength) {
    register ulong hash = 5381;

    /* variant with the hash unrolled eight times */
    for (; nKeyLength >= 8; nKeyLength -= 8) {
        hash = ((hash << 5) + hash) + *arKey++;
        hash = ((hash << 5) + hash) + *arKey++;
        hash = ((hash << 5) + hash) + *arKey++;
        hash = ((hash << 5) + hash) + *arKey++;
        hash = ((hash << 5) + hash) + *arKey++;
        hash = ((hash << 5) + hash) + *arKey++;
        hash = ((hash << 5) + hash) + *arKey++;
        hash = ((hash << 5) + hash) + *arKey++;
    }
    switch (nKeyLength) {
        case 7:
            hash = ((hash << 5) + hash) + *arKey++; /* fallthrough... */
        case 6:
            hash = ((hash << 5) + hash) + *arKey++; /* fallthrough... */
        case 5:
            hash = ((hash << 5) + hash) + *arKey++; /* fallthrough... */
        case 4:
            hash = ((hash << 5) + hash) + *arKey++; /* fallthrough... */
        case 3:
            hash = ((hash << 5) + hash) + *arKey++; /* fallthrough... */
        case 2:
            hash = ((hash << 5) + hash) + *arKey++; /* fallthrough... */
        case 1:
            hash = ((hash << 5) + hash) + *arKey++;
            break;
        case 0:
            break;
            EMPTY_SWITCH_DEFAULT_CASE()
    }
    return hash;
}
#else
inline constexpr static uint64_t string_hash(const char *str, size_t len) {
    uint64_t hash = 5381UL;

#if defined(_WIN32) || defined(__i386__) || defined(__x86_64__) || defined(__aarch64__)
    /* Version with multiplication works better on modern CPU */
    for (; len >= 8; len -= 8, str += 8) {
#if defined(__aarch64__) && !defined(WORDS_BIGENDIAN)
        /* On some architectures it is beneficial to load 8 bytes at a
           time and extract each byte with a bit field extract instr. */
        uint64_t chunk;

        memcpy(&chunk, str, sizeof(chunk));
        hash = hash * 33 * 33 * 33 * 33 + ((chunk >> (8 * 0)) & 0xff) * 33 * 33 * 33 +
               ((chunk >> (8 * 1)) & 0xff) * 33 * 33 + ((chunk >> (8 * 2)) & 0xff) * 33 + ((chunk >> (8 * 3)) & 0xff);
        hash = hash * 33 * 33 * 33 * 33 + ((chunk >> (8 * 4)) & 0xff) * 33 * 33 * 33 +
               ((chunk >> (8 * 5)) & 0xff) * 33 * 33 + ((chunk >> (8 * 6)) & 0xff) * 33 + ((chunk >> (8 * 7)) & 0xff);
#else
        hash = hash * 33 * 33 * 33 * 33 + str[0] * 33 * 33 * 33 + str[1] * 33 * 33 + str[2] * 33 + str[3];
        hash = hash * 33 * 33 * 33 * 33 + str[4] * 33 * 33 * 33 + str[5] * 33 * 33 + str[6] * 33 + str[7];
#endif
    }
    if (len >= 4) {
        hash = hash * 33 * 33 * 33 * 33 + str[0] * 33 * 33 * 33 + str[1] * 33 * 33 + str[2] * 33 + str[3];
        len -= 4;
        str += 4;
    }
    if (len >= 2) {
        if (len > 2) {
            hash = hash * 33 * 33 * 33 + str[0] * 33 * 33 + str[1] * 33 + str[2];
        } else {
            hash = hash * 33 * 33 + str[0] * 33 + str[1];
        }
    } else if (len != 0) {
        hash = hash * 33 + *str;
    }
#else
    /* variant with the hash unrolled eight times */
    for (; len >= 8; len -= 8) {
        hash = ((hash << 5) + hash) + *str++;
        hash = ((hash << 5) + hash) + *str++;
        hash = ((hash << 5) + hash) + *str++;
        hash = ((hash << 5) + hash) + *str++;
        hash = ((hash << 5) + hash) + *str++;
        hash = ((hash << 5) + hash) + *str++;
        hash = ((hash << 5) + hash) + *str++;
        hash = ((hash << 5) + hash) + *str++;
    }
    switch (len) {
        case 7:
            hash = ((hash << 5) + hash) + *str++; /* fallthrough... */
        case 6:
            hash = ((hash << 5) + hash) + *str++; /* fallthrough... */
        case 5:
            hash = ((hash << 5) + hash) + *str++; /* fallthrough... */
        case 4:
            hash = ((hash << 5) + hash) + *str++; /* fallthrough... */
        case 3:
            hash = ((hash << 5) + hash) + *str++; /* fallthrough... */
        case 2:
            hash = ((hash << 5) + hash) + *str++; /* fallthrough... */
        case 1:
            hash = ((hash << 5) + hash) + *str++;
            break;
        case 0:
            break;
            EMPTY_SWITCH_DEFAULT_CASE()
    }
#endif

    /* Hash value can't be zero, so we always set the high bit */
#if SIZEOF_ZEND_LONG == 8
    return hash | Z_UL(0x8000000000000000);
#elif SIZEOF_ZEND_LONG == 4
    return hash | Z_UL(0x80000000);
#else
// #error "Unknown SIZEOF_ZEND_LONG"
#endif
}
#endif
}  // namespace ddtrace
