#include <catch2/catch.hpp>
#include <datadog/memhash.hh>
#include <datadog/string_table.hh>
#include <string>

using datadog::interned_string;
using datadog::string_table;

TEST_CASE("string_table defaults", "[string_table]") {
    datadog_arena *arena = datadog_arena_create(4096);
    string_table string_table{&arena};

    // string_table has an empty string at offset 0 (unless the table is dtor'd)
    REQUIRE(string_table.size() == 1);

    interned_string &interned = string_table[0];
    REQUIRE(interned.size == 0);
    REQUIRE(interned.offset == 0);
    REQUIRE(interned.hash == datadog::memhash(0, nullptr));

    std::string empty_string{};
    auto &empty2 = string_table.intern({empty_string.size(), empty_string.data()});
    REQUIRE(&empty2 == &interned);

    const char empty_cstr[] = "";
    auto &empty3 = string_table.intern({0, empty_cstr});
    REQUIRE(&empty3 == &interned);

    datadog_arena_destroy(arena);
}

TEST_CASE("string_table basics", "[string_table]") {
    datadog_arena *arena = datadog_arena_create(4096);
    string_table string_table{&arena};

    std::string datadog = "datadog";
    interned_string &interned = string_table.intern({datadog.size(), datadog.data()});

    REQUIRE(interned.size == datadog.size());
    REQUIRE(interned.offset == 1);  // remember, there is an empty string at 0
    REQUIRE(interned.hash == datadog::memhash(datadog.size(), datadog.data()));

    interned_string &again = string_table.intern({datadog.size(), datadog.data()});
    REQUIRE(&interned == &again);

    datadog_arena_destroy(arena);
}

TEST_CASE("intern a few strings", "[string_table]") {
    datadog_arena *arena = datadog_arena_create(4096);
    string_table string_table{&arena};

    for (int i = 0; i < 10; ++i) {
        std::string input = std::to_string(i * 9973);
        interned_string &interned = string_table.intern({input.size(), input.data()});

        REQUIRE(interned.size == input.size());

        // remember, there is an empty string at 0
        REQUIRE(interned.offset == i + 1);
        REQUIRE(interned.hash == datadog::memhash(input.size(), input.data()));
    }

    REQUIRE(string_table.size() == 11);

    datadog_arena_destroy(arena);
}

TEST_CASE("intern a moderate number of strings", "[string_table]") {
    datadog_arena *arena = datadog_arena_create(4096);
    string_table string_table{&arena};

    for (int i = 0; i < 100; ++i) {
        std::string input = std::to_string(i * 9973);
        interned_string &interned = string_table.intern({input.size(), input.data()});

        REQUIRE(interned.size == input.size());

        // remember, there is an empty string at 0
        REQUIRE(interned.offset == i + 1);
        REQUIRE(interned.hash == datadog::memhash(input.size(), input.data()));
    }

    REQUIRE(string_table.size() == 101);

    datadog_arena_destroy(arena);
}

TEST_CASE("intern strings of different lengths", "[string_table]") {
    /* With a max_len of 512 and storing strings of length 0 .. 512, we will
     * easily exceed the starting arena size of 8192, so this will also test
     * some string_table interactions with the arena.
     */
    size_t max_len = 512;
    datadog_arena *arena = datadog_arena_create(8192);
    string_table string_table{&arena};

    for (size_t i = 1; i < max_len + 1; ++i) {
        auto input = std::string(i, '.');
        interned_string &interned = string_table.intern({input.size(), input.data()});

        REQUIRE(interned.size == input.size());

        REQUIRE(interned.offset == i);
        REQUIRE(interned.hash == datadog::memhash(input.size(), input.data()));
    }

    REQUIRE(string_table.size() == max_len + 1);

    datadog_arena_destroy(arena);
}
