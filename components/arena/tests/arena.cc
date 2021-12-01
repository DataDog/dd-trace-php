extern "C" {
#include <components/arena/arena.h>
}

#include <catch2/catch.hpp>

// Generic buffer -- used when you don't need to test specific capacities
alignas(32) static uint8_t buffer[1024];

TEST_CASE("new and delete", "[arena]") {
    datadog_php_arena *arena = datadog_php_arena_new(&buffer[0], sizeof buffer);
    REQUIRE(arena);
    datadog_php_arena_delete(arena);
}

TEST_CASE("generic alignment", "[arena]") {
    CHECK(datadog_php_arena_align_diff(reinterpret_cast<uintptr_t>(buffer + 0), 4) == 0u);
    CHECK(datadog_php_arena_align_diff(reinterpret_cast<uintptr_t>(buffer + 1), 4) == 3u);
    CHECK(datadog_php_arena_align_diff(reinterpret_cast<uintptr_t>(buffer + 2), 4) == 2u);
    CHECK(datadog_php_arena_align_diff(reinterpret_cast<uintptr_t>(buffer + 3), 4) == 1u);
    CHECK(datadog_php_arena_align_diff(reinterpret_cast<uintptr_t>(buffer + 4), 4) == 0u);
    CHECK(datadog_php_arena_align_diff(reinterpret_cast<uintptr_t>(buffer + 5), 4) == 3u);
}

TEST_CASE("allocator self-alignment", "[arena]") {
    alignas(16) uint8_t bytes[24];

    /* Since buffer is aligned to an even byte boundary, by passing in a pointer
     * + 1 (and taking 1 off the size to match), we can test that the arena
     * object itself gets correctly aligned in the buffer, since there is no
     * guarantee that the buffer has suitable alignment.
     */
    datadog_php_arena *arena = datadog_php_arena_new(&bytes[1], sizeof bytes - 1);
    REQUIRE(arena);

    REQUIRE(((uint8_t *)arena) == bytes + 8);

    datadog_php_arena_delete(arena);
}

TEST_CASE("capacity and reset", "[arena]") {
    // UNSAFE: this requires knowledge of size of datadog_php_arena
    alignas(8) uint8_t bytes[20];

    datadog_php_arena *arena = datadog_php_arena_new(bytes + 0, sizeof bytes);
    REQUIRE(arena);

    uint8_t *i = datadog_php_arena_alloc(arena, 4, 1);
    REQUIRE(i);

    // This allocation should fail; no more room.
    uint8_t *j = datadog_php_arena_alloc(arena, 1, 1);
    REQUIRE(!j);

    datadog_php_arena_reset(arena);

    // Since we have reset the arena, this should allocate the same address
    uint8_t *k = datadog_php_arena_alloc(arena, 4, 1);
    REQUIRE(i == k);

    datadog_php_arena_delete(arena);
}

TEST_CASE("allocation alignment", "[arena]") {
    // UNSAFE: this requires knowledge of size of datadog_php_arena
    alignas(16) uint8_t bytes[24];

    datadog_php_arena *arena = datadog_php_arena_new(bytes + 0, sizeof bytes);
    REQUIRE(arena);

    /* Intentionally use a size and align combo that will set up the next alloc
     * to start misaligned.
     */
    uint8_t *i = datadog_php_arena_alloc(arena, 1, 1);
    REQUIRE(i);

    uint8_t *j = datadog_php_arena_alloc(arena, 4, 4);
    REQUIRE(j);
    REQUIRE(i + 4 == j);

    // This allocation should fail; no more room.
    uint8_t *k = datadog_php_arena_alloc(arena, 1, 1);
    REQUIRE(!k);

    datadog_php_arena_delete(arena);
}

TEST_CASE("allocation alignment capacity", "[arena]") {
    // UNSAFE: this requires knowledge of size of datadog_php_arena
    alignas(16) uint8_t bytes[24];

    datadog_php_arena *arena = datadog_php_arena_new(bytes + 0, sizeof bytes);
    REQUIRE(arena);

    uint8_t *i = datadog_php_arena_alloc(arena, 1, 1);
    REQUIRE(i);

    // This allocation would fit except for its alignment
    uint8_t *j = datadog_php_arena_alloc(arena, 7, 2);
    REQUIRE(!j);

    datadog_php_arena_delete(arena);
}
