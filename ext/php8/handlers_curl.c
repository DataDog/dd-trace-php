#include <php.h>
#include <pthread.h>
#include <stdbool.h>

/* Comment to prevent reordering by code style fixer */
#include <Zend/zend_weakrefs.h>

__attribute__((weak)) zend_class_entry *curl_ce = NULL;
__attribute__((weak)) zend_class_entry *curl_multi_ce = NULL;

#include "configuration.h"
#include "engine_hooks.h"  // for ddtrace_backup_error_handling
#include "handlers_internal.h"
#include "logging.h"
#include "priority_sampling/priority_sampling.h"
#include "span.h"

// True global - only modify during MINIT/MSHUTDOWN
bool dd_ext_curl_loaded = false;
zend_long dd_const_curlopt_httpheader = 0;

ZEND_TLS HashTable dd_headers;

// Multi-handle API: curl_multi_*()
ZEND_TLS HashTable dd_multi_handles;

static void (*dd_curl_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_copy_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_add_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_init_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_remove_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_setopt_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_setopt_array_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static HashTable *(*dd_curl_multi_get_gc)(zend_object *object, zval **table, int *n) = NULL;

static bool dd_load_curl_integration(void) {
    if (!dd_ext_curl_loaded || !get_DD_TRACE_ENABLED()) {
        return false;
    }
    return get_DD_DISTRIBUTED_TRACING();
}

static void dd_ht_dtor(void *pData) {
    HashTable *ht = *((HashTable **)pData);
    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}

static void dd_ch_store_headers(zend_object *ch, HashTable *headers) {
    HashTable *new_headers;
    ALLOC_HASHTABLE(new_headers);
    zend_hash_init(new_headers, zend_hash_num_elements(headers), NULL, ZVAL_PTR_DTOR, 0);
    zend_hash_copy(new_headers, headers, (copy_ctor_func_t)zval_add_ref);

    if (!zend_weakrefs_hash_add_ptr(&dd_headers, ch, new_headers)) {
        zend_hash_index_update_ptr(&dd_headers, (zend_ulong)(uintptr_t)ch, new_headers);
    }
}

static void dd_ch_delete_headers(zend_object *ch) { zend_weakrefs_hash_del(&dd_headers, ch); }

static void dd_ch_duplicate_headers(zend_object *ch_orig, zend_object *ch_new) {
    HashTable *headers = zend_hash_index_find_ptr(&dd_headers, (zend_ulong)(uintptr_t)ch_orig);
    if (headers) {
        dd_ch_store_headers(ch_new, headers);
    }
}

static void dd_inject_distributed_tracing_headers(zend_object *ch) {
    zval headers;
    zend_array *dd_header_array;
    if ((dd_header_array = zend_hash_index_find_ptr(&dd_headers, (zend_ulong)(uintptr_t)ch))) {
        ZVAL_ARR(&headers, zend_array_dup(dd_header_array));
    } else {
        array_init(&headers);
    }

    zend_long sampling_priority = ddtrace_fetch_prioritySampling_from_root();
    if (sampling_priority != DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
        add_next_index_str(&headers,
                           zend_strpprintf(0, "x-datadog-sampling-priority: " ZEND_LONG_FMT, sampling_priority));
    }
    if (DDTRACE_G(trace_id)) {
        add_next_index_str(&headers, zend_strpprintf(0, "x-datadog-trace-id: %" PRIu64, (DDTRACE_G(trace_id))));
        if (DDTRACE_G(span_ids_top)) {
            add_next_index_str(&headers,
                               zend_strpprintf(0, "x-datadog-parent-id: %" PRIu64, (DDTRACE_G(span_ids_top)->id)));
        }
    } else if (DDTRACE_G(span_ids_top)) {
        ddtrace_log_err("Found span_id without active trace id, skipping sending of x-datadog-parent-id");
    }
    if (DDTRACE_G(dd_origin)) {
        add_next_index_str(&headers, zend_strpprintf(0, "x-datadog-origin: %s", ZSTR_VAL(DDTRACE_G(dd_origin))));
    }

    zend_function *setopt_fn = zend_hash_str_find_ptr(EG(function_table), ZEND_STRL("curl_setopt"));

    // avoiding going through our own function, directly calling curl_setopt
    zend_execute_data *call = zend_vm_stack_push_call_frame(ZEND_CALL_TOP_FUNCTION, setopt_fn, 3, NULL);
    ZVAL_OBJ_COPY(ZEND_CALL_ARG(call, 1), ch);
    ZVAL_LONG(ZEND_CALL_ARG(call, 2), dd_const_curlopt_httpheader);
    ZVAL_COPY_VALUE(ZEND_CALL_ARG(call, 3), &headers);

    zval ret;
    dd_curl_setopt_handler(call, &ret);

    zend_vm_stack_free_args(call);
    zend_vm_stack_free_call_frame(call);
}

/* Find or create the multi-handle map for this multi-handle and save the curl handle object.
 * We need to keep a reference to the curl handle in order to inject the distributed tracing
 * headers on the first call to curl_multi_exec().
 */
static void dd_multi_add_handle(zend_object *mh, zend_object *ch) {
    HashTable *handles = zend_hash_index_find_ptr(&dd_multi_handles, (zend_ulong)(uintptr_t)mh);
    if (!handles) {
        ALLOC_HASHTABLE(handles);
        zend_hash_init(handles, 8, NULL, ZVAL_PTR_DTOR, 0);
        zend_weakrefs_hash_add_ptr(&dd_multi_handles, mh, handles);
    }

    zval tmp;
    ZVAL_OBJ_COPY(&tmp, ch);
    zend_hash_index_update(handles, (zend_ulong)ch->handle, &tmp);
}

/* Remove a curl handle from the multi-handle map when curl_multi_remove_handle() is called.
 */
static void dd_multi_remove_handle(zend_object *mh, zend_object *ch) {
    HashTable *handles = zend_hash_index_find_ptr(&dd_multi_handles, (zend_ulong)(uintptr_t)mh);
    if (handles) {
        zend_hash_index_del(handles, (zend_ulong)ch->handle);
    }
}

static void dd_multi_inject_headers(zend_object *mh) {
    HashTable *handles = zend_hash_index_find_ptr(&dd_multi_handles, (zend_ulong)(uintptr_t)mh);

    if (handles && zend_hash_num_elements(handles) > 0) {
        zend_object *ch;
        ZEND_HASH_FOREACH_PTR(handles, ch) { dd_inject_distributed_tracing_headers(ch); }
        ZEND_HASH_FOREACH_END();

        zend_weakrefs_hash_del(&dd_multi_handles, mh);
    }
}

static HashTable *ddtrace_curl_multi_get_gc(zend_object *object, zval **table, int *n) {
    HashTable *ret = dd_curl_multi_get_gc(object, table, n);

    HashTable *handles = zend_hash_index_find_ptr(&dd_multi_handles, (zend_ulong)(uintptr_t)object);
    if (handles) {
        zend_object *ch;
        ZEND_HASH_FOREACH_PTR(handles, ch) { zend_get_gc_buffer_add_obj(&EG(get_gc_buffer), ch); }
        ZEND_HASH_FOREACH_END();
    }

    return ret;
}

static pthread_once_t dd_replace_curl_get_gc_once = PTHREAD_ONCE_INIT;
static zend_object_handlers *dd_curl_object_handlers;
static void dd_replace_curl_get_gc(void) {
    dd_curl_multi_get_gc = dd_curl_object_handlers->get_gc;
    dd_curl_object_handlers->get_gc = ddtrace_curl_multi_get_gc;
}

ZEND_FUNCTION(ddtrace_curl_close) {
    zval *ch;

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "O", &ch, curl_ce) == SUCCESS) {
        dd_ch_delete_headers(Z_OBJ_P(ch));
    }

    dd_curl_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_copy_handle) {
    zval *ch;

    dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "O", &ch, curl_ce) == SUCCESS &&
        Z_TYPE_P(return_value) == IS_OBJECT) {
        dd_ch_duplicate_headers(Z_OBJ_P(ch), Z_OBJ_P(return_value));
    }
}

ZEND_FUNCTION(ddtrace_curl_exec) {
    zval *ch;

    if (dd_load_curl_integration() && ddtrace_peek_span_id() != 0 &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "O", &ch, curl_ce) == SUCCESS) {
        dd_inject_distributed_tracing_headers(Z_OBJ_P(ch));
    }

    dd_curl_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_add_handle) {
    zval *z_mh;
    zval *z_ch;

    if (dd_load_curl_integration() && zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "OO", &z_mh,
                                                               curl_multi_ce, &z_ch, curl_ce) == SUCCESS) {
        dd_multi_add_handle(Z_OBJ_P(z_mh), Z_OBJ_P(z_ch));
    }

    dd_curl_multi_add_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_close) {
    zval *z_mh;

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "O", &z_mh, curl_multi_ce) == SUCCESS) {
        zend_weakrefs_hash_del(&dd_multi_handles, Z_OBJ_P(z_mh));
    }

    dd_curl_multi_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_exec) {
    zval *z_mh;
    zval *z_still_running;

    if (dd_load_curl_integration() && ddtrace_peek_span_id() != 0 &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Oz", &z_mh, curl_multi_ce,
                                 &z_still_running) == SUCCESS) {
        dd_multi_inject_headers(Z_OBJ_P(z_mh));
    }

    dd_curl_multi_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_init) {
    dd_curl_multi_init_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration() && Z_TYPE_P(return_value) == IS_OBJECT) {
        // workaround as we have no access to the curl object_handlers, them being declared as static
        dd_curl_object_handlers = (zend_object_handlers *)Z_OBJ_P(return_value)->handlers;
        pthread_once(&dd_replace_curl_get_gc_once, dd_replace_curl_get_gc);
    }
}

ZEND_FUNCTION(ddtrace_curl_multi_remove_handle) {
    zval *z_mh;
    zval *z_ch;

    if (dd_load_curl_integration() && zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "OO", &z_mh,
                                                               curl_multi_ce, &z_ch, curl_ce) == SUCCESS) {
        dd_multi_remove_handle(Z_OBJ_P(z_mh), Z_OBJ_P(z_ch));
    }

    dd_curl_multi_remove_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_setopt) {
    zval *ch, *zvalue;
    zend_long option;

    dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Olz", &ch, curl_ce, &option, &zvalue) ==
            SUCCESS &&
        Z_TYPE_P(return_value) == IS_TRUE && dd_const_curlopt_httpheader == option && Z_TYPE_P(zvalue) == IS_ARRAY) {
        dd_ch_store_headers(Z_OBJ_P(ch), Z_ARRVAL_P(zvalue));
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt_array) {
    zval *ch, *arr;

    dd_curl_setopt_array_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "Oa", &ch, curl_ce, &arr) == SUCCESS
        /* We still want to apply the original headers even if the this call
         * returns false. The call will (mostly) only ever fail for reasons
         * unrelated to setting CURLOPT_HTTPHEADER (see comment below).
         */
        /* && Z_TYPE_P(return_value) == IS_TRUE */) {
        zval *value = zend_hash_index_find(Z_ARRVAL_P(arr), dd_const_curlopt_httpheader);
        if (value && Z_TYPE_P(value) == IS_ARRAY) {
            /* Although curl_setopt_array() can return false, it is unlikely to
             * be related to setting CURLOPT_HTTPHEADER. On the PHP side, the
             * values in the header array are converted to string before passing
             * to libcurl.
             * @see https://github.com/php/php-src/blob/b63ea10/ext/curl/interface.c#L2684-L2704
             *
             * On the libcurl side, curl_slist_append will only fail when malloc
             * or strdup fails.
             * @see https://github.com/curl/curl/blob/ac0a88f/lib/slist.c#L82-L102
             *
             * Additionally curl_easy_setopt is unlikely to fail in this case
             * also, since it is simply updating the pointer to the slist.
             * @see https://github.com/curl/curl/blob/4d2f800/lib/setopt.c#L672-L677
             *
             * The only other reasons curl_easy_setopt can fail appear to be API
             * related.
             * @see https://github.com/curl/curl/blob/4d2f800/lib/setopt.c#L2917-L2940
             *
             * For these reasons we do not validate the headers before storing
             * them.
             */
            dd_ch_store_headers(Z_OBJ_P(ch), Z_ARRVAL_P(value));
        }
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
    dd_ext_curl_loaded = zend_hash_str_exists(&module_registry, ZEND_STRL("curl"));
    if (!dd_ext_curl_loaded) {
        return;
    }

    /* If curl is loaded as a shared library we need to fetch the addresses of
     * the class entry symbols and account or any name mangling.
     */
    zend_module_entry *curl_me = NULL;
    if (curl_ce == NULL || curl_multi_ce == NULL) {
        curl_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("curl"));
    }

    if (curl_me != NULL && curl_me->handle) {
        zend_class_entry **curl_ce_ptr = (zend_class_entry **)DL_FETCH_SYMBOL(curl_me->handle, "curl_ce");
        if (curl_ce_ptr == NULL) {
            curl_ce_ptr = (zend_class_entry **)DL_FETCH_SYMBOL(curl_me->handle, "_curl_ce");
        }

        zend_class_entry **curl_multi_ce_ptr = (zend_class_entry **)DL_FETCH_SYMBOL(curl_me->handle, "curl_multi_ce");
        if (curl_multi_ce_ptr == NULL) {
            curl_multi_ce_ptr = (zend_class_entry **)DL_FETCH_SYMBOL(curl_me->handle, "_curl_multi_ce");
        }

        if (curl_ce_ptr != NULL && curl_multi_ce_ptr != NULL) {
            curl_ce = *curl_ce_ptr;
            curl_multi_ce = *curl_multi_ce_ptr;
        } else {
            ddtrace_log_debug("Unable to load ext/curl symbols");
            dd_ext_curl_loaded = false;
            return;
        }
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
    dd_zif_handler handlers[] = {
        {ZEND_STRL("curl_close"), &dd_curl_close_handler, ZEND_FN(ddtrace_curl_close)},
        {ZEND_STRL("curl_copy_handle"), &dd_curl_copy_handle_handler, ZEND_FN(ddtrace_curl_copy_handle)},
        {ZEND_STRL("curl_exec"), &dd_curl_exec_handler, ZEND_FN(ddtrace_curl_exec)},
        {ZEND_STRL("curl_multi_add_handle"), &dd_curl_multi_add_handle_handler, ZEND_FN(ddtrace_curl_multi_add_handle)},
        {ZEND_STRL("curl_multi_close"), &dd_curl_multi_close_handler, ZEND_FN(ddtrace_curl_multi_close)},
        {ZEND_STRL("curl_multi_exec"), &dd_curl_multi_exec_handler, ZEND_FN(ddtrace_curl_multi_exec)},
        {ZEND_STRL("curl_multi_init"), &dd_curl_multi_init_handler, ZEND_FN(ddtrace_curl_multi_init)},
        {ZEND_STRL("curl_multi_remove_handle"), &dd_curl_multi_remove_handle_handler,
         ZEND_FN(ddtrace_curl_multi_remove_handle)},
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
    zend_hash_init(&dd_headers, 8, NULL, (dtor_func_t)dd_ht_dtor, 0);
    zend_hash_init(&dd_multi_handles, 8, NULL, (dtor_func_t)dd_ht_dtor, 0);
}

static void dd_curl_destroy_weakref_ht(HashTable *ht) {
    zend_ulong key;
    ZEND_HASH_FOREACH_NUM_KEY(ht, key) { zend_weakrefs_hash_del(ht, (zend_object *)(uintptr_t)key); }
    ZEND_HASH_FOREACH_END();
    zend_hash_destroy(ht);
    // now ensure there's no use-after-free (zend_hash_init here is fine as memory allocation is deferred to first add)
    zend_hash_init(ht, 8, NULL, (dtor_func_t)dd_ht_dtor, 0);
}

void ddtrace_curl_handlers_rshutdown(void) {
    dd_curl_destroy_weakref_ht(&dd_headers);
    dd_curl_destroy_weakref_ht(&dd_multi_handles);
}
