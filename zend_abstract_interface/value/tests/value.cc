extern "C" {
#include "value/value.h"
#include "zai_sapi/zai_sapi.h"
}

#include <catch2/catch.hpp>

TEST_CASE("value version agnostic value handling", "[value]") {
    REQUIRE(zai_sapi_sinit());
    REQUIRE(zai_sapi_minit());
    REQUIRE(zai_sapi_rinit());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    {
        zval *value;

        ZAI_VALUE_MAKE(value);

        ZVAL_LONG(value, 10);

        REQUIRE(Z_TYPE_P(value) == IS_LONG);

        ZAI_VALUE_DTOR(value);
    }
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("value version agnostic string value constructor", "[value]") {
    REQUIRE(zai_sapi_sinit());
    REQUIRE(zai_sapi_minit());
    REQUIRE(zai_sapi_rinit());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    {
        zval *value;

        ZAI_VALUE_MAKE(value);

        ZAI_VALUE_STRINGL(value, "string", sizeof("string")-1);

        REQUIRE(Z_TYPE_P(value) == IS_STRING);

        ZAI_VALUE_DTOR(value);
    }
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}
