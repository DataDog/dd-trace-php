// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#include "configuration.h"

#include <assert.h>

#include "helper_process.h"
#include "ip_extraction.h"
#include "logging.h"
#include "php_objects.h"
#include "user_tracking.h"
#include "zai_string/string.h"

#include "compatibility.h"

#define DD_TO_DATADOG_INC 5 /* "DD" expanded to "datadog" */

#define APPLY_0(...)
#define APPLY_1(macro, arg, ...) macro(arg)
#define APPLY_2(macro, arg, ...) macro(arg) APPLY_1(macro, __VA_ARGS__)
#define APPLY_3(macro, arg, ...) macro(arg) APPLY_2(macro, __VA_ARGS__)
#define APPLY_4(macro, arg, ...) macro(arg) APPLY_3(macro, __VA_ARGS__)
#define APPLY_NAME_EXPAND(count) APPLY_##count
#define APPLY_NAME(count) APPLY_NAME_EXPAND(count)
#define APPLY_COUNT(_0, _1, _2, _3, _4, N, ...) N
#define APPLY_N(macro, ...)                                                    \
    APPLY_NAME(APPLY_COUNT(0, ##__VA_ARGS__, 4, 3, 2, 1, 0))                   \
    (macro, ##__VA_ARGS__)

#define SYSCFG(type, name, val, ...)                                           \
    CONFIG(type, name, val, .ini_change = zai_config_system_ini_change,        \
        ##__VA_ARGS__)

// static assert name lengths, number of configs and number of aliases
#define CALIAS CONFIG
#define CONFIG(...) 1,
#define NUMBER_OF_CONFIGURATIONS sizeof((uint8_t[]){DD_CONFIGURATION})
_Static_assert(NUMBER_OF_CONFIGURATIONS < ZAI_CONFIG_ENTRIES_COUNT_MAX,
    "There are more config entries than ZAI_CONFIG_ENTRIES_COUNT_MAX.");
#undef CONFIG
#define CONFIG(type, name, ...)                                                \
    _Static_assert(sizeof(#name) < ZAI_CONFIG_NAME_BUFSIZ - DD_TO_DATADOG_INC, \
        "The name of " #name                                                   \
        " is longer than allowed ZAI_CONFIG_NAME_BUFSIZ - " DD_CFG_STR(        \
            DD_TO_DATADOG_INC));
DD_CONFIGURATION
#undef CONFIG
#undef CALIAS
#define CONFIG(...)
#define ELEMENT(arg) 1,
#define CALIASES(...) APPLY_N(ELEMENT, ##__VA_ARGS__)
#define CALIAS(type, name, default, aliases, ...)                              \
    _Static_assert(sizeof((uint8_t[]){aliases}) < ZAI_CONFIG_NAMES_COUNT_MAX,  \
        #name                                                                  \
        " has more than the allowed ZAI_CONFIG_NAMES_COUNT_MAX alias names");
DD_CONFIGURATION
#undef CALIAS
#undef CALIASES
#define CALIAS_CHECK_LENGTH(name)                                              \
    _Static_assert(sizeof(#name) < ZAI_CONFIG_NAME_BUFSIZ - DD_TO_DATADOG_INC, \
        "The name of " #name                                                   \
        " alias is longer than allowed ZAI_CONFIG_NAME_BUFSIZ - " DD_CFG_STR(  \
            DD_TO_DATADOG_INC));
#define CALIASES(...) APPLY_N(CALIAS_CHECK_LENGTH, ##__VA_ARGS__)
#define CALIAS(type, name, default, aliases, ...) aliases
DD_CONFIGURATION
#undef CALIAS
#undef CALIASES
#undef CONFIG

// Allow for partially defined struct initialization here
#pragma GCC diagnostic ignored "-Wmissing-field-initializers"

static bool _parse_uint(
    zai_str value, zval *nonnull decoded_value, long long max);

static bool _parse_uint32(
    zai_str value, zval *nonnull decoded_value, bool persistent)
{
    UNUSED(persistent);
    return _parse_uint(value, decoded_value, UINT32_MAX);
}
static bool _parse_uint64(
    zai_str value, zval *nonnull decoded_value, bool persistent)
{
    UNUSED(persistent);
    return _parse_uint(value, decoded_value, LONG_MAX);
}

static bool _parse_list(
    zai_str value, zval *nonnull decoded_value, bool persistent)
{
    zval tmp;
    ZVAL_ARR(&tmp, pemalloc(sizeof(HashTable), persistent)); // NOLINT
    zend_hash_init(Z_ARRVAL(tmp), 8, NULL,
        persistent ? ZVAL_INTERNAL_PTR_DTOR : ZVAL_PTR_DTOR, persistent);

    char *data = (char *)value.ptr;
    if (data && *data) { // non-empty
        const char *val_start;
        const char *val_end;
        do {
            if (*data != ',' && *data != ' ' && *data != '\t' &&
                *data != '\n') {
                val_start = val_end = data;
                while (*++data && *data != ',') {
                    if (*data != ' ' && *data != '\t' && *data != '\n') {
                        val_end = data;
                    }
                }
                size_t val_len = val_end - val_start + 1;
                zval val;
                ZVAL_NEW_STR(
                    &val, zend_string_init(val_start, val_len, persistent));
                zend_hash_next_index_insert_new(Z_ARRVAL(tmp), &val);
            } else {
                ++data;
            }
        } while (*data);

        if (zend_hash_num_elements(Z_ARRVAL(tmp)) == 0) {
            zend_hash_destroy(Z_ARRVAL(tmp));
            pefree(Z_ARRVAL(tmp), persistent);
            return false;
        }
    }

    ZVAL_COPY_VALUE(decoded_value, &tmp);
    return true;
}

#define CUSTOM(...) CUSTOM
// NOLINTNEXTLINE(bugprone-macro-parentheses)
#define CALIAS_EXPAND(name) {.ptr = name, .len = sizeof(name) - 1},
#define CALIASES(...) ((zai_str[]){APPLY_N(CALIAS_EXPAND, ##__VA_ARGS__)})
#define CONFIG(type, name, ...)                                                \
    ZAI_CONFIG_ENTRY(DDAPPSEC_CONFIG_##name, name, type, __VA_ARGS__),
#define CALIAS(type, name, ...)                                                \
    ZAI_CONFIG_ALIASED_ENTRY(DDAPPSEC_CONFIG_##name, name, type, __VA_ARGS__),
static zai_config_entry config_entries[] = {DD_CONFIGURATION};
#undef CALIAS
#undef CONFIG

bool runtime_config_first_init = false;

static bool _parse_uint(
    zai_str value, zval *nonnull decoded_value, long long max)
{
    char *endptr = NULL;
    const int base = 10;
    long long ini_value = strtoll(value.ptr, &endptr, base);

    if (endptr == value.ptr || *endptr != '\0') {
        return false;
    }

    if (ini_value < 0) {
        ini_value = 0;
    } else if (ini_value > max) {
        ini_value = max;
    }

    ZVAL_LONG(decoded_value, ini_value);
    return true;
}

static char _tolower_ascii(char c)
{
    return (char)(c >= 'A' && c <= 'Z' ? c - ('A' - 'a') : c);
}

static void _copy_tolower(char *restrict dst, const char *restrict src)
{
    while (*src) { *(dst++) = _tolower_ascii(*(src++)); }
}

static void dd_ini_env_to_ini_name(
    const zai_str env_name, zai_config_name *nonnull ini_name)
{
    if (env_name.len + DD_TO_DATADOG_INC >= ZAI_CONFIG_NAME_BUFSIZ) {
        assert(false &&
               "Expanded env name length is larger than the INI name buffer");
        return;
    }

    if (env_name.ptr[0] == 'D' && env_name.ptr[1] == 'D' &&
        env_name.ptr[2] == '_') {
        memcpy(ini_name->ptr, "datadog.", sizeof("datadog.") - 1);
        _copy_tolower(ini_name->ptr + sizeof("datadog.") - 1,
            env_name.ptr + sizeof("DD_") - 1);
        ini_name->len = env_name.len + DD_TO_DATADOG_INC;

        if (env_name.ptr == strstr(env_name.ptr, "DD_APPSEC_")) {
            ini_name->ptr[sizeof("datadog.appsec") - 1] = '.';
        }

        if (env_name.ptr == strstr(env_name.ptr, "DD_TRACE_")) {
            ini_name->ptr[sizeof("datadog.trace") - 1] = '.';
        }

    } else {
        ini_name->len = 0;
        assert(false && "Unexpected env var name: missing 'DD_' prefix");
    }

    ini_name->ptr[ini_name->len] = '\0';
}

#ifdef TESTING
static void _register_testing_objects(void);
#endif

bool dd_config_minit(int module_number)
{
    if (!zai_config_minit(config_entries,
            (sizeof config_entries / sizeof *config_entries),
            dd_ini_env_to_ini_name, module_number)) {
        mlog(dd_log_fatal, "Unable to load configuration.");
        return false;
    }
    // We immediately initialize inis at MINIT, so that we can use a select few
    // values already at minit. Note that we are not calling zai_config_rinit(),
    // i.e. the get_...() functions will not work. This is intentional, so that
    // places wishing to use values pre-RINIT do have to explicitly opt in by
    // using the arduous way of accessing the decoded_value directly from
    // zai_config_memoized_entries.
    zai_config_first_time_rinit(false);
#ifdef TESTING
    _register_testing_objects();
#endif

    return true;
}

void dd_config_first_rinit(void)
{
    zai_config_first_time_rinit(true);
    zai_config_rinit();

    runtime_config_first_init = true;
}

static PHP_FUNCTION(datadog_appsec_testing_zai_config_get_value)
{
    zend_string *key;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &key) == FAILURE) {
        RETURN_FALSE;
    }

    unsigned entries = sizeof config_entries / sizeof *config_entries;
    for (unsigned i = 0; i < entries; i++) {
        if (strcmp(ZSTR_VAL(key), config_entries[i].name.ptr) == 0) {
            RETURN_ZVAL(zai_config_get_value(config_entries[i].id),
                1 /* copy */, 0 /* keep original */);
        }
    }

    RETURN_FALSE;
}

static PHP_FUNCTION(datadog_appsec_testing_zai_config_get_global_value)
{
    zend_string *key;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &key) == FAILURE) {
        RETURN_FALSE;
    }

    unsigned entries = sizeof config_entries / sizeof *config_entries;
    for (unsigned i = 0; i < entries; i++) {
        if (strcmp(ZSTR_VAL(key), config_entries[i].name.ptr) == 0) {
            zval *value = &zai_config_memoized_entries[config_entries[i].id]
                               .decoded_value;
            RETURN_ZVAL(value, 1 /* copy */, 0 /* keep original */);
        }
    }

    RETURN_FALSE;
}

ZEND_BEGIN_ARG_INFO_EX(set_string_arginfo, 0, 0, 1)
ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "zai_config_get_value", PHP_FN(datadog_appsec_testing_zai_config_get_value), set_string_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "zai_config_get_global_value", PHP_FN(datadog_appsec_testing_zai_config_get_global_value), set_string_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void)
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }
    dd_phpobj_reg_funcs(testing_functions);
}

static bool _is_config_using_default(dd_config_id id)
{
    zai_config_memoized_entry config = zai_config_memoized_entries[id];

    return config.name_index == -1;
}

bool dd_cfg_enable_via_remcfg(void)
{
    return _is_config_using_default(DDAPPSEC_CONFIG_DD_APPSEC_ENABLED) &&
           get_DD_REMOTE_CONFIG_ENABLED();
}
