#include "fnv.h"

uint64_t dd_fnv1_64(const unsigned char *data, size_t len) {
    uint64_t hash = DD_FNV_OFFSET_BASIS;

    for (size_t i = 0; i < len; i++) {
        hash ^= (uint64_t)data[i];
        hash *= DD_FNV_PRIME;
    }

    return hash;
}
