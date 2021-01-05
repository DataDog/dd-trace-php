extern "C" {
#include <ddprof/arena.h>
}

static size_t arena_size(const ddprof_arena *arena) {
    char *begin = (char *)arena;
    return arena->end - begin;
}

#include <catch2/catch.hpp>

TEST_CASE("basic arena creation", "[arena]") {
    ddprof_arena *arena = ddprof_arena_create(1024);
    REQUIRE(arena->ptr > (char *)arena);
    REQUIRE((void *)arena->ptr == (void *)ddprof_arena_begin(arena));
    REQUIRE(arena->ptr < arena->end);
    REQUIRE(arena->end - (char *)arena == 1024);
    REQUIRE(arena->prev == nullptr);
    ddprof_arena_destroy(arena);
}

TEST_CASE("basic arena alignment", "[arena]") {
    ddprof_arena *arena = ddprof_arena_create(1024);
    char *checkpoint = ddprof_arena_checkpoint(arena);
    ddprof_arena_alloc(&arena, 1);
    REQUIRE((void *)ddprof_arena_checkpoint(arena) == (void *)(checkpoint + DDPROF_ARENA_ALIGNMENT));
    ddprof_arena_destroy(arena);
}

TEST_CASE("basic arena growth", "[arena]") {
    ddprof_arena *arena = ddprof_arena_create(1024);
    ddprof_arena *first_arena = arena;

    // arena size 1024 cannot fit 512 x2 because of arena struct overhead
    ddprof_arena_alloc(&arena, 512);
    ddprof_arena_alloc(&arena, 512);

    REQUIRE(arena->prev == first_arena);
    REQUIRE((void *)arena->ptr == (void *)((char *)arena + sizeof(ddprof_arena) + 512));
    REQUIRE(arena->end - (char *)arena == 1024);
    ddprof_arena_destroy(arena);
}

TEST_CASE("arena initial size grows to fit the size of an arena", "[arena]") {
    ddprof_arena *arena = ddprof_arena_create(1);
    size_t size = arena_size(arena);

    REQUIRE(size >= sizeof(ddprof_arena));

    ddprof_arena_destroy(arena);
}

TEST_CASE("arena try alloc", "[arena]") {
    ddprof_arena *arena = ddprof_arena_create(1024);
    ddprof_arena *first_arena = arena;

    char *ptr = ddprof_arena_try_alloc(arena, 512);
    REQUIRE((void *)ptr == (void *)((char *)arena + sizeof(ddprof_arena)));

    char *checkpoint = ddprof_arena_checkpoint(arena);
    // arena size 1024 cannot fit 512 x2 because of arena struct overhead
    REQUIRE(!ddprof_arena_try_alloc(arena, 512));

    REQUIRE(arena == first_arena);
    REQUIRE((void *)checkpoint == (void *)ddprof_arena_checkpoint(arena));

    ddprof_arena_destroy(arena);
}

TEST_CASE("arena basic checkpoint and restore", "[arena]") {
    ddprof_arena *arena = ddprof_arena_create(1024);
    ddprof_arena *first_arena = arena;

    char *checkpoint = ddprof_arena_checkpoint(arena);
    ddprof_arena_alloc(&arena, 512);
    // ensure we didn't accidentally grow
    REQUIRE(arena == first_arena);

    ddprof_arena_restore(&arena, checkpoint);
    REQUIRE((void *)arena->ptr == (void *)checkpoint);

    ddprof_arena_destroy(arena);
}

TEST_CASE("arena growing checkpoint and restore", "[arena]") {
    ddprof_arena *arena = ddprof_arena_create(512);

    ddprof_arena_alloc(&arena, 256);

    ddprof_arena *first_arena = arena;
    char *checkpoint = ddprof_arena_checkpoint(arena);
    ddprof_arena_alloc(&arena, 256);

    // make sure we grew
    REQUIRE(arena != first_arena);
    REQUIRE(arena->prev == first_arena);

    ddprof_arena_restore(&arena, checkpoint);
    // make sure we rewound
    REQUIRE(arena == first_arena);
    REQUIRE((void *)arena->ptr == (void *)checkpoint);

    ddprof_arena_destroy(arena);
}

TEST_CASE("arena checkpoint and restore where the checkpoint is not in the root nor in a leaf", "[arena]") {
    ddprof_arena *arena = ddprof_arena_create(32);
    ddprof_arena *first_arena = arena;

    size_t remaining = arena->end - arena->ptr;

    // ensure we have at least a single byte remaining
    REQUIRE(remaining > 0);

    ddprof_arena_alloc(&arena, remaining);
    // we shouldn't have grown yet
    REQUIRE(arena->prev == NULL);
    // nor should there be any space remaining; next allocation triggers a new arena
    REQUIRE(arena->ptr == arena->end);

    char *checkpoint = ddprof_arena_alloc(&arena, remaining);
    // ensure we grew
    REQUIRE(arena->prev == first_arena);
    // shouldn't be any space remaining; next allocation triggers a new arena
    REQUIRE(((void *)arena->ptr) == (void *)arena->end);

    ddprof_arena_alloc(&arena, remaining);
    // ensure we grew
    REQUIRE(arena->prev->prev == first_arena);

    // Now we have 3 arenas, and the checkpoint is in the middle.
    // Let's restore the checkpoint.
    // Use a memory checker to ensure this doesn't leak.
    ddprof_arena_restore(&arena, checkpoint);

    // assert the restored checkpoint properties
    REQUIRE(arena->prev == first_arena);
    REQUIRE((void *)arena->ptr == (void *)(arena->end - remaining));

    ddprof_arena_destroy(arena);
}
