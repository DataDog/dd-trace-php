// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#include "configuration.h"

#include "logging.h"
#include "php_objects.h"

#include <ext/compatibility.h>

#ifdef TESTING
static void _register_testing_objects(void);
#endif

void dd_config_minit(void)
{
#ifdef TESTING
    _register_testing_objects();
#endif
}

bool dd_cfg_enable_via_remcfg(void)
{
    return zai_config_memoized_entries[DATADOG_CONFIG_DD_APPSEC_ENABLED]
                   .name_index == ZAI_CONFIG_ORIGIN_DEFAULT &&
           get_DD_REMOTE_CONFIG_ENABLED();
}

#ifdef TESTING
static zai_config_entry *nullable _find_entry(zend_string *nonnull name)
{
    for (unsigned i = 0; i < DATADOG_CONFIG_COUNT; i++) {
        if (strcmp(ZSTR_VAL(name), datadog_config_entries[i].name.ptr) == 0) {
            return &datadog_config_entries[i];
        }
    }
    return NULL;
}

static PHP_FUNCTION(datadog_appsec_testing_zai_config_get_value)
{
    zend_string *key;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &key) == FAILURE) {
        RETURN_FALSE;
    }

    zai_config_entry *entry = _find_entry(key);
    if (!entry) {
        RETURN_FALSE;
    }
    RETURN_ZVAL(
        zai_config_get_value(entry->id), 1 /* copy */, 0 /* keep original */);
}

static PHP_FUNCTION(datadog_appsec_testing_zai_config_get_global_value)
{
    zend_string *key;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &key) == FAILURE) {
        RETURN_FALSE;
    }

    zai_config_entry *entry = _find_entry(key);
    if (!entry) {
        RETURN_FALSE;
    }
    zval *value = &zai_config_memoized_entries[entry->id].decoded_value;
    RETURN_ZVAL(value, 1 /* copy */, 0 /* keep original */);
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
    if (get_global_DD_APPSEC_TESTING()) {
        dd_phpobj_reg_funcs(testing_functions);
    }
}
#endif
