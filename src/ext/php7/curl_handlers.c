#include "curl_handlers.h"

#include <Zend/zend_interfaces.h>
#include <php.h>

#include "configuration.h"
#include "engine_hooks.h"  // for ddtrace_backup_error_handling
#include "logging.h"

/* "le_curl" is ext/curl's resource type.
 * "le_curl" is what php_curl.h names this variable
 */
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
    if (!get_dd_trace_sandbox_enabled()) {
        return;
    }
    zend_function *curl_exec;
    curl_exec = zend_hash_str_find_ptr(CG(function_table), "curl_exec", sizeof("curl_exec") - 1);
    if (curl_exec != NULL) {
        _dd_curl_exec_handler = curl_exec->internal_function.handler;
        curl_exec->internal_function.handler = ZEND_FN(ddtrace_curl_exec);
    }
}

static void _dd_find_curl_resource_type(void) {
    zval retval;

    /* If we didn't find a curl_exec handler then curl probably isn't loaded
     * and it's probably not safe to run `curl_init()`.
     */
    if (!_dd_curl_exec_handler) {
        return;
    }

    ddtrace_error_handling eh;
    ddtrace_backup_error_handling(&eh, EH_THROW);

    // curl_var = curl_init()
    zval *curl_var = zend_call_method_with_0_params(NULL, NULL, NULL, "curl_init", &retval);
    if (curl_var && Z_TYPE_P(curl_var) == IS_RESOURCE) {
        // extract resource type
        le_curl = Z_RES_P(curl_var)->type;

        // curl_close(result)
        zend_call_method_with_1_params(NULL, NULL, NULL, "curl_close", &retval, curl_var);
    }

    ddtrace_restore_error_handling(&eh);
    // this doesn't throw (today anyway) but let's be safe
    ddtrace_maybe_clear_exception();
}

void ddtrace_curl_handlers_rinit(void) { _dd_find_curl_resource_type(); }
