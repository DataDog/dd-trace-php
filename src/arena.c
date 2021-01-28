#include "ddprof/arena.h"

#include <stdlib.h>

_Static_assert(sizeof(size_t) == 8, "Unexpected size_t size");

extern inline char *ddprof_arena_begin(ddprof_arena *arena);
extern inline char *ddprof_arena_alloc(ddprof_arena **arena_ptr, size_t size);
extern inline char *ddprof_arena_try_alloc(ddprof_arena *arena, size_t size);

/* prefer powers of 2 */
ddprof_arena *ddprof_arena_create(size_t size) {
  size_t aligned = DDPROF_ARENA_ALIGNED_SIZE(sizeof(ddprof_arena));
  size = size > aligned ? size : aligned;
  ddprof_arena *arena = (ddprof_arena *)calloc(1, size);

  arena->ptr = (char *)arena + aligned;
  arena->end = (char *)arena + size;
  arena->prev = NULL;

  return arena;
}

void ddprof_arena_grow(ddprof_arena **arena_ptr, size_t min_size) {
  ddprof_arena *arena = *arena_ptr;
  size_t prev_size = arena->end - (char *)arena;
  min_size += DDPROF_ARENA_ALIGNED_SIZE(sizeof(ddprof_arena));
  size_t size = prev_size > min_size ? prev_size : min_size;
  // todo: align min_size to a power of 2 if it doesn't fit?

  ddprof_arena *new_arena = ddprof_arena_create(size);
  new_arena->prev = *arena_ptr;
  *arena_ptr = new_arena;
}

void ddprof_arena_destroy(ddprof_arena *arena) {
  do {
    ddprof_arena *prev = arena->prev;
    free(arena);
    arena = prev;
  } while (arena);
}

char *ddprof_arena_checkpoint(ddprof_arena *arena) { return arena->ptr; }

static ddprof_arena *dd_arena_restore(ddprof_arena *arena, char *checkpoint) {
  if (arena) {
    /* Hand-waving a bit, but comparing pointers from different allocations
     * is undefined behavior. Comparing uintptr_ts is implementation defined
     * behavior; a step up.
     */
    uintptr_t begin = (uintptr_t)arena;
    uintptr_t end = (uintptr_t)arena->end;
    uintptr_t ptr = (uintptr_t)checkpoint;
    if (begin <= ptr && ptr <= end) {
      arena->ptr = checkpoint;
    } else {
      ddprof_arena *root = dd_arena_restore(arena->prev, checkpoint);
      /* If the checkpoint is found in a previous arena then delete this
       * arena; return the new root upwards.
       */
      if (root) {
        arena->prev = NULL;
        free(arena);
      }
      return root;
    }
  }
  return arena;
}

void ddprof_arena_restore(ddprof_arena **arena_ptr, char *checkpoint) {
  ddprof_arena *root = dd_arena_restore(*arena_ptr, checkpoint);
  if (root) {
    *arena_ptr = root;
  }
}
