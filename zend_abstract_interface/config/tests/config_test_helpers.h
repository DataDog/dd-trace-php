#ifndef CONFIG_TEST_HELPERS_H
#define CONFIG_TEST_HELPERS_H

#include "zai_sapi/zai_sapi.h"

#define REQUEST_BEGIN()            \
    {                              \
        REQUIRE(zai_sapi_rinit()); \
        ZAI_SAPI_TSRMLS_FETCH();   \
        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

#define REQUEST_END()                 \
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE() \
    zai_sapi_rshutdown();             \
    }

#define REQUIRE_MAP_VALUE_EQ(zv, key, value)                             \
    zval *z_##key = zend_hash_str_find(Z_ARRVAL_P(zv), ZEND_STRL(#key)); \
    REQUIRE(z_##key != NULL);                                            \
    REQUIRE(Z_TYPE_P(z_##key) == IS_STRING);                             \
    REQUIRE(zend_string_equals_literal(Z_STR_P(z_##key), #value))

#endif  // CONFIG_TEST_HELPERS_H
