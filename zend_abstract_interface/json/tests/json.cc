extern "C" {
#include "json/json.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"

#include "zai_compat.h"
}

#include <catch2/catch.hpp>
#include <cstdlib>
#include <cstring>

#define TEST(name, code) TEST_CASE(name, "[zai_json]") { \
        REQUIRE(zai_sapi_sinit()); \
        REQUIRE(zai_sapi_minit()); \
        zai_json_setup_bindings(); \
        REQUIRE(zai_sapi_rinit()); \
        ZAI_SAPI_TSRMLS_FETCH(); \
        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() \
        { code } \
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE() \
        zai_sapi_spindown(); \
    }

TEST("encode", {
    smart_str buf = {0};
    zval val;

    ZVAL_LONG(&val, 42);

    zai_json_encode(&buf, &val, 0 ZAI_TSRMLS_CC);

    REQUIRE(smart_str_length(&buf) > 0);

    smart_str_free(&buf);
})

TEST("decode", {
    smart_str buf = {0};
    zval val;

    ZVAL_LONG(&val, 42);

    zai_json_encode(&buf, &val, 0 ZAI_TSRMLS_CC);
    smart_str_0(&buf);

    REQUIRE(smart_str_length(&buf));

    ZVAL_NULL(&val);

    zai_json_decode(&val, smart_str_value(&buf), smart_str_length(&buf), 0, 1 ZAI_TSRMLS_CC);

    REQUIRE(Z_TYPE(val) == IS_LONG);
    REQUIRE(Z_LVAL(val) == 42);

    smart_str_free(&buf);
})
