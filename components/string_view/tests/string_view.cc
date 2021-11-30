extern "C" {
#include <components/string_view/string_view.h>
}

#include <catch2/catch.hpp>
#include <cstring>

TEST_CASE("string_view init", "[string_view]") {
    datadog_php_string_view str = DATADOG_PHP_STRING_VIEW_INIT;

    REQUIRE(str.len == 0);
    REQUIRE(str.ptr);
}

TEST_CASE("string_view cstrings", "[string_view]") {
    datadog_php_string_view empty = datadog_php_string_view_from_cstr("");
    REQUIRE(empty.len == 0);
    REQUIRE(empty.ptr);

    datadog_php_string_view nil = datadog_php_string_view_from_cstr(nullptr);
    REQUIRE(nil.len == 0);
    REQUIRE(nil.ptr);

    datadog_php_string_view abc = datadog_php_string_view_from_cstr("abc");
    REQUIRE(abc.len == 3);
    REQUIRE(memcmp(abc.ptr, "abc", 3) == 0);

    const char buff[5] = "four";
    datadog_php_string_view four = datadog_php_string_view_from_cstr(buff);
    REQUIRE(four.len == 4);
    REQUIRE((const void *)four.ptr == (const void *)&buff);
}

TEST_CASE("string_view empty equal", "[string_view]") {
    datadog_php_string_view empty = datadog_php_string_view_from_cstr("");
    REQUIRE(empty.len == 0);
    REQUIRE(empty.ptr);

    datadog_php_string_view nil = datadog_php_string_view_from_cstr(nullptr);
    REQUIRE(nil.len == 0);
    REQUIRE(nil.ptr);

    REQUIRE(datadog_php_string_view_equal(empty, nil));
}

TEST_CASE("string_view equal", "[string_view]") {
    const char buff1[] = "asdf";
    char buff2[sizeof buff1];
    memcpy((void *)buff2, buff1, sizeof buff1);

    datadog_php_string_view str1 = datadog_php_string_view_from_cstr(buff1);
    datadog_php_string_view str2 = datadog_php_string_view_from_cstr(buff2);

    REQUIRE(datadog_php_string_view_equal(str1, str2));

    // identity cases
    CHECK(datadog_php_string_view_equal(str1, str1));
    CHECK(datadog_php_string_view_equal(str2, str2));

    char a[] = "aBcDE01234";
    char b[] = "abcde01234";

    auto x = datadog_php_string_view_from_cstr(a);
    auto y = datadog_php_string_view_from_cstr(b);
    CHECK(!datadog_php_string_view_equal(x, y));

    for (auto &ch : a) {
        ch = (char)(unsigned char)tolower((unsigned char)ch);
    }

    CHECK(datadog_php_string_view_equal(x, y));
}

TEST_CASE("string_view equal different lengths", "[string_view]") {
    char a[] = "aBcDE0";
    char b[] = "abcde01234";

    auto x = datadog_php_string_view_from_cstr(a);
    auto y = datadog_php_string_view_from_cstr(b);
    CHECK(!datadog_php_string_view_equal(x, y));

    for (auto &ch : a) {
        ch = (char)(unsigned char)tolower((unsigned char)ch);
    }

    // still not equal after lower-casing
    CHECK(!datadog_php_string_view_equal(x, y));
}
