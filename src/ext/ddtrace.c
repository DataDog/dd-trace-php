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
#define _GET_UNUSED_MACRO_OF_ARITY(_1, _2, _3, ARITY, ...) UNUSED_##ARITY
#define UNUSED(...) _GET_UNUSED_MACRO_OF_ARITY(__VA_ARGS__, 3, 2, 1)(__VA_ARGS__)

#if PHP_VERSION_ID < 70000
#define PHP5_UNUSED(...) UNUSED(__VA_ARGS__)
#else
#define PHP5_UNUSED(...) /* unused unused */
#endif

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

PHP_INI_BEGIN()
STD_PHP_INI_ENTRY("ddtrace.disable", "0", PHP_INI_SYSTEM, OnUpdateBool, disable, zend_ddtrace_globals, ddtrace_globals)
STD_PHP_INI_ENTRY("ddtrace.request_init_hook", "some.php", PHP_INI_SYSTEM, OnUpdateString, request_init_hook,
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

static int run_a_file(zend_string *filename_val);

static PHP_RINIT_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

#if defined(ZTS) && PHP_VERSION_ID >= 70000
    ZEND_TSRMLS_CACHE_UPDATE();
#endif

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    ddtrace_dispatch_init(TSRMLS_C);

    zend_string *filename = zend_string_init(DDTRACE_G(request_init_hook), strlen(DDTRACE_G(request_init_hook)), 0);

    if (filename && ZSTR_LEN(filename) != 0) {
        run_a_file(filename);
    }
    zend_string_release(filename);
    return SUCCESS;
}

// static int run_a_file_php5(const char *filename TSRMLS_DC)
// {
// 	int filename_len = strlen(filename);
// 	int dummy = 1;
// 	zend_file_handle file_handle;
// 	zend_op_array *new_op_array;
// 	zval *result = NULL;
// 	int ret;

// 	ret = php_stream_open_for_zend_ex(filename, &file_handle, USE_PATH|STREAM_OPEN_FOR_INCLUDE TSRMLS_CC);

// 	if (ret == SUCCESS) {
// 		if (!file_handle.opened_path) {
// 			// file_handle.opened_path = estrndup(filename, filename_len);

// 		}
// 		if (zend_hash_add(&EG(included_files), file_handle.opened_path, strlen(file_handle.opened_path)+1, (void
// *)&dummy, sizeof(int), NULL)==SUCCESS) { 			new_op_array = zend_compile_file(&file_handle, ZEND_REQUIRE TSRMLS_CC);
// 			zend_destroy_file_handle(&file_handle TSRMLS_CC);
// 		} else {
// 			new_op_array = NULL;
// 			zend_file_handle_dtor(&file_handle TSRMLS_CC);
// 		}
// 		if (new_op_array) {
// 			EG(return_value_ptr_ptr) = &result;
// 			EG(active_op_array) = new_op_array;
// 			if (!EG(active_symbol_table)) {
// 				zend_rebuild_symbol_table(TSRMLS_C);
// 			}

// 			zend_execute(new_op_array TSRMLS_CC);

// 			destroy_op_array(new_op_array TSRMLS_CC);
// 			efree(new_op_array);
// 			if (!EG(exception)) {
// 				if (EG(return_value_ptr_ptr)) {
// 					zval_ptr_dtor(EG(return_value_ptr_ptr));
// 				}
// 			}

// 			return 1;
// 		}
// 	}
// 	return 0;
// }

static int run_a_file(zend_string *filename_val) {
    char *filename = ZSTR_VAL(filename_val);
    int filename_len = ZSTR_LEN(filename_val);
    zval dummy;
    zend_file_handle file_handle;
    zend_op_array *new_op_array;
    zval result;
    int ret;

    ret = php_stream_open_for_zend_ex(filename, &file_handle, USE_PATH | STREAM_OPEN_FOR_INCLUDE);

    if (ret == SUCCESS) {
        zend_string *opened_path;
        if (!file_handle.opened_path) {
            file_handle.opened_path = zend_string_init(filename, filename_len, 0);
        }
        opened_path = zend_string_copy(file_handle.opened_path);
        ZVAL_NULL(&dummy);
        if (zend_hash_add(&EG(included_files), opened_path, &dummy)) {
            new_op_array = zend_compile_file(&file_handle, ZEND_REQUIRE);
            zend_destroy_file_handle(&file_handle);
        } else {
            new_op_array = NULL;
            zend_file_handle_dtor(&file_handle);
        }
        zend_string_release(opened_path);
        if (new_op_array) {
            ZVAL_UNDEF(&result);
            zend_execute(new_op_array, &result);

            destroy_op_array(new_op_array);
            efree(new_op_array);
            if (!EG(exception)) {
                zval_ptr_dtor(&result);
            }

            return 1;
        }
    }
    return 0;
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
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                                "unexpected parameter combination, expected (class, function, closure) "
                                "or (function, closure)");
        return;
    }
    DD_PRINTF("Function name: %s", Z_STRVAL_P(function));

#else
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "CSz", &clazz, &function, &callable) !=
            SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Sz", &function, &callable) != SUCCESS) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
                                "unexpected parameter combination, expected (class, function, closure) "
                                "or (function, closure)");
        return;
    }
#endif
    zend_bool rv = ddtrace_trace(clazz, function, callable TSRMLS_CC);

#if PHP_VERSION_ID < 70000
    FREE_ZVAL(function);
#endif
    RETURN_BOOL(rv);
}

static const zend_function_entry ddtrace_functions[] = {PHP_FE(dd_trace, NULL) ZEND_FE_END};

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
