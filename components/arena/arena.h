#ifndef DATADOG_PHP_ARENA_H
#define DATADOG_PHP_ARENA_H

#include <stdint.h>

#if __cplusplus
#define C_STATIC(...)
#else
#define C_STATIC(...) static __VA_ARGS__
#endif

#if __GNUC__
#if __GNUC__ >= 11
#define HAVE_ATTRIBUTE_MALLOC_EXTENDED 1
#endif
#endif

#if HAVE_ATTRIBUTE_MALLOC_EXTENDED
/* Note that GCC 11 doesn't yet do a good job with this attribute, but
 * experimenting with it to report bugs so it hopefully gets better.
 */
#define DATADOG_PHP__ATTR_MALLOC_EXTENDED(...) __attribute__((malloc(__VA_ARGS__)))
#else
#define DATADOG_PHP__ATTR_MALLOC_EXTENDED(...)
#endif

/**
 * This arena is used to do bump pointer style allocations inside of a
 * previously allocated block, such as stack memory, static storage, or heap
 * memory.
 *
 * Individual allocations made by the arena are not tracked and are not freed.
 * The use-case is allocating a bunch of data which has the same lifetime, and
 * then everything can be freed at once by freeing the arena. This means that
 * care must be taken to either not store types which have custom destructors,
 * or that they should be tracked and dtor'd outside of the arena.
 */
typedef struct datadog_php_arena_s datadog_php_arena;

/**
 * This is a no-op at present, but it theoretically helps the GCC analyzer to
 * understand the lifetime of the arena.
 * Note that it cannot be inline, as deallocator functions cannot be inline.
 */
void datadog_php_arena_delete(datadog_php_arena *arena);

/**
 * Creates a new arena from the provided `buffer` + `len`. This function may
 * return NULL, such as if `len` is not large enough to hold the arena object.
 *
 * # Safety
 * The `buffer` object must have at least `len` contiguous bytes and must not
 * be null.
 */
DATADOG_PHP__ATTR_MALLOC_EXTENDED(datadog_php_arena_delete)
datadog_php_arena *datadog_php_arena_new(uint32_t len, uint8_t buffer[C_STATIC(len)]);

/**
 * Resets the arena contents, but the arena object itself stays valid. All other
 * objects that have been allocated by the arena are now invalid.
 */
__attribute__((nonnull(1))) void datadog_php_arena_reset(datadog_php_arena *arena);

/**
 * Returns the number of bytes to add to `ptr` to align it to `align`. Only
 * pass powers of 2 for `align`!
 */
uint32_t datadog_php_arena_align_diff(uintptr_t ptr, uint32_t align);

/**
 * Allocates at least `size` bytes from the `arena`, aligned to `align`. May
 * return NULL, such as if `align` is not a power of two or if there is not
 * enough remaining space in the `arena` storage.
 */
__attribute__((nonnull(1))) uint8_t *datadog_php_arena_alloc(datadog_php_arena *arena, uint32_t size, uint32_t align);

#undef C_STATIC

#endif  // DATADOG_PHP_ARENA_H
