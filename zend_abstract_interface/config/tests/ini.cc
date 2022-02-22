extern "C" {
#include "config_test_helpers.h"

#include "config/config.h"
#include "ext_zai_config.h"
}

#include "zai_tests_common.hpp"

typedef enum {
    EXT_CFG_INI_FOO_BOOL,
    EXT_CFG_INI_FOO_DOUBLE,
    EXT_CFG_INI_FOO_INT,
    EXT_CFG_INI_FOO_MAP,
    EXT_CFG_INI_FOO_STRING,
    EXT_CFG_INI_BAR_ALIASED_INT,
    EXT_CFG_INI_BAR_ALIASED_STRING,
    EXT_CFG_INI_BAZ_MAP_EMPTY,
} ext_ini_cfg_id;

static void ext_ini_env_to_ini_name(zai_string_view env_name, zai_config_name *ini_name) {
    int len = snprintf(ini_name->ptr, ZAI_CONFIG_NAME_BUFSIZ, "zai_config.%s", env_name.ptr);
    ini_name->len = (len > 0 && len < ZAI_CONFIG_NAME_BUFSIZ) ? (size_t)len : 0;
}

static PHP_MINIT_FUNCTION(zai_config_ini) {
    zai_string_view aliases_int[] = {ZAI_STRL_VIEW("INI_BAR_ALIASED_INT_OLD")};
    zai_string_view aliases_string[] = {ZAI_STRL_VIEW("INI_BAR_ALIASED_STRING_OLD"), ZAI_STRL_VIEW("INI_BAR_ALIASED_STRING_OLDER")};
    zai_config_entry entries[] = {
        EXT_CFG_ENTRY(INI_FOO_BOOL, BOOL, "1"),
        EXT_CFG_ENTRY(INI_FOO_DOUBLE, DOUBLE, "0.5"),
        EXT_CFG_ENTRY(INI_FOO_INT, INT, "42"),
        EXT_CFG_ENTRY(INI_FOO_MAP, MAP, "one:1,two:2"),
        EXT_CFG_ENTRY(INI_FOO_STRING, STRING, "foo string"),
        EXT_CFG_ALIASED_ENTRY(INI_BAR_ALIASED_INT, INT, "0", aliases_int),
        EXT_CFG_ALIASED_ENTRY(INI_BAR_ALIASED_STRING, STRING, "0", aliases_string),
        EXT_CFG_ENTRY(INI_BAZ_MAP_EMPTY, MAP, ""),
    };
    if (!zai_config_minit(entries, (sizeof entries / sizeof entries[0]), ext_ini_env_to_ini_name, module_number)) {
        return FAILURE;
    }
    return SUCCESS;
}

#undef TEST_BODY
#define TEST_BODY(ini, ...)    \
{                              \
    REQUIRE(tea_sapi_sinit()); \
    ext_zai_config_ctor(PHP_MINIT(zai_config_ini)); \
    { ini }                    \
    REQUIRE(tea_sapi_minit()); \
    { __VA_ARGS__ }            \
    tea_sapi_mshutdown();      \
    tea_sapi_sshutdown();      \
}

#define TEST_INI(description, ini, ...) TEA_TEST_CASE_BARE("config/ini", description, TEST_BODY(ini, __VA_ARGS__))

/********************* zai_config_get_value() (from INI) **********************/

static bool zai_config_set_runtime_ini(const char *name, size_t name_len, const char *value, size_t value_len, int stage) {
#if PHP_VERSION_ID < 70000
    TEA_TSRMLS_FETCH();
    return zend_alter_ini_entry_ex((char *) name, name_len + 1, (char *) value, value_len, PHP_INI_USER, stage, /* force_change */ 0 TEA_TSRMLS_CC) == SUCCESS;
#else
    zend_string *zs_name = zend_string_init(name, name_len, /* persistent */ 0);
    zend_string *zs_value = zend_string_init(value, value_len, /* persistent */ 0);
    bool ret = zend_alter_ini_entry_ex(zs_name, zs_value, PHP_INI_USER, stage, /* force_change */ 0) == SUCCESS;
    zend_string_release(zs_name);
    zend_string_release(zs_value);
    return ret;
#endif
}

static void zai_config_restore_runtime_ini(const char *name, size_t name_len) {
#if PHP_VERSION_ID < 70000
    zend_restore_ini_entry((char *) name, name_len + 1, PHP_INI_STAGE_RUNTIME);
#else
    zend_string *zs_name = zend_string_init(name, name_len, /* persistent */ 0);
    zend_restore_ini_entry(zs_name, ZEND_INI_STAGE_RUNTIME);
    zend_string_release(zs_name);
#endif
}

static char *zai_config_ini_string(const char *name, size_t name_len) {
#if PHP_VERSION_ID < 70000
    ++name_len;
#endif
    return zend_ini_string((char *) name, name_len, 0);
}

#define REQUIRE_SET_INI_PERDIR(name, val) REQUIRE(zai_config_set_runtime_ini(ZEND_STRL(name), ZEND_STRL(val), PHP_INI_STAGE_ACTIVATE))
#define REQUIRE_SET_INI_PERDIR_FAILURE(name, val) REQUIRE(!zai_config_set_runtime_ini(ZEND_STRL(name), ZEND_STRL(val), PHP_INI_STAGE_RUNTIME))
#define REQUIRE_SET_INI(name, val) REQUIRE(zai_config_set_runtime_ini(ZEND_STRL(name), ZEND_STRL(val), PHP_INI_STAGE_RUNTIME))
#define REQUIRE_SET_INI_FAILURE(name, val) REQUIRE(!zai_config_set_runtime_ini(ZEND_STRL(name), ZEND_STRL(val), PHP_INI_STAGE_RUNTIME))

TEST_INI("bool INI: default value", {}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(ZVAL_IS_TRUE(value));

    REQUEST_END()
})

TEST_INI("bool INI: system value", {
    REQUIRE(tea_sapi_append_system_ini_entry("zai_config.INI_FOO_BOOL", "0"));
}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(ZVAL_IS_FALSE(value));

    REQUEST_END()
})

TEST_INI("bool INI: user value", {}, {
    REQUEST_BEGIN()

    REQUIRE_SET_INI("zai_config.INI_FOO_BOOL", "0");

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_BOOL);

    REQUIRE(value != NULL);
    REQUIRE(ZVAL_IS_FALSE(value));

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
    REQUIRE(tea_sapi_append_system_ini_entry("zai_config.INI_FOO_DOUBLE", "4.2"));
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
    REQUIRE(tea_sapi_append_system_ini_entry("zai_config.INI_FOO_INT", "1337"));
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
    REQUIRE_MAP_VALUE_EQ(value, "one", "1");
    REQUIRE_MAP_VALUE_EQ(value, "two", "2");

    REQUEST_END()
})

TEST_INI("map INI: system value", {
    REQUIRE(tea_sapi_append_system_ini_entry("zai_config.INI_FOO_MAP", "type:system"));
}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 1);
    REQUIRE_MAP_VALUE_EQ(value, "type", "system");

    REQUEST_END()
})

TEST_INI("map INI: user value", {}, {
    REQUEST_BEGIN()

    REQUIRE_SET_INI("zai_config.INI_FOO_MAP", "type:user");

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_MAP);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_ARRAY);
    REQUIRE(zend_hash_num_elements(Z_ARRVAL_P(value)) == 1);
    REQUIRE_MAP_VALUE_EQ(value, "type", "user");

    REQUEST_END()
})

TEST_INI("string INI: default value", {}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zval_string_equals(value, "foo string"));

    REQUEST_END()
})

TEST_INI("string INI: system value", {
    REQUIRE(tea_sapi_append_system_ini_entry("zai_config.INI_FOO_STRING", "system string"));
}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zval_string_equals(value, "system string"));

    REQUEST_END()
})

TEST_INI("string INI: user value", {}, {
    REQUEST_BEGIN()

    REQUIRE_SET_INI("zai_config.INI_FOO_STRING", "user string");

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_STRING);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_STRING);
    REQUIRE(zval_string_equals(value, "user string"));

    REQUEST_END()
})

TEST_INI("INI: invalid user value", {}, {
    REQUEST_BEGIN()

    REQUIRE_SET_INI_FAILURE("zai_config.INI_FOO_INT", "user string");

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 42);

    REQUEST_END()
})

void ini_perdir_set_invalid_int() {
    REQUIRE_SET_INI_PERDIR_FAILURE("zai_config.INI_FOO_INT", "user string");
}

TEST_INI("INI: invalid perdir value", {}, {
    ext_zai_config_pre_rinit = ini_perdir_set_invalid_int;

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_FOO_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == 42);

    REQUEST_END()
})


/********************* zai_config_get_value() (ini aliases + env precendence) **********************/

TEST_INI("env overrides system INI", {
    REQUIRE(tea_sapi_append_system_ini_entry("zai_config.INI_BAR_ALIASED_STRING", "system string"));
}, {
    REQUIRE_SETENV("INI_BAR_ALIASED_STRING_OLD", "1");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_STRING);

    REQUIRE(value != NULL);
    REQUIRE(zval_string_equals(value, "1"));

    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING")) == '1');

    zai_config_restore_runtime_ini(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING"));

    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING")) == '1');

    REQUEST_END()
})

TEST_INI("system INI reflected in all aliases", {
    REQUIRE(tea_sapi_append_system_ini_entry("zai_config.INI_BAR_ALIASED_STRING", "system string"));
}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_STRING);

    REQUIRE(value != NULL);
    REQUIRE(zval_string_equals(value, "system string"));

    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING")) == 's');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLD")) == 's');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLDER")) == 's');

    zai_config_restore_runtime_ini(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLD"));

    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLD")) == 's');

    REQUEST_END()
})

TEST_INI("runtime INI update reflected in all aliases", {
    REQUIRE(tea_sapi_append_system_ini_entry("zai_config.INI_BAR_ALIASED_STRING", "system string"));
}, {
    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_STRING);

    REQUIRE(value != NULL);
    REQUIRE(zval_string_equals(value, "system string"));

    REQUIRE_SET_INI("zai_config.INI_BAR_ALIASED_STRING", "Updated");

    value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_STRING);
    REQUIRE(zval_string_equals(value, "Updated"));
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING")) == 'U');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLD")) == 'U');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLDER")) == 'U');

    REQUIRE_SET_INI("zai_config.INI_BAR_ALIASED_STRING_OLDER", "Modified");

    value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_STRING);
    REQUIRE(zval_string_equals(value, "Modified"));
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING")) == 'M');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLD")) == 'M');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLDER")) == 'M');

    zai_config_restore_runtime_ini(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLD"));

    value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_STRING);
    REQUIRE(zval_string_equals(value, "system string"));
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING")) == 's');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLD")) == 's');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLDER")) == 's');

    REQUEST_END()
})

TEST_INI("env followed by ini_restore", { }, {
    REQUIRE_SETENV("INI_BAR_ALIASED_STRING_OLD", "1");

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_STRING);

    REQUIRE(value != NULL);
    REQUIRE(zval_string_equals(value, "1"));

    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING")) == '1');

    REQUIRE_SET_INI("zai_config.INI_BAR_ALIASED_STRING", "Updated");

    value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_STRING);
    REQUIRE(zval_string_equals(value, "Updated"));
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING")) == 'U');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLD")) == 'U');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLDER")) == 'U');

    zai_config_restore_runtime_ini(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLDER"));

    REQUIRE(zval_string_equals(value, "1"));
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING")) == '1');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLD")) == '1');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_STRING_OLDER")) == '1');

    REQUEST_END()
})

void ini_perdir_set_int_alias() {
    REQUIRE_SET_INI_PERDIR("zai_config.INI_BAR_ALIASED_INT_OLD", "1");
}

TEST_INI("perdir INI setting reflected in all aliases", {
    REQUIRE(tea_sapi_append_system_ini_entry("zai_config.INI_BAR_ALIASED_INT", "0"));
}, {
    ext_zai_config_pre_rinit = ini_perdir_set_int_alias;

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_LVAL_P(value) == 1);
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT")) == '1');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT_OLD")) == '1');

    REQUIRE_SET_INI("zai_config.INI_BAR_ALIASED_INT_OLD", "2");

    value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_INT);
    REQUIRE(Z_LVAL_P(value) == 2);
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT")) == '2');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT_OLD")) == '2');

    zai_config_restore_runtime_ini(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT_OLD"));

    value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_INT);
    REQUIRE(Z_LVAL_P(value) == 0);
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT")) == '0');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT_OLD")) == '0');

    REQUEST_END()
})

void ini_perdir_set_invalid_int_alias() {
    REQUIRE_SET_INI_PERDIR_FAILURE("zai_config.INI_BAR_ALIASED_INT_OLD", "abc");
}

TEST_INI("invalid perdir INI setting ignored", {
    REQUIRE(tea_sapi_append_system_ini_entry("zai_config.INI_BAR_ALIASED_INT", "0"));
}, {
    ext_zai_config_pre_rinit = ini_perdir_set_invalid_int_alias;

    REQUEST_BEGIN()

    zval *value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_INT);

    REQUIRE(value != NULL);
    REQUIRE(Z_LVAL_P(value) == 0);
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT")) == '0');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT_OLD")) == '0');

    zai_config_restore_runtime_ini(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT_OLD"));

    value = zai_config_get_value(EXT_CFG_INI_BAR_ALIASED_INT);
    REQUIRE(Z_LVAL_P(value) == 0);
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT")) == '0');
    REQUIRE(*zai_config_ini_string(ZEND_STRL("zai_config.INI_BAR_ALIASED_INT_OLD")) == '0');

    REQUEST_END()
})

