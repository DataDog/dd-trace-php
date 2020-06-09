#include <inttypes.h>
#include <php.h>
#include <stdbool.h>

#include <ext/standard/php_array.h>

#include "configuration.h"
#include "ddtrace.h"
#include "distributed_tracing.h"
#include "engine_api.h"
#include "handlers_internal.h"
#include "random.h"

// True global - only modify during MINIT/MSHUTDOWN
long _dd_const_curlopt_httpheader = 0;

static void (*_dd_curl_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_dd_curl_copy_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_dd_curl_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_dd_curl_init_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_dd_curl_setopt_array_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_dd_curl_setopt_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static bool _dd_load_curl_integration(TSRMLS_D) {
    if (!get_dd_trace_sandbox_enabled() || DDTRACE_G(disable_in_current_request)) {
        return false;
    }
    return ddtrace_config_distributed_tracing_enabled(TSRMLS_C) && DDTRACE_G(le_curl);
}

static void _dd_saved_headers_dtor(void *headers) {
    HashTable *ht = *((HashTable **)headers);
    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}

static void _dd_store_resource_header_cache(zval *resource, HashTable *headers TSRMLS_DC) {
    if (!DDTRACE_G(dt_http_saved_curl_headers)) {
        ALLOC_HASHTABLE(DDTRACE_G(dt_http_saved_curl_headers));
        zend_hash_init(DDTRACE_G(dt_http_saved_curl_headers), 8, NULL, (dtor_func_t)_dd_saved_headers_dtor, 0);
    }

    HashTable *new_headers;
    ALLOC_HASHTABLE(new_headers);
    zend_hash_init(new_headers, zend_hash_num_elements(headers), NULL, ZVAL_PTR_DTOR, 0);
    zend_hash_copy(new_headers, headers, (copy_ctor_func_t)zval_add_ref, NULL, sizeof(zval *));

    zend_hash_index_update(DDTRACE_G(dt_http_saved_curl_headers), Z_RESVAL_P(resource), &new_headers,
                           sizeof(HashTable *), NULL);
}

static void _dd_delete_resource_header_cache(zval *resource TSRMLS_DC) {
    if (DDTRACE_G(dt_http_saved_curl_headers)) {
        zend_hash_index_del(DDTRACE_G(dt_http_saved_curl_headers), Z_RESVAL_P(resource));
    }
}

static bool _dd_is_valid_curl_resource(zval *ch TSRMLS_DC) {
    void *resource = zend_fetch_resource(&ch TSRMLS_CC, -1, "cURL handle", NULL, 1, DDTRACE_G(le_curl));
    return resource != NULL;
}

ZEND_FUNCTION(ddtrace_curl_close) {
    zval *ch;

    if (_dd_load_curl_integration(TSRMLS_C) &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "r", &ch) == SUCCESS) {
        if (_dd_is_valid_curl_resource(ch TSRMLS_CC)) {
            _dd_delete_resource_header_cache(ch TSRMLS_CC);
        }
    }

    _dd_curl_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

static void _dd_copy_dt_http_headers(zval *ch_orig, zval *ch_new TSRMLS_DC) {
    HashTable *headers_store = DDTRACE_G(dt_http_saved_curl_headers);
    HashTable **users_headers = NULL;
    if (headers_store && zend_hash_index_find(headers_store, Z_RESVAL_P(ch_orig), (void **)&users_headers) == SUCCESS) {
        _dd_store_resource_header_cache(ch_new, *users_headers TSRMLS_CC);
    }
}

ZEND_FUNCTION(ddtrace_curl_copy_handle) {
    zval *ch1;

    if (!_dd_load_curl_integration(TSRMLS_C) ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "r", &ch1) == FAILURE) {
        _dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    _dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_RESOURCE) {
        _dd_copy_dt_http_headers(ch1, return_value TSRMLS_CC);
    }
}

// Returns the user's headers for this resource
// Caller must free the HashTable
static HashTable *_dd_get_existing_headers(zval *resource TSRMLS_DC) {
    HashTable *retval;
    ALLOC_HASHTABLE(retval);

    HashTable *headers_store = DDTRACE_G(dt_http_saved_curl_headers);
    HashTable **users_headers = NULL;
    if (headers_store &&
        zend_hash_index_find(headers_store, Z_RESVAL_P(resource), (void **)&users_headers) == SUCCESS) {
        size_t headers_count = zend_hash_num_elements(*users_headers);
        zend_hash_init(retval, headers_count, NULL, ZVAL_PTR_DTOR, 0);
        zend_hash_copy(retval, *users_headers, (copy_ctor_func_t)zval_add_ref, NULL, sizeof(zval *));
    } else {
        zend_hash_init(retval, 0, NULL, ZVAL_PTR_DTOR, 0);
    }
    return retval;
}

ZEND_FUNCTION(ddtrace_curl_exec) {
    zval *ch;

    if (_dd_load_curl_integration(TSRMLS_C) && ddtrace_peek_span_id(TSRMLS_C) != 0 &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "r", &ch) == SUCCESS) {
        if (_dd_is_valid_curl_resource(ch TSRMLS_CC) &&
            zend_hash_exists(EG(function_table), "ddtrace\\bridge\\curl_inject_distributed_headers",
                             sizeof("ddtrace\\bridge\\curl_inject_distributed_headers") /* no - 1 */)) {
            // Inject distributed tracing headers before the curl_exec() call
            zval **setopt_args[2];

            // Arg 0: resource $ch
            setopt_args[0] = &ch;

            // Arg 1: mixed $value (array of headers)
            HashTable *headers = _dd_get_existing_headers(ch TSRMLS_CC);
            zval *headers_zv;
            MAKE_STD_ZVAL(headers_zv);
            headers_zv->type = IS_ARRAY;
            headers_zv->value.ht = headers;
            zval_copy_ctor(headers_zv);
            setopt_args[1] = &headers_zv;

            zval *retval = NULL;
            DDTRACE_G(back_up_http_headers) = 0;  // Don't save our own HTTP headers
            if (ddtrace_call_sandboxed_function(ZEND_STRL("ddtrace\\bridge\\curl_inject_distributed_headers"), &retval,
                                                2, setopt_args TSRMLS_CC) == SUCCESS &&
                retval) {
                zval_ptr_dtor(&retval);
            }
            DDTRACE_G(back_up_http_headers) = 1;

            zval_ptr_dtor(&headers_zv);
            zend_hash_destroy(headers);
            FREE_HASHTABLE(headers);
        }
    }

    _dd_curl_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_init) {
    _dd_curl_init_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_RESOURCE) {
        if (!DDTRACE_G(le_curl)) {
            zend_list_find(Z_LVAL_P(return_value), &DDTRACE_G(le_curl));
            DDTRACE_G(back_up_http_headers) = 1;
        }
        if (_dd_load_curl_integration(TSRMLS_C)) {
            _dd_delete_resource_header_cache(return_value TSRMLS_CC);
        }
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt) {
    zval *zid, **zvalue;
    long option;

    if (!_dd_load_curl_integration(TSRMLS_C) ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "rlZ", &zid, &option, &zvalue) ==
            FAILURE) {
        _dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    _dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (DDTRACE_G(back_up_http_headers) && Z_BVAL_P(return_value) && _dd_const_curlopt_httpheader == option) {
        _dd_store_resource_header_cache(zid, Z_ARRVAL_PP(zvalue) TSRMLS_CC);
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt_array) {
    zval *zid, *arr;

    if (!_dd_load_curl_integration(TSRMLS_C) ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "ra", &zid, &arr) == FAILURE) {
        _dd_curl_setopt_array_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    _dd_curl_setopt_array_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_BVAL_P(return_value)) {
        zval **value;
        if (zend_hash_index_find(Z_ARRVAL_P(arr), _dd_const_curlopt_httpheader, (void **)&value) == SUCCESS) {
            _dd_store_resource_header_cache(zid, Z_ARRVAL_PP(value) TSRMLS_CC);
        }
    }
}

struct _dd_curl_handler {
    const char *name;
    size_t name_len;
    void (**old_handler)(INTERNAL_FUNCTION_PARAMETERS);
    void (*new_handler)(INTERNAL_FUNCTION_PARAMETERS);
};
typedef struct _dd_curl_handler _dd_curl_handler;

static void _dd_install_handler(_dd_curl_handler handler TSRMLS_DC) {
    zend_function *old_handler;
    if (zend_hash_find(CG(function_table), handler.name, handler.name_len, (void **)&old_handler) == SUCCESS &&
        old_handler != NULL) {
        *handler.old_handler = old_handler->internal_function.handler;
        old_handler->internal_function.handler = handler.new_handler;
    }
}

void ddtrace_curl_handlers_startup(void) {
    TSRMLS_FETCH();
    // If we cannot find ext/curl then do not hook the functions
    if (!zend_hash_exists(&module_registry, "curl", sizeof("curl") /* no - 1 */)) {
        return;
    }

    zval *tmp;
    MAKE_STD_ZVAL(tmp);
    int res = zend_get_constant_ex(ZEND_STRL("CURLOPT_HTTPHEADER"), tmp, NULL, ZEND_FETCH_CLASS_SILENT TSRMLS_CC);
    if (res) {
        _dd_const_curlopt_httpheader = Z_LVAL_P(tmp);
    }
    zval_dtor(tmp);
    efree(tmp);
    if (!res) {
        return;
    }

    // These are not 'sizeof() - 1' on PHP 5
    _dd_curl_handler handlers[] = {
        {"curl_close", sizeof("curl_close"), &_dd_curl_close_handler, ZEND_FN(ddtrace_curl_close)},
        {"curl_copy_handle", sizeof("curl_copy_handle"), &_dd_curl_copy_handle_handler,
         ZEND_FN(ddtrace_curl_copy_handle)},
        {"curl_exec", sizeof("curl_exec"), &_dd_curl_exec_handler, ZEND_FN(ddtrace_curl_exec)},
        {"curl_init", sizeof("curl_init"), &_dd_curl_init_handler, ZEND_FN(ddtrace_curl_init)},
        {"curl_setopt", sizeof("curl_setopt"), &_dd_curl_setopt_handler, ZEND_FN(ddtrace_curl_setopt)},
        {"curl_setopt_array", sizeof("curl_setopt_array"), &_dd_curl_setopt_array_handler,
         ZEND_FN(ddtrace_curl_setopt_array)},
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        _dd_install_handler(handlers[i] TSRMLS_CC);
    }
}

void ddtrace_curl_handlers_rshutdown(void) {
    TSRMLS_FETCH();
    DDTRACE_G(le_curl) = 0;
}
