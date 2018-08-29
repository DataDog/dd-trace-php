#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "php/ext/spl/spl_exceptions.h"

#include "compat_zend_string.h"
#include "ddtrace.h"
#include "dispatch.h"
#include "dispatch_compat.h"

#include "Zend/zend_exceptions.h"
#include "Zend/zend.h"
#include "debug.h"

#define UNUSED(x) (void)(x)

#if PHP_VERSION_ID < 70000
#define PHP5_UNUSED(x) (void)(x)
#else
#define PHP5_UNUSED(x) //unused unused
#endif

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

#define ddtrace_disabled_guard() do { \
    if (DDTRACE_G(disable)) { \
        zend_throw_exception_ex(spl_ce_RuntimeException, 0 TSRMLS_CC, "ddtrace is disabled by configuration (ddtrace.disable)"); \
        return; \
    } \
} while(0)

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("ddtrace.disable", "0", PHP_INI_SYSTEM, OnUpdateBool, disable, zend_ddtrace_globals, ddtrace_globals)
PHP_INI_END()

static void php_ddtrace_init_globals(zend_ddtrace_globals *ng) {
    memset(ng, 0, sizeof(zend_ddtrace_globals));
}

static PHP_MINIT_FUNCTION(ddtrace)
{
    UNUSED(type);
    ZEND_INIT_MODULE_GLOBALS(ddtrace, php_ddtrace_init_globals, NULL);
    REGISTER_INI_ENTRIES();

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    ddtrace_dispatch_init();

    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(ddtrace)
{
    UNUSED(module_number);
    UNUSED(type);

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    return SUCCESS;
}

static inline void table_dtor(void *zv) {
    zend_hash_destroy((HashTable *)zv);
    efree(zv);
}

static PHP_RINIT_FUNCTION(ddtrace)
{
    UNUSED(module_number);
    UNUSED(type);

#if defined(ZTS) && PHP_VERSION_ID >= 70000
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    zend_hash_init(&DDTRACE_G(class_lookup), 8, NULL, (dtor_func_t) table_dtor, 0);
    zend_hash_init(&DDTRACE_G(function_lookup), 8, NULL, (dtor_func_t) ddtrace_class_lookup_free, 0);

    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(ddtrace)
{
    UNUSED(module_number);
    UNUSED(type);
    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    return SUCCESS;
}

static PHP_MINFO_FUNCTION(ddtrace)
{
    UNUSED(zend_module);
    php_info_print_table_start();
    php_info_print_table_header(2, "Datadog tracing support", DDTRACE_G(disable) ? "disabled" : "enabled");
    php_info_print_table_row(2, "Version", PHP_DDTRACE_VERSION);
    php_info_print_table_end();
}


static PHP_FUNCTION(dd_trace)
{
    PHP5_UNUSED(return_value_used);
    PHP5_UNUSED(this_ptr);
    PHP5_UNUSED(return_value_ptr);
    STRING_T *function = NULL;
    zend_class_entry *clazz = NULL;
    zval *callable = NULL;

    ddtrace_disabled_guard();

    #if PHP_VERSION_ID < 70000
    ALLOC_INIT_ZVAL(function);

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "Csz", &clazz, &Z_STRVAL_P(function), &Z_STRLEN_P(function), &callable) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "sz", &Z_STRVAL_P(function), &Z_STRLEN_P(function), &callable) != SUCCESS) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC, "unexpected parameter combination, expected (class, function, closure) or (function, closure)");
        return;
    }
    DD_PRINTF("Function name: %s", Z_STRVAL_P(function));

    #else
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "CSz", &clazz, &function, &callable) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Sz", &function, &callable) != SUCCESS) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,	"unexpected parameter combination, expected (class, function, closure) or (function, closure)");
        return;
    }
    #endif


    RETURN_BOOL(ddtrace_trace(clazz, function, callable TSRMLS_CC));
}

static const zend_function_entry ddtrace_functions[] = {
    PHP_FE(dd_trace, NULL)
    ZEND_FE_END
};

zend_module_entry ddtrace_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_DDTRACE_EXTNAME,
    ddtrace_functions,
    PHP_MINIT(ddtrace),
    PHP_MSHUTDOWN(ddtrace),
    PHP_RINIT(ddtrace),
    PHP_RSHUTDOWN(ddtrace),
    PHP_MINFO(ddtrace),
    PHP_DDTRACE_VERSION,
    STANDARD_MODULE_PROPERTIES
};


#ifdef COMPILE_DL_DDTRACE
ZEND_GET_MODULE(ddtrace)
#if defined(ZTS) && PHP_VERSION_ID >= 70000
    ZEND_TSRMLS_CACHE_DEFINE();
#endif
#endif
