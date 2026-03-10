#ifndef CONFIG_TEST_HELPERS_H
#define CONFIG_TEST_HELPERS_H

#include "tea/sapi.h"

static inline bool zval_string_equals(zval *value, const char *str) {
    return Z_STRLEN_P(value) == strlen(str) && !strcmp(Z_STRVAL_P(value), str);
}

#ifdef __cplusplus
#include <string>

/* Use Catch2's expression decomposition: REQUIRE(a == b) prints both values on failure (like assert_eq! in Rust). */
#define REQUIRE_ZVAL_STRING_EQ(zv, expected)                                              \
    do {                                                                                   \
        REQUIRE(zv != NULL);                                                               \
        REQUIRE(Z_TYPE_P(zv) == IS_STRING);                                                \
        REQUIRE(std::string(Z_STRVAL_P(zv), Z_STRLEN_P(zv)) == std::string(expected));    \
    } while (0)
#endif

#define REQUIRE_SETENV(key, val) REQUIRE(0 == setenv(key, val, /* overwrite */ 1))

#define REQUEST_BEGIN()            \
    {                              \
        REQUIRE(tea_sapi_rinit()); \
        TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()

#define REQUEST_END()                   \
    TEA_TEST_CASE_WITHOUT_BAILOUT_END() \
    tea_sapi_rshutdown();               \
    }

#define REQUIRE_MAP_VALUE_EQ(zv, key, value)                                     \
    {                                                                            \
        zval *z_mapvalue = zend_hash_str_find(Z_ARRVAL_P(zv), key, strlen(key)); \
        REQUIRE(z_mapvalue != NULL);                                             \
        REQUIRE(Z_TYPE_P(z_mapvalue) == IS_STRING);                              \
        REQUIRE(zval_string_equals(z_mapvalue, value));                          \
    }

#define REQUIRE_MAP_KEY(zv, key) REQUIRE(zend_hash_str_find(Z_ARRVAL_P(zv), key, strlen(key)) != NULL);

#endif  // CONFIG_TEST_HELPERS_H
