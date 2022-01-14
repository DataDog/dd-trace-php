extern "C" {
#include "config_test_helpers.h"

#include "config/config_decode.h"
#include "ext_zai_config.h"
#include "json/json.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
}

#include "zai_tests_common.hpp"

/************************* zai_config_decode_value() **************************/

ZAI_SAPI_TEST_CASE("config/decode", "bool", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_BOOL;

    // ---

    zai_string_view truthy[] = {
        ZAI_STRL_VIEW("1"),
        ZAI_STRL_VIEW("true"),
        ZAI_STRL_VIEW("TRUE"),
        ZAI_STRL_VIEW("True"),
        ZAI_STRL_VIEW("yes"),
        ZAI_STRL_VIEW("on"),
    };

    for (zai_string_view name : truthy) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == true);
        REQUIRE(ZVAL_IS_TRUE(&value));
    }

    // ---

    zai_string_view falsey[] = {
        ZAI_STRL_VIEW("0"),
        ZAI_STRL_VIEW("false"),
        ZAI_STRL_VIEW("FALSE"),
        ZAI_STRL_VIEW("False"),
        ZAI_STRL_VIEW("no"),
        ZAI_STRL_VIEW("off"),
        ZAI_STRL_VIEW(""),
    };

    for (zai_string_view name : falsey) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == true);
        REQUIRE(ZVAL_IS_FALSE(&value));
    }
})

typedef struct expected_double_s {
    zai_string_view name;
    double value;
} expected_double;

ZAI_SAPI_TEST_CASE("config/decode", "double", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_DOUBLE;

    // ---

    expected_double successes[] = {
        {ZAI_STRL_VIEW("0"), 0.0},
        {ZAI_STRL_VIEW("1"), 1.0},
        {ZAI_STRL_VIEW("-1.5"), -1.5},
        {ZAI_STRL_VIEW("4.2"), 4.2},
        {ZAI_STRL_VIEW("    4.2    "), 4.2},
        {ZAI_STRL_VIEW("4.   2"), 4.0},  // It's weird, but ¯\_(ツ)_/¯
    };

    for (expected_double expected : successes) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(expected.name, type, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_DOUBLE);
        REQUIRE(Z_DVAL(value) == expected.value);
    }

    // ---

    zai_string_view errors[] = {
        ZAI_STRL_VIEW(""),
        ZAI_STRL_VIEW("NaN"),
        ZAI_STRL_VIEW("INF"),
        ZAI_STRL_VIEW("foo"),
        ZAI_STRL_VIEW("4foo"),
        ZAI_STRL_VIEW("0x0"),
        ZAI_STRL_VIEW("0.0.0"),
        ZAI_STRL_VIEW("4.2.0"),
    };

    for (zai_string_view name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) <= IS_NULL);
    }
})

typedef struct expected_int_s {
    zai_string_view name;
    int value;
} expected_int;

ZAI_SAPI_TEST_CASE("config/decode", "int", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_INT;

    // ---

    expected_int successes[] = {
        {ZAI_STRL_VIEW("0"), 0},
        {ZAI_STRL_VIEW("1"), 1},
        {ZAI_STRL_VIEW("-2"), -2},
        {ZAI_STRL_VIEW("42"), 42},
        {ZAI_STRL_VIEW("    42    "), 42},
        {ZAI_STRL_VIEW("4   2"), 4},  // It's weird, but ¯\_(ツ)_/¯
    };

    for (expected_int expected : successes) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(expected.name, type, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_LONG);
        REQUIRE(Z_LVAL(value) == expected.value);
    }

    // ---

    zai_string_view errors[] = {
        ZAI_STRL_VIEW(""),
        ZAI_STRL_VIEW("NaN"),
        ZAI_STRL_VIEW("INF"),
        ZAI_STRL_VIEW("foo"),
        ZAI_STRL_VIEW("4foo"),
        ZAI_STRL_VIEW("0x0"),
        ZAI_STRL_VIEW("0.0.0"),
        ZAI_STRL_VIEW("4.2.0"),
    };

    for (zai_string_view name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) <= IS_NULL);
    }
})

typedef struct expected_map_s {
    zai_string_view name;
    const char *key[3];
    const char *value[3];
} expected_map;

ZAI_SAPI_TEST_CASE("config/decode", "map", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_MAP;

    // ---

    expected_map successes[] = {
        {ZAI_STRL_VIEW("key:val,c:d"), { "key", "c" }, { "val", "d" }},
        {ZAI_STRL_VIEW("\t\na\t:\n"), { "a" }, { "" }},
        {ZAI_STRL_VIEW("\t\na\t:\n,c:"), { "a", "c" }, { "", "" }},
        {ZAI_STRL_VIEW("\t\na\t: b \n"), { "a" }, { "b" }},
        {ZAI_STRL_VIEW("a:b\t,\t"), { "a" }, { "b" }},
        {ZAI_STRL_VIEW("\t,a:b"), { "a" }, { "b" }},
    };

    for (expected_map expected : successes) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(expected.name, type, &value, false);

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

    zai_string_view errors[] = {
        ZAI_STRL_VIEW(":"),
        ZAI_STRL_VIEW(","),
        ZAI_STRL_VIEW(":,"),
        ZAI_STRL_VIEW(":a"),
        ZAI_STRL_VIEW(" : "),
        ZAI_STRL_VIEW(", "),
        ZAI_STRL_VIEW("\t\n:"),
    };

    for (zai_string_view name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) <= IS_NULL);
    }
})

typedef struct expected_set_s {
    zai_string_view name;
    const char *key[3];
} expected_set;


ZAI_SAPI_TEST_CASE("config/decode", "set", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_SET;

    // ---

    expected_set successes[] = {
        {ZAI_STRL_VIEW("key,foo"), { "key", "foo" }},
        {ZAI_STRL_VIEW("\t\n a \t\n"), { "a" }},
        {ZAI_STRL_VIEW("a\t\n,\t\nb"), { "a", "b" }},
        {ZAI_STRL_VIEW("a\t,\t"), { "a" }},
        {ZAI_STRL_VIEW("\t,,a"), { "a" }},
    };

    for (expected_set expected : successes) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(expected.name, type, &value, false);

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

    zai_string_view errors[] = {
        ZAI_STRL_VIEW(","),
        ZAI_STRL_VIEW("\t\n, "),
    };

    for (zai_string_view name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) <= IS_NULL);
    }
})

// we deliberately do not test the json implementation, but just the basic invocation and conversion to persistent
// and also the handling of error cases
ZAI_SAPI_TEST_CASE_BARE("config/decode", "json", {
    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_JSON;

    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN()
    REQUIRE(zai_json_setup_bindings());

    // ---

    zai_string_view errors[] = {
        ZAI_STRL_VIEW("0"),
        ZAI_STRL_VIEW("\"foo\""),
        ZAI_STRL_VIEW("[[[[[[[[[[[[[[[[[[[[[[[[[]]]]]]]]]]]]]]]]]]]]]]]]]"),
    };

    for (zai_string_view name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) <= IS_NULL);
    }

    // ---

    ZVAL_UNDEF(&value);
    ret = zai_config_decode_value(ZAI_STRL_VIEW("{\"foo\":1,\"bar\":\"str\",\"baz\":[1],\"empty\":[]}"), type, &value, true);

    // ---

    ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END()
    zai_sapi_spindown();

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
