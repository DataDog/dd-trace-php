extern "C" {
#include "value/value.h"
#include "tea/sapi.h"
}

#include "tea/testing/catch2.hpp"

TEA_TEST_CASE("value", "scalar", {
    zval *value;

    ZAI_VALUE_MAKE(value);

    ZVAL_LONG(value, 10);

    REQUIRE(Z_TYPE_P(value) == IS_LONG);

    ZAI_VALUE_DTOR(value);
})

TEA_TEST_CASE("value", "string", {
    zval *value;

    ZAI_VALUE_MAKE(value);

    ZAI_VALUE_STRINGL(value, "string", sizeof("string")-1);

    REQUIRE(Z_TYPE_P(value) == IS_STRING);

    ZAI_VALUE_DTOR(value);
})
