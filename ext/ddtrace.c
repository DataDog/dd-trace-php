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

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

#define ddtrace_disabled_guard() do { \
	if (DDTRACE(disable)) { \
		zend_throw_exception_ex(spl_ce_RuntimeException, 0, "ddtrace is disabled by configuration (ddtrace.disable)"); \
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
	ZEND_INIT_MODULE_GLOBALS(ddtrace, php_ddtrace_init_globals, NULL);
	REGISTER_INI_ENTRIES();

	if (DDTRACE(disable)) {
		return SUCCESS;
    }

	ddtrace_dispatch_init();

	return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(ddtrace)
{
	if (DDTRACE(disable)) {
		return SUCCESS;
	}

	return SUCCESS;
} 

static inline void table_dtor(zval *zv) { 
	zend_hash_destroy(Z_PTR_P(zv));
	efree(Z_PTR_P(zv));
}

static PHP_RINIT_FUNCTION(ddtrace)
{
	zend_class_entry *ce = NULL;

#ifdef ZTS
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	if (DDTRACE(disable)) {
		return SUCCESS;
	}

    zend_hash_init(&DDTRACE(dispatch_lookup), 8, NULL, table_dtor, 0);

	return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(ddtrace)
{
	if (DDTRACE(disable)) {
		return SUCCESS;
	}

	return SUCCESS;
}

static PHP_MINFO_FUNCTION(ddtrace)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "Datadog tracing support", DDTRACE(disable) ? "disabled" : "enabled");
	php_info_print_table_row(2, "Version", PHP_DDTRACE_VERSION);
	php_info_print_table_end();
}

static PHP_FUNCTION(dd_trace) 
{
	STRING_T *function = NULL;
	zend_class_entry *clazz = NULL;
    zval *callable = NULL;

	ddtrace_disabled_guard();

	if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "CSz", &clazz, &function, &callable) != SUCCESS &&
		zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Sz", &function, &callable) != SUCCESS) {
	    zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,	"unexpected parameter combination, expected (class, function, closure) or (function, closure)");
		return;
	}

	RETURN_BOOL(ddtrace_trace(clazz, function, callable));
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
#ifdef ZTS
	ZEND_TSRMLS_CACHE_DEFINE();
#endif
#endif
