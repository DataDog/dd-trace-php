extern "C" {
#include "config_test_helpers.h"

#include "config/config.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
#include "ext_zai_config.h"
}

#include <catch2/catch.hpp>
#include <cstdio>
#include <cstring>

typedef enum {
    EXT_CFG_FOO_BOOL,
    EXT_CFG_FOO_DOUBLE,
    EXT_CFG_FOO_INT,
    EXT_CFG_FOO_MAP,
    EXT_CFG_FOO_STRING,
    EXT_CFG_BAR_ALIASED_BOOL,
    EXT_CFG_BAR_ALIASED_DOUBLE,
    EXT_CFG_BAR_ALIASED_INT,
    EXT_CFG_BAZ_MAP_EMPTY,
} ext_cfg_id;

static PHP_MINIT_FUNCTION(zai_config_env) {
    zai_string_view aliases_bool[] = {ZAI_STRL_VIEW("BAR_ALIASED_BOOL_OLD")};
    zai_string_view aliases_double[] = {ZAI_STRL_VIEW("BAR_ALIASED_DOUBLE_OLD"), ZAI_STRL_VIEW("BAR_ALIASED_DOUBLE_OLDER")};
    zai_string_view aliases_int[] = {ZAI_STRL_VIEW("BAR_ALIASED_INT_OLD"), ZAI_STRL_VIEW("BAR_ALIASED_INT_OLDER"), ZAI_STRL_VIEW("BAR_ALIASED_INT_OLDEST")};
    zai_config_entry entries[] = {
        EXT_CFG_ENTRY(FOO_BOOL, BOOL, "1"),
        EXT_CFG_ENTRY(FOO_DOUBLE, DOUBLE, "0.5"),
        EXT_CFG_ENTRY(FOO_INT, INT, "42"),
        EXT_CFG_ENTRY(FOO_MAP, MAP, "one:1,two:2"),
        EXT_CFG_ENTRY(FOO_STRING, STRING, "foo string"),
        EXT_CFG_ALIASED_ENTRY(BAR_ALIASED_BOOL, BOOL, "0", aliases_bool),
        EXT_CFG_ALIASED_ENTRY(BAR_ALIASED_DOUBLE, DOUBLE, "0", aliases_double),
        EXT_CFG_ALIASED_ENTRY(BAR_ALIASED_INT, INT, "0", aliases_int),
        EXT_CFG_ENTRY(BAZ_MAP_EMPTY, MAP, ""),
    };
    zai_config_minit(entries, (sizeof entries / sizeof entries[0]), NULL, 0);
    return SUCCESS;
}

#define TEST(name, code) TEST_CASE(name, "[zai_config]") { \
        REQUIRE(zai_sapi_sinit()); \
        ext_zai_config_ctor(&zai_sapi_extension, PHP_MINIT(zai_config_env)); \
        REQUIRE(zai_sapi_minit()); \
        { code } \
        zai_sapi_mshutdown(); \
        zai_sapi_sshutdown(); \
    }

/******************* zai_config_get_value() (default value) *******************/

TEST("default value: bool", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_TRUE);

    REQUEST_END()
})

TEST("default value: double", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 0.5);

    REQUEST_END()
})

TEST("default value: int", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 42);

    REQUEST_END()
})

TEST("default value: map", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 2);

    REQUIRE_MAP_VALUE_EQ(value, one, 1);
    REQUIRE_MAP_VALUE_EQ(value, two, 2);

    REQUEST_END()
})

TEST("default value: map (empty)", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_BAZ_MAP_EMPTY);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 0);

    REQUEST_END()
})

TEST("default value: string", {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(value), "foo string"));

    REQUEST_END()
})

/********************* zai_config_get_value() (from env) **********************/

#define REQUIRE_SETENV(key, val) REQUIRE(0 == setenv(key, val, /* overwrite */ 1))

TEST("env value: bool", {
    REQUIRE_SETENV("FOO_BOOL", "false");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_FALSE);

    REQUEST_END()
})

TEST("env value: bool (decoding error)", {
    REQUIRE_SETENV("FOO_BOOL", "nope");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_TRUE);  // Default fallback value

    REQUEST_END()
})

TEST("env value: double", {
    REQUIRE_SETENV("FOO_DOUBLE", "0");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 0.0);

    REQUEST_END()
})

TEST("env value: double (decoding error)", {
    REQUIRE_SETENV("FOO_DOUBLE", "zero");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 0.5);

    REQUEST_END()
})

TEST("env value: int", {
    REQUIRE_SETENV("FOO_INT", "0");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 0);

    REQUEST_END()
})

TEST("env value: int (decoding error)", {
    REQUIRE_SETENV("FOO_INT", "zero");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 42);

    REQUEST_END()
})

TEST("env value: map", {
    REQUIRE_SETENV("FOO_MAP", "env1:one,env2:two,env3:three");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 3);

    REQUIRE_MAP_VALUE_EQ(value, env1, one);
    REQUIRE_MAP_VALUE_EQ(value, env2, two);
    REQUIRE_MAP_VALUE_EQ(value, env3, three);

    REQUEST_END()
})

TEST("env value: map (empty)", {
    REQUIRE_SETENV("FOO_MAP", "");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 0);

    REQUEST_END()
})

TEST("env value: map (decoding error)", {
    REQUIRE_SETENV("FOO_MAP", "env1,one,env2,two,env3,three");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 2);

    REQUIRE_MAP_VALUE_EQ(value, one, 1);
    REQUIRE_MAP_VALUE_EQ(value, two, 2);

    REQUEST_END()
})

TEST("env value: string", {
    REQUIRE_SETENV("FOO_STRING", "env string");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(value), "env string"));

    REQUEST_END()
})

TEST("env value: string (empty)", {
    REQUIRE_SETENV("FOO_STRING", "");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(value), ""));

    REQUEST_END()
})

/*************************** zai_config_set_value() ***************************/

TEST("set bool", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_BOOL(&tmp, false);
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_BOOL, &tmp);

    REQUIRE(res == ZAI_CONFIG_SUCCESS);

    zval *value = zai_config_get_value(EXT_CFG_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_FALSE);

    REQUEST_END()
})

TEST("set bool (encoded)", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "0");
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_BOOL, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_SUCCESS);

    zval *value = zai_config_get_value(EXT_CFG_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_FALSE);

    REQUEST_END()
})

TEST("set bool (encoding error)", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "nope");
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_BOOL, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_ERROR_DECODING);

    zval *value = zai_config_get_value(EXT_CFG_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_TRUE);

    REQUEST_END()
})

TEST("set double", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_DOUBLE(&tmp, 4.2);
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_DOUBLE, &tmp);

    REQUIRE(res == ZAI_CONFIG_SUCCESS);

    zval *value = zai_config_get_value(EXT_CFG_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 4.2);

    REQUEST_END()
})

TEST("set double (encoded)", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "4.2");
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_DOUBLE, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_SUCCESS);

    zval *value = zai_config_get_value(EXT_CFG_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 4.2);

    REQUEST_END()
})

TEST("set double (encoding error)", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "one");
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_DOUBLE, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_ERROR_DECODING);

    zval *value = zai_config_get_value(EXT_CFG_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 0.5);

    REQUEST_END()
})

TEST("set int", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_LONG(&tmp, 1337);
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_INT, &tmp);

    REQUIRE(res == ZAI_CONFIG_SUCCESS);

    zval *value = zai_config_get_value(EXT_CFG_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 1337);

    REQUEST_END()
})

TEST("set int (encoded)", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "1337");
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_INT, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_SUCCESS);

    zval *value = zai_config_get_value(EXT_CFG_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 1337);

    REQUEST_END()
})

TEST("set int (encoding error)", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "one");
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_INT, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_ERROR_DECODING);

    zval *value = zai_config_get_value(EXT_CFG_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 42);

    REQUEST_END()
})

TEST("set map", {
    REQUEST_BEGIN()

    zval tmp;
    array_init(&tmp);
    add_assoc_stringl_ex(&tmp, ZEND_STRL("key_foo"), ZEND_STRL("foo"));
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_MAP, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_SUCCESS);

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 1);

    REQUIRE_MAP_VALUE_EQ(value, key_foo, foo);

    REQUEST_END()
})

TEST("set map (encoded)", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "key_foo:foo");
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_MAP, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_SUCCESS);

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 1);

    REQUIRE_MAP_VALUE_EQ(value, key_foo, foo);

    REQUEST_END()
})

TEST("set map (encoding error)", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "key_foo,foo");
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_MAP, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_ERROR_DECODING);

    zval *value = zai_config_get_value(EXT_CFG_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 2);

    REQUIRE_MAP_VALUE_EQ(value, one, 1);
    REQUIRE_MAP_VALUE_EQ(value, two, 2);

    REQUEST_END()
})

TEST("set string", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "updated string");
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_STRING, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_SUCCESS);

    zval *value = zai_config_get_value(EXT_CFG_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(value), "updated string"));

    REQUEST_END()
})

TEST("set value does not persist", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "updated string");
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_STRING, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_SUCCESS);

    zval *value = zai_config_get_value(EXT_CFG_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(value), "updated string"));

    REQUEST_END()

    // ---

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(value), "foo string"));

    REQUEST_END()
})

TEST("set error: invalid id", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_STRING(&tmp, "foo string");
    zai_config_result res = zai_config_set_value(ZAI_CONFIG_ENTRIES_COUNT_MAX, &tmp);
    zval_ptr_dtor(&tmp);

    REQUIRE(res == ZAI_CONFIG_ERROR);

    REQUEST_END()
})

TEST("set error: NULL value", {
    REQUEST_BEGIN()

    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_BOOL, NULL);

    REQUIRE(res == ZAI_CONFIG_ERROR);

    zval *value = zai_config_get_value(EXT_CFG_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_TRUE);

    REQUEST_END()
})

TEST("set error: invalid type", {
    REQUEST_BEGIN()

    zval tmp;
    ZVAL_LONG(&tmp, 42);
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_BOOL, &tmp);

    REQUIRE(res == ZAI_CONFIG_ERROR_INVALID_TYPE);

    zval *value = zai_config_get_value(EXT_CFG_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_TRUE);

    REQUEST_END()
})

TEST("set error: outside of request context", {
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval tmp;
    ZVAL_BOOL(&tmp, false);
    zai_config_result res = zai_config_set_value(EXT_CFG_FOO_BOOL, &tmp);

    REQUIRE(res == ZAI_CONFIG_ERROR_NOT_READY);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
})

/************************ zai_config_get_id_by_name() *************************/

TEST("get id", {
    REQUEST_BEGIN()

    zai_config_id id;
    bool res = zai_config_get_id_by_name(ZAI_STRL_VIEW("FOO_BOOL"), &id);

    REQUIRE(res == true);
    REQUIRE(id == EXT_CFG_FOO_BOOL);

    REQUEST_END()
})

TEST("get id: alias", {
    REQUEST_BEGIN()

    zai_config_id id;
    bool res = zai_config_get_id_by_name(ZAI_STRL_VIEW("BAR_ALIASED_INT_OLDEST"), &id);

    REQUIRE(res == true);
    REQUIRE(id == EXT_CFG_BAR_ALIASED_INT);

    REQUEST_END()
})

TEST("get id: unknown", {
    REQUEST_BEGIN()

    zai_config_id id;
    bool res = zai_config_get_id_by_name(ZAI_STRL_VIEW("THIS_DOES_NOT_EXIST"), &id);

    REQUIRE(res == false);

    REQUEST_END()
})

TEST("get id: null name", {
    REQUEST_BEGIN()

    zai_config_id id;
    zai_string_view name = ZAI_STRL_VIEW("FOO_BOOL");
    name.ptr = NULL;
    bool res = zai_config_get_id_by_name(name, &id);

    REQUIRE(res == false);

    REQUEST_END()
})

TEST("get id: null id", {
    REQUEST_BEGIN()

    bool res = zai_config_get_id_by_name(ZAI_STRL_VIEW("FOO_BOOL"), NULL);

    REQUIRE(res == false);

    REQUEST_END()
})

TEST("get id: outside of request context", {
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zai_config_id id;
    bool res = zai_config_get_id_by_name(ZAI_STRL_VIEW("FOO_BOOL"), &id);

    REQUIRE(res == false);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
})
