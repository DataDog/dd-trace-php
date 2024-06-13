// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <SAPI.h>
#include <Zend/zend_builtin_functions.h>
#include <php.h>

#include "configuration.h"
#include "php_objects.h"
#include "string_helpers.h"

void php_backtrace_to_datadog_backtrace(
    zval *php_backtrace, zval *datadog_backtrace)
{
    if (Z_TYPE_P(php_backtrace) != IS_ARRAY) {
        return;
    }

    array_init(datadog_backtrace);

    HashTable *datadog_backtrace_ht = Z_ARRVAL_P(datadog_backtrace);

    zval *tmp;
    zend_ulong index;
    ZEND_HASH_FOREACH_NUM_KEY_VAL(Z_ARRVAL_P(php_backtrace), index, tmp)
    {
        if (Z_TYPE_P(tmp) != IS_ARRAY) {
            continue;
        }
        HashTable *frame = Z_ARRVAL_P(tmp);
        zval *line = zend_hash_str_find(frame, LSTRARG("line"));
        zval *function = zend_hash_str_find(frame, LSTRARG("function"));
        zval *file = zend_hash_str_find(frame, LSTRARG("file"));
        zval id;
        ZVAL_LONG(&id, index);

        zval new_frame;
        array_init(&new_frame);
        HashTable *new_frame_ht = Z_ARRVAL(new_frame);

        zend_hash_str_add(new_frame_ht, LSTRARG("line"), line);
        zend_hash_str_add(new_frame_ht, LSTRARG("function"), function);
        zend_hash_str_add(new_frame_ht, LSTRARG("file"), file);
        zend_hash_str_add(new_frame_ht, LSTRARG("id"), &id);

        zend_hash_next_index_insert_new(datadog_backtrace_ht, &new_frame);
    }
    ZEND_HASH_FOREACH_END();
}

void generate_backtrace(zval *result)
{
    zval php_backtrace;
    zend_fetch_debug_backtrace(
        &php_backtrace, 0, DEBUG_BACKTRACE_IGNORE_ARGS, 5);

    php_backtrace_to_datadog_backtrace(&php_backtrace, result);

    zval_dtor(&php_backtrace);
}

static PHP_FUNCTION(datadog_appsec_testing_generate_backtrace)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    generate_backtrace(return_value);

    return;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    void_ret_array_arginfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "generate_backtrace",
        PHP_FN(datadog_appsec_testing_generate_backtrace),
        void_ret_array_arginfo, 0) PHP_FE_END};
// clang-format on

static void _register_testing_objects()
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }

    dd_phpobj_reg_funcs(testing_functions);
}

void dd_backtrace_startup()
{
#ifdef TESTING
    _register_testing_objects();
#endif
}
