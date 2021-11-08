extern "C" {
#include <string-view/string-view.h>
}

#include <catch2/catch.hpp>

template <size_t N>
constexpr datadog_php_string_view SV(const char (&str)[N]) noexcept {
  return {N - 1, str};
}

TEST_CASE("eq", "[string-view]") {
  char a[] = "aBcDE01234";
  char b[] = "abcde01234";

  auto x = datadog_php_string_view_from_cstr(a);
  auto y = datadog_php_string_view_from_cstr(b);
  REQUIRE(!datadog_php_string_view_eq(x, y));

  for (auto &ch : a) {
    ch = (char)(unsigned char)tolower((unsigned char)ch);
  }

  REQUIRE(datadog_php_string_view_eq(x, y));
}

TEST_CASE("eq different lengths", "[string-view]") {
  char a[] = "aBcDE0";
  char b[] = "abcde01234";

  auto x = datadog_php_string_view_from_cstr(a);
  auto y = datadog_php_string_view_from_cstr(b);
  REQUIRE(!datadog_php_string_view_eq(x, y));

  for (auto &ch : a) {
    ch = (char)(unsigned char)tolower((unsigned char)ch);
  }

  // still not equal after lower-casing
  REQUIRE(!datadog_php_string_view_eq(x, y));
}

TEST_CASE("is boolean true", "[string-view]") {
  datadog_php_string_view true_strings[] = {
      SV("1"),
      SV("on"),
      SV("yes"),
      SV("true"),
  };

  for (auto str : true_strings) {
    CHECK(datadog_php_string_view_is_boolean_true(str));
  }

  /* There are many strings which are not true, but these are likely to be
   * passed by users, so let's ensure them:
   */
  datadog_php_string_view false_strings[] = {
      SV("0"),
      SV("no"),
      SV("off"),
      SV("false"),
  };

  for (auto str : false_strings) {
    CHECK(!datadog_php_string_view_is_boolean_true(str));
  }
}
