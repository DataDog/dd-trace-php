#include "curl_handlers.h"

#include <Zend/zend_interfaces.h>
#include <php.h>

#include "logging.h"

ZEND_TLS int le_curl = 0;

static void (*_dd_curl_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

ZEND_FUNCTION(ddtrace_curl_exec) {
    zval *zid;

    if (!le_curl || zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &zid) == FAILURE) {
        _dd_curl_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    void *resource = zend_fetch_resource(Z_RES_P(zid), NULL, le_curl);
    if (!resource) {
        _dd_curl_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    ddtrace_log_debug("Successfully instrumented curl_exec");
    _dd_curl_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

void ddtrace_curl_handlers_startup(void) {
    zend_function *curl_exec;
    curl_exec = zend_hash_str_find_ptr(CG(function_table), "curl_exec", sizeof("curl_exec") - 1);
    if (curl_exec != NULL) {
        _dd_curl_exec_handler = curl_exec->internal_function.handler;
        curl_exec->internal_function.handler = ZEND_FN(ddtrace_curl_exec);
    }
}

void ddtrace_curl_handlers_rinit(void) {
    if (!_dd_curl_exec_handler) {
        return;
    }

    zval retval;
    // todo: begin sandbox

    // curl_var = curl_init()
    zval *curl_var = zend_call_method_with_0_params(NULL, NULL, NULL, "curl_init", &retval);
    if (curl_var && Z_TYPE_P(curl_var) == IS_RESOURCE) {
        // extract resource type
        le_curl = Z_RES_P(curl_var)->type;

        // curl_close(result)
        zend_call_method_with_1_params(NULL, NULL, NULL, "curl_close", &retval, curl_var);
    }

    // todo: close sandbox
}
