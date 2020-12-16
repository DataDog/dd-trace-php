#include <php.h>
#include <stdbool.h>

#include <ext/curl/php_curl.h>

#include "configuration.h"
#include "engine_api.h"
#include "engine_hooks.h"  // for ddtrace_backup_error_handling
#include "handlers_internal.h"
#include "logging.h"
#include "span.h"

// True global - only modify during MINIT/MSHUTDOWN
bool dd_ext_curl_loaded = false;
zend_long dd_const_curlopt_httpheader = 0;

ZEND_TLS HashTable *dd_headers = NULL;
ZEND_TLS bool dd_should_save_headers = true;
ZEND_TLS zend_function *dd_curl_inject_fn_proxy = NULL;

// Multi-handle API: curl_multi_*()
struct dd_mh_span {
    zend_long mh_id;
    bool finished;
    ddtrace_span_t span;
};
typedef struct dd_mh_span dd_mh_span;

ZEND_TLS HashTable *dd_mh_spans = NULL;
ZEND_TLS dd_mh_span *dd_mh_span_cache = NULL;

static void (*dd_curl_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_copy_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_init_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_setopt_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_setopt_array_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

static bool dd_load_curl_integration(void) {
    if (!dd_ext_curl_loaded || DDTRACE_G(disable_in_current_request)) {
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

    zend_hash_index_update_ptr(dd_headers, Z_OBJ_HANDLE_P(ch), new_headers);
}

static void dd_ch_delete_headers(zval *ch) {
    if (dd_headers) {
        zend_hash_index_del(dd_headers, Z_OBJ_HANDLE_P(ch));
    }
}

static void dd_ch_duplicate_headers(zval *ch_orig, zval *ch_new) {
    if (dd_headers) {
        HashTable *headers = zend_hash_index_find_ptr(dd_headers, Z_OBJ_HANDLE_P(ch_orig));
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
        headers = zend_hash_index_find_ptr(dd_headers, Z_OBJ_HANDLE_P(ch));
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

static void dd_mh_span_dtor(void *mh_span) {
    dd_mh_span *mh_s = *((dd_mh_span **)mh_span);
    ddtrace_spandata_free(&mh_s->span);
    efree(mh_s);
}

static void dd_mh_store_span(zval *mh, dd_mh_span *mh_span) {
    if (!dd_mh_spans) {
        ALLOC_HASHTABLE(dd_mh_spans);
        zend_hash_init(dd_mh_spans, 8, NULL, (dtor_func_t)dd_mh_span_dtor, 0);
    }
    zend_hash_index_update_ptr(dd_mh_spans, Z_OBJ_HANDLE_P(mh), mh_span);
    dd_mh_span_cache = mh_span;
}

static dd_mh_span *dd_mh_fetch_span(zval *mh) {
    if (dd_mh_span_cache != NULL && dd_mh_span_cache->mh_id == Z_OBJ_HANDLE_P(mh)) {
        return dd_mh_span_cache;
    }
    if (dd_mh_spans) {
        dd_mh_span *mh_span = zend_hash_index_find_ptr(dd_mh_spans, Z_OBJ_HANDLE_P(mh));
        if (mh_span) {
            dd_mh_span_cache = mh_span;
        }
        return mh_span;
    }
    return NULL;
}

ZEND_FUNCTION(ddtrace_curl_close) {
    zval *ch;

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "O", &ch, curl_ce) == SUCCESS) {
        dd_ch_delete_headers(ch);
    }

    dd_curl_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_copy_handle) {
    zval *ch;

    if (!dd_load_curl_integration() ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "O", &ch, curl_ce) == FAILURE) {
        dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_OBJECT) {
        dd_ch_duplicate_headers(ch, return_value);
    }
}

ZEND_FUNCTION(ddtrace_curl_exec) {
    zval *ch;

    if (dd_load_curl_integration() && ddtrace_peek_span_id() != 0 &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "O", &ch, curl_ce) == SUCCESS) {
        zend_string *inject_func = zend_string_init(ZEND_STRL("ddtrace\\bridge\\curl_inject_distributed_headers"), 0);
        if (zend_hash_exists(EG(function_table), inject_func)) {
            zend_function **fn_proxy = &dd_curl_inject_fn_proxy;
            zval retval = {.u1.type_info = IS_UNDEF};

            zval headers;
            dd_init_headers_arg(&headers, ch);

            ddtrace_sandbox_backup backup = ddtrace_sandbox_begin();
            dd_should_save_headers = false;  // Don't save our own HTTP headers
            // Arg 0: CurlHandle $ch
            // Arg 1: mixed $value (array of headers)
            if (ddtrace_call_function(fn_proxy, ZSTR_VAL(inject_func), ZSTR_LEN(inject_func), &retval, 2, ch,
                                      &headers) == SUCCESS) {
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

    dd_curl_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_init) {
    dd_curl_init_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration() && Z_TYPE_P(return_value) == IS_OBJECT) {
        dd_ch_delete_headers(return_value);
    }
}

ZEND_FUNCTION(ddtrace_curl_multi_exec) {
    zval *z_mh;
    zval *z_still_running;
    int still_running;
    dd_mh_span *mh_span = NULL;
    bool pop_span_id = false;

    if (!dd_load_curl_integration() ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Oz", &z_mh, curl_multi_ce, &z_still_running) == FAILURE) {
        dd_curl_multi_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }
    
    /* curl_multi_exec() cannot be a root-level span because its span is tracked outside of the regular span stack to prevent inadvertent children spans. This also makes it incompatible with auto flushing. */
    if (ddtrace_peek_span_id() == 0) {
        ddtrace_log_debug("curl_multi_exec() cannot be a root-level span");
        dd_curl_multi_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    mh_span = dd_mh_fetch_span(z_mh);
    if (mh_span == NULL) {
        /* We start a short-lived span that will move to the closed-span stack right after this call. We keep track of this span on the multi-handle and stop the span timer as soon as the curl_multi_exec() call finishes. This ensures that the only children spans are the individual curl handle calls that are associated with this multi-handle. */
        mh_span = ecalloc(1, sizeof(dd_mh_span));
        mh_span->finished = false;
        ddtrace_span_start(&mh_span->span);

        // TODO Manually set SpanData::$name = 'curl_multi_exec'

        dd_mh_store_span(z_mh, mh_span);
        pop_span_id = true;
    }

    // TODO if a new ch has been added to the mh, push the span ID before injecting
    // TODO inject DT headers for all curl handles for this mh

    dd_curl_multi_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (pop_span_id) {
        /* Since the multi-handle spans are not tracked on the main span stack, we only want to have the span_id available in userland from dd_trace_peek_span_id() for when we inject the distributed-tracing headers on the first call to curl_multi_exec(). */
        ddtrace_pop_span_id();
    }

    // Re-parse the params to see if we are still running
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Oz", &z_mh, curl_multi_ce, &z_still_running) == SUCCESS) {
        still_running = zval_get_long(z_still_running);
        // TODO Validate "done" logic
        if (still_running > 0 && mh_span != NULL && !mh_span->finished) {
            ddtrace_span_stop(&mh_span->span);
            mh_span->finished = true;

            // TODO We need something akin to ddtrace_push_closed_span() to add this finished span to the closed span stack

            // TODO Add rich metadata
        }
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt) {
    zval *ch, *zvalue;
    zend_long option;

    if (!dd_load_curl_integration() || zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Olz", &ch,
                                                                curl_ce, &option, &zvalue) == FAILURE) {
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
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Oa", &ch, curl_ce, &arr) == FAILURE) {
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

void ddtrace_curl_handlers_startup(void) {
    // if we cannot find ext/curl then do not instrument it
    zend_string *curl = zend_string_init(ZEND_STRL("curl"), 0);
    dd_ext_curl_loaded = zend_hash_exists(&module_registry, curl);
    zend_string_release(curl);
    if (!dd_ext_curl_loaded) {
        return;
    }

    zend_string *const_name = zend_string_init(ZEND_STRL("CURLOPT_HTTPHEADER"), 0);
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
        {ZEND_STRL("curl_multi_exec"), &dd_curl_multi_exec_handler, ZEND_FN(ddtrace_curl_multi_exec)},
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
    dd_headers = NULL;
    dd_should_save_headers = true;
    dd_curl_inject_fn_proxy = NULL;

    dd_mh_spans = NULL;
    dd_mh_span_cache = NULL;
}

void ddtrace_curl_handlers_rshutdown(void) {
    if (dd_headers) {
        zend_hash_destroy(dd_headers);
        FREE_HASHTABLE(dd_headers);
        dd_headers = NULL;
    }
    dd_curl_inject_fn_proxy = NULL;

    if (dd_mh_spans) {
        zend_hash_destroy(dd_mh_spans);
        FREE_HASHTABLE(dd_mh_spans);
        dd_mh_spans = NULL;
    }
    dd_mh_span_cache = NULL;
}
