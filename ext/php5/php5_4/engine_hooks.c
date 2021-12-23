#include "ext/php5/engine_hooks.h"

#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <php.h>

#include "ext/php5/compatibility.h"
#include "ext/php5/ddtrace.h"
#include "ext/php5/dispatch.h"
#include "ext/php5/logging.h"
#include "ext/php5/span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define DDTRACE_NOT_TRACED ((void *)1)
int ddtrace_resource = -1;

static zend_class_entry *dd_get_called_scope(zend_function *fbc TSRMLS_DC) {
    /* For internal functions the globals can only be trusted if it's a method.
     * This is why we look at the function being called at all.
     */
    if (fbc->common.scope) {
        zval *This = EG(This);
        return (This && Z_TYPE_P(This) == IS_OBJECT) ? Z_OBJCE_P(This) : EG(called_scope);
    } else {
        return NULL;
    }
}

static ddtrace_dispatch_t *dd_lookup_dispatch_from_fbc(zend_function *fbc TSRMLS_DC) {
    if (!PG(modules_activated)) {
        return NULL;
    }

    if (!get_DD_TRACE_ENABLED() || !DDTRACE_G(class_lookup) || !DDTRACE_G(function_lookup) || !fbc) {
        return NULL;
    }

    // Don't trace closures or functions without names
    if ((fbc->common.fn_flags & ZEND_ACC_CLOSURE) || !fbc->common.function_name) {
        return NULL;
    }

    zval fname_zv, *fname = &fname_zv;
    ZVAL_STRING(fname, fbc->common.function_name, 0);

    zend_class_entry *scope = dd_get_called_scope(fbc TSRMLS_CC);
    return ddtrace_find_dispatch(scope, fname TSRMLS_CC);
}

static bool dd_should_trace_dispatch(ddtrace_dispatch_t *dispatch TSRMLS_DC) {
    if (dispatch->busy) {
        return false;
    }

    if (dispatch->options & (DDTRACE_DISPATCH_NON_TRACING)) {
        // non-tracing types should trigger regardless of limited tracing mode
        return true;
    }

    if (ddtrace_tracer_is_limited(TSRMLS_C) && (dispatch->options & DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED) == 0) {
        return false;
    }
    return true;
}

// args should be a valid but uninitialized zval.
static int dd_copy_function_args(zval *args TSRMLS_DC) {
    void **p = zend_vm_stack_top(TSRMLS_C) - 1;
    int arg_count = (int)(zend_uintptr_t)*p;

    array_init_size(args, arg_count);
    return zend_copy_parameters_array(arg_count, args TSRMLS_CC);
}

static zval *dd_exception_get_entry(zval *object, char *name, int name_len TSRMLS_DC) {
    zend_class_entry *exception_ce = zend_exception_get_default(TSRMLS_C);
    return zend_read_property(exception_ce, object, name, name_len, 1 TSRMLS_CC);
}

static void dd_try_fetch_executing_function_name(zend_function *fbc, const char **scope, const char **colon,
                                                 const char **name) {
    *scope = "";
    *colon = "";
    *name = "(unknown function)";
    if (fbc->common.function_name) {
        *name = fbc->common.function_name;
        if (fbc->common.scope) {
            *scope = fbc->common.scope->name;
            *colon = "::";
        }
    }
}

static int dd_sandbox_fci_call(zend_function *fbc, zend_fcall_info *fci, zend_fcall_info_cache *fcc TSRMLS_DC, int argc,
                               ...) {
    int ret;
    va_list argv;

    va_start(argv, argc);
    ret = zend_fcall_info_argv(fci TSRMLS_CC, argc, &argv);
    va_end(argv);

    // The only way we mess this up is by passing in argc < 0
    if (ret != SUCCESS) {
        return FAILURE;
    }

    ddtrace_sandbox_backup backup = ddtrace_sandbox_begin(EG(opline_before_exception) TSRMLS_CC);
    ret = zend_call_function(fci, fcc TSRMLS_CC);

    if (UNEXPECTED(ret != SUCCESS)) {
        ddtrace_log_debug("Could not execute ddtrace's closure");
    }

    if (get_DD_TRACE_DEBUG()) {
        const char *scope, *colon, *name;
        dd_try_fetch_executing_function_name(fbc, &scope, &colon, &name);

        if (PG(last_error_message) && backup.eh.message != PG(last_error_message)) {
            ddtrace_log_errf("Error raised in ddtrace's closure for %s%s%s(): %s in %s on line %d", scope, colon, name,
                             PG(last_error_message), PG(last_error_file), PG(last_error_lineno));
        }

        // If the tracing closure threw an exception, ignore it to not impact the original call
        if (EG(exception)) {
            zval *ex = EG(exception), *message = NULL;
            const char *type = Z_OBJCE_P(ex)->name;
            message = dd_exception_get_entry(ex, ZEND_STRL("message") TSRMLS_CC);
            const char *msg = message && Z_TYPE_P(message) == IS_STRING ? Z_STRVAL_P(message)
                                                                        : "(internal error reading exception message)";
            ddtrace_log_errf("%s thrown in ddtrace's closure for %s%s%s(): %s", type, scope, colon, name, msg);
        }
    }

    ddtrace_sandbox_end(&backup TSRMLS_CC);
    zend_fcall_info_args_clear(fci, 1);

    return ret;
}

typedef void (*dd_execute_hook)(zend_op_array *op_array TSRMLS_DC);

static void (*dd_prev_execute)(zend_op_array *op_array TSRMLS_DC);

void ddtrace_span_attach_exception(ddtrace_span_fci *span_fci, zval *exception) {
    if (!exception) {
        return;
    }

    zval **prop_exception = ddtrace_spandata_property_exception_write(&span_fci->span);
    if (*prop_exception != NULL && Z_TYPE_PP(prop_exception) != IS_NULL &&
        (Z_TYPE_PP(prop_exception) != IS_BOOL || Z_BVAL_PP(prop_exception) != 0)) {
        return;
    }

    *prop_exception = exception;
    SEPARATE_ARG_IF_REF(*prop_exception);
}

// It's impls all the way down
static bool dd_tracing_posthook_impl_impl(zend_function *fbc, ddtrace_span_fci *span_fci,
                                          zval *return_value TSRMLS_DC) {
    ddtrace_span_t *span = &span_fci->span;
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;

    dd_trace_stop_span_time(span);
    ddtrace_span_attach_exception(span_fci, EG(exception));

    if (UNEXPECTED(!return_value)) {
        if (get_DD_TRACE_DEBUG()) {
            const char *fname = Z_STRVAL(dispatch->function_name);
            ddtrace_log_errf("Tracing closure could not be run for %s() because it is in an invalid state", fname);
        }
        return false;
    }

    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    if (UNEXPECTED(zend_fcall_info_init(&dispatch->posthook, 0, &fci, &fcc, NULL, NULL TSRMLS_CC) != SUCCESS)) {
        return false;
    }

    /* Note: In PHP 5 there is a bug where closures are automatically
     * marked as static if they are defined from a static method context.
     * @see https://3v4l.org/Rgo87
     */
    if (fbc->common.scope) {
        bool is_instance_method = (fbc->common.fn_flags & ZEND_ACC_STATIC) ? false : true;
        bool is_closure_static = (fcc.function_handler->common.fn_flags & ZEND_ACC_STATIC) ? true : false;
        if (UNEXPECTED(is_instance_method && is_closure_static)) {
            ddtrace_log_debug("Cannot trace non-static method with static tracing closure");
            return false;
        }
    }

    fcc.initialized = 1;

    // the retval of the tracing posthook tells us whether to keep the span: drop if IS_FALSE; keep otherwise
    zval *keep_span_zv = NULL;
    fci.retval_ptr_ptr = &keep_span_zv;

    zval *span_data;
    MAKE_STD_ZVAL(span_data);
    Z_TYPE_P(span_data) = IS_OBJECT;
    Z_OBJVAL_P(span_data) = span_fci->span.obj_value;
    zend_objects_store_add_ref(span_data TSRMLS_CC);

    zval *args;
    MAKE_STD_ZVAL(args);
    dd_copy_function_args(args TSRMLS_CC);

    zval *called_this = EG(uninitialized_zval_ptr), *called_scope = EG(uninitialized_zval_ptr);
    zend_class_entry *scope = dd_get_called_scope(fbc TSRMLS_CC);
    if (scope) {
        fcc.called_scope = scope;
        if (EG(This)) {
            MAKE_STD_ZVAL(called_this);
            ZVAL_ZVAL(called_this, EG(This), 1, 0);
            fcc.object_ptr = called_this;
        }

        // Give the closure access to private & protected class members
        fcc.function_handler->common.scope = fcc.called_scope;
    }

    zval *exception = EG(exception) ?: &EG(uninitialized_zval);

    dd_sandbox_fci_call(fbc, &fci, &fcc TSRMLS_CC, 4, &span_data, &args, &return_value, &exception);

    bool keep_span = true;
    if (fci.retval_ptr_ptr && keep_span_zv) {
        if (Z_TYPE_P(keep_span_zv) == IS_BOOL && !Z_LVAL_P(keep_span_zv)) {
            keep_span = false;
        }
        zval_ptr_dtor(&keep_span_zv);
    }

    if (called_this != EG(uninitialized_zval_ptr)) {
        zval_ptr_dtor(&called_this);
    }
    if (called_scope != EG(uninitialized_zval_ptr)) {
        zval_ptr_dtor(&called_scope);
    }
    zval_ptr_dtor(&args);
    zval_ptr_dtor(&span_data);

    return keep_span;
}

static void dd_set_fqn(ddtrace_span_fci *span_fci, zend_function *func TSRMLS_DC) {
    if (!func || !func->common.function_name) {
        return;
    }

    zval **prop_name = ddtrace_spandata_property_name_write(&span_fci->span);
    if (*prop_name) {
        zval_ptr_dtor(prop_name);
    }
    MAKE_STD_ZVAL(*prop_name);

    zend_class_entry *called_scope = dd_get_called_scope(func TSRMLS_CC);
    if (called_scope) {
        // This cannot be cached on the dispatch since sub classes can share the same parent dispatch
        char *fqn;
        size_t fqn_len = spprintf(&fqn, 0, "%s.%s", called_scope->name, func->common.function_name);
        ZVAL_STRINGL(*prop_name, fqn, fqn_len, 0);
    } else {
        ZVAL_STRING(*prop_name, func->common.function_name, 1);
    }
}

static void dd_tracing_posthook_impl(zend_function *fbc, ddtrace_span_fci *span_fci, zval *return_value TSRMLS_DC) {
    bool keep_span = dd_tracing_posthook_impl_impl(fbc, span_fci, return_value TSRMLS_CC);

    if (!ddtrace_has_top_internal_span(span_fci TSRMLS_CC)) {
        // This can happen if the tracer flushes while an internal span is active
        return;
    }

    ddtrace_close_userland_spans_until(
        span_fci TSRMLS_CC);  // because dropping / setting default properties happens on top span

    if (keep_span) {
        ddtrace_close_span(span_fci TSRMLS_CC);
    } else {
        ddtrace_drop_top_open_span(TSRMLS_C);
    }
}

static void dd_execute_tracing_posthook(zend_op_array *op_array TSRMLS_DC) {
    ddtrace_dispatch_t *dispatch = op_array->reserved[ddtrace_resource];
    zend_function *fbc = (zend_function *)op_array;

    bool free_retval = false;
    zval *retval = NULL;

    /* If the retval doesn't get used then sometimes the engine won't set
     * the retval_ptr_ptr at all. We expect it to always be present, so
     * adjust it. Be sure to dtor it later.
     */
    if (!EG(return_value_ptr_ptr)) {
        EG(return_value_ptr_ptr) = &retval;
        free_retval = true;
    }

    ddtrace_dispatch_copy(dispatch);

    ddtrace_span_fci *span_fci = ddtrace_init_span(TSRMLS_C);
    span_fci->dispatch = dispatch;
    ddtrace_open_span(span_fci TSRMLS_CC);
    zend_objects_store_add_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);

    // SpanData::$name defaults to fully qualified called name
    dd_set_fqn(span_fci, fbc TSRMLS_CC);

    dd_prev_execute(op_array TSRMLS_CC);

    /* Sometimes the retval goes away when there is an exception, and
     * sometimes it's there but points to nothing (even excluding our fixup
     * above), so check both.
     */
    zval *actual_retval =
        (EG(return_value_ptr_ptr) && *EG(return_value_ptr_ptr)) ? *EG(return_value_ptr_ptr) : &zval_used_for_init;

    if (ddtrace_has_top_internal_span(span_fci TSRMLS_CC)) {
        dd_tracing_posthook_impl(fbc, span_fci, actual_retval TSRMLS_CC);
    } else if (get_DD_TRACE_DEBUG() && !span_fci->span.duration) {
        // todo: update to Classname::Methodname format
        const char *fname = Z_STRVAL(dispatch->function_name);
        ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync", fname);
    }

    if (free_retval && *EG(return_value_ptr_ptr)) {
        zval_ptr_dtor(EG(return_value_ptr_ptr));
        EG(return_value_ptr_ptr) = NULL;
    }

    zend_objects_store_del_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);
}

// copy dispatch on outside!
static void dd_non_tracing_posthook_impl(zend_function *fbc, ddtrace_dispatch_t *dispatch,
                                         zval *return_value TSRMLS_DC) {
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    if (UNEXPECTED(zend_fcall_info_init(&dispatch->posthook, 0, &fci, &fcc, NULL, NULL TSRMLS_CC) != SUCCESS)) {
        return;
    }

    /* We only bind $this on PHP 5, because if we don't it will flag any
     * closures that get defined within the prehook call to be static, which
     * means they can't be bound during trace_method.
     * Phooey.
     */
    fcc.initialized = 1;

    zval *args;
    MAKE_STD_ZVAL(args);
    dd_copy_function_args(args TSRMLS_CC);

    // We don't do anything with the non-tracing posthook return value
    zval *unused_retval = NULL;
    fci.retval_ptr_ptr = &unused_retval;

    zval *called_this = EG(uninitialized_zval_ptr), *called_scope = EG(uninitialized_zval_ptr);
    zend_class_entry *scope = dd_get_called_scope(fbc TSRMLS_CC);
    if (scope) {
        fcc.called_scope = scope;
        if (EG(This)) {
            MAKE_STD_ZVAL(called_this);
            ZVAL_ZVAL(called_this, EG(This), 1, 0);
            fcc.object_ptr = called_this;
        }

        MAKE_STD_ZVAL(called_scope);
        ZVAL_STRINGL(called_scope, scope->name, scope->name_length, 1);

        dd_sandbox_fci_call(fbc, &fci, &fcc TSRMLS_CC, 4, &called_this, &called_scope, &args, &return_value);
    } else {
        dd_sandbox_fci_call(fbc, &fci, &fcc TSRMLS_CC, 2, &args, &return_value);
    }

    if (called_this != EG(uninitialized_zval_ptr)) {
        zval_ptr_dtor(&called_this);
    }
    if (called_scope != EG(uninitialized_zval_ptr)) {
        zval_ptr_dtor(&called_scope);
    }
    if (unused_retval) {
        zval_ptr_dtor(&unused_retval);
    }
    zval_ptr_dtor(&args);
}

static void dd_execute_non_tracing_posthook(zend_op_array *op_array TSRMLS_DC) {
    ddtrace_dispatch_t *dispatch = op_array->reserved[ddtrace_resource];

    zval *retval_dummy = NULL;
    bool free_retval = 0;
    if (!EG(return_value_ptr_ptr)) {
        EG(return_value_ptr_ptr) = &retval_dummy;
        free_retval = 1;
    }

    ddtrace_dispatch_copy(dispatch);
    dd_prev_execute(op_array TSRMLS_CC);

    /* Sometimes the retval goes away when there is an exception, and
     * sometimes it's there but points to nothing (even excluding our fixup
     * above), so check both.
     */
    zval *actual_retval =
        (EG(return_value_ptr_ptr) && *EG(return_value_ptr_ptr)) ? *EG(return_value_ptr_ptr) : &zval_used_for_init;

    dd_non_tracing_posthook_impl((zend_function *)op_array, dispatch, actual_retval TSRMLS_CC);

    ddtrace_dispatch_release(dispatch);

    if (free_retval && *EG(return_value_ptr_ptr)) {
        zval_ptr_dtor(EG(return_value_ptr_ptr));
        EG(return_value_ptr_ptr) = NULL;
    }
}

static void dd_execute_tracing_prehook(zend_op_array *op_array TSRMLS_DC) {
    // tracing prehook not yet supported on PHP 5
    dd_prev_execute(op_array TSRMLS_CC);
}

static void dd_non_tracing_prehook_impl(zend_function *fbc, ddtrace_dispatch_t *dispatch TSRMLS_DC) {
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    if (UNEXPECTED(zend_fcall_info_init(&dispatch->prehook, 0, &fci, &fcc, NULL, NULL TSRMLS_CC) != SUCCESS)) {
        return;
    }

    /* We only bind $this on PHP 5, because if we don't it will flag any
     * closures that get defined within the prehook call to be static, which
     * means they can't be bound during trace_method.
     * Phooey.
     */
    fcc.initialized = 1;

    zval *args;
    MAKE_STD_ZVAL(args);
    dd_copy_function_args(args TSRMLS_CC);

    // We don't do anything with the prehook return value
    zval *unused_retval = NULL;
    fci.retval_ptr_ptr = &unused_retval;

    zval *called_this = EG(uninitialized_zval_ptr), *called_scope = EG(uninitialized_zval_ptr);
    zend_class_entry *scope = dd_get_called_scope(fbc TSRMLS_CC);
    if (scope) {
        fcc.called_scope = scope;
        if (EG(This)) {
            MAKE_STD_ZVAL(called_this);
            ZVAL_ZVAL(called_this, EG(This), 1, 0);
            fcc.object_ptr = called_this;
        }

        MAKE_STD_ZVAL(called_scope);
        ZVAL_STRINGL(called_scope, scope->name, scope->name_length, 1);

        dd_sandbox_fci_call(fbc, &fci, &fcc TSRMLS_CC, 3, &called_this, &called_scope, &args);
    } else {
        dd_sandbox_fci_call(fbc, &fci, &fcc TSRMLS_CC, 1, &args);
    }

    if (called_this != EG(uninitialized_zval_ptr)) {
        zval_ptr_dtor(&called_this);
    }
    if (called_scope != EG(uninitialized_zval_ptr)) {
        zval_ptr_dtor(&called_scope);
    }
    if (unused_retval) {
        zval_ptr_dtor(&unused_retval);
    }
    zval_ptr_dtor(&args);
}

static void dd_execute_non_tracing_prehook(zend_op_array *op_array TSRMLS_DC) {
    ddtrace_dispatch_t *dispatch = op_array->reserved[ddtrace_resource];

    ddtrace_dispatch_copy(dispatch);
    dd_non_tracing_prehook_impl((zend_function *)op_array, dispatch TSRMLS_CC);
    dd_prev_execute(op_array TSRMLS_CC);
    ddtrace_dispatch_release(dispatch);
}

static dd_execute_hook execute_hooks[] = {
    [DDTRACE_DISPATCH_POSTHOOK] = dd_execute_tracing_posthook,
    [DDTRACE_DISPATCH_POSTHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_execute_non_tracing_posthook,
    [DDTRACE_DISPATCH_PREHOOK] = dd_execute_tracing_prehook,
    [DDTRACE_DISPATCH_PREHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_execute_non_tracing_prehook,
};

static bool dd_try_fetch_user_dispatch(zend_op_array *op_array TSRMLS_DC, ddtrace_dispatch_t **dispatch_ptr) {
    void *slot = op_array->reserved[ddtrace_resource];

    if (slot == DDTRACE_NOT_TRACED) {
        return false;
    }

    // we're not yet set-up to respect a cached dispatch; only a NOT_TRACED flag
    ddtrace_dispatch_t *dispatch = NULL;
    zend_function *fbc = (zend_function *)op_array;
    dispatch = dd_lookup_dispatch_from_fbc(fbc TSRMLS_CC);

    if (!dispatch) {
        op_array->reserved[ddtrace_resource] = DDTRACE_NOT_TRACED;
    } else if (dd_should_trace_dispatch(dispatch TSRMLS_CC)) {
        op_array->reserved[ddtrace_resource] = dispatch;
    } else {
        // dispatch located but shouldn't trace; do not cache anything
        dispatch = NULL;
    }

    *dispatch_ptr = dispatch;

    return dispatch;
}

static void ddtrace_execute(zend_op_array *op_array TSRMLS_DC) {
    ddtrace_dispatch_t *dispatch = NULL;
    dd_execute_hook execute_hook = dd_try_fetch_user_dispatch(op_array TSRMLS_CC, &dispatch)
                                       ? execute_hooks[DDTRACE_DISPATCH_JUMP_OFFSET(dispatch->options)]
                                       : dd_prev_execute;

    if (++DDTRACE_G(call_depth) >= 512 && get_DD_TRACE_WARN_CALL_STACK_DEPTH() && !DDTRACE_G(has_warned_call_depth)) {
        DDTRACE_G(has_warned_call_depth) = true;
        php_log_err(
            "ddtrace has detected a call stack depth of 512. If the call stack depth continues to grow the application "
            "may encounter a segmentation fault; see "
            "https://docs.datadoghq.com/tracing/troubleshooting/php_5_deep_call_stacks/ for details." TSRMLS_CC);
    }
    execute_hook(op_array TSRMLS_CC);
    --DDTRACE_G(call_depth);
}

static bool dd_try_fetch_internal_dispatch(zend_execute_data *execute_data TSRMLS_DC,
                                           ddtrace_dispatch_t **dispatch_ptr) {
    ddtrace_dispatch_t *dispatch = NULL;
    zend_function *fbc = execute_data->function_state.function;

    dispatch = dd_lookup_dispatch_from_fbc(fbc TSRMLS_CC);

    *dispatch_ptr = dispatch;

    return dispatch && dd_should_trace_dispatch(dispatch TSRMLS_CC);
}

static void (*dd_prev_execute_internal)(zend_execute_data *execute_data_ptr, int return_value_used TSRMLS_DC);
typedef void (*dd_execute_internal_hook)(zend_execute_data *execute_data_ptr, int return_value_used TSRMLS_DC,
                                         ddtrace_dispatch_t *dispatch);

static void dd_internal_tracing_prehook(zend_execute_data *execute_data_ptr, int return_value_used TSRMLS_DC,
                                        ddtrace_dispatch_t *dispatch) {
    // todo: add support for tracing prehook for PHP 5.4
    UNUSED(dispatch);
    dd_prev_execute_internal(execute_data_ptr, return_value_used TSRMLS_CC);
}

static void dd_internal_tracing_posthook(zend_execute_data *execute_data, int return_value_used TSRMLS_DC,
                                         ddtrace_dispatch_t *dispatch) {
    zend_function *fbc = execute_data->function_state.function;

    // taken from `execute_internal` on PHP 5.4
    zval **return_value_ptr = &(*(temp_variable *)((char *)EX(Ts) + EX(opline)->result.var)).var.ptr;
    zval *return_value = (fbc->common.fn_flags & ZEND_ACC_RETURN_REFERENCE) ? NULL : *return_value_ptr;

    ddtrace_dispatch_copy(dispatch);

    ddtrace_span_fci *span_fci = ddtrace_init_span(TSRMLS_C);
    span_fci->execute_data = execute_data;
    span_fci->dispatch = dispatch;
    ddtrace_open_span(span_fci TSRMLS_CC);
    zend_objects_store_add_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);

    // SpanData::$name defaults to fully qualified called name
    dd_set_fqn(span_fci, fbc TSRMLS_CC);

    dd_prev_execute_internal(execute_data, return_value_used TSRMLS_CC);

    if (ddtrace_has_top_internal_span(span_fci TSRMLS_CC)) {
        dd_tracing_posthook_impl(fbc, span_fci, return_value TSRMLS_CC);
    } else if (get_DD_TRACE_DEBUG() && !span_fci->span.duration) {
        // todo: update to Classname::Methodname format
        const char *fname = Z_STRVAL(dispatch->function_name);
        ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync", fname);
    }

    zend_objects_store_del_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);
}

static void dd_internal_non_tracing_posthook(zend_execute_data *execute_data, int return_value_used TSRMLS_DC,
                                             ddtrace_dispatch_t *dispatch) {
    zend_function *fbc = execute_data->function_state.function;

    // taken from `execute_internal` on PHP 5.4
    zval **return_value_ptr = &(*(temp_variable *)((char *)EX(Ts) + EX(opline)->result.var)).var.ptr;
    zval *return_value = (fbc->common.fn_flags & ZEND_ACC_RETURN_REFERENCE) ? NULL : *return_value_ptr;

    ddtrace_dispatch_copy(dispatch);
    dd_prev_execute_internal(execute_data, return_value_used TSRMLS_CC);
    dd_non_tracing_posthook_impl(fbc, dispatch, return_value TSRMLS_CC);
    ddtrace_dispatch_release(dispatch);
}

static void dd_internal_non_tracing_prehook(zend_execute_data *execute_data, int return_value_used TSRMLS_DC,
                                            ddtrace_dispatch_t *dispatch) {
    ddtrace_dispatch_copy(dispatch);
    dd_non_tracing_prehook_impl(execute_data->function_state.function, dispatch TSRMLS_CC);
    dd_prev_execute_internal(execute_data, return_value_used TSRMLS_CC);
    ddtrace_dispatch_release(dispatch);
}

static dd_execute_internal_hook execute_internal_hooks[] = {
    [DDTRACE_DISPATCH_POSTHOOK] = dd_internal_tracing_posthook,
    [DDTRACE_DISPATCH_POSTHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_internal_non_tracing_posthook,
    [DDTRACE_DISPATCH_PREHOOK] = dd_internal_tracing_prehook,
    [DDTRACE_DISPATCH_PREHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_internal_non_tracing_prehook,
};

static void ddtrace_execute_internal(zend_execute_data *execute_data_ptr, int return_value_used TSRMLS_DC) {
    ddtrace_dispatch_t *dispatch = NULL;
    if (dd_try_fetch_internal_dispatch(execute_data_ptr TSRMLS_CC, &dispatch)) {
        dd_execute_internal_hook hook = execute_internal_hooks[DDTRACE_DISPATCH_JUMP_OFFSET(dispatch->options)];
        hook(execute_data_ptr, return_value_used TSRMLS_CC, dispatch);
    } else {
        dd_prev_execute_internal(execute_data_ptr, return_value_used TSRMLS_CC);
    }
}

// todo: consolidate all these init hooks (no need for opcode and execute_internal to have own init/shutdown)
void ddtrace_opcode_minit(void) {}
void ddtrace_opcode_mshutdown(void) {}

void ddtrace_engine_hooks_rinit(TSRMLS_D) {
#if ZTS
    UNUSED(TSRMLS_C);
#endif
}

void ddtrace_engine_hooks_rshutdown(TSRMLS_D) {
#if ZTS
    UNUSED(TSRMLS_C);
#endif
}

void ddtrace_execute_internal_minit(void) {
    dd_prev_execute = zend_execute;
    zend_execute = ddtrace_execute;

    dd_prev_execute_internal = zend_execute_internal ?: execute_internal;
    zend_execute_internal = ddtrace_execute_internal;
}

void ddtrace_execute_internal_mshutdown(void) {
    if (zend_execute == ddtrace_execute) {
        zend_execute = dd_prev_execute;
    }

    if (zend_execute_internal == ddtrace_execute_internal) {
        dd_prev_execute_internal = dd_prev_execute_internal == execute_internal ? NULL : dd_prev_execute_internal;
    }
}

// TODO: can we support close-at-exit and by extension fatal errors on PHP 5.4?
zval *ddtrace_make_exception_from_error(DDTRACE_ERROR_CB_PARAMETERS TSRMLS_DC) {
    UNUSED(DDTRACE_ERROR_CB_PARAM_PASSTHRU);
#if ZTS
    UNUSED(TSRMLS_C);
#endif

    return NULL;
}

void ddtrace_close_all_open_spans(TSRMLS_D) {
    ddtrace_span_fci *span_fci;
    while ((span_fci = DDTRACE_G(open_spans_top)) &&
           (span_fci->execute_data != NULL || span_fci->dispatch != NULL || span_fci->next)) {
        // limited closing support, we do not allow posttracing handlers to be invoked on PHP 5.4 in autoclose
        if (get_DD_AUTOFINISH_SPANS() || span_fci->execute_data != NULL || span_fci->dispatch != NULL) {
            dd_trace_stop_span_time(&span_fci->span);
            ddtrace_close_span(span_fci TSRMLS_CC);
        } else {
            ddtrace_drop_top_open_span(TSRMLS_C);
        }
    }
    DDTRACE_G(open_spans_top) = span_fci;
}
