extern "C" {
#include "config_test_helpers.h"

#include "config/config.h"
#include "ext_zai_config.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
}

#include <catch2/catch.hpp>
#include <cstdio>
#include <cstring>

typedef enum {
    EXT_CFG_INI_FOO_BOOL,
    EXT_CFG_INI_FOO_DOUBLE,
    EXT_CFG_INI_FOO_INT,
    EXT_CFG_INI_FOO_MAP,
    EXT_CFG_INI_FOO_STRING,
    EXT_CFG_INI_BAR_ALIASED_BOOL,
    EXT_CFG_INI_BAR_ALIASED_DOUBLE,
    EXT_CFG_INI_BAR_ALIASED_INT,
    EXT_CFG_INI_BAZ_MAP_EMPTY,
} ext_ini_cfg_id;

static void ext_ini_env_to_ini_name(zai_string_view env_name, zai_config_ini_name *ini_name) {
    int len = snprintf(ini_name->ptr, ZAI_CONFIG_INI_NAME_BUFSIZ, "zai_config.%s", env_name.ptr);
    ini_name->len = (len > 0 && len < ZAI_CONFIG_INI_NAME_BUFSIZ) ? (size_t)len : 0;
}

static PHP_MINIT_FUNCTION(zai_config_ini) {
    zai_string_view aliases_bool[] = {ZAI_STRL_VIEW("BAR_ALIASED_BOOL_OLD")};
    zai_string_view aliases_double[] = {ZAI_STRL_VIEW("BAR_ALIASED_DOUBLE_OLD"), ZAI_STRL_VIEW("BAR_ALIASED_DOUBLE_OLDER")};
    zai_string_view aliases_int[] = {ZAI_STRL_VIEW("BAR_ALIASED_INT_OLD"), ZAI_STRL_VIEW("BAR_ALIASED_INT_OLDER"), ZAI_STRL_VIEW("BAR_ALIASED_INT_OLDEST")};
    zai_config_entry entries[] = {
        EXT_CFG_ENTRY(INI_FOO_BOOL, BOOL, "1"),
        EXT_CFG_ENTRY(INI_FOO_DOUBLE, DOUBLE, "0.5"),
        EXT_CFG_ENTRY(INI_FOO_INT, INT, "42"),
        EXT_CFG_ENTRY(INI_FOO_MAP, MAP, "one:1,two:2"),
        EXT_CFG_ENTRY(INI_FOO_STRING, STRING, "foo string"),
        /*
        EXT_CFG_ALIASED_ENTRY(INI_BAR_ALIASED_BOOL, BOOL, "0", aliases_bool),
        EXT_CFG_ALIASED_ENTRY(INI_BAR_ALIASED_DOUBLE, DOUBLE, "0", aliases_double),
        EXT_CFG_ALIASED_ENTRY(INI_BAR_ALIASED_INT, INT, "0", aliases_int),
        EXT_CFG_ENTRY(INI_BAZ_MAP_EMPTY, MAP, ""),
        */
    };
    zai_config_minit(entries, (sizeof entries / sizeof entries[0]), ext_ini_env_to_ini_name, module_number);
    return SUCCESS;
}

/********************* zai_config_get_value() (from INI) **********************/

#define TEST_INI(name, ini, code) TEST_CASE(name, "[zai_config_ini]") { \
        REQUIRE(zai_sapi_sinit()); \
        ext_zai_config_ctor(&zai_sapi_extension, PHP_MINIT(zai_config_ini)); \
        { ini } \
        REQUIRE(zai_sapi_minit()); \
        { code } \
        zai_sapi_mshutdown(); \
        zai_sapi_sshutdown(); \
    }

static bool zai_config_set_runtime_ini(const char *name, size_t name_len, const char *value, size_t value_len) {
    zend_string *zs_name = zend_string_init(name, name_len, /* persistent */ 0);
    zend_string *zs_value = zend_string_init(value, value_len, /* persistent */ 0);
    bool ret = zend_alter_ini_entry_ex(zs_name, zs_value, PHP_INI_USER, PHP_INI_STAGE_RUNTIME, /* force_change */ 0) == SUCCESS;
    zend_string_release(zs_name);
    zend_string_release(zs_value);
    return ret;
}

#define REQUIRE_SET_INI(name, val) REQUIRE(zai_config_set_runtime_ini(ZEND_STRL(name), ZEND_STRL(val)))

TEST_INI("bool INI: default value", {}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_TRUE);

    REQUEST_END()
})

TEST_INI("bool INI: system value", {
    REQUIRE(zai_sapi_append_system_ini_entry("zai_config.INI_FOO_BOOL", "0"));
}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_FALSE);

    REQUEST_END()
})

TEST_INI("bool INI: user value", {}, {
    REQUEST_BEGIN()

    REQUIRE_SET_INI("zai_config.INI_FOO_BOOL", "0");

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_FALSE);

    REQUEST_END()
})

TEST_INI("double INI: default value", {}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 0.5);

    REQUEST_END()
})

TEST_INI("double INI: system value", {
    REQUIRE(zai_sapi_append_system_ini_entry("zai_config.INI_FOO_DOUBLE", "4.2"));
}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 4.2);

    REQUEST_END()
})

TEST_INI("double INI: user value", {}, {
    REQUEST_BEGIN()

    REQUIRE_SET_INI("zai_config.INI_FOO_DOUBLE", "0");

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_DOUBLE);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(value) == 0.0);

    REQUEST_END()
})

TEST_INI("int INI: default value", {}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 42);

    REQUEST_END()
})

TEST_INI("int INI: system value", {
    REQUIRE(zai_sapi_append_system_ini_entry("zai_config.INI_FOO_INT", "1337"));
}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 1337);

    REQUEST_END()
})

TEST_INI("int INI: user value", {}, {
    REQUEST_BEGIN()

    REQUIRE_SET_INI("zai_config.INI_FOO_INT", "0");

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 0);

    REQUEST_END()
})

TEST_INI("map INI: default value", {}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 2);
    REQUIRE_MAP_VALUE_EQ(value, one, 1);
    REQUIRE_MAP_VALUE_EQ(value, two, 2);

    REQUEST_END()
})

TEST_INI("map INI: system value", {
    REQUIRE(zai_sapi_append_system_ini_entry("zai_config.INI_FOO_MAP", "type:system"));
}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 1);
    REQUIRE_MAP_VALUE_EQ(value, type, system);

    REQUEST_END()
})

TEST_INI("map INI: user value", {}, {
    REQUEST_BEGIN()

    REQUIRE_SET_INI("zai_config.INI_FOO_MAP", "type:user");

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 1);
    REQUIRE_MAP_VALUE_EQ(value, type, user);

    REQUEST_END()
})

TEST_INI("string INI: default value", {}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(value), "foo string"));

    REQUEST_END()
})

TEST_INI("string INI: system value", {
    REQUIRE(zai_sapi_append_system_ini_entry("zai_config.INI_FOO_STRING", "system string"));
}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(value), "system string"));

    REQUEST_END()
})

TEST_INI("string INI: user value", {}, {
    REQUEST_BEGIN()

    REQUIRE_SET_INI("zai_config.INI_FOO_STRING", "user string");

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(value), "user string"));

    REQUEST_END()
})
