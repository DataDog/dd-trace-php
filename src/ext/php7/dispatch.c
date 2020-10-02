#include "dispatch.h"

#include <Zend/zend_exceptions.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "arrays.h"
#include "compatibility.h"
#include "ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

void ddtrace_dispatch_dtor(ddtrace_dispatch_t *dispatch) {
    zval_ptr_dtor(&dispatch->function_name);
    zval_ptr_dtor(&dispatch->callable);
}

void ddtrace_class_lookup_release_compat(zval *zv) {
    ddtrace_dispatch_t *dispatch = Z_PTR_P(zv);
    ddtrace_dispatch_release(dispatch);
}

HashTable *ddtrace_new_class_lookup(zval *class_name) {
    HashTable *class_lookup;

    ALLOC_HASHTABLE(class_lookup);
    zend_hash_init(class_lookup, 8, NULL, ddtrace_class_lookup_release_compat, 0);
    zend_hash_update_ptr(DDTRACE_G(class_lookup), Z_STR_P(class_name), class_lookup);

    return class_lookup;
}

#if PHP_VERSION_ID >= 70300
#define DDTRACE_IS_ARRAY_PERSISTENT IS_ARRAY_PERSISTENT
#else
#define DDTRACE_IS_ARRAY_PERSISTENT HASH_FLAG_PERSISTENT
#endif

zend_bool ddtrace_dispatch_store(HashTable *lookup, ddtrace_dispatch_t *dispatch_orig) {
    ddtrace_dispatch_t *dispatch = pemalloc(sizeof(ddtrace_dispatch_t), lookup->u.flags & DDTRACE_IS_ARRAY_PERSISTENT);

    memcpy(dispatch, dispatch_orig, sizeof(ddtrace_dispatch_t));
    ddtrace_dispatch_copy(dispatch);
    return zend_hash_update_ptr(lookup, Z_STR(dispatch->function_name), dispatch) != NULL;
}

void ddtrace_wrapper_forward_call_from_userland(zend_execute_data *execute_data, zval *return_value) {
    zval fname, retval;
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    if (!DDTRACE_G(original_context).execute_data || !EX(prev_execute_data)) {
        zend_throw_exception_ex(spl_ce_LogicException, 0,
                                "Cannot use dd_trace_forward_call() outside of a tracing closure");
        return;
    }

    // Jump out of any include files
    zend_execute_data *prev_ex = EX(prev_execute_data);
    while (!prev_ex->func->common.function_name) {
        prev_ex = prev_ex->prev_execute_data;
    }
    zend_string *callback_name = !prev_ex ? NULL : prev_ex->func->common.function_name;

    if (!callback_name || !zend_string_equals_literal(callback_name, DDTRACE_CALLBACK_NAME)) {
        zend_throw_exception_ex(spl_ce_LogicException, 0,
                                "Cannot use dd_trace_forward_call() outside of a tracing closure");
        return;
    }

    ZVAL_STR_COPY(&fname, DDTRACE_G(original_context).execute_data->func->common.function_name);

    fci.size = sizeof(fci);
    fci.function_name = fname;
    fci.retval = &retval;
    fci.param_count = ZEND_CALL_NUM_ARGS(DDTRACE_G(original_context).execute_data);
    fci.params = ZEND_CALL_ARG(DDTRACE_G(original_context).execute_data, 1);
    fci.object = DDTRACE_G(original_context).this;
#if PHP_VERSION_ID < 80000
    fci.no_separation = 1;
#endif

#if PHP_VERSION_ID < 70300
    fcc.initialized = 1;
#endif
    fcc.function_handler = DDTRACE_G(original_context).execute_data->func;
    fcc.calling_scope = DDTRACE_G(original_context).calling_ce;
    fcc.called_scope = zend_get_called_scope(DDTRACE_G(original_context).execute_data);
    fcc.object = fci.object;

    if (zend_call_function(&fci, &fcc) == SUCCESS && Z_TYPE(retval) != IS_UNDEF) {
#if PHP_VERSION_ID >= 70100
        if (Z_ISREF(retval)) {
            zend_unwrap_reference(&retval);
        }
#endif
        ZVAL_COPY_VALUE(return_value, &retval);
    }

    zval_ptr_dtor(&fname);
}
