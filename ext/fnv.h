#ifndef DD_FNV_H
#define DD_FNV_H

#include <stdint.h>
#include <stddef.h>

// FNV-1a 64-bit hash constants
// See: https://en.wikipedia.org/wiki/Fowler%E2%80%93Noll%E2%80%93Vo_hash_function
#define DD_FNV_PRIME 1099511628211ULL
#define DD_FNV_OFFSET_BASIS 14695981039346656037ULL

/**
 * FNV-1a 64-bit non-cryptographic hash function.
 *
 * @param data Pointer to the data to hash
 * @param len Length of the data in bytes
 * @return The 64-bit FNV-1a hash value
 */
uint64_t dd_fnv1_64(const unsigned char *data, size_t len);

#endif // DD_FNV_H

