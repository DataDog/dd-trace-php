extern "C" {
#include "config_test_helpers.h"

#include "config/config_decode.h"
#include "ext_zai_config.h"
#include "json/json.h"
}

#include "zai_tests_common.hpp"

/************************* zai_config_decode_value() **************************/

TEA_TEST_CASE("config/decode", "bool", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_BOOL;

    // ---

    zai_str truthy[] = {
        ZAI_STRL("1"),
        ZAI_STRL("true"),
        ZAI_STRL("TRUE"),
        ZAI_STRL("True"),
        ZAI_STRL("yes"),
        ZAI_STRL("on"),
    };

    for (zai_str name : truthy) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, NULL, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_TRUE);
    }

    // ---

    zai_str falsey[] = {
        ZAI_STRL("0"),
        ZAI_STRL("false"),
        ZAI_STRL("FALSE"),
        ZAI_STRL("False"),
        ZAI_STRL("no"),
        ZAI_STRL("off"),
        ZAI_STR_EMPTY,
    };

    for (zai_str name : falsey) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, NULL, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_FALSE);
    }
})

typedef struct expected_double_s {
    zai_str name;
    double value;
} expected_double;

TEA_TEST_CASE("config/decode", "double", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_DOUBLE;

    // ---

    expected_double successes[] = {
        {ZAI_STRL("0"), 0.0},
        {ZAI_STRL("1"), 1.0},
        {ZAI_STRL("-1.5"), -1.5},
        {ZAI_STRL("4.2"), 4.2},
        {ZAI_STRL("    4.2    "), 4.2},
        {ZAI_STRL("4.   2"), 4.0},  // It's weird, but ¯\_(ツ)_/¯
    };

    for (expected_double expected : successes) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(expected.name, type, NULL, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_DOUBLE);
        REQUIRE(Z_DVAL(value) == expected.value);
    }

    // ---

    zai_str errors[] = {
        ZAI_STR_EMPTY,
        ZAI_STRL("NaN"),
        ZAI_STRL("INF"),
        ZAI_STRL("foo"),
        ZAI_STRL("4foo"),
        ZAI_STRL("0x0"),
        ZAI_STRL("0.0.0"),
        ZAI_STRL("4.2.0"),
    };

    for (zai_str name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, NULL, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) <= IS_NULL);
    }
})

typedef struct expected_int_s {
    zai_str name;
    int value;
} expected_int;

TEA_TEST_CASE("config/decode", "int", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_INT;

    // ---

    expected_int successes[] = {
        {ZAI_STRL("0"), 0},
        {ZAI_STRL("1"), 1},
        {ZAI_STRL("-2"), -2},
        {ZAI_STRL("42"), 42},
        {ZAI_STRL("    42    "), 42},
#if PHP_VERSION_ID < 80200
// int parsing changed on PHP 8.2
        {ZAI_STRL("4   2"), 4},  // It's weird, but ¯\_(ツ)_/¯
#endif
    };

    for (expected_int expected : successes) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(expected.name, type, NULL, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_LONG);
        REQUIRE(Z_LVAL(value) == expected.value);
    }

    // ---

    zai_str errors[] = {
        ZAI_STR_EMPTY,
        ZAI_STRL("NaN"),
        ZAI_STRL("INF"),
        ZAI_STRL("foo"),
        ZAI_STRL("4foo"),
        ZAI_STRL("0x0"),
        ZAI_STRL("0.0.0"),
        ZAI_STRL("4.2.0"),
#if PHP_VERSION_ID >= 80200
        ZAI_STRL("4   2"),
#endif
    };

    for (zai_str name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, NULL, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) <= IS_NULL);
    }
})

typedef struct expected_map_s {
    zai_str name;
    const char *key[3];
    const char *value[3];
} expected_map;

TEA_TEST_CASE("config/decode", "map", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_MAP;

    // ---

    expected_map successes[] = {
        {ZAI_STRL("key:val,c:d"), { "key", "c" }, { "val", "d" }},
        {ZAI_STRL("\t\na\t:\n"), { "a" }, { "" }},
        {ZAI_STRL("\t\na\t:\n,c:"), { "a", "c" }, { "", "" }},
        {ZAI_STRL("\t\na\t: b \n"), { "a" }, { "b" }},
        {ZAI_STRL("a:b\t,\t"), { "a" }, { "b" }},
        {ZAI_STRL("\t,a:b"), { "a" }, { "b" }},
    };

    for (expected_map expected : successes) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(expected.name, type, NULL, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_ARRAY);
        int count = 0;
        while (expected.key[count]) {
            REQUIRE_MAP_VALUE_EQ(&value, expected.key[count], expected.value[count]);
            ++count;
        }
        REQUIRE(zend_hash_num_elements(Z_ARRVAL(value)) == count);
        zend_hash_destroy(Z_ARRVAL(value));
        efree(Z_ARRVAL(value));
    }

    // ---

    zai_str errors[] = {
        ZAI_STRL(":"),
        ZAI_STRL(","),
        ZAI_STRL(":,"),
        ZAI_STRL(":a"),
        ZAI_STRL(" : "),
        ZAI_STRL(", "),
        ZAI_STRL("\t\n:"),
    };

    for (zai_str name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, NULL, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) <= IS_NULL);
    }
})

typedef struct expected_set_s {
    zai_str name;
    const char *key[3];
} expected_set;


TEA_TEST_CASE("config/decode", "set", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_SET;

    // ---

    expected_set successes[] = {
        {ZAI_STRL("key,foo"), { "key", "foo" }},
        {ZAI_STRL("\t\n a \t\n"), { "a" }},
        {ZAI_STRL("a\t\n,\t\nb"), { "a", "b" }},
        {ZAI_STRL("a\t,\t"), { "a" }},
        {ZAI_STRL("\t,,a"), { "a" }},
    };

    for (expected_set expected : successes) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(expected.name, type, NULL, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_ARRAY);
        int count = 0;
        while (expected.key[count]) {
            REQUIRE_MAP_KEY(&value, expected.key[count]);
            ++count;
        }
        REQUIRE(zend_hash_num_elements(Z_ARRVAL(value)) == count);
        zend_hash_destroy(Z_ARRVAL(value));
        efree(Z_ARRVAL(value));
    }

    // ---

    zai_str errors[] = {
        ZAI_STRL(","),
        ZAI_STRL("\t\n, "),
    };

    for (zai_str name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, NULL, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) <= IS_NULL);
    }
})

// we deliberately do not test the json implementation, but just the basic invocation and conversion to persistent
// and also the handling of error cases
TEA_TEST_CASE_BARE("config/decode", "json", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_JSON;

    REQUIRE(tea_sapi_spinup());
    TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()
    REQUIRE(zai_json_setup_bindings());

    // ---

    zai_str errors[] = {
        ZAI_STRL("0"),
        ZAI_STRL("\"foo\""),
        ZAI_STRL("[[[[[[[[[[[[[[[[[[[[[[[[[]]]]]]]]]]]]]]]]]]]]]]]]]"),
    };

    for (zai_str name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, NULL, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) <= IS_NULL);
    }

    // ---

    ZVAL_UNDEF(&value);
    ret = zai_config_decode_value(ZAI_STRL("{\"foo\":1,\"bar\":\"str\",\"baz\":[1],\"empty\":[]}"), type, NULL, &value, true);

    // ---

    TEA_TEST_CASE_WITHOUT_BAILOUT_END()
    tea_sapi_spindown();

    // execute after zend_MM free
    REQUIRE(ret == true);
    REQUIRE(Z_TYPE(value) == IS_ARRAY);
    REQUIRE_MAP_KEY(&value, "foo");
    REQUIRE_MAP_VALUE_EQ(&value, "bar", "str");
    REQUIRE_MAP_KEY(&value, "baz");
    REQUIRE_MAP_KEY(&value, "empty");
    REQUIRE(zend_hash_num_elements(Z_ARRVAL(value)) == 4);
    zend_hash_destroy(Z_ARRVAL(value));
    free(Z_ARRVAL(value));
})

/******************* zai_config_decode_value() (persistent) *******************/
