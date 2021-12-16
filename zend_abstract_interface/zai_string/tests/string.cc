extern "C" {
#include "zai_string/string.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"

#include "zai_compat.h"
}

#include <catch2/catch.hpp>
#include <cstdlib>
#include <cstring>

#define TEST(name, code) TEST_CASE(name, "[zai_string]") { \
        REQUIRE(zai_sapi_sinit()); \
        REQUIRE(zai_sapi_minit()); \
        REQUIRE(zai_string_minit()); \
        REQUIRE(zai_sapi_rinit()); \
        REQUIRE(zai_string_rinit()); \
        ZAI_SAPI_TSRMLS_FETCH(); \
        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() \
        { code } \
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE() \
        REQUIRE(zai_string_mshutdown()); \
        zai_sapi_spindown(); \
    }

TEST("known string values and lengths", {
    for (uint32_t idx = 0; idx < ZAI_STRINGS_KNOWN_LAST; idx++) {
    	REQUIRE(ZAI_STRING_KNOWN_VAL(idx));
    	REQUIRE(ZAI_STRING_KNOWN_LEN(idx));
    }
})
