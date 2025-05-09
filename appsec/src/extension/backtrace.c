// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "backtrace.h"
#include "compatibility.h"
#include "configuration.h"
#include "ddappsec.h"
#include "ddtrace.h"
#include "logging.h"
#include "php_compat.h"
#include "php_objects.h"
#include "string_helpers.h"

static const int NO_LIMIT = 0;
static const double STACK_DEFAULT_TOP_RATE = 0.25;
static const char QUALIFIED_NAME_SEPARATOR[] = "::";

static zend_string *_frames_key;
static zend_string *_language_key;
static zend_string *_php_value;
static zend_string *_exploit_key;
static zend_string *_dd_stack_key;
static zend_string *_frame_line;
static zend_string *_frame_function;
static zend_string *_frame_file;
static zend_string *_id_key;
static zend_string *_line_field;
static zend_string *_function_field;
static zend_string *_file_field;
static zend_string *_class_field;

static bool
php_backtrace_frame_to_datadog_backtrace_frame( // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    zval *nonnull php_backtrace_frame, zval *nonnull datadog_backtrace_frame,
    zend_ulong index)
{
    if (Z_TYPE_P(php_backtrace_frame) != IS_ARRAY) {
        return false;
    }
    HashTable *frame = Z_ARRVAL_P(php_backtrace_frame);
    zval *line = zend_hash_find(frame, _line_field);
    zval *function = zend_hash_find(frame, _function_field);
    zval *file = zend_hash_find(frame, _file_field);
    zval *class = zend_hash_find(frame, _class_field);
    zval id;
    ZVAL_LONG(&id, index);
#ifdef TESTING
    if (file) {
        // In order to be able to test full path encoded everywhere lets set
        // only the file name without path
        const char *file_name =
            zend_memrchr(Z_STRVAL_P(file), '/', Z_STRLEN_P(file));
        if (file_name) {
            zend_string *new_file = zend_string_init(file_name + 1,
                Z_STRLEN_P(file) - (file_name + 1 - Z_STRVAL_P(file)), 0);
            zval_ptr_dtor(file);
            ZVAL_NEW_STR(file, new_file);
        }
    }
#endif

    if (!function) {
        return false;
    }

    // Remove tracer integration php code frames
    if (STR_STARTS_WITH_CONS(
            Z_STRVAL_P(function), Z_STRLEN_P(function), "DDTrace") ||
        STR_STARTS_WITH_CONS(
            Z_STRVAL_P(function), Z_STRLEN_P(function), "{closure:DDTrace")) {
        return false;
    }

    array_init(datadog_backtrace_frame);
    HashTable *datadog_backtrace_frame_ht = Z_ARRVAL_P(datadog_backtrace_frame);
    if (line) {
        zend_hash_add(datadog_backtrace_frame_ht, _frame_line, line);
    }

    zend_ulong qualified_name_size = Z_STRLEN_P(function);
    qualified_name_size +=
        class ? Z_STRLEN_P(class) + sizeof(QUALIFIED_NAME_SEPARATOR) - 1 : 0;
    zend_string *qualified_name_zstr =
        zend_string_alloc(qualified_name_size, 0);
    char *qualified_name = ZSTR_VAL(qualified_name_zstr);
    int position = 0;

    if (class) {
        memcpy(qualified_name, Z_STRVAL_P(class), Z_STRLEN_P(class));
        position = Z_STRLEN_P(class);
        memcpy(&qualified_name[position], QUALIFIED_NAME_SEPARATOR,
            sizeof(QUALIFIED_NAME_SEPARATOR) - 1);
        position += 2;
    }

    memcpy(
        &qualified_name[position], Z_STRVAL_P(function), Z_STRLEN_P(function));

    qualified_name[qualified_name_size] = '\0';

    zval zv_qualified_name;
    ZVAL_STR(&zv_qualified_name, qualified_name_zstr);
    zend_hash_add(
        datadog_backtrace_frame_ht, _frame_function, &zv_qualified_name);

    if (file) {
        zend_hash_add(datadog_backtrace_frame_ht, _frame_file, file);
        Z_TRY_ADDREF_P(file);
    }
    zend_hash_add(datadog_backtrace_frame_ht, _id_key, &id);

    return true;
}

static void php_backtrace_to_datadog_backtrace(
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    zval *nonnull php_backtrace, zval *nonnull datadog_backtrace)
{
    if (Z_TYPE_P(php_backtrace) != IS_ARRAY) {
        return;
    }

    HashTable *php_backtrace_ht = Z_ARRVAL_P(php_backtrace);
    uint32_t frames_on_stack = zend_array_count(php_backtrace_ht);

    uint32_t top = frames_on_stack;
    uint32_t bottom = 0;
    if (get_global_DD_APPSEC_MAX_STACK_TRACE_DEPTH() != 0 &&
        frames_on_stack > get_global_DD_APPSEC_MAX_STACK_TRACE_DEPTH()) {
        top = (uint32_t)round(
            (double)get_global_DD_APPSEC_MAX_STACK_TRACE_DEPTH() *
            STACK_DEFAULT_TOP_RATE);
        bottom = get_global_DD_APPSEC_MAX_STACK_TRACE_DEPTH() - top;
    }

    array_init(datadog_backtrace);

    HashTable *datadog_backtrace_ht = Z_ARRVAL_P(datadog_backtrace);

    zval *php_frame;
    zend_ulong index;
    if (top > 0) {
        ZEND_HASH_FOREACH_NUM_KEY_VAL(php_backtrace_ht, index, php_frame)
        {
            zval new_frame;

            if (php_backtrace_frame_to_datadog_backtrace_frame(
                    php_frame, &new_frame, index)) {
                zend_hash_next_index_insert_new(
                    datadog_backtrace_ht, &new_frame);
            }
            if (--top == 0) {
                break;
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    if (bottom > 0) {
        unsigned int position = frames_on_stack - bottom;
        DD_FOREACH_FROM(php_backtrace_ht, 0, position, index)
        {
            php_frame = _z;
            zval new_frame;

            if (!php_backtrace_frame_to_datadog_backtrace_frame(
                    php_frame, &new_frame, index)) {
                continue;
            }

            zend_hash_next_index_insert_new(datadog_backtrace_ht, &new_frame);
        }
        ZEND_HASH_FOREACH_END();
    }
}

void dd_generate_backtrace(zend_string *nullable id, zval *nonnull dd_backtrace)
{
    array_init(dd_backtrace);

    if (!id) {
        return;
    }

    zval language;
    ZVAL_STR_COPY(&language, _php_value);
    zval id_zv;
    ZVAL_STR_COPY(&id_zv, id);
    zend_hash_add(Z_ARRVAL_P(dd_backtrace), _language_key, &language);
    zend_hash_add(Z_ARRVAL_P(dd_backtrace), _id_key, &id_zv);

    zval frames;
    zval php_backtrace;
    zend_fetch_debug_backtrace(
        &php_backtrace, 1, DEBUG_BACKTRACE_IGNORE_ARGS, NO_LIMIT);
    php_backtrace_to_datadog_backtrace(&php_backtrace, &frames);
    zend_hash_add(Z_ARRVAL_P(dd_backtrace), _frames_key, &frames);

    zval_dtor(&php_backtrace);
}

static PHP_FUNCTION(datadog_appsec_testing_generate_backtrace)
{
    zend_string *id = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &id) != SUCCESS) {
        RETURN_FALSE;
    }

    dd_generate_backtrace(id, return_value);
}

bool dd_report_exploit_backtrace(zend_string *nullable id)
{
    mlog(dd_log_trace, "Generating backtrace");
    if (!get_global_DD_APPSEC_STACK_TRACE_ENABLED()) {
        mlog(dd_log_trace, "Backtrace generation is disabled with "
                           "DD_APPSEC_STACK_TRACE_ENABLED");
        return false;
    }

    if (!id) {
        mlog(dd_log_warning,
            "Backtrace can not be generated because id is missing");
    }

    zend_object *span = dd_trace_get_active_root_span();
    if (!span) {
        mlog(dd_log_warning, "Failed to retrieve root span");
        return false;
    }

    zval *meta_struct = dd_trace_span_get_meta_struct(span);
    if (!meta_struct) {
        mlog(dd_log_warning, "Failed to retrieve root span meta_struct");
        return false;
    }

    if (Z_TYPE_P(meta_struct) == IS_NULL) {
        array_init(meta_struct);
    } else if (Z_TYPE_P(meta_struct) != IS_ARRAY) {
        mlog(dd_log_trace,
            "Field 'meta_struct' is of type '%d', expected 'array'",
            Z_TYPE_P(meta_struct));
        return false;
    }

    zval *dd_stack = zend_hash_find(Z_ARR_P(meta_struct), _dd_stack_key);
    zval *exploit = NULL;
    if (!dd_stack || Z_TYPE_P(dd_stack) == IS_NULL) {
        dd_stack = zend_hash_add_new(
            Z_ARR_P(meta_struct), _dd_stack_key, &EG(uninitialized_zval));
        array_init(dd_stack);
        exploit = zend_hash_add_new(
            Z_ARR_P(dd_stack), _exploit_key, &EG(uninitialized_zval));
        array_init(exploit);
        mlog(dd_log_trace, "Backtrace stack created");
    } else if (Z_TYPE_P(dd_stack) != IS_ARRAY) {
        mlog(dd_log_warning, "Field 'stack' is of type '%d', expected 'array'",
            Z_TYPE_P(dd_stack));
        return false;
    } else {
        exploit = zend_hash_find(Z_ARR_P(dd_stack), _exploit_key);
    }

    if (Z_TYPE_P(exploit) != IS_ARRAY) {
        mlog(dd_log_warning,
            "Field 'exploit' is of type '%d', expected 'array'",
            Z_TYPE_P(exploit));
        return false;
    }

    unsigned int limit = get_global_DD_APPSEC_MAX_STACK_TRACES();
    if (limit != 0 && zend_array_count(Z_ARR_P(exploit)) == limit) {
        mlog(dd_log_debug,
            "Backtrace not generated due to limit "
            "DD_APPSEC_MAX_STACK_TRACES(%u) has been reached",
            limit);
        return false;
    }

    zval backtrace;
    dd_generate_backtrace(id, &backtrace);

    if (zend_hash_next_index_insert_new(Z_ARRVAL_P(exploit), &backtrace) ==
        NULL) {
        if (!get_global_DD_APPSEC_TESTING()) {
            mlog(dd_log_warning, "Error adding Backtrace");
        }
        return false;
    }

    mlog(dd_log_trace, "Backtrace generated");
    return true;
}

static PHP_FUNCTION(datadog_appsec_testing_report_exploit_backtrace)
{
    zend_string *id = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &id) != SUCCESS) {
        RETURN_FALSE;
    }

    if (dd_report_exploit_backtrace(id)) {
        RETURN_TRUE;
    }

    RETURN_FALSE;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    void_ret_bool_arginfo, 0, 1, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, id, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    void_ret_array_arginfo, 0, 1, IS_ARRAY, 0)
ZEND_ARG_TYPE_INFO(0, id, IS_STRING, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "generate_backtrace", PHP_FN(datadog_appsec_testing_generate_backtrace), void_ret_array_arginfo,0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "report_exploit_backtrace", PHP_FN(datadog_appsec_testing_report_exploit_backtrace), void_ret_bool_arginfo, 0, NULL, NULL)
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

void dd_backtrace_startup(void)
{
    _frames_key = zend_string_init_interned(LSTRARG("frames"), 1);
    _language_key = zend_string_init_interned(LSTRARG("language"), 1);
    _php_value = zend_string_init_interned(LSTRARG("php"), 1);
    _exploit_key = zend_string_init_interned(LSTRARG("exploit"), 1);
    _dd_stack_key = zend_string_init_interned(LSTRARG("_dd.stack"), 1);
    _frame_line = zend_string_init_interned(LSTRARG("line"), 1);
    _frame_function = zend_string_init_interned(LSTRARG("function"), 1);
    _frame_file = zend_string_init_interned(LSTRARG("file"), 1);
    _id_key = zend_string_init_interned(LSTRARG("id"), 1);
    _line_field = zend_string_init_interned(LSTRARG("line"), 1);
    _file_field = zend_string_init_interned(LSTRARG("file"), 1);
    _function_field = zend_string_init_interned(LSTRARG("function"), 1);
    _class_field = zend_string_init_interned(LSTRARG("class"), 1);
#ifdef TESTING
    _register_testing_objects();
#endif
}
