#include <php.h>
#include <stdbool.h>

#include "configuration.h"
#include "engine_api.h"
#include "engine_hooks.h"  // for ddtrace_backup_error_handling
#include "handlers_internal.h"
#include "logging.h"
#include "priority_sampling/priority_sampling.h"
#include "span.h"

// True global - only modify during MINIT/MSHUTDOWN
bool dd_ext_curl_loaded = false;
zend_long dd_const_curlopt_httpheader = 0;

/* "le_curl" is ext/curl's resource type.
 * "le_curl" is what php_curl.h names this variable
 */
ZEND_TLS int le_curl = 0;

ZEND_TLS HashTable *dd_headers = NULL;

// Multi-handle API: curl_multi_*()
ZEND_TLS HashTable *dd_multi_handles = NULL;
ZEND_TLS HashTable *dd_multi_handles_cache = NULL;
ZEND_TLS zend_long dd_multi_handles_cache_id = 0;

static zend_class_entry dd_curl_wrap_handler_ce;
static zend_object_handlers dd_curl_wrap_handler_handlers;

static void (*dd_curl_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_copy_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_init_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_add_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_init_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_multi_remove_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_setopt_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_curl_setopt_array_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

static bool dd_load_curl_integration(void) {
    if (!dd_ext_curl_loaded || !get_DD_TRACE_ENABLED()) {
        return false;
    }
    return get_DD_DISTRIBUTED_TRACING();
}

/* We need to track the curl resource liveliness.
 * The only real way to do so, without relying on the platform ABI, is adding some zval whose destructor gets called on
 * close. We are limited in our choices what zval to replace: a) the zval must be replaceable at a location reachable
 * through offsets which are constant on all builds (i.e. every value after "passwd" in php_curl struct is unusable due
 * to the variable structure offset of all values after it (it depending on CURLOPT_PASSWDFUNCTION)), b) the contents
 * of the zval must not be accessed or manipulated in non-generic ways. This leaves us with write, write_header and read
 * functions. We opt for read, given that its default implementation is the simplest one (which we necessarily have to
 * re-implement ourselves).
 * Theoretically it would have sufficed to track the liveliness of the multi handles, but these do not provide any way
 * (e.g. the classical zval whose dtor is invoked) to hook into their destruction. Hence we overcome this by tracking
 * all easy handles, and conclude, that, when they are freed, the multi handles attached must also have been freed.
 */
struct dd_curl_read_stub {
    zval func_name;
    zend_fcall_info_cache fci_cache;
    FILE *fp;
};

struct dd_curl_handlers_stub {
    void *write_handler;
    void *write_headers_handler;
    struct dd_curl_read_stub *read_handler;
};

struct dd_curl_stub {
    void *curl_ptr;
    struct dd_curl_handlers_stub *handlers;
};

#define CURL_READ(zv) (((struct dd_curl_stub *)Z_RES_P(zv)->ptr)->handlers->read_handler)

struct dd_curl_wrapper {
    zend_object std;
    int res_handle;
    HashTable multi;
};

static void dd_ht_dtor(void *pData) {
    HashTable *ht = *((HashTable **)pData);
    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}

static void dd_ch_store_headers(zval *ch, HashTable *headers) {
    if (!dd_headers) {
        ALLOC_HASHTABLE(dd_headers);
        zend_hash_init(dd_headers, 8, NULL, (dtor_func_t)dd_ht_dtor, 0);
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

static int dd_inject_distributed_tracing_headers(zval *ch) {
    zval headers;
    zend_array *dd_header_array;
    if (dd_headers && (dd_header_array = zend_hash_index_find_ptr(dd_headers, Z_RES_HANDLE_P(ch)))) {
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
#if PHP_VERSION_ID < 70400
    zend_execute_data *call = zend_vm_stack_push_call_frame(ZEND_CALL_TOP_FUNCTION, setopt_fn, 3, NULL, NULL);
#else
    zend_execute_data *call = zend_vm_stack_push_call_frame(ZEND_CALL_TOP_FUNCTION, setopt_fn, 3, NULL);
#endif
    ZVAL_COPY(ZEND_CALL_ARG(call, 1), ch);
    ZVAL_LONG(ZEND_CALL_ARG(call, 2), dd_const_curlopt_httpheader);
    ZVAL_COPY_VALUE(ZEND_CALL_ARG(call, 3), &headers);

    zend_execute_data *ex = EG(current_execute_data);
    EG(current_execute_data) = call;
    zval ret;
    dd_curl_setopt_handler(call, &ret);
    EG(current_execute_data) = ex;

    zend_vm_stack_free_args(call);
    zend_vm_stack_free_call_frame(call);

    return ZEND_HASH_APPLY_REMOVE;
}

static bool dd_is_valid_curl_resource(zval *ch) {
    if (le_curl) {
        void *resource = zend_fetch_resource(Z_RES_P(ch), NULL, le_curl);
        return resource != NULL;
    } else {
        return false;
    }
}

static void dd_multi_update_cache(zval *mh, HashTable *handles) {
    dd_multi_handles_cache_id = Z_RES_HANDLE_P(mh);
    dd_multi_handles_cache = handles;
}

static void dd_multi_lazy_init_globals(void) {
    if (!dd_multi_handles) {
        ALLOC_HASHTABLE(dd_multi_handles);
        zend_hash_init(dd_multi_handles, 8, NULL, (dtor_func_t)dd_ht_dtor, 0);
    }
}

/* Find or create the multi-handle map for this multi-handle and save the curl handle resource.
 * We need to keep a reference to the curl handle in order to inject the distributed tracing
 * headers on the first call to curl_multi_exec().
 */
static void dd_multi_add_handle(zval *mh, zval *ch) {
    if (UNEXPECTED(!dd_multi_handles)) {
        return;
    }

    HashTable *handles = zend_hash_index_find_ptr(dd_multi_handles, Z_RES_HANDLE_P(mh));

    if (!handles) {
        ALLOC_HASHTABLE(handles);
        zend_hash_init(handles, 8, NULL, ZVAL_PTR_DTOR, 0);
        zend_hash_index_update_ptr(dd_multi_handles, Z_RES_HANDLE_P(mh), handles);
    }

    zval tmp;
    ZVAL_COPY(&tmp, ch);
    zend_hash_index_update(handles, Z_RES_HANDLE_P(ch), &tmp);

    dd_multi_update_cache(mh, handles);

    zval *readfunc = &CURL_READ(ch)->func_name;
    if (readfunc && Z_TYPE_P(readfunc) == IS_OBJECT && Z_OBJCE_P(readfunc) == &dd_curl_wrap_handler_ce) {
        struct dd_curl_wrapper *wrapper = (struct dd_curl_wrapper *)Z_OBJ_P(readfunc);
        zend_hash_index_add_empty_element(&wrapper->multi, Z_RES_HANDLE_P(mh));
    }
}

/* Remove a curl handle from the multi-handle map when curl_multi_remove_handle() is called.
 */
static void dd_multi_remove_handle(zval *mh, zval *ch) {
    if (dd_multi_handles) {
        HashTable *handles = zend_hash_index_find_ptr(dd_multi_handles, Z_RES_HANDLE_P(mh));
        if (handles) {
            zend_hash_index_del(handles, Z_RES_HANDLE_P(ch));
            if (zend_hash_num_elements(handles)) {
                dd_multi_update_cache(mh, handles);
            } else {
                zend_hash_index_del(dd_multi_handles, Z_RES_HANDLE_P(mh));
                dd_multi_update_cache(mh, NULL);
            }
        }
    }
    zval *readfunc = &CURL_READ(ch)->func_name;
    if (readfunc && Z_TYPE_P(readfunc) == IS_OBJECT && Z_OBJCE_P(readfunc) == &dd_curl_wrap_handler_ce) {
        struct dd_curl_wrapper *wrapper = (struct dd_curl_wrapper *)Z_OBJ_P(readfunc);
        zend_hash_index_del(&wrapper->multi, Z_RES_HANDLE_P(mh));
    }
}

/* Remove the map of curl handles from a multi-handle map. This resets the multi-handle map
 * when either 1) curl_multi_init() / curl_multi_close() is called or 2) the distributed
 * tracing headers have been injected for all of the curl handles associated with this
 * multi-handle.
 */
static void dd_multi_reset(zval *mh) {
    if (dd_multi_handles) {
        HashTable *handles = zend_hash_index_find_ptr(dd_multi_handles, Z_RES_HANDLE_P(mh));
        if (handles) {
            zval *easy_res;
            ZEND_HASH_FOREACH_VAL(handles, easy_res) {
                if (dd_is_valid_curl_resource(easy_res)) {
                    zval *readfunc = &CURL_READ(easy_res)->func_name;
                    if (readfunc && Z_TYPE_P(readfunc) == IS_OBJECT &&
                        Z_OBJCE_P(readfunc) == &dd_curl_wrap_handler_ce) {
                        struct dd_curl_wrapper *wrapper = (struct dd_curl_wrapper *)Z_OBJ_P(readfunc);
                        zend_hash_index_del(&wrapper->multi, Z_RES_HANDLE_P(mh));
                    }
                }
            }
            ZEND_HASH_FOREACH_END();
            zend_hash_index_del(dd_multi_handles, Z_RES_HANDLE_P(mh));
        }
        dd_multi_update_cache(mh, NULL);
    }
}

static void dd_multi_inject_headers(zval *mh) {
    HashTable *handles = NULL;

    if (dd_multi_handles_cache_id == Z_RES_HANDLE_P(mh)) {
        handles = dd_multi_handles_cache;
    } else if (dd_multi_handles) {
        handles = zend_hash_index_find_ptr(dd_multi_handles, Z_RES_HANDLE_P(mh));
        dd_multi_update_cache(mh, handles);
    }

    if (handles && zend_hash_num_elements(handles) > 0) {
        zend_hash_apply(handles, dd_inject_distributed_tracing_headers);
        dd_multi_reset(mh);
    }
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

    dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &ch) == SUCCESS &&
        Z_TYPE_P(return_value) == IS_RESOURCE) {
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
            zval *read_wrapper = &CURL_READ(return_value)->func_name, new_wrapper;
            object_init_ex(&new_wrapper, &dd_curl_wrap_handler_ce);
            /* handle the case of some other extension already pre-populating the value */
            ZVAL_COPY_VALUE(OBJ_PROP_NUM(Z_OBJ(new_wrapper), 0), read_wrapper);
            ZVAL_COPY_VALUE(read_wrapper, &new_wrapper);
            ((struct dd_curl_wrapper *)Z_OBJ_P(read_wrapper))->res_handle = Z_RES_HANDLE_P(return_value);
        }
    }
}

ZEND_FUNCTION(ddtrace_curl_multi_add_handle) {
    zval *z_mh;
    zval *z_ch;

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "rr", &z_mh, &z_ch) == SUCCESS) {
        dd_multi_add_handle(z_mh, z_ch);
    }

    dd_curl_multi_add_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_close) {
    zval *z_mh;

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &z_mh) == SUCCESS) {
        dd_multi_reset(z_mh);
    }

    dd_curl_multi_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_exec) {
    zval *z_mh;
    zval *z_still_running;

    if (dd_load_curl_integration() && ddtrace_peek_span_id() != 0 &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "rz", &z_mh, &z_still_running) == SUCCESS) {
        dd_multi_inject_headers(z_mh);
    }

    dd_curl_multi_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_multi_init) {
    dd_curl_multi_init_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (dd_load_curl_integration() && ZEND_NUM_ARGS() == 0 && Z_TYPE_P(return_value) == IS_RESOURCE) {
        dd_multi_lazy_init_globals();
        // Reset this multi-handle map in the event the resource ID is reused
        dd_multi_reset(return_value);
    }
}

ZEND_FUNCTION(ddtrace_curl_multi_remove_handle) {
    zval *z_mh;
    zval *z_ch;

    if (dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "rr", &z_mh, &z_ch) == SUCCESS) {
        dd_multi_remove_handle(z_mh, z_ch);
    }

    dd_curl_multi_remove_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

static void dd_wrap_setopt(zval *ch, void (*orig_setopt)(INTERNAL_FUNCTION_PARAMETERS), INTERNAL_FUNCTION_PARAMETERS) {
    zend_object *read_wrapper = NULL;
    uint32_t orig_refcount;

    if (ch && dd_is_valid_curl_resource(ch)) {
        zval *readfunc = &CURL_READ(ch)->func_name;
        if (readfunc && Z_TYPE_P(readfunc) == IS_OBJECT && Z_OBJCE_P(readfunc) == &dd_curl_wrap_handler_ce) {
            read_wrapper = Z_OBJ_P(readfunc);
            /* Addref to prevent triggering dtor in curl setopt logic */
            orig_refcount = GC_ADDREF(read_wrapper);
        }
    }

    orig_setopt(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (read_wrapper != NULL) {
        /* Now that we've backed up the original refcount, we can check whether it was changed during the function call.
         * If it was freed (rc differing), then we assume that something replaced our wrapper object by a new handler.
         * In that case we release the old handler and replace it by the new handler, then we put our wrapper back.
         * Otherwise we just restore the refcount.
         */
        if (GC_REFCOUNT(read_wrapper) == orig_refcount) {
            GC_DELREF(read_wrapper);
        } else {
            zval *handler = OBJ_PROP_NUM(read_wrapper, 0);
            zval_ptr_dtor(handler);
            ZVAL_COPY_VALUE(handler, &CURL_READ(ch)->func_name);
            ZVAL_OBJ(&CURL_READ(ch)->func_name, read_wrapper);
        }
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt) {
    zval *ch, *zvalue;
    zend_long option;
    bool load_integration =
        dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "rlz", &ch, &option, &zvalue) == SUCCESS;

    dd_wrap_setopt(load_integration ? ch : NULL, dd_curl_setopt_handler, INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (load_integration && Z_TYPE_P(return_value) == IS_TRUE && dd_const_curlopt_httpheader == option &&
        Z_TYPE_P(zvalue) == IS_ARRAY) {
        dd_ch_store_headers(ch, Z_ARRVAL_P(zvalue));
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt_array) {
    zval *ch, *arr;
    bool load_integration =
        dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "ra", &ch, &arr) == SUCCESS;

    dd_wrap_setopt(load_integration ? ch : NULL, dd_curl_setopt_array_handler, INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (load_integration
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
            dd_ch_store_headers(ch, Z_ARRVAL_P(value));
        }
    }
}

static zend_internal_function dd_default_curl_read_function;

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_default_curl_read, 0, 0, 3)
ZEND_ARG_INFO(0, ch)
ZEND_ARG_INFO(0, fp)
ZEND_ARG_INFO(0, size)
ZEND_END_ARG_INFO()

static PHP_FUNCTION(dd_default_curl_read) {
    zval *ch, *fpzv;
    zend_long size;

    ZEND_PARSE_PARAMETERS_START(3, 3)
    Z_PARAM_RESOURCE(ch)
    Z_PARAM_ZVAL(fpzv)
    Z_PARAM_LONG(size)
    ZEND_PARSE_PARAMETERS_END();

    FILE *fp = CURL_READ(ch)->fp;
    if (fp) {
        /* emulate logic of curl_read() function */
        zend_string *ret = zend_string_alloc(size, 0);
        ret = zend_string_truncate(ret, fread(ZSTR_VAL(ret), size, 1, fp), 0);
        ZSTR_VAL(ret)[ZSTR_LEN(ret)] = 0;
        RETURN_STR(ret);
    }
    ZVAL_UNDEF(return_value);
}

static int dd_curl_wrap_get_closure(zval *obj, zend_class_entry **ce_ptr, zend_function **fptr_ptr,
                                    zend_object **obj_ptr) {
    zval *handler = OBJ_PROP_NUM(Z_OBJ_P(obj), 0);
    if (Z_TYPE_P(handler) > IS_NULL) {
        zend_fcall_info_cache fcc;
        zend_is_callable_ex(handler, NULL, 0, NULL, &fcc, NULL);

        *fptr_ptr = fcc.function_handler;
        *ce_ptr = fcc.called_scope;
        *obj_ptr = fcc.object;
    } else {
        *fptr_ptr = (zend_function *)&dd_default_curl_read_function;
        *ce_ptr = NULL;
        *obj_ptr = NULL;
    }
    return SUCCESS;
}

static zend_object *dd_curl_wrap_ctor_obj(zend_class_entry *ce) {
    struct dd_curl_wrapper *wrapper = emalloc(sizeof(*wrapper));
    zend_object_std_init(&wrapper->std, ce);
    object_properties_init(&wrapper->std, ce);
    wrapper->std.handlers = &dd_curl_wrap_handler_handlers;
    zend_hash_init(&wrapper->multi, 8, NULL, ZVAL_PTR_DTOR, 0);
    return &wrapper->std;
}

static void dd_curl_wrap_dtor_obj(zend_object *obj) {
    zend_objects_destroy_object(obj);

    struct dd_curl_wrapper *wrapper = (struct dd_curl_wrapper *)obj;
    if (dd_multi_handles) {
        zend_ulong multi_res;
        ZEND_HASH_FOREACH_NUM_KEY(&wrapper->multi, multi_res) {
            HashTable *handles = zend_hash_index_find_ptr(dd_multi_handles, multi_res);
            if (handles) {
                zend_hash_index_del(handles, wrapper->res_handle);
                if (zend_hash_num_elements(handles) == 0) {
                    zend_hash_index_del(handles, wrapper->res_handle);
                }
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    if (dd_headers) {
        zend_hash_index_del(dd_headers, wrapper->res_handle);
    }

    zend_hash_destroy(&wrapper->multi);
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
    dd_default_curl_read_function = (zend_internal_function){
        .type = ZEND_INTERNAL_FUNCTION,
        .function_name = zend_new_interned_string(zend_string_init(ZEND_STRL("dd_default_curl_read"), 1)),
        .num_args = 3,
        .required_num_args = 3,
        .arg_info = (zend_internal_arg_info *)(arginfo_dd_default_curl_read + 1),
        .handler = &PHP_FN(dd_default_curl_read),
    };

    INIT_NS_CLASS_ENTRY(dd_curl_wrap_handler_ce, "DDTrace", "CurlHandleWrapper", NULL);
    dd_curl_wrap_handler_ce.type = ZEND_INTERNAL_CLASS;
    dd_curl_wrap_handler_ce.create_object = dd_curl_wrap_ctor_obj;
    zend_initialize_class_data(&dd_curl_wrap_handler_ce, false);
    dd_curl_wrap_handler_ce.info.internal.module = &ddtrace_module_entry;
    zend_declare_property_null(&dd_curl_wrap_handler_ce, "handler", sizeof("handler") - 1, ZEND_ACC_PUBLIC);
    memcpy(&dd_curl_wrap_handler_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    dd_curl_wrap_handler_handlers.get_closure = dd_curl_wrap_get_closure;
    dd_curl_wrap_handler_handlers.dtor_obj = dd_curl_wrap_dtor_obj;

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
    dd_zif_handler handlers[] = {
        {ZEND_STRL("curl_close"), &dd_curl_close_handler, ZEND_FN(ddtrace_curl_close)},
        {ZEND_STRL("curl_copy_handle"), &dd_curl_copy_handle_handler, ZEND_FN(ddtrace_curl_copy_handle)},
        {ZEND_STRL("curl_exec"), &dd_curl_exec_handler, ZEND_FN(ddtrace_curl_exec)},
        {ZEND_STRL("curl_init"), &dd_curl_init_handler, ZEND_FN(ddtrace_curl_init)},
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

void ddtrace_curl_handlers_shutdown(void) { ddtrace_free_unregistered_class(&dd_curl_wrap_handler_ce); }

void ddtrace_curl_handlers_rinit(void) {
    le_curl = 0;
    dd_headers = NULL;

    dd_multi_handles = NULL;
    dd_multi_handles_cache = NULL;
    dd_multi_handles_cache_id = 0;
}

void ddtrace_curl_handlers_rshutdown(void) {
    le_curl = 0;
    if (dd_headers) {
        zend_hash_destroy(dd_headers);
        FREE_HASHTABLE(dd_headers);
        dd_headers = NULL;
    }

    if (dd_multi_handles) {
        zend_hash_destroy(dd_multi_handles);
        FREE_HASHTABLE(dd_multi_handles);
        dd_multi_handles = NULL;
    }
    dd_multi_handles_cache = NULL;
    dd_multi_handles_cache_id = 0;
}
