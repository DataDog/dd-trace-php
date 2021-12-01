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
    uintptr_t diff = (~ptr + 1) & (align - 1);
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
