// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "backtrace.h"
#include "configuration.h"
#include "ddtrace.h"
#include "php_compat.h"
#include "php_objects.h"
#include "string_helpers.h"

static zend_string *_dd_stack_key;

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
        zval *line = zend_hash_str_find(frame, "line", sizeof("line") - 1);
        zval *function =
            zend_hash_str_find(frame, "function", sizeof("function") - 1);
        zval *file = zend_hash_str_find(frame, "file", sizeof("file") - 1);
        zval id;
        ZVAL_LONG(&id, index);

#ifdef TESTING
        // In order to be able to test full path encoded everywhere lets set
        // only the file name without path
        char *file_name = strrchr(Z_STRVAL_P(file), '/');
        zval normalised_file_path;
        ZVAL_STRINGL(
            &normalised_file_path, file_name + 1, strlen(file_name) - 1);
        file = &normalised_file_path;
#endif

        zval new_frame;
        array_init(&new_frame);
        HashTable *new_frame_ht = Z_ARRVAL(new_frame);
        zend_hash_str_add_new(new_frame_ht, "line", sizeof("line") - 1, line);
        zend_hash_str_add_new(
            new_frame_ht, "function", sizeof("function") - 1, function);
        zend_hash_str_add_new(new_frame_ht, "file", sizeof("file") - 1, file);
        zend_hash_str_add_new(new_frame_ht, "id", sizeof("id") - 1, &id);

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

static PHP_FUNCTION(datadog_appsec_testing_report_backtrace)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    zval backtrace;
    generate_backtrace(&backtrace);

    add_entry_to_meta_struct(_dd_stack_key, &backtrace);

    RETURN_TRUE;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    void_ret_bool_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    void_ret_array_arginfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "generate_backtrace", PHP_FN(datadog_appsec_testing_generate_backtrace), void_ret_array_arginfo,0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "report_backtrace", PHP_FN(datadog_appsec_testing_report_backtrace), void_ret_bool_arginfo, 0)
    PHP_FE_END
};
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
    _dd_stack_key =
        zend_string_init_interned("_dd.stack", sizeof("_dd.stack") - 1, 1);
#ifdef TESTING
    _register_testing_objects();
#endif
}
