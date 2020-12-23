#include <php.h>
#include <stdbool.h>

#include "configuration.h"
#include "engine_api.h"
#include "engine_hooks.h"  // for ddtrace_backup_error_handling
#include "handlers_internal.h"
#include "logging.h"
#include "span.h"

// True global - only modify during MINIT/MSHUTDOWN
bool dd_ext_curl_loaded = false;
zend_long dd_const_curlopt_httpheader = 0;

/* "le_curl" is ext/curl's resource type.
 * "le_curl" is what php_curl.h names this variable
 */
ZEND_TLS int le_curl = 0;

ZEND_TLS HashTable *dd_headers = NULL;
ZEND_TLS bool dd_should_save_headers = true;
ZEND_TLS zend_function *dd_curl_inject_fn_proxy = NULL;

static void (*dd_curl_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_copy_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_init_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_setopt_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_setopt_array_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

static bool dd_load_curl_integration(void) {
    if (!dd_ext_curl_loaded || !le_curl || DDTRACE_G(disable_in_current_request)) {
        return false;
    }
    return ddtrace_config_distributed_tracing_enabled();
}

static void dd_headers_dtor(void *headers) {
    HashTable *ht = *((HashTable **)headers);
    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}

static void dd_ch_store_headers(zval *ch, HashTable *headers) {
    if (!dd_headers) {
        ALLOC_HASHTABLE(dd_headers);
        zend_hash_init(dd_headers, 8, NULL, (dtor_func_t)dd_headers_dtor, 0);
    }

    HashTable *new_headers;
    ALLOC_HASHTABLE(new_headers);
    zend_hash_init(new_headers, zend_hash_num_elements(headers), NULL, ZVAL_PTR_DTOR, 0);
    zend_hash_copy(new_headers, headers, (copy_ctor_func_t)zval_add_ref);

    zend_hash_index_update_ptr(dd_headers, Z_RES_HANDLE_P(ch), new_headers);
}

static void dd_ch_delete_headers(zval *ch) {
    if (dd_headers) {
        zend_hash_index_del(dd_headers, Z_RES_HANDLE_P(ch));
    }
}

static void dd_ch_duplicate_headers(zval *ch_orig, zval *ch_new) {
    if (dd_headers) {
        HashTable *headers = zend_hash_index_find_ptr(dd_headers, Z_RES_HANDLE_P(ch_orig));
        if (headers) {
            dd_ch_store_headers(ch_new, headers);
        }
    }
}

static void dd_init_headers_arg(zval *arg, zval *ch) {
    HashTable *retval;
    ALLOC_HASHTABLE(retval);
    HashTable *headers = NULL;

    if (dd_headers) {
        headers = zend_hash_index_find_ptr(dd_headers, Z_RES_HANDLE_P(ch));
        if (headers) {
            size_t headers_count = zend_hash_num_elements(headers);
            zend_hash_init(retval, headers_count, NULL, ZVAL_PTR_DTOR, 0);
            zend_hash_copy(retval, headers, (copy_ctor_func_t)zval_add_ref);
        }
    }

    if (!headers) {
        zend_hash_init(retval, 0, NULL, ZVAL_PTR_DTOR, 0);
    }
    ZVAL_ARR(arg, retval);
}

static void dd_free_headers_arg(zval *arg) { zend_array_destroy(Z_ARRVAL_P(arg)); }

static void dd_inject_distributed_tracing_headers(zval *ch) {
    zend_string *inject_func = zend_string_init(ZEND_STRL("ddtrace\\bridge\\curl_inject_distributed_headers"), 0);
    if (zend_hash_exists(EG(function_table), inject_func)) {
        zend_function **fn_proxy = &dd_curl_inject_fn_proxy;
        zval retval = ddtrace_zval_undef();

        zval headers;
        dd_init_headers_arg(&headers, ch);

        ddtrace_sandbox_backup backup = ddtrace_sandbox_begin();
        dd_should_save_headers = false;  // Don't save our own HTTP headers
        // Arg 0: CurlHandle $ch
        // Arg 1: mixed $value (array of headers)
        if (ddtrace_call_function(fn_proxy, ZSTR_VAL(inject_func), ZSTR_LEN(inject_func), &retval, 2, ch, &headers) ==
            SUCCESS) {
            zval_ptr_dtor(&retval);
        } else {
            ddtrace_log_debug("Could not inject distributed tracing headers");
        }
        dd_should_save_headers = true;
        ddtrace_sandbox_end(&backup);

        dd_free_headers_arg(&headers);
    }
    zend_string_release(inject_func);
}

static bool dd_is_valid_curl_resource(zval *ch) {
    void *resource = zend_fetch_resource(Z_RES_P(ch), NULL, le_curl);
    return resource != NULL;
}

ZEND_FUNCTION(ddtrace_curl_close) {
    zval *ch;

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &ch) == SUCCESS) {
        if (dd_is_valid_curl_resource(ch)) {
            dd_ch_delete_headers(ch);
        }
    }

    dd_curl_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_copy_handle) {
    zval *ch;

    if (!dd_load_curl_integration() ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &ch) == FAILURE) {
        dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_RESOURCE) {
        dd_ch_duplicate_headers(ch, return_value);
    }
}

ZEND_FUNCTION(ddtrace_curl_exec) {
    zval *ch;

    if (dd_load_curl_integration() && ddtrace_peek_span_id() != 0 &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &ch) == SUCCESS) {
        if (dd_is_valid_curl_resource(ch)) {
            dd_inject_distributed_tracing_headers(ch);
        }
    }

    dd_curl_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_init) {
    dd_curl_init_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_RESOURCE) {
        if (!le_curl) {
            le_curl = Z_RES_TYPE_P(return_value);
        }
        if (dd_load_curl_integration()) {
            dd_ch_delete_headers(return_value);
        }
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt) {
    zval *ch, *zvalue;
    zend_long option;

    if (!dd_load_curl_integration() ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "rlz", &ch, &option, &zvalue) == FAILURE) {
        dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_should_save_headers && Z_TYPE_P(return_value) == IS_TRUE && dd_const_curlopt_httpheader == option &&
        Z_TYPE_P(zvalue) == IS_ARRAY) {
        dd_ch_store_headers(ch, Z_ARRVAL_P(zvalue));
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt_array) {
    zval *ch, *arr;

    if (!dd_load_curl_integration() ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "ra", &ch, &arr) == FAILURE) {
        dd_curl_setopt_array_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_curl_setopt_array_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_TRUE) {
        zval *value = zend_hash_index_find(Z_ARRVAL_P(arr), dd_const_curlopt_httpheader);
        if (value && Z_TYPE_P(value) == IS_ARRAY) {
            dd_ch_store_headers(ch, Z_ARRVAL_P(value));
        }
    }
}

struct dd_curl_handler {
    const char *name;
    size_t name_len;
    void (**old_handler)(INTERNAL_FUNCTION_PARAMETERS);
    void (*new_handler)(INTERNAL_FUNCTION_PARAMETERS);
};
typedef struct dd_curl_handler dd_curl_handler;

static void dd_install_handler(dd_curl_handler handler) {
    zend_function *old_handler;
    old_handler = zend_hash_str_find_ptr(CG(function_table), handler.name, handler.name_len);
    if (old_handler != NULL) {
        *handler.old_handler = old_handler->internal_function.handler;
        old_handler->internal_function.handler = handler.new_handler;
    }
}

/* This function is called during process startup so all of the memory allocations should be
 * persistent to avoid using the Zend Memory Manager. This will avoid an accidental use after free.
 * 
 * "If you use ZendMM out of the scope of a request (like in MINIT()), the allocation will be
 * silently cleared by ZendMM before treating the first request, and you'll probably use-after-free:
 * simply don't."
 *
 * @see http://www.phpinternalsbook.com/php7/memory_management/zend_memory_manager.html#common-errors-and-mistakes
 */
void ddtrace_curl_handlers_startup(void) {
    // if we cannot find ext/curl then do not instrument it
    zend_string *curl = zend_string_init(ZEND_STRL("curl"), 1);
    dd_ext_curl_loaded = zend_hash_exists(&module_registry, curl);
    zend_string_release(curl);
    if (!dd_ext_curl_loaded) {
        return;
    }

    zend_string *const_name = zend_string_init(ZEND_STRL("CURLOPT_HTTPHEADER"), 1);
    zval *const_value = zend_get_constant_ex(const_name, NULL, ZEND_FETCH_CLASS_SILENT);
    zend_string_release(const_name);
    if (!const_value) {
        // If this fails, something is really wrong
        dd_ext_curl_loaded = false;
        return;
    }
    dd_const_curlopt_httpheader = Z_LVAL_P(const_value);

    /* We hook into curl_exec twice:
     *   - One that handles general dispatch so it will call the associated closure with curl_exec
     *   - One that handles the distributed tracing headers
     * The latter expects the former is already done because it needs a span id for the distributed tracing headers;
     * register them inside-out.
     */
    dd_curl_handler handlers[] = {
        {ZEND_STRL("curl_close"), &dd_curl_close_handler, ZEND_FN(ddtrace_curl_close)},
        {ZEND_STRL("curl_copy_handle"), &dd_curl_copy_handle_handler, ZEND_FN(ddtrace_curl_copy_handle)},
        {ZEND_STRL("curl_exec"), &dd_curl_exec_handler, ZEND_FN(ddtrace_curl_exec)},
        {ZEND_STRL("curl_init"), &dd_curl_init_handler, ZEND_FN(ddtrace_curl_init)},
        {ZEND_STRL("curl_setopt"), &dd_curl_setopt_handler, ZEND_FN(ddtrace_curl_setopt)},
        {ZEND_STRL("curl_setopt_array"), &dd_curl_setopt_array_handler, ZEND_FN(ddtrace_curl_setopt_array)},
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        dd_install_handler(handlers[i]);
    }

    if (ddtrace_resource != -1) {
        ddtrace_string curl_exec = DDTRACE_STRING_LITERAL("curl_exec");
        ddtrace_replace_internal_function(CG(function_table), curl_exec);
    }
}

void ddtrace_curl_handlers_rinit(void) {
    le_curl = 0;
    dd_headers = NULL;
    dd_should_save_headers = true;
    dd_curl_inject_fn_proxy = NULL;
}

void ddtrace_curl_handlers_rshutdown(void) {
    le_curl = 0;
    if (dd_headers) {
        zend_hash_destroy(dd_headers);
        FREE_HASHTABLE(dd_headers);
        dd_headers = NULL;
    }
    dd_curl_inject_fn_proxy = NULL;
}
