#ifndef HAVE_ZAI_TESTS_COMMON_HPP
#define HAVE_ZAI_TESTS_COMMON_HPP

#include "zai_sapi/testing/catch2.hpp"

#define REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE()            \
    REQUIRE(false == zai_sapi_unhandled_exception_exists()); \
    REQUIRE(zai_sapi_last_error_is_empty());
#endif
