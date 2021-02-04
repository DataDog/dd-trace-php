extern "C" {
#include <datadog/string.h>
}

#include <catch2/catch.hpp>
#include <string.h>

TEST_CASE("basic string allocation", "[string]") {
    datadog_string *str = datadog_string_alloc(16);
    REQUIRE(str->val > (char *)str);
    REQUIRE(str->len == 0);
    datadog_string_free(str);
}

TEST_CASE("basic string initialization", "[string]") {
    datadog_string *str = datadog_string_init("my string", sizeof("my string") - 1);
    REQUIRE(strcmp("my string", str->val) == 0);
    REQUIRE(str->len == sizeof("my string") - 1);
    datadog_string_free(str);
}
