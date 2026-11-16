extern "C" {
#include "zai_string/string.h"
}

#include "tea/testing/catch2.hpp"

TEA_TEST_CASE("zai_string/strings", "zai_str_eq empty string", {
    zai_str a = ZAI_STR_EMPTY;
    zai_str b = ZAI_STR_EMPTY;
    zai_str c = ZAI_STRL("datadog");

    REQUIRE(zai_str_eq(a, b));
    REQUIRE(zai_str_eq(b, a));

    REQUIRE(!zai_str_eq(a, c));
    REQUIRE(!zai_str_eq(c, b));
})

TEA_TEST_CASE("zai_string/strings", "zai_str_eq non-empty string", {
    zai_str a = ZAI_STRL("datadog");
    zai_str b = ZAI_STRL("datadog");
    zai_str c = ZAI_STRL("datadoge");
    zai_str d = ZAI_STRL("datad0g");

    REQUIRE(zai_str_eq(a, b));
    REQUIRE(zai_str_eq(b, a));

    REQUIRE(!zai_str_eq(a, c));
    REQUIRE(!zai_str_eq(c, a));

    REQUIRE(!zai_str_eq(a, d));
    REQUIRE(!zai_str_eq(d, a));

    REQUIRE(!zai_str_eq(c, d));
    REQUIRE(!zai_str_eq(d, c));
})

TEA_TEST_CASE("zai_string/strings", "zai_string_concat3 empty first and second", {
    // this models namespace lookups
    zai_str first   = ZAI_STR_EMPTY;
    zai_str second  = ZAI_STR_EMPTY;
    zai_str third   = ZAI_STRL("datadog");
    zai_string result = zai_string_concat3(first, second, third);

    REQUIRE(zai_str_eq(third, zai_string_as_str(&result)));

    zai_string_destroy(&result);
})

TEA_TEST_CASE("zai_string/strings", "zai_string_concat3 all full", {
    // this models namespace lookups
    zai_str first   = ZAI_STRL("Datadog\\Test");
    zai_str second  = ZAI_STRL("\\");
    zai_str third   = ZAI_STRL("str");
    zai_string result = zai_string_concat3(first, second, third);

    zai_str expected = ZAI_STRL("Datadog\\Test\\str");
    REQUIRE(zai_str_eq(expected, zai_string_as_str(&result)));

    zai_string_destroy(&result);
})
