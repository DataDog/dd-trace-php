#ifndef DDPROF_ARENA_H
#define DDPROF_ARENA_H 1

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

struct ddprof_arena {
    char *ptr, *end;
    struct ddprof_arena *prev;
};
typedef struct ddprof_arena ddprof_arena;

/* Aligns allocations to 8 byte boundaries */
#define DDPROF_ARENA_ALIGNMENT UINT64_C(8)
#define DDPROF_ARENA_ALIGNMENT_MASK ~(DDPROF_ARENA_ALIGNMENT - 1)

#define DDPROF_ARENA_ALIGNED_SIZE(size) \
    (((size) + DDPROF_ARENA_ALIGNMENT - 1) & DDPROF_ARENA_ALIGNMENT_MASK)

/* prefer powers of 2 */
ddprof_arena *ddprof_arena_create(size_t size);

inline char *ddprof_arena_begin(ddprof_arena *arena) {
    return (char *)arena + DDPROF_ARENA_ALIGNED_SIZE(sizeof(ddprof_arena));
}

void ddprof_arena_grow(ddprof_arena **arena_ptr, size_t min_size);

void ddprof_arena_destroy(ddprof_arena *arena);

/* We want the main allocation code to be inlined; one of the reasons for using
 * an arena is for performance.
 */
inline char *ddprof_arena_alloc(ddprof_arena **arena_ptr, size_t size) {
    ddprof_arena *arena = *arena_ptr;
    size = DDPROF_ARENA_ALIGNED_SIZE(size);

    if (size > (size_t)(arena->end - arena->ptr)) {
        ddprof_arena_grow(arena_ptr, size);
        arena = *arena_ptr;
    }

    char *ptr = arena->ptr;
    arena->ptr += size;
    return ptr;
}

/* Try to allocate `size` memory without growing the arena.
 * If the allocation fits, return pointer to the address; otherwise return NULL.
 */
inline char *ddprof_arena_try_alloc(ddprof_arena *arena, size_t size) {
    size = DDPROF_ARENA_ALIGNED_SIZE(size);
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
char *ddprof_arena_checkpoint(ddprof_arena *arena);

/* The checkpoint must exist in the arena or the arena's prev (recursively).
 * If it doesn't the behavior is undefined.
 */
void ddprof_arena_restore(ddprof_arena **arena_ptr, char *checkpoint);

#endif  // DDPROF_ARENA_H
