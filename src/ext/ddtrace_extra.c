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

#include "compatibility.h"
#include "ddtrace.h"
#include "debug.h"
#include "env_config.h"
#include "serializer.h"
#include "ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

/* {{{ proto string dd_trace_serialize_msgpack(array trace_array) */
PHP_FUNCTION(dd_trace_serialize_msgpack) {
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
PHP_FUNCTION(dd_trace_noop) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    RETURN_BOOL(1);
}

#define ALLOWED_MAX_MEMORY_USE_IN_PERCENT_OF_MEMORY_LIMIT 0.20

static zend_long get_memory_limit(){
    char *raw_memory_limit = ddtrace_get_c_string_config("DD_MEMORY_LIMIT");
    size_t len = 0;
    zend_long limit = -1;

    if (raw_memory_limit) {
        len = strlen(raw_memory_limit);
    }
    if (len == 0) {
        if (PG(memory_limit) > 0) {
            limit = PG(memory_limit) * ALLOWED_MAX_MEMORY_USE_IN_PERCENT_OF_MEMORY_LIMIT;
        } else {
            limit = -1;
        }
    } else {
        limit = zend_atol(raw_memory_limit, len);
        if (raw_memory_limit[len-1] == '%') {
            if (PG(memory_limit)) {
                limit = PG(memory_limit) * (100.0 / (double)limit);
            } else {
                limit = -1;
            }
        }
    }

    if (raw_memory_limit) {
        efree(raw_memory_limit);
    }

    return limit;

}

/* {{{ proto int dd_trace_dd_get_memory_limit() */
PHP_FUNCTION(dd_trace_dd_get_memory_limit) {
    PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    PHP7_UNUSED(execute_data);

    RETURN_LONG(get_memory_limit());
}


// /* {{{ proto bool dd_trace_check_memory_pressure() */
// PHP_FUNCTION(dd_trace_check_memory_pressure) {
//     PHP5_UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
//     PHP7_UNUSED(execute_data);

//     zend_long memory_limit =
//     char *raw_memory_limit = ddtrace_get_c_string_config("DD_MEMORY_LIMIT");
//     if (!memory_limit) {

//     }

//     zend_long zend_atoi()
//     DD_SET_MEMORY_LIMIT=20%
//     DD_SET_MEMORY_LIMIT=100M

//     if PG(memory_limit)


// }
