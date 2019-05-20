#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include <SAPI.h>
#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#include <php.h>
#include <php_ini.h>
#include <php_main.h>
#include <ext/spl/spl_exceptions.h>
#include <ext/standard/info.h>

#include "backtrace.h"
#include "circuit_breaker.h"
#include "compat_zend_string.h"
#include "compatibility.h"
#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "dispatch_compat.h"
#include "memory_limit.h"
#include "request_hooks.h"
#include "serializer.h"

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

PHP_INI_BEGIN()
STD_PHP_INI_BOOLEAN("ddtrace.disable", "0", PHP_INI_SYSTEM, OnUpdateBool, disable, zend_ddtrace_globals,
                    ddtrace_globals)
STD_PHP_INI_ENTRY("ddtrace.internal_blacklisted_modules_list", "ionCube Loader,", PHP_INI_SYSTEM, OnUpdateString,
                  internal_blacklisted_modules_list, zend_ddtrace_globals, ddtrace_globals)
STD_PHP_INI_ENTRY("ddtrace.request_init_hook", "", PHP_INI_SYSTEM, OnUpdateString, request_init_hook,
                  zend_ddtrace_globals, ddtrace_globals)
STD_PHP_INI_BOOLEAN("ddtrace.strict_mode", "0", PHP_INI_SYSTEM, OnUpdateBool, strict_mode, zend_ddtrace_globals,
                    ddtrace_globals)
STD_PHP_INI_BOOLEAN("ddtrace.log_backtrace", "0", PHP_INI_SYSTEM, OnUpdateBool, log_backtrace, zend_ddtrace_globals,
                    ddtrace_globals)
PHP_INI_END()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_serialize_msgpack, 0, 0, 1)
ZEND_ARG_INFO(0, trace_array)
ZEND_END_ARG_INFO()

static void php_ddtrace_init_globals(zend_ddtrace_globals *ng) { memset(ng, 0, sizeof(zend_ddtrace_globals)); }

static PHP_MINIT_FUNCTION(ddtrace) {
    UNUSED(type);
    ZEND_INIT_MODULE_GLOBALS(ddtrace, php_ddtrace_init_globals, NULL);
    REGISTER_INI_ENTRIES();

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }
    ddtrace_install_backtrace_handler(TSRMLS_C);

    ddtrace_dispatch_init(TSRMLS_C);
    ddtrace_dispatch_inject(TSRMLS_C);

    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(ddtrace) {
    UNUSED(module_number, type);
    UNREGISTER_INI_ENTRIES();

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    return SUCCESS;
}

static PHP_RINIT_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

#if defined(ZTS) && PHP_VERSION_ID >= 70000
    ZEND_TSRMLS_CACHE_UPDATE();
#endif

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    ddtrace_dispatch_init(TSRMLS_C);
    DDTRACE_G(disable_in_current_request) = 0;

    if (DDTRACE_G(internal_blacklisted_modules_list) && !dd_no_blacklisted_modules(TSRMLS_C)) {
        return SUCCESS;
    }

    if (DDTRACE_G(request_init_hook)) {
        DD_PRINTF("%s", DDTRACE_G(request_init_hook));
        dd_execute_php_file(DDTRACE_G(request_init_hook) TSRMLS_CC);
    }

    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }
    ddtrace_dispatch_destroy(TSRMLS_C);

    return SUCCESS;
}

static int datadog_info_print(const char *str TSRMLS_DC) { return php_output_write(str, strlen(str) TSRMLS_CC); }

static PHP_MINFO_FUNCTION(ddtrace) {
    UNUSED(zend_module);

    php_info_print_box_start(0);
    datadog_info_print("Datadog PHP tracer extension" TSRMLS_CC);
    if (!sapi_module.phpinfo_as_text) {
        datadog_info_print("<br><strong>For help, check out " TSRMLS_CC);
        datadog_info_print(
            "<a href=\"https://docs.datadoghq.com/tracing/languages/php/\" "
            "style=\"background:transparent;\">the documentation</a>.</strong>" TSRMLS_CC);
    } else {
        datadog_info_print(
            "\nFor help, check out the documentation at "
            "https://docs.datadoghq.com/tracing/languages/php/" TSRMLS_CC);
    }
    datadog_info_print(!sapi_module.phpinfo_as_text ? "<br><br>" : "\n" TSRMLS_CC);
    datadog_info_print("(c) Datadog 2019\n" TSRMLS_CC);
    php_info_print_box_end();

    php_info_print_table_start();
    php_info_print_table_row(2, "Datadog tracing support", DDTRACE_G(disable) ? "disabled" : "enabled");
    php_info_print_table_row(2, "Version", PHP_DDTRACE_VERSION);
    php_info_print_table_end();

    DISPLAY_INI_ENTRIES();
}

static PHP_FUNCTION(dd_trace) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr);
    zval *function = NULL;
    zval *class_name = NULL;
    zval *callable = NULL;

    if (DDTRACE_G(disable) || DDTRACE_G(disable_in_current_request)) {
        RETURN_BOOL(0);
    }

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "zzz", &class_name, &function,
                                 &callable) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "zz", &function, &callable) !=
            SUCCESS) {
        if (DDTRACE_G(strict_mode)) {
            zend_throw_exception_ex(
                spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                "unexpected parameter combination, expected (class, function, closure) or (function, closure)");
        }

        RETURN_BOOL(0);
    }
    if (class_name) {
        DD_PRINTF("Class name: %s", Z_STRVAL_P(class_name));
    }
    DD_PRINTF("Function name: %s", Z_STRVAL_P(function));

    if (!function || Z_TYPE_P(function) != IS_STRING) {
        if (class_name) {
            ddtrace_zval_ptr_dtor(class_name);
        }
        ddtrace_zval_ptr_dtor(function);

        if (DDTRACE_G(strict_mode)) {
            zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                                    "function/method name parameter must be a string");
        }

        RETURN_BOOL(0);
    }

    if (class_name && DDTRACE_G(strict_mode) && Z_TYPE_P(class_name) == IS_STRING) {
        zend_class_entry *class = ddtrace_target_class_entry(class_name, function TSRMLS_CC);

        if (!class) {
            ddtrace_zval_ptr_dtor(class_name);
            ddtrace_zval_ptr_dtor(function);

            zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC, "class not found");

            RETURN_BOOL(0);
        }
    }

    zend_bool rv = ddtrace_trace(class_name, function, callable TSRMLS_CC);
    RETURN_BOOL(rv);
}

// Invoke the function/method from the original context
static PHP_FUNCTION(dd_trace_forward_call) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

#if PHP_VERSION_ID >= 70000
    ddtrace_forward_call(execute_data, return_value TSRMLS_CC);
#else
    ddtrace_forward_call(EG(current_execute_data), return_value TSRMLS_CC);
#endif
}

// This function allows untracing a function.
static PHP_FUNCTION(dd_untrace) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    if (DDTRACE_G(disable) && DDTRACE_G(disable_in_current_request)) {
        RETURN_BOOL(0);
    }

    zval *function = NULL;
    DD_PRINTF("Untracing function: %s", Z_STRVAL_P(function));

    // Remove the traced function from the global lookup
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "z", &function) != SUCCESS) {
        if (DDTRACE_G(strict_mode)) {
            zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                                    "unexpected parameter. the function name must be provided");
        }
        RETURN_BOOL(0);
    }

    // Remove the traced function from the global lookup
    if (!function || Z_TYPE_P(function) != IS_STRING) {
        RETURN_BOOL(0);
    }

#if PHP_VERSION_ID < 70000
    zend_hash_del(&DDTRACE_G(function_lookup), Z_STRVAL_P(function), Z_STRLEN_P(function));
#else
    zend_hash_del(&DDTRACE_G(function_lookup), Z_STR_P(function));
#endif

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_trace_disable_in_request) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    DDTRACE_G(disable_in_current_request) = 1;

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_trace_reset) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    ddtrace_dispatch_reset(TSRMLS_C);
    RETURN_BOOL(1);
}

/* {{{ proto string dd_trace_serialize_msgpack(array trace_array) */
static PHP_FUNCTION(dd_trace_serialize_msgpack) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    zval *trace_array;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "a", &trace_array) == FAILURE) {
        if (DDTRACE_G(strict_mode)) {
            zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC, "Expected an array");
        }
        RETURN_BOOL(0);
    }

    if (ddtrace_serialize_simple_array(trace_array, return_value TSRMLS_CC) != 1) {
        RETURN_BOOL(0);
    }
} /* }}} */

// method used to be able to easily breakpoint the execution at specific PHP line in GDB
static PHP_FUNCTION(dd_trace_noop) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    RETURN_BOOL(1);
}

/* {{{ proto int dd_trace_dd_get_memory_limit() */
static PHP_FUNCTION(dd_trace_dd_get_memory_limit) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    RETURN_LONG(get_memory_limit(TSRMLS_C));
}

/* {{{ proto bool dd_trace_check_memory_under_limit() */
static PHP_FUNCTION(dd_trace_check_memory_under_limit) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    static zend_long limit = -1;
    static zend_bool fetched_limit = 0;
    if (!fetched_limit) {  // cache get_memory_limit() result to make this function blazing fast
        fetched_limit = 1;
        limit = get_memory_limit(TSRMLS_C);
    }

    if (limit > 0) {
        RETURN_BOOL((zend_ulong)limit > zend_memory_usage(0 TSRMLS_CC));
    } else {
        RETURN_BOOL(1);
    }
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_register_error) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    PHP7_UNUSED(execute_data);

    dd_tracer_circuit_breaker_register_error();

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_register_success) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    PHP7_UNUSED(execute_data);

    dd_tracer_circuit_breaker_register_success();

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_can_try) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    PHP7_UNUSED(execute_data);

    RETURN_BOOL(dd_tracer_circuit_breaker_can_try());
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_info) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    PHP7_UNUSED(execute_data);

    array_init_size(return_value, 5);

    add_assoc_bool(return_value, "closed", dd_tracer_circuit_breaker_is_closed());
    add_assoc_long(return_value, "total_failures", dd_tracer_circuit_breaker_total_failures());
    add_assoc_long(return_value, "consecutive_failures", dd_tracer_circuit_breaker_consecutive_failures());
    add_assoc_long(return_value, "opened_timestamp", dd_tracer_circuit_breaker_opened_timestamp());
    add_assoc_long(return_value, "last_failure_timestamp", dd_tracer_circuit_breaker_last_failure_timestamp());
    return;
}

static const zend_function_entry ddtrace_functions[] = {
    PHP_FE(dd_trace, NULL) PHP_FE(dd_trace_forward_call, NULL) PHP_FE(dd_trace_reset, NULL) PHP_FE(dd_trace_noop, NULL)
        PHP_FE(dd_untrace, NULL) PHP_FE(dd_trace_disable_in_request, NULL) PHP_FE(dd_trace_dd_get_memory_limit, NULL)
            PHP_FE(dd_trace_check_memory_under_limit, NULL) PHP_FE(dd_tracer_circuit_breaker_register_error, NULL)
                PHP_FE(dd_tracer_circuit_breaker_register_success, NULL) PHP_FE(dd_tracer_circuit_breaker_can_try, NULL)
                    PHP_FE(dd_tracer_circuit_breaker_info, NULL)
                        PHP_FE(dd_trace_serialize_msgpack, arginfo_dd_trace_serialize_msgpack) ZEND_FE_END};

zend_module_entry ddtrace_module_entry = {STANDARD_MODULE_HEADER,    PHP_DDTRACE_EXTNAME,    ddtrace_functions,
                                          PHP_MINIT(ddtrace),        PHP_MSHUTDOWN(ddtrace), PHP_RINIT(ddtrace),
                                          PHP_RSHUTDOWN(ddtrace),    PHP_MINFO(ddtrace),     PHP_DDTRACE_VERSION,
                                          STANDARD_MODULE_PROPERTIES};

#ifdef COMPILE_DL_DDTRACE
ZEND_GET_MODULE(ddtrace)
#if defined(ZTS) && PHP_VERSION_ID >= 70000
ZEND_TSRMLS_CACHE_DEFINE();
#endif
#endif
