#ifndef DATADOG_ARENA_H
#define DATADOG_ARENA_H 1

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

struct datadog_arena {
    char *ptr, *end;
    struct datadog_arena *prev;
};
typedef struct datadog_arena datadog_arena;

/* Aligns allocations to 8 byte boundaries */
#define DATADOG_ARENA_ALIGNMENT UINT64_C(8)
#define DATADOG_ARENA_ALIGNMENT_MASK ~(DATADOG_ARENA_ALIGNMENT - 1)

#define DATADOG_ARENA_ALIGNED_SIZE(size) (((size) + DATADOG_ARENA_ALIGNMENT - 1) & DATADOG_ARENA_ALIGNMENT_MASK)

/* prefer powers of 2 */
datadog_arena *datadog_arena_create(size_t size);

inline size_t datadog_arena_size(const datadog_arena *arena) {
    char *begin = (char *)arena;
    return arena->end - begin;
}

inline char *datadog_arena_begin(datadog_arena *arena) {
    return (char *)arena + DATADOG_ARENA_ALIGNED_SIZE(sizeof(datadog_arena));
}

void datadog_arena_grow(datadog_arena **arena_ptr, size_t min_size);

void datadog_arena_destroy(datadog_arena *arena);

/* We want the main allocation code to be inlined; one of the reasons for using
 * an arena is for performance.
 */
inline char *datadog_arena_alloc(datadog_arena **arena_ptr, size_t size) {
    datadog_arena *arena = *arena_ptr;
    size = DATADOG_ARENA_ALIGNED_SIZE(size);

    if (size > (size_t)(arena->end - arena->ptr)) {
        datadog_arena_grow(arena_ptr, size);
        arena = *arena_ptr;
    }

    char *ptr = arena->ptr;
    arena->ptr += size;
    return ptr;
}

/* Try to allocate `size` memory without growing the arena.
 * If the allocation fits, return pointer to the address; otherwise return NULL.
 */
inline char *datadog_arena_try_alloc(datadog_arena *arena, size_t size) {
    size = DATADOG_ARENA_ALIGNED_SIZE(size);
    if (size > (size_t)(arena->end - arena->ptr)) {
        return NULL;
    }

    char *result = arena->ptr;
    arena->ptr += size;
    return result;
}

/* Checkpointing allows you to save a position in the arena, then to later
 * free any memory after the checkpoint.
 * Example where it may be useful: serialization. You can checkpoint,
 * serialize, transfer, then rewind back.
 */
char *datadog_arena_checkpoint(datadog_arena *arena);

/* The checkpoint must exist in the arena or the arena's prev (recursively).
 * If it doesn't the behavior is undefined.
 */
void datadog_arena_restore(datadog_arena **arena_ptr, char *checkpoint);

#endif  // DATADOG_ARENA_H
