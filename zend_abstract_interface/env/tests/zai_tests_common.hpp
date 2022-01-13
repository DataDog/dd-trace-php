#ifndef HAVE_ENV_TESTS_COMMON_HPP
#define HAVE_ENV_TESTS_COMMON_HPP
#include "zai_sapi/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

#define REQUIRE_SETENV(key, val) REQUIRE(0 == setenv(key, val, /* overwrite */ 1))
#define REQUIRE_UNSETENV(key) REQUIRE(0 == unsetenv(key))
#define REQUIRE_BUF_EQ(str, buf) REQUIRE(0 == strcmp(str, buf.ptr))

#endif
