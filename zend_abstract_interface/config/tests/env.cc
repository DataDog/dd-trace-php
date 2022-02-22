extern "C" {
#include "config_test_helpers.h"

#include "config/config.h"
#include "ext_zai_config.h"
}

#include "zai_tests_common.hpp"

#define TEST_ENV(description, ...)     TEA_TEST_CASE_BARE("config/env", description, ZAI_CONFIG_TEST_BODY(__VA_ARGS__))

TEST_ENV("bool", {
    REQUIRE_SETENV("FOO_BOOL", "false");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(ZVAL_IS_FALSE(value));

    REQUEST_END()
})

TEST_ENV("double", {
    REQUIRE_SETENV("FOO_DOUBLE", "0");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 0.0);

    REQUEST_END()
})

TEST_ENV("double (decoding error)", {
    REQUIRE_SETENV("FOO_DOUBLE", "zero");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 0.5);

    REQUEST_END()
})

TEST_ENV("int", {
    REQUIRE_SETENV("FOO_INT", "0");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 0);

    REQUEST_END()
})

TEST_ENV("int (decoding error)", {
    REQUIRE_SETENV("FOO_INT", "zero");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 42);

    REQUEST_END()
})

TEST_ENV("map", {
    REQUIRE_SETENV("FOO_MAP", "env1:one,env2:two,env3:three");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 3);

    REQUIRE_MAP_VALUE_EQ(value, "env1", "one");
    REQUIRE_MAP_VALUE_EQ(value, "env2", "two");
    REQUIRE_MAP_VALUE_EQ(value, "env3", "three");

    REQUEST_END()
})

TEST_ENV("map (empty)", {
    REQUIRE_SETENV("FOO_MAP", "");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 0);

    REQUEST_END()
})

TEST_ENV("map (decoding error)", {
    REQUIRE_SETENV("FOO_MAP", "env1,one,env2,two,env3,three");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 2);

    REQUIRE_MAP_VALUE_EQ(value, "one", "1");
    REQUIRE_MAP_VALUE_EQ(value, "two", "2");

    REQUEST_END()
})

TEST_ENV("string", {
    REQUIRE_SETENV("FOO_STRING", "env string");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zval_string_equals(value, "env string"));

    REQUEST_END()
})

TEST_ENV("string (empty)", {
    REQUIRE_SETENV("FOO_STRING", "");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zval_string_equals(value, ""));

    REQUEST_END()
})

TEST_ENV("alias", {
    REQUIRE_SETENV("BAR_ALIASED_INT_OLDER", "1");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_BAR_ALIASED_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 1);

    REQUEST_END()
})
