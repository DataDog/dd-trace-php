#ifndef HAVE_ZAI_TESTS_COMMON_HPP
#define HAVE_ZAI_TESTS_COMMON_HPP

#include "tea/testing/catch2.hpp"

#define REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE()         \
    REQUIRE(false == tea_exception_exists()); \
    REQUIRE(tea_error_is_empty());
#endif
