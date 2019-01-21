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

#include "compat_zend_string.h"
#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "dispatch_compat.h"
#include "request_hooks.h"

#define UNUSED_1(x) (void)(x)
#define UNUSED_2(x, y) \
    do {               \
        UNUSED_1(x);   \
        UNUSED_1(y);   \
    } while (0)
#define UNUSED_3(x, y, z) \
    do {                  \
        UNUSED_1(x);      \
        UNUSED_1(y);      \
        UNUSED_1(z);      \
    } while (0)
#define UNUSED_4(x, y, z, q) \
    do {                     \
        UNUSED_1(x);         \
        UNUSED_1(y);         \
        UNUSED_1(z);         \
        UNUSED_1(q);         \
    } while (0)
#define _GET_UNUSED_MACRO_OF_ARITY(_1, _2, _3, _4, ARITY, ...) UNUSED_##ARITY
#define UNUSED(...) _GET_UNUSED_MACRO_OF_ARITY(__VA_ARGS__, 4, 3, 2, 1)(__VA_ARGS__)

#if PHP_VERSION_ID < 70000
#define PHP5_UNUSED(...) UNUSED(__VA_ARGS__)
#define PHP7_UNUSED(...) /* unused unused */
#else
#define PHP5_UNUSED(...) /* unused unused */
#define PHP7_UNUSED(...) UNUSED(__VA_ARGS__)
#endif

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

PHP_INI_BEGIN()
STD_PHP_INI_ENTRY("ddtrace.disable", "0", PHP_INI_SYSTEM, OnUpdateBool, disable, zend_ddtrace_globals, ddtrace_globals)
STD_PHP_INI_ENTRY("ddtrace.request_init_hook", "", PHP_INI_SYSTEM, OnUpdateString, request_init_hook,
                  zend_ddtrace_globals, ddtrace_globals)
STD_PHP_INI_ENTRY("ddtrace.ignore_missing_overridables", "1", PHP_INI_SYSTEM, OnUpdateBool, ignore_missing_overridables,
                  zend_ddtrace_globals, ddtrace_globals)
PHP_INI_END()

static inline void table_dtor(void *zv) {
    HashTable *ht = *(HashTable **)zv;
    zend_hash_destroy(ht);
    efree(ht);
}

static void php_ddtrace_init_globals(zend_ddtrace_globals *ng) { memset(ng, 0, sizeof(zend_ddtrace_globals)); }

static PHP_MINIT_FUNCTION(ddtrace) {
    UNUSED(type);
    ZEND_INIT_MODULE_GLOBALS(ddtrace, php_ddtrace_init_globals, NULL);
    REGISTER_INI_ENTRIES();

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    zend_hash_init(&DDTRACE_G(class_lookup), 8, NULL, (dtor_func_t)table_dtor, 0);
    zend_hash_init(&DDTRACE_G(function_lookup), 8, NULL, (dtor_func_t)ddtrace_class_lookup_free, 0);

    ddtrace_dispatch_init(TSRMLS_C);
    ddtrace_dispatch_inject();

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
            "<a href=\"https://github.com/DataDog/dd-trace-php/blob/master/README.md#getting-started\" "
            "style=\"background:transparent;\">the documentation</a>.</strong>" TSRMLS_CC);
    } else {
        datadog_info_print(
            "\nFor help, check out the documentation at "
            "https://github.com/DataDog/dd-trace-php/blob/master/README.md#getting-started" TSRMLS_CC);
    }
    datadog_info_print(!sapi_module.phpinfo_as_text ? "<br><br>" : "\n" TSRMLS_CC);
    datadog_info_print("(c) Datadog 2018\n" TSRMLS_CC);
    php_info_print_box_end();

    php_info_print_table_start();
    php_info_print_table_row(2, "Datadog tracing support", DDTRACE_G(disable) ? "disabled" : "enabled");
    php_info_print_table_row(2, "Version", PHP_DDTRACE_VERSION);
    php_info_print_table_end();

    DISPLAY_INI_ENTRIES();
}

static PHP_FUNCTION(dd_trace) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr);
    STRING_T *function = NULL;
    zend_class_entry *clazz = NULL;
    zval *callable = NULL;

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

#if PHP_VERSION_ID < 70000
    ALLOC_INIT_ZVAL(function);

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "Csz", &clazz,
                                 &Z_STRVAL_P(function), &Z_STRLEN_P(function), &callable) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "sz", &Z_STRVAL_P(function),
                                 &Z_STRLEN_P(function), &callable) != SUCCESS) {
        if (!DDTRACE_G(ignore_missing_overridables)) {
            zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                                    "unexpected parameter combination, expected (class, function, closure) "
                                    "or (function, closure)");
        }

        RETURN_BOOL(0);
    }
    DD_PRINTF("Function name: %s", Z_STRVAL_P(function));

#else
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "CSz", &clazz, &function, &callable) !=
            SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Sz", &function, &callable) != SUCCESS) {
        if (!DDTRACE_G(ignore_missing_overridables)) {
            zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
                                    "unexpected parameter combination, expected (class, function, closure) "
                                    "or (function, closure)");
        }
        RETURN_BOOL(0);
    }
#endif
    zend_bool rv = ddtrace_trace(clazz, function, callable TSRMLS_CC);

#if PHP_VERSION_ID < 70000
    FREE_ZVAL(function);
#endif
    RETURN_BOOL(rv);
}

// This function allows untracing a function.
static PHP_FUNCTION(dd_untrace) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    STRING_T *function = NULL;

#if PHP_VERSION_ID < 70000
    ALLOC_INIT_ZVAL(function);
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "s", &Z_STRVAL_P(function),
                                 &Z_STRLEN_P(function)) != SUCCESS) {
        if (!DDTRACE_G(ignore_missing_overridables)) {
            zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                                    "unexpected parameter. the function name must be provided");
        }

        RETURN_BOOL(0);
    }
    DD_PRINTF("Untracing function: %s", Z_STRVAL_P(function));

    // Remove the traced function from the global lookup
    zend_hash_del(&DDTRACE_G(function_lookup), Z_STRVAL_P(function), Z_STRLEN_P(function));
#else
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "S", &function) != SUCCESS) {
        if (!DDTRACE_G(ignore_missing_overridables)) {
            zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
                                    "unexpected parameter. the function name must be provided");
        }
        RETURN_BOOL(0);
    }

    // Remove the traced function from the global lookup
    zend_hash_del(&DDTRACE_G(function_lookup), function);
#endif

#if PHP_VERSION_ID < 70000
    FREE_ZVAL(function);
#endif
    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_trace_reset) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    ddtrace_dispatch_reset();
    RETURN_BOOL(1);
}

// method used to be able to easily breakpoint the execution at specific PHP line in GDB
static PHP_FUNCTION(dd_trace_noop) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    RETURN_BOOL(1);
}

static const zend_function_entry ddtrace_functions[] = {PHP_FE(dd_trace, NULL) PHP_FE(dd_trace_reset, NULL) PHP_FE(
    dd_trace_noop, NULL) PHP_FE(dd_untrace, NULL) ZEND_FE_END};

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
