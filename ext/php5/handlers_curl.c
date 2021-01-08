#include <inttypes.h>
#include <php.h>
#include <stdbool.h>

#include <ext/standard/php_array.h>

#include "configuration.h"
#include "ddtrace.h"
#include "engine_api.h"
#include "handlers_internal.h"
#include "logging.h"
#include "random.h"

// True global - only modify during MINIT/MSHUTDOWN
bool dd_ext_curl_loaded = false;
long dd_const_curlopt_httpheader = 0;
/* In PHP < 5.6.16, a crash occurs from curl_multi_exec() when headers are
 * updated from a curl handle that was duplicated via curl_copy_handle().
 * Since distributed trace headers are injected via a call to curl_setopt()
 * just before the first call to curl_multi_exec(), this bug will always
 * cause a crash when using a copied handle with curl_multi_exec().
 *
 * @see https://bugs.php.net/bug.php?id=71523
 * @see https://github.com/php/php-src/commit/5fdfab7
 */
bool dd_enable_bug_71523_workaround = false;

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

static void dd_ht_dtor(void *pData) {
    HashTable *ht = *((HashTable **)pData);
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
    if (DDTRACE_G(curl_bug_71523_copied_ch)) {
        zend_hash_index_del(DDTRACE_G(curl_bug_71523_copied_ch), Z_RESVAL_P(ch));
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

static void dd_multi_update_cache(zval *mh, HashTable *handles TSRMLS_DC) {
    DDTRACE_G(curl_multi_handles_cache_id) = Z_RESVAL_P(mh);
    DDTRACE_G(curl_multi_handles_cache) = handles;
}

static void dd_multi_lazy_init_globals(TSRMLS_D) {
    if (!DDTRACE_G(curl_multi_handles)) {
        ALLOC_HASHTABLE(DDTRACE_G(curl_multi_handles));
        zend_hash_init(DDTRACE_G(curl_multi_handles), 8, NULL, (dtor_func_t)dd_ht_dtor, 0);
    }
}

static HashTable *dd_multi_get_handles(zval *mh TSRMLS_DC) {
    HashTable **tmp = NULL;
    if (zend_hash_index_find(DDTRACE_G(curl_multi_handles), Z_RESVAL_P(mh), (void **)&tmp) == SUCCESS) {
        return *tmp;
    }
    return NULL;
}

/* Find or create the multi-handle map for this multi-handle and save the curl handle resource.
 * We need to keep a reference to the curl handle in order to inject the distributed tracing
 * headers on the first call to curl_multi_exec().
 */
static void dd_multi_add_handle(zval *mh, zval *ch TSRMLS_DC) {
    HashTable *handles = NULL;

    if (UNEXPECTED(!DDTRACE_G(curl_multi_handles))) {
        return;
    }

    handles = dd_multi_get_handles(mh TSRMLS_CC);

    if (!handles) {
        ALLOC_HASHTABLE(handles);
        zend_hash_init(handles, 8, NULL, ZVAL_PTR_DTOR, 0);
        zend_hash_index_update(DDTRACE_G(curl_multi_handles), Z_RESVAL_P(mh), &handles, sizeof(HashTable *), NULL);
    }

    zval *tmp;
    MAKE_STD_ZVAL(tmp);
    ZVAL_COPY_VALUE(tmp, ch);
    zval_copy_ctor(tmp);
    zend_hash_index_update(handles, Z_RESVAL_P(ch), &tmp, sizeof(zval *), NULL);

    dd_multi_update_cache(mh, handles TSRMLS_CC);
}

/* Remove a curl handle from the multi-handle map when curl_multi_remove_handle() is called.
 */
static void dd_multi_remove_handle(zval *mh, zval *ch TSRMLS_DC) {
    HashTable *handles = NULL;

    if (DDTRACE_G(curl_multi_handles)) {
        handles = dd_multi_get_handles(mh TSRMLS_CC);
        dd_multi_update_cache(mh, handles TSRMLS_CC);
        if (handles) {
            zend_hash_index_del(handles, Z_RESVAL_P(ch));
        }
    }
}

/* Remove the map of curl handles from a multi-handle map. This resets the multi-handle map
 * when either 1) curl_multi_init() / curl_multi_close() is called or 2) the distributed
 * tracing headers have been injected for all of the curl handles associated with this
 * multi-handle.
 */
static void dd_multi_reset(zval *mh TSRMLS_DC) {
    if (DDTRACE_G(curl_multi_handles)) {
        zend_hash_index_del(DDTRACE_G(curl_multi_handles), Z_RESVAL_P(mh));
        dd_multi_update_cache(mh, NULL TSRMLS_CC);
    }
}

static void dd_bug_71523_add_copied_ch(zval *ch1, zval *ch2 TSRMLS_DC) {
    if (!DDTRACE_G(curl_bug_71523_copied_ch)) {
        ALLOC_HASHTABLE(DDTRACE_G(curl_bug_71523_copied_ch));
        zend_hash_init(DDTRACE_G(curl_bug_71523_copied_ch), 8, NULL, NULL, 0);
    }

    void *tmp = (void *)1;
    zend_hash_index_update(DDTRACE_G(curl_bug_71523_copied_ch), Z_RESVAL_P(ch1), &tmp, sizeof(void *), NULL);
    zend_hash_index_update(DDTRACE_G(curl_bug_71523_copied_ch), Z_RESVAL_P(ch2), &tmp, sizeof(void *), NULL);
}

static bool dd_bug_71523_should_inject_from_multi_exec(zval *ch TSRMLS_DC) {
    return !zend_hash_index_exists(DDTRACE_G(curl_bug_71523_copied_ch), Z_RESVAL_P(ch));
}

static void dd_multi_inject_headers(zval *mh TSRMLS_DC) {
    HashTable *handles = NULL;

    if (DDTRACE_G(curl_multi_handles_cache_id) == Z_RESVAL_P(mh)) {
        handles = DDTRACE_G(curl_multi_handles_cache);
    } else if (DDTRACE_G(curl_multi_handles)) {
        handles = dd_multi_get_handles(mh TSRMLS_CC);
        dd_multi_update_cache(mh, handles TSRMLS_CC);
    }

    if (handles && zend_hash_num_elements(handles) > 0) {
        zval **ch;
        HashPosition pos;
        for (zend_hash_internal_pointer_reset_ex(handles, &pos);
             zend_hash_get_current_data_ex(handles, (void **)&ch, &pos) == SUCCESS;
             zend_hash_move_forward_ex(handles, &pos)) {
            if (DDTRACE_G(curl_bug_71523_copied_ch) && !dd_bug_71523_should_inject_from_multi_exec(*ch TSRMLS_CC)) {
                ddtrace_log_debugf(
                    "Could not inject distributed tracing headers for curl handle #%d because it was copied with "
                    "curl_copy_handle(). Upgrade to PHP 5.6.16 or greater to fix this issue. See "
                    "https://bugs.php.net/bug.php?id=71523 for more information.",
                    Z_RESVAL_P(*ch));
                continue;
            }
            dd_inject_distributed_tracing_headers(*ch TSRMLS_CC);
        }
        dd_multi_reset(mh TSRMLS_CC);
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

    dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration(TSRMLS_C) &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "r", &ch1) == SUCCESS &&
        Z_TYPE_P(return_value) == IS_RESOURCE) {
        dd_ch_duplicate_headers(ch1, return_value TSRMLS_CC);
        if (dd_enable_bug_71523_workaround) {
            // Both handles are affected by bug #71523
            dd_bug_71523_add_copied_ch(ch1, return_value TSRMLS_CC);
        }
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

    if (dd_load_curl_integration(TSRMLS_C) &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "rr", &z_mh, &z_ch) == SUCCESS) {
        dd_multi_add_handle(z_mh, z_ch TSRMLS_CC);
    }

    dd_curl_multi_add_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_close) {
    zval *z_mh;

    if (dd_load_curl_integration(TSRMLS_C) &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "r", &z_mh) == SUCCESS) {
        dd_multi_reset(z_mh TSRMLS_CC);
    }

    dd_curl_multi_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_exec) {
    zval *z_mh;
    zval *z_still_running;

    if (dd_load_curl_integration(TSRMLS_C) && ddtrace_peek_span_id(TSRMLS_C) != 0 &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "rz", &z_mh, &z_still_running) ==
            SUCCESS) {
        dd_multi_inject_headers(z_mh TSRMLS_CC);
    }

    dd_curl_multi_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_init) {
    dd_curl_multi_init_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration(TSRMLS_C) && ZEND_NUM_ARGS() == 0 && Z_TYPE_P(return_value) == IS_RESOURCE) {
        dd_multi_lazy_init_globals(TSRMLS_C);
        // Reset this multi-handle map in the event the resource ID is reused
        dd_multi_reset(return_value TSRMLS_CC);
    }
}

ZEND_FUNCTION(ddtrace_curl_multi_remove_handle) {
    zval *z_mh;
    zval *z_ch;

    if (dd_load_curl_integration(TSRMLS_C) &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "rr", &z_mh, &z_ch) == SUCCESS) {
        dd_multi_remove_handle(z_mh, z_ch TSRMLS_CC);
    }

    dd_curl_multi_remove_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_setopt) {
    zval *zid, **zvalue;
    long option;

    dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration(TSRMLS_C) &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "rlZ", &zid, &option, &zvalue) ==
            SUCCESS &&
        DDTRACE_G(curl_back_up_headers) && Z_BVAL_P(return_value) && dd_const_curlopt_httpheader == option) {
        dd_ch_store_headers(zid, Z_ARRVAL_PP(zvalue) TSRMLS_CC);
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt_array) {
    zval *zid, *arr;

    dd_curl_setopt_array_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration(TSRMLS_C) &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "ra", &zid, &arr) == SUCCESS &&
        Z_BVAL_P(return_value)) {
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

    dd_enable_bug_71523_workaround = (PHP_VERSION_ID < 50616);

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
    if (DDTRACE_G(curl_multi_handles)) {
        zend_hash_destroy(DDTRACE_G(curl_multi_handles));
        FREE_HASHTABLE(DDTRACE_G(curl_multi_handles));
        DDTRACE_G(curl_multi_handles) = NULL;
    }
    if (DDTRACE_G(curl_bug_71523_copied_ch)) {
        zend_hash_destroy(DDTRACE_G(curl_bug_71523_copied_ch));
        FREE_HASHTABLE(DDTRACE_G(curl_bug_71523_copied_ch));
        DDTRACE_G(curl_bug_71523_copied_ch) = NULL;
    }
}
