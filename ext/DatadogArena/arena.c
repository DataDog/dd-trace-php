#include "datadog/arena.h"

#include <stdlib.h>

_Static_assert(sizeof(size_t) == 8, "Unexpected size_t size");

extern inline char *datadog_arena_alloc(datadog_arena **arena_ptr, size_t size);
extern inline char *datadog_arena_try_alloc(datadog_arena *arena, size_t size);

/* prefer powers of 2 */
datadog_arena *datadog_arena_create(size_t size) {
    datadog_arena *arena = (datadog_arena *)calloc(1, size);

    size_t aligned = DATADOG_ARENA_ALIGNED_SIZE(sizeof(datadog_arena));
    arena->ptr = (char *)arena + aligned;
    arena->end = (char *)arena + size;
    arena->prev = NULL;

    return arena;
}

void datadog_arena_grow(datadog_arena **arena_ptr, size_t min_size) {
    datadog_arena *arena = *arena_ptr;
    size_t prev_size = arena->end - (char *)arena;
    min_size += DATADOG_ARENA_ALIGNED_SIZE(sizeof(datadog_arena));
    size_t size = prev_size > min_size ? prev_size : min_size;
    // todo: align min_size to a power of 2 if it doesn't fit?

    datadog_arena *new_arena = datadog_arena_create(size);
    new_arena->prev = *arena_ptr;
    *arena_ptr = new_arena;
}

void datadog_arena_destroy(datadog_arena *arena) {
    do {
        datadog_arena *prev = arena->prev;
        free(arena);
        arena = prev;
    } while (arena);
}

char *datadog_arena_checkpoint(datadog_arena *arena) { return arena->ptr; }

// todo: what to do when checkpoint isn't found in arena?
void datadog_arena_restore(datadog_arena **arena_ptr, char *checkpoint) {
    datadog_arena *arena = *arena_ptr;

    while (arena) {
        if ((char *)arena <= checkpoint && checkpoint <= arena->end) {
            arena->ptr = checkpoint;
            break;
        } else {
            datadog_arena *prev = arena->prev;
            free(arena);
            arena = prev;
        }
    }
    *arena_ptr = arena;
}
