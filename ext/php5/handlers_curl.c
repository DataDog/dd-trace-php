#include <inttypes.h>
#include <php.h>
#include <stdbool.h>

#include <ext/standard/php_array.h>

#include "configuration.h"
#include "ddtrace.h"
#include "engine_api.h"
#include "handlers_internal.h"
#include "random.h"

// True global - only modify during MINIT/MSHUTDOWN
bool dd_ext_curl_loaded = false;
long dd_const_curlopt_httpheader = 0;

static void (*dd_curl_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_copy_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_init_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_add_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_init_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_remove_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_setopt_array_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_setopt_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static bool dd_load_curl_integration(TSRMLS_D) {
    if (!dd_ext_curl_loaded || !DDTRACE_G(le_curl) || DDTRACE_G(disable_in_current_request)) {
        return false;
    }
    return ddtrace_config_distributed_tracing_enabled(TSRMLS_C);
}

static void dd_ht_dtor(void *data) {
    HashTable *ht = *((HashTable **)data);
    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}

static void dd_ch_store_headers(zval *ch, HashTable *headers TSRMLS_DC) {
    if (!DDTRACE_G(curl_headers)) {
        ALLOC_HASHTABLE(DDTRACE_G(curl_headers));
        zend_hash_init(DDTRACE_G(curl_headers), 8, NULL, (dtor_func_t)dd_ht_dtor, 0);
    }

    HashTable *new_headers;
    ALLOC_HASHTABLE(new_headers);
    zend_hash_init(new_headers, zend_hash_num_elements(headers), NULL, ZVAL_PTR_DTOR, 0);
    zend_hash_copy(new_headers, headers, (copy_ctor_func_t)zval_add_ref, NULL, sizeof(zval *));

    zend_hash_index_update(DDTRACE_G(curl_headers), Z_RESVAL_P(ch), &new_headers, sizeof(HashTable *), NULL);
}

static void dd_ch_delete_headers(zval *ch TSRMLS_DC) {
    if (DDTRACE_G(curl_headers)) {
        zend_hash_index_del(DDTRACE_G(curl_headers), Z_RESVAL_P(ch));
    }
}

static HashTable *dd_get_ch_headers(zval *ch TSRMLS_DC) {
    if (DDTRACE_G(curl_headers)) {
        HashTable **tmp = NULL;
        if (zend_hash_index_find(DDTRACE_G(curl_headers), Z_RESVAL_P(ch), (void **)&tmp) == SUCCESS) {
            return *tmp;
        }
    }
    return NULL;
}

static void dd_ch_duplicate_headers(zval *ch_orig, zval *ch_new TSRMLS_DC) {
    HashTable *headers = dd_get_ch_headers(ch_orig TSRMLS_CC);
    if (headers) {
        dd_ch_store_headers(ch_new, headers TSRMLS_CC);
    }
}

static zval *dd_init_headers_arg(zval *ch TSRMLS_DC) {
    HashTable *headers_copy = NULL;
    HashTable *headers = dd_get_ch_headers(ch TSRMLS_CC);

    ALLOC_HASHTABLE(headers_copy);
    if (headers) {
        zend_hash_init(headers_copy, zend_hash_num_elements(headers), NULL, ZVAL_PTR_DTOR, 0);
        zend_hash_copy(headers_copy, headers, (copy_ctor_func_t)zval_add_ref, NULL, sizeof(zval *));
    } else {
        zend_hash_init(headers_copy, 0, NULL, ZVAL_PTR_DTOR, 0);
    }

    zval *arg;
    MAKE_STD_ZVAL(arg);
    Z_TYPE_P(arg) = IS_ARRAY;
    Z_ARRVAL_P(arg) = headers_copy;
    return arg;
}

static void dd_free_headers_arg(zval *arg) { zval_ptr_dtor(&arg); }

static void dd_inject_distributed_tracing_headers(zval *ch TSRMLS_DC) {
    if (zend_hash_exists(EG(function_table), "ddtrace\\bridge\\curl_inject_distributed_headers",
                         sizeof("ddtrace\\bridge\\curl_inject_distributed_headers") /* no - 1 */)) {
        zval **setopt_args[2];

        // Arg 0: resource $ch
        setopt_args[0] = &ch;

        // Arg 1: mixed $value (array of headers)
        zval *headers = dd_init_headers_arg(ch TSRMLS_CC);
        setopt_args[1] = &headers;

        zval *retval = NULL;
        DDTRACE_G(curl_back_up_headers) = 0;  // Don't save our own HTTP headers
        if (ddtrace_call_sandboxed_function(ZEND_STRL("ddtrace\\bridge\\curl_inject_distributed_headers"), &retval, 2,
                                            setopt_args TSRMLS_CC) == SUCCESS &&
            retval) {
            zval_ptr_dtor(&retval);
        }
        DDTRACE_G(curl_back_up_headers) = 1;

        dd_free_headers_arg(headers);
    }
}

static bool dd_is_valid_curl_resource(zval *ch TSRMLS_DC) {
    void *resource = zend_fetch_resource(&ch TSRMLS_CC, -1, "cURL handle", NULL, 1, DDTRACE_G(le_curl));
    return resource != NULL;
}

static void dd_update_mh_ch_map_cache(zval *mh, HashTable *ch_map TSRMLS_DC) {
    DDTRACE_G(curl_mh_ch_map_cache_id) = Z_RESVAL_P(mh);
    DDTRACE_G(curl_mh_ch_map_cache) = ch_map;
}

static HashTable *dd_mh_get_ch_map(zval *mh TSRMLS_DC) {
    HashTable **tmp = NULL;
    if (zend_hash_index_find(DDTRACE_G(curl_mh_ch_map), Z_RESVAL_P(mh), (void **)&tmp) == SUCCESS) {
        return *tmp;
    }
    return NULL;
}

static void dd_mh_map_add_ch(zval *mh, zval *ch TSRMLS_DC) {
    HashTable *ch_map = NULL;

    if (!DDTRACE_G(curl_mh_ch_map)) {
        ALLOC_HASHTABLE(DDTRACE_G(curl_mh_ch_map));
        zend_hash_init(DDTRACE_G(curl_mh_ch_map), 8, NULL, (dtor_func_t)dd_ht_dtor, 0);
    } else {
        ch_map = dd_mh_get_ch_map(mh TSRMLS_CC);
    }

    if (!ch_map) {
        ALLOC_HASHTABLE(ch_map);
        zend_hash_init(ch_map, 8, NULL, ZVAL_PTR_DTOR, 0);
        zend_hash_index_update(DDTRACE_G(curl_mh_ch_map), Z_RESVAL_P(mh), &ch_map, sizeof(HashTable *), NULL);
    }

    zval *tmp;
    MAKE_STD_ZVAL(tmp);
    ZVAL_COPY_VALUE(tmp, ch);
    zval_copy_ctor(tmp);
    zend_hash_index_update(ch_map, Z_RESVAL_P(ch), &tmp, sizeof(zval *), NULL);

    dd_update_mh_ch_map_cache(mh, ch_map TSRMLS_CC);
}

static void dd_mh_map_remove_ch(zval *mh, zval *ch TSRMLS_DC) {
    HashTable *ch_map = NULL;

    if (DDTRACE_G(curl_mh_ch_map)) {
        ch_map = dd_mh_get_ch_map(mh TSRMLS_CC);
        if (ch_map) {
            zend_hash_index_del(ch_map, Z_RESVAL_P(ch));
        }
        dd_update_mh_ch_map_cache(mh, ch_map TSRMLS_CC);
    }
}

static void dd_mh_map_delete(zval *mh TSRMLS_DC) {
    if (DDTRACE_G(curl_mh_ch_map)) {
        zend_hash_index_del(DDTRACE_G(curl_mh_ch_map), Z_RESVAL_P(mh));
        dd_update_mh_ch_map_cache(mh, NULL TSRMLS_CC);
    }
}

static void dd_mh_map_inject_headers(zval *mh TSRMLS_DC) {
    HashTable *ch_map = NULL;

    if (DDTRACE_G(curl_mh_ch_map_cache_id) == Z_RESVAL_P(mh)) {
        ch_map = DDTRACE_G(curl_mh_ch_map_cache);
    } else if (DDTRACE_G(curl_mh_ch_map)) {
        ch_map = dd_mh_get_ch_map(mh TSRMLS_CC);
        dd_update_mh_ch_map_cache(mh, ch_map TSRMLS_CC);
    }

    if (ch_map && zend_hash_num_elements(ch_map) > 0) {
        zval **ch;
        HashPosition pos;
        zend_hash_internal_pointer_reset_ex(ch_map, &pos);
        while (zend_hash_get_current_data_ex(ch_map, (void **)&ch, &pos) == SUCCESS) {
            dd_inject_distributed_tracing_headers(*ch TSRMLS_CC);
            zend_hash_move_forward_ex(ch_map, &pos);
        }
        dd_mh_map_delete(mh TSRMLS_CC);
    }
}

ZEND_FUNCTION(ddtrace_curl_close) {
    zval *ch;

    if (dd_load_curl_integration(TSRMLS_C) &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "r", &ch) == SUCCESS) {
        if (dd_is_valid_curl_resource(ch TSRMLS_CC)) {
            dd_ch_delete_headers(ch TSRMLS_CC);
        }
    }

    dd_curl_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_copy_handle) {
    zval *ch1;

    if (!dd_load_curl_integration(TSRMLS_C) ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "r", &ch1) == FAILURE) {
        dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_RESOURCE) {
        dd_ch_duplicate_headers(ch1, return_value TSRMLS_CC);
    }
}

ZEND_FUNCTION(ddtrace_curl_exec) {
    zval *ch;

    if (dd_load_curl_integration(TSRMLS_C) && ddtrace_peek_span_id(TSRMLS_C) != 0 &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "r", &ch) == SUCCESS) {
        if (dd_is_valid_curl_resource(ch TSRMLS_CC)) {
            dd_inject_distributed_tracing_headers(ch TSRMLS_CC);
        }
    }

    dd_curl_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_init) {
    dd_curl_init_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_RESOURCE) {
        if (!DDTRACE_G(le_curl)) {
            zend_list_find(Z_LVAL_P(return_value), &DDTRACE_G(le_curl));
            DDTRACE_G(curl_back_up_headers) = 1;
        }
        if (dd_load_curl_integration(TSRMLS_C)) {
            // Reset the headers for this ch in the event the resource ID is reused
            dd_ch_delete_headers(return_value TSRMLS_CC);
        }
    }
}

ZEND_FUNCTION(ddtrace_curl_multi_add_handle) {
    zval *z_mh;
    zval *z_ch;

    if (!dd_load_curl_integration(TSRMLS_C) ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "rr", &z_mh, &z_ch) == FAILURE) {
        dd_curl_multi_add_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_mh_map_add_ch(z_mh, z_ch TSRMLS_CC);

    dd_curl_multi_add_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_close) {
    zval *z_mh;

    if (!dd_load_curl_integration(TSRMLS_C) ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "r", &z_mh) == FAILURE) {
        dd_curl_multi_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_mh_map_delete(z_mh TSRMLS_CC);

    dd_curl_multi_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_exec) {
    zval *z_mh;
    zval *z_still_running;

    if (!dd_load_curl_integration(TSRMLS_C) || ddtrace_peek_span_id(TSRMLS_C) == 0 ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "rz", &z_mh, &z_still_running) ==
            FAILURE) {
        dd_curl_multi_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_mh_map_inject_headers(z_mh TSRMLS_CC);

    dd_curl_multi_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_init) {
    if (!dd_load_curl_integration(TSRMLS_C) || ZEND_NUM_ARGS() != 0) {
        dd_curl_multi_init_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_curl_multi_init_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_RESOURCE) {
        // Reset this multi-handle map in the event the resource ID is reused
        dd_mh_map_delete(return_value TSRMLS_CC);
    }
}

ZEND_FUNCTION(ddtrace_curl_multi_remove_handle) {
    zval *z_mh;
    zval *z_ch;

    if (!dd_load_curl_integration(TSRMLS_C) ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "rr", &z_mh, &z_ch) == FAILURE) {
        dd_curl_multi_remove_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_mh_map_remove_ch(z_mh, z_ch TSRMLS_CC);

    dd_curl_multi_remove_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_setopt) {
    zval *zid, **zvalue;
    long option;

    if (!dd_load_curl_integration(TSRMLS_C) ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "rlZ", &zid, &option, &zvalue) ==
            FAILURE) {
        dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (DDTRACE_G(curl_back_up_headers) && Z_BVAL_P(return_value) && dd_const_curlopt_httpheader == option) {
        dd_ch_store_headers(zid, Z_ARRVAL_PP(zvalue) TSRMLS_CC);
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt_array) {
    zval *zid, *arr;

    if (!dd_load_curl_integration(TSRMLS_C) ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "ra", &zid, &arr) == FAILURE) {
        dd_curl_setopt_array_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_curl_setopt_array_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_BVAL_P(return_value)) {
        zval **value;
        if (zend_hash_index_find(Z_ARRVAL_P(arr), dd_const_curlopt_httpheader, (void **)&value) == SUCCESS) {
            dd_ch_store_headers(zid, Z_ARRVAL_PP(value) TSRMLS_CC);
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

static void dd_install_handler(dd_curl_handler handler TSRMLS_DC) {
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
        dd_const_curlopt_httpheader = Z_LVAL_P(tmp);
    }
    zval_dtor(tmp);
    efree(tmp);
    if (!res) {
        // If we are unable to fetch the CURLOPT_HTTPHEADER const, something is really wrong
        return;
    }

    // These are not 'sizeof() - 1' on PHP 5
    dd_curl_handler handlers[] = {
        {"curl_close", sizeof("curl_close"), &dd_curl_close_handler, ZEND_FN(ddtrace_curl_close)},
        {"curl_copy_handle", sizeof("curl_copy_handle"), &dd_curl_copy_handle_handler,
         ZEND_FN(ddtrace_curl_copy_handle)},
        {"curl_exec", sizeof("curl_exec"), &dd_curl_exec_handler, ZEND_FN(ddtrace_curl_exec)},
        {"curl_init", sizeof("curl_init"), &dd_curl_init_handler, ZEND_FN(ddtrace_curl_init)},
        {"curl_multi_add_handle", sizeof("curl_multi_add_handle"), &dd_curl_multi_add_handle_handler,
         ZEND_FN(ddtrace_curl_multi_add_handle)},
        {"curl_multi_close", sizeof("curl_multi_close"), &dd_curl_multi_close_handler,
         ZEND_FN(ddtrace_curl_multi_close)},
        {"curl_multi_exec", sizeof("curl_multi_exec"), &dd_curl_multi_exec_handler, ZEND_FN(ddtrace_curl_multi_exec)},
        {"curl_multi_init", sizeof("curl_multi_init"), &dd_curl_multi_init_handler, ZEND_FN(ddtrace_curl_multi_init)},
        {"curl_multi_remove_handle", sizeof("curl_multi_remove_handle"), &dd_curl_multi_remove_handle_handler,
         ZEND_FN(ddtrace_curl_multi_remove_handle)},
        {"curl_setopt", sizeof("curl_setopt"), &dd_curl_setopt_handler, ZEND_FN(ddtrace_curl_setopt)},
        {"curl_setopt_array", sizeof("curl_setopt_array"), &dd_curl_setopt_array_handler,
         ZEND_FN(ddtrace_curl_setopt_array)},
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        dd_install_handler(handlers[i] TSRMLS_CC);
    }

    dd_ext_curl_loaded = true;
}

/* We don't need to initialize the request globals on RINIT like we do
 * on PHP 7 & 8 because the GINIT function php_ddtrace_init_globals()
 * will memset everything to 0.
 */
// void ddtrace_curl_handlers_rinit(TSRMLS_D) {}

void ddtrace_curl_handlers_rshutdown(TSRMLS_D) {
    DDTRACE_G(le_curl) = 0;
    if (DDTRACE_G(curl_headers)) {
        zend_hash_destroy(DDTRACE_G(curl_headers));
        FREE_HASHTABLE(DDTRACE_G(curl_headers));
        DDTRACE_G(curl_headers) = NULL;
    }
    if (DDTRACE_G(curl_mh_ch_map)) {
        zend_hash_destroy(DDTRACE_G(curl_mh_ch_map));
        FREE_HASHTABLE(DDTRACE_G(curl_mh_ch_map));
        DDTRACE_G(curl_mh_ch_map) = NULL;
    }
}
