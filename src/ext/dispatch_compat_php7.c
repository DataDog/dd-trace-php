#include "php.h"
#if PHP_VERSION_ID >= 70000

#include <Zend/zend_exceptions.h>

#include <ext/spl/spl_exceptions.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "dispatch_compat.h"
#include "env_config.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

void ddtrace_setup_fcall(zend_execute_data *execute_data, zend_fcall_info *fci, zval **result) {
    fci->param_count = ZEND_CALL_NUM_ARGS(execute_data);
    fci->params = fci->param_count ? ZEND_CALL_ARG(execute_data, 1) : NULL;
    fci->retval = *result;
}

zend_function *ddtrace_function_get(const HashTable *table, zval *name) {
    if (Z_TYPE_P(name) != IS_STRING) {
        return NULL;
    }

    zend_string *to_free = NULL, *key = Z_STR_P(name);
    if (!ddtrace_is_all_lower(key)) {
        key = zend_string_tolower(key);
        to_free = key;
    }

    zend_function *ptr = zend_hash_find_ptr(table, key);

    if (to_free) {
        zend_string_release(to_free);
    }
    return ptr;
}

void ddtrace_dispatch_free_owned_data(ddtrace_dispatch_t *dispatch) {
    zval_ptr_dtor(&dispatch->function_name);
    zval_ptr_dtor(&dispatch->callable);
}

void ddtrace_class_lookup_release_compat(zval *zv) {
    DD_PRINTF("freeing %p", (void *)zv);
    ddtrace_dispatch_t *dispatch = Z_PTR_P(zv);
    ddtrace_class_lookup_release(dispatch);
}

HashTable *ddtrace_new_class_lookup(zval *class_name) {
    HashTable *class_lookup;

    ALLOC_HASHTABLE(class_lookup);
    zend_hash_init(class_lookup, 8, NULL, ddtrace_class_lookup_release_compat, 0);
    zend_hash_update_ptr(DDTRACE_G(class_lookup), Z_STR_P(class_name), class_lookup);

    return class_lookup;
}

zend_bool ddtrace_dispatch_store(HashTable *lookup, ddtrace_dispatch_t *dispatch_orig) {
#if PHP_VERSION_ID >= 70300
    ddtrace_dispatch_t *dispatch = pemalloc(sizeof(ddtrace_dispatch_t), lookup->u.flags & IS_ARRAY_PERSISTENT);
#else
    ddtrace_dispatch_t *dispatch = pemalloc(sizeof(ddtrace_dispatch_t), lookup->u.flags & HASH_FLAG_PERSISTENT);
#endif

    memcpy(dispatch, dispatch_orig, sizeof(ddtrace_dispatch_t));
    ddtrace_class_lookup_acquire(dispatch);
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
    fci.no_separation = 1;

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

BOOL_T ddtrace_should_trace_call(zend_execute_data *execute_data, zend_function **fbc, ddtrace_dispatch_t **dispatch) {
    if (DDTRACE_G(disable) || DDTRACE_G(disable_in_current_request) || DDTRACE_G(class_lookup) == NULL ||
        DDTRACE_G(function_lookup) == NULL) {
        return FALSE;
    }
    *fbc = EX(call)->func;
    if (!*fbc) {
        return FALSE;
    }

    zval fname;
    if ((*fbc)->common.function_name) {
        ZVAL_STR_COPY(&fname, (*fbc)->common.function_name);
    } else {
        return FALSE;
    }

    // Don't trace closures
    if ((*fbc)->common.fn_flags & ZEND_ACC_CLOSURE) {
        zval_ptr_dtor(&fname);
        return FALSE;
    }

    zval *this = ddtrace_this(execute_data);
    *dispatch = ddtrace_find_dispatch(this, *fbc, &fname);
    zval_ptr_dtor(&fname);
    if (!*dispatch || (*dispatch)->busy) {
        return FALSE;
    }

    return TRUE;
}

int ddtrace_forward_call(zend_execute_data *execute_data, zend_function *fbc, zval *return_value, zend_fcall_info *fci,
                         zend_fcall_info_cache *fcc) {
    int fcall_status;

#if PHP_VERSION_ID < 70300
    fcc->initialized = 1;
#endif
    fcc->function_handler = fbc;
    fcc->object = Z_TYPE(EX(This)) == IS_OBJECT ? Z_OBJ(EX(This)) : NULL;
    fcc->calling_scope = fbc->common.scope;  // EG(scope);
    fcc->called_scope = zend_get_called_scope(execute_data);

    fci->size = sizeof(zend_fcall_info);
    fci->no_separation = 1;
    fci->object = fcc->object;

    ddtrace_setup_fcall(execute_data, fci, &return_value);

    fcall_status = zend_call_function(fci, fcc);
    if (fcall_status == SUCCESS && Z_TYPE_P(return_value) != IS_UNDEF) {
#if PHP_VERSION_ID >= 70100
        if (Z_ISREF_P(return_value)) {
            zend_unwrap_reference(return_value);
        }
#endif
    }

    // We don't want to clear the args with zend_fcall_info_args_clear() yet
    // since our tracing closure might need them
    return fcall_status;
}

void ddtrace_copy_function_args(zend_execute_data *execute_data, zval *user_args) {
    zend_execute_data *ex = EX(call);
    uint32_t i;
    zval *p, *q;
    uint32_t arg_count = ZEND_CALL_NUM_ARGS(ex);

    // @see https://github.com/php/php-src/blob/PHP-7.0/Zend/zend_builtin_functions.c#L506-L562
    array_init_size(user_args, arg_count);
    if (arg_count) {
        zend_hash_real_init(Z_ARRVAL_P(user_args), 1);
        ZEND_HASH_FILL_PACKED(Z_ARRVAL_P(user_args)) {
            i = 0;
            p = ZEND_CALL_ARG(ex, 1);
            while (i < arg_count) {
                q = p;
                if (EXPECTED(Z_TYPE_INFO_P(q) != IS_UNDEF)) {
                    ZVAL_DEREF(q);
                    if (Z_OPT_REFCOUNTED_P(q)) {
                        Z_ADDREF_P(q);
                    }
                } else {
                    q = &EG(uninitialized_zval);
                }
                ZEND_HASH_FILL_ADD(q);
                p++;
                i++;
            }
        }
        ZEND_HASH_FILL_END();
        Z_ARRVAL_P(user_args)->nNumOfElements = arg_count;
    }
}

BOOL_T ddtrace_execute_tracing_closure(zval *callable, zval *span_data, zend_execute_data *execute_data,
                                       zval *user_args, zval *user_retval, zend_object *exception) {
    BOOL_T status = TRUE;
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    zval rv;
    INIT_ZVAL(rv);
    zval args[4];
    zval exception_arg = {.value = {0}};
    ZVAL_UNDEF(&exception_arg);
    if (exception) {
        ZVAL_OBJ(&exception_arg, exception);
    }
    zval *this = ddtrace_this(execute_data);

    if (zend_fcall_info_init(callable, 0, &fci, &fcc, NULL, NULL) == FAILURE) {
        ddtrace_log_debug("Could not init tracing closure");
        return FALSE;
    }

    if (this) {
        BOOL_T is_instance_method = (EX(call)->func->common.fn_flags & ZEND_ACC_STATIC) ? FALSE : TRUE;
        BOOL_T is_closure_static = (fcc.function_handler->common.fn_flags & ZEND_ACC_STATIC) ? TRUE : FALSE;
        if (is_instance_method && is_closure_static) {
            ddtrace_log_debug("Cannot trace non-static method with static tracing closure");
            return FALSE;
        }
    }

    // Arg 0: DDTrace\SpanData $span
    ZVAL_COPY(&args[0], span_data);

    // Arg 1: array $args
    ZVAL_COPY(&args[1], user_args);

    // Arg 2: mixed $retval
    ZVAL_COPY(&args[2], user_retval);

    // Arg 3: Exception|null $exception
    ZVAL_COPY(&args[3], &exception_arg);

    fci.param_count = 4;
    fci.params = args;
    fci.retval = &rv;

#if PHP_VERSION_ID < 70300
    fcc.initialized = 1;
#endif
    fcc.object = this ? Z_OBJ_P(this) : NULL;
    fcc.called_scope = zend_get_called_scope(EX(call));
    // Give the tracing closure access to private & protected class members
    fcc.function_handler->common.scope = fcc.called_scope;

    if (zend_call_function(&fci, &fcc) == FAILURE) {
        ddtrace_log_debug("Could not execute tracing closure");
        status = FALSE;
    } else if (Z_TYPE(rv) == IS_FALSE) {
        status = FALSE;
    }

    zval_ptr_dtor(&rv);
    zend_fcall_info_args_clear(&fci, 0);
    return status;
}

void ddtrace_span_attach_exception(ddtrace_span_t *span, zend_object *exception) {
    if (exception) {
        GC_ADDREF(exception);
        span->exception = exception;
    }
}
#endif  // PHP_VERSION_ID >= 70000
