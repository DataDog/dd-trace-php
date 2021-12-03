#include "arena.h"

#include <stddef.h>
#include <stdint.h>

struct datadog_php_arena_s {
    uint32_t offset, capacity;
    uint8_t *start;
};

void datadog_php_arena_delete(datadog_php_arena *arena) { (void)arena; }
void datadog_php_arena_reset(datadog_php_arena *arena) { arena->offset = 0; }

static uint32_t align_diff(uintptr_t ptr, uint32_t align) {
    /* The `align` must be a power of two, which means it has a single bit set:
     * 0b0100
     * By subtracting one, the bits below the only set bit will all be 1, while
     * that bit and above will all be 0:
     * 0b0011
     */
    uintptr_t lower_bits_mask = align - 1;

    /* Here's how (~ptr) + 1 evaluates for a few numbers:
     * Each group is distinct:
     *      0     1     2     3     4     5     6     7     8
     * into binary:
     *   0000  0001  0010  0011  0100  0101  0110  0111  1000
     * ~ ====  ====  ====  ====  ====  ====  ====  ====  ====
     *   1111  1110  1101  1100  1011  1010  1001  1000  0111
     * + 0001  0001  0001  0001  0001  0001  0001  0001  0001
     *   ====  ====  ====  ====  ====  ====  ====  ====  ====
     *  10000  1111  1110  1101  1100  1011  1010  1001  1000
     */
    uintptr_t inverted = (~ptr) + 1;  // parens are redundant

    /* Okay, let's use an alignment of 8, which is a mask of 0111 (or 7)
     * on the numbers from above:
     *  10000  1111  1110  1101  1100  1011  1010  1001  1000
     * & 0111  0111  0111  0111  0111  0111  0111  0111  0111
     *   ====  ====  ====  ====  ====  ====  ====  ====  ====
     *   0000  0111  0110  0101  0100  0011  0010  0001  0000
     * Converted to decimal:
     *      0     7     6     5     4     3     2     1     0
     * which check out; add those to our original numbers and they all become
     * a multiple of 8 (yes, 0 is a multiple of 8):
     *      0     1     2     3     4     5     6     7     8
     * +    0     7     6     5     4     3     2     1     0
     *      =     =     =     =     =     =     =     =     =
     *      0     8     8     8     8     8     8     8     8
     */
    uintptr_t diff = inverted & lower_bits_mask;
    return diff;
}

uint32_t datadog_php_arena_align_diff(uintptr_t ptr, uint32_t align) {
    uint32_t diff = align_diff(ptr, align);
    return diff;
}

datadog_php_arena *datadog_php_arena_new(uint32_t len, uint8_t buffer[static len]) {
    if (!len) {
        return NULL;
    }

    uint32_t diff = align_diff((uintptr_t)buffer, _Alignof(datadog_php_arena));
    uint8_t *obj = buffer + diff;

    // ensure the alignment + size fits
    if ((diff + sizeof(datadog_php_arena)) > len) {
        return NULL;
    }

    datadog_php_arena *allocator = (datadog_php_arena *)obj;
    allocator->offset = 0;
    allocator->capacity = len - sizeof(datadog_php_arena) - diff;
    allocator->start = obj + sizeof(datadog_php_arena);

    return allocator;
}

uint8_t *datadog_php_arena_alloc(datadog_php_arena *arena, uint32_t size, uint32_t align) {
    // minimum allocation size is 1 byte
    size = size ? size : 1;

    // align the offset
    uint8_t *begin = arena->start + arena->offset;
    uint32_t diff = align_diff((uintptr_t)begin, align);
    ptrdiff_t offset = arena->offset + diff;

    // ensure it doesn't go out of bounds
    if (offset + size > arena->capacity) {
        return NULL;
    }

    unsigned char *obj = arena->start + offset;

    // bump the offset
    arena->offset = offset + size;
    return obj;
}
