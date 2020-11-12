#include <catch2/catch.hpp>
#include <ddprof/string_table.hh>
#include <string>

using ddprof::interned_string;
using ddprof::string_table;

TEST_CASE("string_table defaults", "[string_table]") {
    string_table string_table;

    // string_table has an empty string at offset 0 (unless the table is dtor'd)
    REQUIRE(string_table.size() == 1);

    interned_string &interned = string_table[0];
    REQUIRE(interned.size == 0);
    REQUIRE(interned.offset == 0);
    REQUIRE(interned.hash == 0);

    std::string empty_string{};
    auto &empty2 = string_table.intern({empty_string.size(), empty_string.data()});
    REQUIRE(&empty2 == &interned);

    const char empty_cstr[] = "";
    auto &empty3 = string_table.intern({0, empty_cstr});
    REQUIRE(&empty3 == &interned);
}

TEST_CASE("string_table basics", "[string_table]") {
    string_table string_table;

    std::string datadog = "datadog";
    interned_string &interned = string_table.intern({datadog.size(), datadog.data()});

    REQUIRE(interned.size == datadog.size());
    REQUIRE(interned.offset == 1);  // remember, there is an empty string at 0
    REQUIRE(interned.hash != 0);

    interned_string &again = string_table.intern({datadog.size(), datadog.data()});
    REQUIRE(&interned == &again);
}

TEST_CASE("intern a few strings", "[string_table]") {
    string_table string_table;

    for (int i = 0; i < 10; ++i) {
        std::string input = std::to_string(i * 9973);
        interned_string &interned = string_table.intern({input.size(), input.data()});

        REQUIRE(interned.size == input.size());

        // remember, there is an empty string at 0
        REQUIRE(interned.offset == i + 1);
        REQUIRE(interned.hash != 0);
    }

    REQUIRE(string_table.size() == 11);
}

TEST_CASE("intern a moderate number of strings", "[string_table]") {
    string_table string_table;

    for (int i = 0; i < 100; ++i) {
        std::string input = std::to_string(i * 9973);
        interned_string &interned = string_table.intern({input.size(), input.data()});

        REQUIRE(interned.size == input.size());

        // remember, there is an empty string at 0
        REQUIRE(interned.offset == i + 1);
        REQUIRE(interned.hash != 0);
    }

    REQUIRE(string_table.size() == 101);
}
