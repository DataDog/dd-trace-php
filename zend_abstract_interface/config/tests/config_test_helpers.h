#ifndef CONFIG_TEST_HELPERS_H
#define CONFIG_TEST_HELPERS_H

#include "zai_sapi/zai_sapi.h"

static inline bool zval_string_equals(zval *value, const char *str) {
    return Z_STRLEN_P(value) == strlen(str) && !strcmp(Z_STRVAL_P(value), str);
}

#define REQUIRE_SETENV(key, val) REQUIRE(0 == setenv(key, val, /* overwrite */ 1))

#define REQUEST_BEGIN()            \
    {                              \
        REQUIRE(zai_sapi_rinit()); \
        ZAI_SAPI_TSRMLS_FETCH();   \
        ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN()

#define REQUEST_END()                        \
    ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END() \
    zai_sapi_rshutdown();                    \
    }

#if PHP_VERSION_ID < 70000
#undef ZVAL_STRING
#define ZVAL_STRING(z, s) ZVAL_STRINGL(z, s, strlen(s), 1)
#define ZVAL_UNDEF(z)  \
    {                  \
        INIT_PZVAL(z); \
        ZVAL_NULL(z);  \
    }

#define ZVAL_IS_TRUE(z) (Z_TYPE_P(z) == IS_BOOL && Z_BVAL_P(z))
#define ZVAL_IS_FALSE(z) (Z_TYPE_P(z) == IS_BOOL && !Z_BVAL_P(z))
#undef add_assoc_string
#define add_assoc_string(ht, key, str) add_assoc_string_ex(ht, key, strlen(key) + 1, str, 1)

#define REQUIRE_MAP_VALUE_EQ(zv, key, value)                                                            \
    {                                                                                                   \
        zval **z_mapvalue;                                                                              \
        REQUIRE(zend_hash_find(Z_ARRVAL_P(zv), key, strlen(key) + 1, (void **)&z_mapvalue) == SUCCESS); \
        REQUIRE(Z_TYPE_PP(z_mapvalue) == IS_STRING);                                                    \
        REQUIRE(zval_string_equals(*z_mapvalue, value));                                                \
    }

#define REQUIRE_MAP_KEY(zv, key)                                                                        \
    {                                                                                                   \
        zval **z_mapvalue;                                                                              \
        REQUIRE(zend_hash_find(Z_ARRVAL_P(zv), key, strlen(key) + 1, (void **)&z_mapvalue) == SUCCESS); \
    }
#else
#define ZVAL_IS_TRUE(z) (Z_TYPE_P(z) == IS_TRUE)
#define ZVAL_IS_FALSE(z) (Z_TYPE_P(z) == IS_FALSE)
#define INIT_PZVAL(z)

#define REQUIRE_MAP_VALUE_EQ(zv, key, value)                                     \
    {                                                                            \
        zval *z_mapvalue = zend_hash_str_find(Z_ARRVAL_P(zv), key, strlen(key)); \
        REQUIRE(z_mapvalue != NULL);                                             \
        REQUIRE(Z_TYPE_P(z_mapvalue) == IS_STRING);                              \
        REQUIRE(zval_string_equals(z_mapvalue, value));                          \
    }

#define REQUIRE_MAP_KEY(zv, key) REQUIRE(zend_hash_str_find(Z_ARRVAL_P(zv), key, strlen(key)) != NULL);
#endif

#endif  // CONFIG_TEST_HELPERS_H
