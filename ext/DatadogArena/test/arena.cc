extern "C" {
#include <datadog/arena.h>
}

#include <catch2/catch.hpp>

TEST_CASE("basic arena creation", "[arena]") {
    datadog_arena *arena = datadog_arena_create(1024);
    REQUIRE(arena->ptr > (char *)arena);
    REQUIRE((void *)arena->ptr ==
            (void *)((char *)arena + sizeof(datadog_arena)));
    REQUIRE(arena->ptr < arena->end);
    REQUIRE(arena->end - (char *)arena == 1024);
    REQUIRE(arena->prev == nullptr);
    datadog_arena_destroy(arena);
}

TEST_CASE("basic arena alignment", "[arena]") {
    datadog_arena *arena = datadog_arena_create(1024);
    char *checkpoint = datadog_arena_checkpoint(arena);
    datadog_arena_alloc(&arena, 1);
    REQUIRE((void *)datadog_arena_checkpoint(arena) ==
            (void *)(checkpoint + DATADOG_ARENA_ALIGNMENT));
    datadog_arena_destroy(arena);
}

TEST_CASE("basic arena growth", "[arena]") {
    datadog_arena *arena = datadog_arena_create(1024);
    datadog_arena *first_arena = arena;

    // arena size 1024 cannot fit 512 x2 because of arena struct overhead
    datadog_arena_alloc(&arena, 512);
    datadog_arena_alloc(&arena, 512);

    REQUIRE(arena->prev == first_arena);
    REQUIRE((void *)arena->ptr ==
            (void *)((char *)arena + sizeof(datadog_arena)));
    REQUIRE(arena->end - (char *)arena == 1024);
    datadog_arena_destroy(arena);
}

TEST_CASE("arena try alloc", "[arena]") {
    datadog_arena *arena = datadog_arena_create(1024);
    datadog_arena *first_arena = arena;

    char *ptr;
    REQUIRE(datadog_arena_try_alloc(arena, 512, &ptr));
    REQUIRE((void *)ptr == (void *)((char *)arena + sizeof(datadog_arena)));

    char *ptr_backup = ptr;
    char *checkpoint = datadog_arena_checkpoint(arena);
    // arena size 1024 cannot fit 512 x2 because of arena struct overhead
    REQUIRE(!datadog_arena_try_alloc(arena, 512, &ptr));

    REQUIRE(arena == first_arena);
    REQUIRE((void *)checkpoint == (void *)datadog_arena_checkpoint(arena));
    REQUIRE((void *)ptr == (void *)ptr_backup);

    datadog_arena_destroy(arena);
}

TEST_CASE("arena basic checkpoint and restore", "[arena]") {
    datadog_arena *arena = datadog_arena_create(1024);
    datadog_arena *first_arena = arena;

    char *checkpoint = datadog_arena_checkpoint(arena);
    datadog_arena_alloc(&arena, 512);
    // ensure we didn't accidentally grow
    REQUIRE(arena == first_arena);

    datadog_arena_restore(&arena, checkpoint);
    REQUIRE((void *)arena->ptr == (void *)checkpoint);

    datadog_arena_destroy(arena);
}

TEST_CASE("arena growing checkpoint and restore", "[arena]") {
    datadog_arena *arena = datadog_arena_create(512);

    datadog_arena_alloc(&arena, 256);

    datadog_arena *first_arena = arena;
    char *checkpoint = datadog_arena_checkpoint(arena);
    datadog_arena_alloc(&arena, 256);

    // make sure we grew
    REQUIRE(arena != first_arena);
    REQUIRE(arena->prev == first_arena);

    datadog_arena_restore(&arena, checkpoint);
    // make sure we rewound
    REQUIRE(arena == first_arena);
    REQUIRE((void *)arena->ptr == (void *)checkpoint);

    datadog_arena_destroy(arena);
}
