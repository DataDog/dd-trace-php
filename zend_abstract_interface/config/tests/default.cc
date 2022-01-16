extern "C" {
#include "config_test_helpers.h"

#include "config/config.h"
#include "tea/sapi.h"
#include "tea/extension.h"
#include "ext_zai_config.h"
}

#include "zai_tests_common.hpp"

#define TEST_DEFAULT(description, ...) TEA_TEST_CASE_BARE("config/default", description, ZAI_CONFIG_TEST_BODY(__VA_ARGS__))

TEST_DEFAULT("bool", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(ZVAL_IS_TRUE(value));

    REQUEST_END()
})

TEST_DEFAULT("double", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 0.5);

    REQUEST_END()
})

TEST_DEFAULT("int", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 42);

    REQUEST_END()
})

TEST_DEFAULT("map", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 2);

    REQUIRE_MAP_VALUE_EQ(value, "one", "1");
    REQUIRE_MAP_VALUE_EQ(value, "two", "2");

    REQUEST_END()
})

TEST_DEFAULT("map (empty)", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_BAZ_MAP_EMPTY);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 0);

    REQUEST_END()
})

TEST_DEFAULT("string", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zval_string_equals(value, "foo string"));

    REQUEST_END()
})
