#include "ext/php5/engine_hooks.h"

#include <Zend/zend.h>
#include <Zend/zend_closures.h>
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

// True globals; only modify in minit/mshutdown
static user_opcode_handler_t dd_prev_exit_handler;

static ddtrace_execute_data dd_execute_data_init(zend_execute_data *execute_data) {
    ddtrace_execute_data dd_execute_data = {NULL, NULL, NULL, NULL, NULL, NULL, false};
    dd_execute_data.fbc = execute_data->function_state.function;
    if (dd_execute_data.fbc) {
        if (dd_execute_data.fbc->common.type != ZEND_INTERNAL_FUNCTION) {
            execute_data = execute_data->prev_execute_data;
            if (!execute_data) {
                return dd_execute_data;
            }
        }
        if (dd_execute_data.fbc->common.scope) {
            dd_execute_data.scope = execute_data->call ? execute_data->call->called_scope : NULL;
            dd_execute_data.This = execute_data->object;
        }
        dd_execute_data.arguments = execute_data->function_state.arguments;
        dd_execute_data.opline = execute_data->opline;
    }

    return dd_execute_data;
}

static void dd_try_fetch_executing_function_name(ddtrace_execute_data *dd_execute_data, const char **scope,
                                                 const char **colon, const char **name) {
    *scope = "";
    *colon = "";
    *name = "(unknown function)";
    zend_function *fbc = dd_execute_data->fbc;
    if (fbc->common.function_name) {
        *name = fbc->common.function_name;
        if (fbc->common.scope) {
            *scope = fbc->common.scope->name;
            *colon = "::";
        }
    }
}

static ZEND_RESULT_CODE dd_sandbox_fci_call(ddtrace_execute_data *dd_execute_data, zend_fcall_info *fci,
                                            zend_fcall_info_cache *fcc TSRMLS_DC, int argc, ...) {
    ZEND_RESULT_CODE ret;
    va_list argv;

    va_start(argv, argc);
    ret = zend_fcall_info_argv(fci TSRMLS_CC, argc, &argv);
    va_end(argv);

    // The only way we mess this up is by passing in argc < 0
    ZEND_ASSERT(ret == SUCCESS);

    ddtrace_sandbox_backup backup = ddtrace_sandbox_begin((zend_op *)dd_execute_data->opline TSRMLS_CC);
    ret = zend_call_function(fci, fcc TSRMLS_CC);

    if (UNEXPECTED(ret != SUCCESS)) {
        ddtrace_log_debug("Could not execute ddtrace's closure");
    }

    if (get_DD_TRACE_DEBUG()) {
        const char *scope, *colon, *name;
        dd_try_fetch_executing_function_name(dd_execute_data, &scope, &colon, &name);

        if (PG(last_error_message) && backup.eh.message != PG(last_error_message)) {
            ddtrace_log_errf("Error raised in ddtrace's closure for %s%s%s(): %s in %s on line %d", scope, colon, name,
                             PG(last_error_message), PG(last_error_file), PG(last_error_lineno));
        }

        // If the tracing closure threw an exception, ignore it to not impact the original call
        if (EG(exception)) {
            zval *ex = EG(exception), *message = NULL;
            const char *type = Z_OBJCE_P(ex)->name;
            message = ddtrace_exception_get_entry(ex, ZEND_STRL("message") TSRMLS_CC);
            const char *msg = message && Z_TYPE_P(message) == IS_STRING ? Z_STRVAL_P(message)
                                                                        : "(internal error reading exception message)";
            ddtrace_log_errf("%s thrown in ddtrace's closure for %s%s%s(): %s", type, scope, colon, name, msg);
        }
    }

    ddtrace_sandbox_end(&backup TSRMLS_CC);
    zend_fcall_info_args_clear(fci, 1);

    return ret;
}

static bool dd_execute_tracing_closure(ddtrace_span_fci *span_fci, zval *user_args, zval *user_retval TSRMLS_DC) {
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;
    zval *exception = ddtrace_spandata_property_exception(&span_fci->span);
    bool keep_span = true;
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    ddtrace_execute_data dd_execute_data = span_fci->dd_execute_data;
    zval *null_zval = &EG(uninitialized_zval);

    if (!user_args || !user_retval) {
        if (get_DD_TRACE_DEBUG()) {
            const char *fname = Z_STRVAL(dispatch->function_name);
            ddtrace_log_errf("Tracing closure could not be run for %s() because it is in an invalid state", fname);
        }
        return false;
    }

    if (zend_fcall_info_init(&dispatch->callable, 0, &fci, &fcc, NULL, NULL TSRMLS_CC) == FAILURE) {
        ddtrace_log_debug("Could not init tracing closure");
        return false;
    }

    /* Note: In PHP 5 there is a bug where closures are automatically
     * marked as static if they are defined from a static method context.
     * @see https://3v4l.org/Rgo87
     */
    if (dd_execute_data.This) {
        bool is_instance_method = (dd_execute_data.fbc->common.fn_flags & ZEND_ACC_STATIC) ? false : true;
        bool is_closure_static = (fcc.function_handler->common.fn_flags & ZEND_ACC_STATIC) ? true : false;
        if (is_instance_method && is_closure_static) {
            ddtrace_log_debug("Cannot trace non-static method with static tracing closure");
            return false;
        }
    }

    zval *span_data;
    MAKE_STD_ZVAL(span_data);
    Z_TYPE_P(span_data) = IS_OBJECT;
    Z_OBJVAL_P(span_data) = span_fci->span.obj_value;
    zend_objects_store_add_ref(span_data TSRMLS_CC);

    zval *retval_ptr = NULL;
    fci.retval_ptr_ptr = &retval_ptr;

    fcc.initialized = 1;
    fcc.object_ptr = dd_execute_data.This;
    fcc.called_scope = dd_execute_data.scope;
    // Give the tracing closure access to private & protected class members
    fcc.function_handler->common.scope = fcc.called_scope;

    dd_sandbox_fci_call(&dd_execute_data, &fci, &fcc TSRMLS_CC, 4, &span_data, &user_args, &user_retval,
                        exception ? &exception : &null_zval);

    if (fci.retval_ptr_ptr && retval_ptr) {
        if (Z_TYPE_P(retval_ptr) == IS_BOOL) {
            keep_span = Z_LVAL_P(retval_ptr) ? true : false;
        }
        zval_ptr_dtor(&retval_ptr);
    }

    zval_ptr_dtor(&span_data);

    return keep_span;
}

/* See zend_copy_parameters_array. This is mostly a copy of that function,
 * except that:
 *   - It doesn't work from the perspective of VM globals.
 *   - It will initialize the args array.
 */
static ZEND_RESULT_CODE ddtrace_copy_function_args(zval *args, void **p) {
    if (!p) {
        array_init(args);
        return SUCCESS;
    }
    int arg_count = (int)(zend_uintptr_t)*p;

    array_init_size(args, arg_count);

    int param_count = arg_count;
    while (param_count-- > 0) {
        zval **param = (zval **)p - (arg_count--);
        zval_add_ref(param);
        add_next_index_zval(args, *param);
    }

    return SUCCESS;
}

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

static void ddtrace_fcall_end_non_tracing_posthook(ddtrace_span_fci *span_fci, zval *user_retval TSRMLS_DC) {
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;
    ddtrace_execute_data dd_execute_data = span_fci->dd_execute_data;

    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    if (zend_fcall_info_init(&dispatch->posthook, 0, &fci, &fcc, NULL, NULL TSRMLS_CC) != SUCCESS) {
        goto release_dispatch;
    }

    fcc.initialized = 1;

    zval *args;
    MAKE_STD_ZVAL(args);
    ddtrace_copy_function_args(args, dd_execute_data.arguments);

    zval *unused_retval = NULL;
    fci.retval_ptr_ptr = &unused_retval;

    zval *called_this = EG(uninitialized_zval_ptr), *called_scope = EG(uninitialized_zval_ptr);
    zend_class_entry *scope = dd_execute_data.scope;
    if (scope) {
        fcc.called_scope = scope;
        if (dd_execute_data.This) {
            MAKE_STD_ZVAL(called_this);
            ZVAL_ZVAL(called_this, dd_execute_data.This, 1, 0);

            /* We only bind $this on PHP 5, because if we don't it will flag any
             * closures that get defined within the prehook call to be static,
             * which means they can't be bound during trace_method.
             */
            fcc.object_ptr = called_this;
        }

        MAKE_STD_ZVAL(called_scope);
        ZVAL_STRINGL(called_scope, scope->name, scope->name_length, 1);

        dd_sandbox_fci_call(&dd_execute_data, &fci, &fcc TSRMLS_CC, 4, &called_this, &called_scope, &args,
                            &user_retval);
    } else {
        dd_sandbox_fci_call(&dd_execute_data, &fci, &fcc TSRMLS_CC, 2, &args, &user_retval);
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

release_dispatch:

    // drop the placeholder span -- we do not need it
    ddtrace_drop_top_open_span(TSRMLS_C);
}

static void dd_exit_span(ddtrace_span_fci *span_fci TSRMLS_DC) {
    zval *user_retval = &EG(uninitialized_zval);
    ddtrace_span_t *span = &span_fci->span;
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;
    if (dispatch->options & DDTRACE_DISPATCH_NON_TRACING) {
        ddtrace_fcall_end_non_tracing_posthook(span_fci, user_retval TSRMLS_CC);
        return;
    }
    zval *user_args;
    ALLOC_INIT_ZVAL(user_args);

    dd_trace_stop_span_time(span);

    ddtrace_execute_data dd_execute_data = span_fci->dd_execute_data;
    ddtrace_copy_function_args(user_args, dd_execute_data.arguments);
    ddtrace_span_attach_exception(span_fci, EG(exception));

    bool keep_span = true;
    if (Z_TYPE(dispatch->callable) == IS_OBJECT || Z_TYPE(dispatch->callable) == IS_STRING) {
        keep_span = dd_execute_tracing_closure(span_fci, user_args, user_retval TSRMLS_CC);
    }

    ddtrace_close_userland_spans_until(
        span_fci TSRMLS_CC);  // because dropping / setting default properties happens on top span

    /* If a closure calls dd_trace_serialize_closed_spans then it will free the
     * open span stack, and the span_fci can go away.
     */
    if (ddtrace_has_top_internal_span(span_fci TSRMLS_CC)) {
        if (keep_span) {
            ddtrace_close_span(span_fci TSRMLS_CC);
        } else {
            ddtrace_drop_top_open_span(TSRMLS_C);
        }
    }

    zval_ptr_dtor(&user_args);
}

static int dd_exit_handler(zend_execute_data *execute_data TSRMLS_DC) {
    ddtrace_close_all_open_spans(TSRMLS_C);
    return dd_prev_exit_handler ? dd_prev_exit_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}

static ddtrace_dispatch_t *dd_lookup_dispatch_from_fbc(zend_function *fbc TSRMLS_DC) {
    if (!PG(modules_activated)) {
        return false;
    }

    if (!get_DD_TRACE_ENABLED() || DDTRACE_G(class_lookup) == NULL || DDTRACE_G(function_lookup) == NULL) {
        return false;
    }
    if (!fbc) {
        return false;
    }

    // Don't trace closures or functions without names
    if (fbc->common.fn_flags & ZEND_ACC_CLOSURE || !fbc->common.function_name) {
        return false;
    }

    zval zv, *fname;
    fname = &zv;
    ZVAL_STRING(fname, fbc->common.function_name, 0);

    return ddtrace_find_dispatch(fbc->common.scope ? EG(called_scope) : NULL, fname TSRMLS_CC);
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

static void dd_execute_end_span(ddtrace_span_fci *span_fci, zval *user_retval TSRMLS_DC) {
    ddtrace_span_t *span = &span_fci->span;
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;
    zval *user_args;
    MAKE_STD_ZVAL(user_args);

    dd_trace_stop_span_time(span);

    ddtrace_execute_data dd_execute_data = dd_execute_data_init(span_fci->execute_data);
    ddtrace_copy_function_args(user_args, dd_execute_data.arguments);
    ddtrace_span_attach_exception(span_fci, EG(exception));

    bool keep_span = true;
    if (Z_TYPE(dispatch->callable) == IS_OBJECT || Z_TYPE(dispatch->callable) == IS_STRING) {
        keep_span = dd_execute_tracing_closure(span_fci, user_args, user_retval TSRMLS_CC);
    }

    ddtrace_close_userland_spans_until(
        span_fci TSRMLS_CC);  // because dropping / setting default properties happens on top span

    /* The span_fci can be freed during the closure if it calls
     * dd_trace_serialize_closed_spans; pointer comparison is the only valid
     * operation we can do here in such cases.
     */
    if (ddtrace_has_top_internal_span(span_fci TSRMLS_CC)) {
        if (keep_span) {
            ddtrace_close_span(span_fci TSRMLS_CC);
        } else {
            ddtrace_drop_top_open_span(TSRMLS_C);
        }
    }

    zval_ptr_dtor(&user_args);
}

void (*dd_prev_execute_ex)(zend_execute_data *execute_data TSRMLS_DC);

static void dd_do_non_tracing_prehook(ddtrace_execute_data *dd_execute_data, ddtrace_dispatch_t *dispatch TSRMLS_DC) {
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    if (zend_fcall_info_init(&dispatch->prehook, 0, &fci, &fcc, NULL, NULL TSRMLS_CC) != SUCCESS) {
        return;
    }

    fcc.initialized = 1;

    zval *args;
    MAKE_STD_ZVAL(args);
    ddtrace_copy_function_args(args, dd_execute_data->arguments);

    fci.retval_ptr_ptr = &dd_execute_data->retval;

    zval *called_this = EG(uninitialized_zval_ptr), *called_scope = EG(uninitialized_zval_ptr);
    zend_class_entry *scope = dd_execute_data->scope;
    ZEND_RESULT_CODE status;
    if (scope) {
        fcc.called_scope = scope;
        if (dd_execute_data->This) {
            MAKE_STD_ZVAL(called_this);
            ZVAL_ZVAL(called_this, dd_execute_data->This, 1, 0);

            /* We only bind $this on PHP 5, because if we don't it will flag any
             * closures that get defined within the prehook call to be static,
             * which means they can't be bound during trace_method.
             */
            fcc.object_ptr = called_this;
        }

        MAKE_STD_ZVAL(called_scope);
        ZVAL_STRINGL(called_scope, scope->name, scope->name_length, 1);

        status = dd_sandbox_fci_call(dd_execute_data, &fci, &fcc TSRMLS_CC, 3, &called_this, &called_scope, &args);
    } else {
        status = dd_sandbox_fci_call(dd_execute_data, &fci, &fcc TSRMLS_CC, 1, &args);
    }

    if (UNEXPECTED(status != SUCCESS)) {
        ddtrace_log_debug("Could not execute ddtrace's closure");
    }

    if (called_this != EG(uninitialized_zval_ptr)) {
        zval_ptr_dtor(&called_this);
    }
    if (called_scope != EG(uninitialized_zval_ptr)) {
        zval_ptr_dtor(&called_scope);
    }
    if (dd_execute_data->retval) {
        zval_ptr_dtor(&dd_execute_data->retval);
    }
    zval_ptr_dtor(&args);
}

// execute_ex hooks {{{
typedef void (*dd_execute_ex_hook)(zend_execute_data *execute_data TSRMLS_DC);

void dd_set_fqn(ddtrace_span_fci *span_fci) {
    ddtrace_execute_data *dd_execute_data = &span_fci->dd_execute_data;
    const char *fname = dd_execute_data->fbc->common.function_name;
    if (!fname) {
        return;
    }

    zval **zv = ddtrace_spandata_property_name_write(&span_fci->span);
    if (*zv) {
        zval_ptr_dtor(zv);
    }
    MAKE_STD_ZVAL(*zv);

    zend_class_entry *called_scope = dd_execute_data->scope;
    if (called_scope) {
        // This cannot be cached on the dispatch since sub classes can share the same parent dispatch
        char *fqn;
        size_t fqn_len = spprintf(&fqn, 0, "%s.%s", called_scope->name, fname);
        ZVAL_STRINGL(*zv, fqn, fqn_len, 0);
    } else {
        ZVAL_STRING(*zv, fname, 1);
    }
}

static void dd_execute_tracing_posthook(zend_execute_data *execute_data TSRMLS_DC) {
    ddtrace_execute_data dd_execute_data = dd_execute_data_init(execute_data);
    ddtrace_dispatch_t *dispatch = dd_execute_data.fbc->op_array.reserved[ddtrace_resource];
    ddtrace_dispatch_copy(dispatch);

    ddtrace_span_fci *span_fci = ddtrace_init_span(TSRMLS_C);
    span_fci->execute_data = execute_data;
    span_fci->dispatch = dispatch;
    span_fci->dd_execute_data = dd_execute_data;
    ddtrace_open_span(span_fci TSRMLS_CC);
    zend_objects_store_add_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);

    // SpanData::$name defaults to fully qualified called name
    dd_set_fqn(span_fci);

    bool free_retval = 0;

    /* If the retval doesn't get used then sometimes the engine won't set the
     * retval_ptr_ptr at all. We expect it to always be present, so adjust it.
     * Be sure to dtor it later.
     */
    if (!EG(return_value_ptr_ptr)) {
        EG(return_value_ptr_ptr) = &dd_execute_data.retval;
        free_retval = 1;
    }

    dd_prev_execute_ex(execute_data TSRMLS_CC);

    /* Sometimes the retval goes away when there is an exception, and
     * sometimes it's there but points to nothing (even excluding our fixup
     * above), so check both.
     */
    zval *actual_retval =
        (EG(return_value_ptr_ptr) && *EG(return_value_ptr_ptr)) ? *EG(return_value_ptr_ptr) : &zval_used_for_init;

    /* The span_fci can be freed by the function this traces if it calls
     * dd_trace_serialize_closed_spans; pointer comparison is the only valid
     * operation we can do here in such cases.
     */
    if (ddtrace_has_top_internal_span(span_fci TSRMLS_CC)) {
        dd_execute_end_span(span_fci, actual_retval TSRMLS_CC);
    } else if (get_DD_TRACE_DEBUG() && get_DD_TRACE_ENABLED()) {
        const char *fname = Z_STRVAL(dispatch->function_name);
        ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync", fname);
    }

    zend_objects_store_del_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);

    if (free_retval && *EG(return_value_ptr_ptr)) {
        zval_ptr_dtor(EG(return_value_ptr_ptr));
        EG(return_value_ptr_ptr) = NULL;
    }
}

static void dd_execute_non_tracing_posthook(zend_execute_data *execute_data TSRMLS_DC) {
    ddtrace_execute_data dd_execute_data = dd_execute_data_init(execute_data);
    ddtrace_dispatch_t *dispatch = dd_execute_data.fbc->op_array.reserved[ddtrace_resource];

    ddtrace_dispatch_copy(dispatch);

    ddtrace_span_fci *span_fci = ddtrace_init_span(TSRMLS_C);
    span_fci->execute_data = execute_data;
    span_fci->dispatch = dispatch;
    span_fci->dd_execute_data = dd_execute_data;

    ddtrace_push_span(span_fci TSRMLS_CC);
    zend_objects_store_add_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);

    ddtrace_span_t *span = &span_fci->span;
    span->trace_id = DDTRACE_G(trace_id);
    span->span_id = ddtrace_peek_span_id(TSRMLS_C);

    /* ddtrace_push_span_id will make an ID if you push 0. We don't want this,
     * so we avoid the push when span_id=0.
     */
    if (span->span_id) {
        ddtrace_push_span_id(span->span_id TSRMLS_CC);
    }

    /* If the retval doesn't get used then sometimes the engine won't set the
     * retval_ptr_ptr at all. We expect it to always be present, so adjust it.
     * Be sure to dtor it later.
     */
    if (!EG(return_value_ptr_ptr)) {
        EG(return_value_ptr_ptr) = &dd_execute_data.retval;
        dd_execute_data.free_retval = 1;
    }

    dd_prev_execute_ex(execute_data TSRMLS_CC);

    /* Sometimes the retval goes away when there is an exception, and
     * sometimes it's there but points to nothing (even excluding our fixup
     * above), so check both.
     */
    zval *actual_retval =
        (EG(return_value_ptr_ptr) && *EG(return_value_ptr_ptr)) ? *EG(return_value_ptr_ptr) : &zval_used_for_init;

    ddtrace_fcall_end_non_tracing_posthook(span_fci, actual_retval TSRMLS_CC);

    if (dd_execute_data.retval) {
        zval_ptr_dtor(&dd_execute_data.retval);
        EG(return_value_ptr_ptr) = NULL;
    }

    zend_objects_store_del_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);
}

static void dd_execute_tracing_prehook(zend_execute_data *execute_data TSRMLS_DC) {
    // todo: implement tracing prehook on PHP 5
    dd_prev_execute_ex(execute_data TSRMLS_CC);
}

static void dd_execute_non_tracing_prehook(zend_execute_data *execute_data TSRMLS_DC) {
    ddtrace_execute_data dd_execute_data = dd_execute_data_init(execute_data);
    ddtrace_dispatch_t *dispatch = dd_execute_data.fbc->op_array.reserved[ddtrace_resource];
    ddtrace_dispatch_copy(dispatch);
    dd_do_non_tracing_prehook(&dd_execute_data, dispatch TSRMLS_CC);
    dd_prev_execute_ex(execute_data TSRMLS_CC);
    ddtrace_dispatch_release(dispatch);
}

static dd_execute_ex_hook execute_ex_hooks[4] = {
    [DDTRACE_DISPATCH_POSTHOOK] = dd_execute_tracing_posthook,
    [DDTRACE_DISPATCH_POSTHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_execute_non_tracing_posthook,
    [DDTRACE_DISPATCH_PREHOOK] = dd_execute_tracing_prehook,
    [DDTRACE_DISPATCH_PREHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_execute_non_tracing_prehook,
};
// }}}

static bool dd_try_fetch_user_dispatch(zend_execute_data *execute_data TSRMLS_DC, ddtrace_dispatch_t **dispatch_ptr) {
    zend_op_array *op_array = &execute_data->function_state.function->op_array;
    void *slot = op_array->reserved[ddtrace_resource];

    if (slot == DDTRACE_NOT_TRACED) {
        return false;
    }

    // Generators are not supported on PHP 5
    if (op_array->fn_flags & ZEND_ACC_GENERATOR) {
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

static void ddtrace_execute_ex(zend_execute_data *execute_data TSRMLS_DC) {
    ddtrace_dispatch_t *dispatch = NULL;

    dd_execute_ex_hook execute_hook = dd_try_fetch_user_dispatch(execute_data TSRMLS_CC, &dispatch)
                                          ? execute_ex_hooks[DDTRACE_DISPATCH_JUMP_OFFSET(dispatch->options)]
                                          : dd_prev_execute_ex;

    if (++DDTRACE_G(call_depth) >= 512 && get_DD_TRACE_WARN_CALL_STACK_DEPTH() && !DDTRACE_G(has_warned_call_depth)) {
        DDTRACE_G(has_warned_call_depth) = true;
        php_log_err(
            "ddtrace has detected a call stack depth of 512. If the call stack depth continues to grow the application "
            "may encounter a segmentation fault; see "
            "https://docs.datadoghq.com/tracing/troubleshooting/php_5_deep_call_stacks/ for details." TSRMLS_CC);
    }
    execute_hook(execute_data TSRMLS_CC);
    --DDTRACE_G(call_depth);
}

// zend_execute_internal override helpers {{{
void (*dd_prev_execute_internal)(zend_execute_data *execute_data_ptr, zend_fcall_info *fci,
                                 int return_value_used TSRMLS_DC);

static void dd_internal_tracing_posthook(ddtrace_span_fci *span_fci, zend_fcall_info *fci, int retval_used TSRMLS_DC) {
    zend_execute_data *execute_data = span_fci->execute_data;
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;

    span_fci->dd_execute_data.opline = EX(opline);

    ddtrace_open_span(span_fci TSRMLS_CC);
    zend_objects_store_add_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);

    // SpanData::$name defaults to fully qualified called name
    dd_set_fqn(span_fci);

    dd_prev_execute_internal(execute_data, fci, retval_used TSRMLS_CC);

    if (ddtrace_has_top_internal_span(span_fci TSRMLS_CC)) {
        dd_execute_end_span(span_fci, *fci->retval_ptr_ptr TSRMLS_CC);
    } else if (get_DD_TRACE_DEBUG() && get_DD_TRACE_ENABLED()) {
        const char *fname = Z_STRVAL(dispatch->function_name);
        ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync", fname);
    }

    zend_objects_store_del_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);
}

static void dd_internal_non_tracing_posthook(ddtrace_span_fci *span_fci, zend_fcall_info *fci,
                                             int retval_used TSRMLS_DC) {
    zend_execute_data *execute_data = span_fci->execute_data;
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;

    ddtrace_push_span(span_fci TSRMLS_CC);
    zend_objects_store_add_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);

    ddtrace_span_t *span = &span_fci->span;
    span->trace_id = DDTRACE_G(trace_id);
    span->span_id = ddtrace_peek_span_id(TSRMLS_C);

    /* ddtrace_push_span_id will make an ID if you push 0. We don't want this,
     * so we avoid the push when span_id=0.
     */
    if (span->span_id) {
        ddtrace_push_span_id(span->span_id TSRMLS_CC);
    }

    span_fci->dd_execute_data.opline = EX(opline);

    dd_prev_execute_internal(execute_data, fci, retval_used TSRMLS_CC);

    if (ddtrace_has_top_internal_span(span_fci TSRMLS_CC)) {
        ddtrace_fcall_end_non_tracing_posthook(span_fci, *fci->retval_ptr_ptr TSRMLS_CC);
    } else if (get_DD_TRACE_DEBUG() && get_DD_TRACE_ENABLED()) {
        const char *fname = Z_STRVAL(dispatch->function_name);
        ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync", fname);
    }

    zend_objects_store_del_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);
}

static void dd_internal_tracing_prehook(ddtrace_span_fci *span_fci, zend_fcall_info *fci, int retval_used TSRMLS_DC) {
    // todo: implement tracing prehook on PHP 5
    zend_execute_data *execute_data = span_fci->execute_data;
    dd_prev_execute_internal(execute_data, fci, retval_used TSRMLS_CC);
    zend_objects_store_del_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);
}

static void dd_internal_non_tracing_prehook(ddtrace_span_fci *span_fci, zend_fcall_info *fci,
                                            int retval_used TSRMLS_DC) {
    zend_execute_data *execute_data = span_fci->execute_data;
    ddtrace_execute_data dd_execute_data = dd_execute_data_init(execute_data);
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;

    dd_do_non_tracing_prehook(&dd_execute_data, dispatch TSRMLS_CC);
    dd_prev_execute_internal(execute_data, fci, retval_used TSRMLS_CC);

    zend_objects_store_del_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);
}

static void (*dd_internal_hooks[])(ddtrace_span_fci *span_fci, zend_fcall_info *fci, int retval_used TSRMLS_DC) = {
    [DDTRACE_DISPATCH_POSTHOOK] = dd_internal_tracing_posthook,
    [DDTRACE_DISPATCH_POSTHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_internal_non_tracing_posthook,
    [DDTRACE_DISPATCH_PREHOOK] = dd_internal_tracing_prehook,
    [DDTRACE_DISPATCH_PREHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_internal_non_tracing_prehook,
};
// }}}

static void dd_execute_internal(zend_execute_data *execute_data, zend_fcall_info *fci, int retval_used TSRMLS_DC) {
    ddtrace_execute_data dd_execute_data = dd_execute_data_init(execute_data);
    ddtrace_dispatch_t *dispatch = dd_lookup_dispatch_from_fbc(dd_execute_data.fbc TSRMLS_CC);
    if (!dispatch || !dd_should_trace_dispatch(dispatch TSRMLS_CC)) {
        dd_prev_execute_internal(execute_data, fci, retval_used TSRMLS_CC);
        return;
    }

    ddtrace_dispatch_copy(dispatch);

    ddtrace_span_fci *span_fci = ddtrace_init_span(TSRMLS_C);
    span_fci->execute_data = execute_data;
    span_fci->dispatch = dispatch;
    span_fci->dd_execute_data = dd_execute_data;

    zend_fcall_info fci_tmp;
    if (!fci) {
        fci = &fci_tmp;

        // Taken from execute_internal on PHP 5.5 and 5.6
        zval **retval_ptr_ptr = &EX_TMP_VAR(execute_data, EX(opline)->result.var)->var.ptr;
        fci->object_ptr = EX(object);
#if PHP_VERSION_ID < 50600
        fci->param_count = EX(opline)->extended_value;
        fci->retval_ptr_ptr = retval_ptr_ptr;
#else
        fci->param_count = EX(opline)->extended_value + EX(call)->num_additional_args;
        fci->retval_ptr_ptr = retval_ptr_ptr;
#endif
    }

    uint16_t dispatch_type = DDTRACE_DISPATCH_JUMP_OFFSET(dispatch->options);
    dd_internal_hooks[dispatch_type](span_fci, fci, retval_used TSRMLS_CC);
}

void ddtrace_opcode_minit(void) {
    dd_prev_exit_handler = zend_get_user_opcode_handler(ZEND_EXIT);
    zend_set_user_opcode_handler(ZEND_EXIT, dd_exit_handler);
}

void ddtrace_opcode_mshutdown(void) { zend_set_user_opcode_handler(ZEND_EXIT, NULL); }

void ddtrace_execute_internal_minit(void) {
    dd_prev_execute_ex = zend_execute_ex;
    zend_execute_ex = ddtrace_execute_ex;

    dd_prev_execute_internal = zend_execute_internal ?: execute_internal;
    zend_execute_internal = dd_execute_internal;
}

void ddtrace_execute_internal_mshutdown(void) {
    if (zend_execute_ex == ddtrace_execute_ex) {
        zend_execute_ex = dd_prev_execute_ex;
    }

    if (zend_execute_internal == dd_execute_internal) {
        zend_execute_internal = dd_prev_execute_internal != execute_internal ? dd_prev_execute_internal : NULL;
    }
}

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

zval *ddtrace_make_exception_from_error(DDTRACE_ERROR_CB_PARAMETERS TSRMLS_DC) {
    UNUSED(error_filename, error_lineno);

    zval *exception, *message_zv, *code_zv;
    MAKE_STD_ZVAL(exception);
    MAKE_STD_ZVAL(message_zv);
    MAKE_STD_ZVAL(code_zv);

    va_list args2;
    char message[1024];
    object_init_ex(exception, ddtrace_ce_fatal_error);

    va_copy(args2, args);
    vsnprintf(message, sizeof(message), format, args2);
    va_end(args2);
    ZVAL_STRING(message_zv, message, 1);
    zend_update_property(ddtrace_ce_fatal_error, exception, "message", sizeof("message") - 1, message_zv TSRMLS_CC);
    zval_ptr_dtor(&message_zv);

    ZVAL_LONG(code_zv, type);
    zend_update_property(ddtrace_ce_fatal_error, exception, "code", sizeof("code") - 1, code_zv TSRMLS_CC);
    zval_ptr_dtor(&code_zv);

    return exception;
}

void ddtrace_close_all_open_spans(TSRMLS_D) {
    ddtrace_span_fci *span_fci;
    while ((span_fci = DDTRACE_G(open_spans_top)) && (span_fci->execute_data != NULL || span_fci->next)) {
        if (span_fci->execute_data) {
            if (span_fci->dd_execute_data.free_retval && span_fci->dd_execute_data.retval) {
                zval_ptr_dtor(&span_fci->dd_execute_data.retval);
                span_fci->dd_execute_data.retval = NULL;
            }
            dd_exit_span(span_fci TSRMLS_CC);
        } else if (get_DD_AUTOFINISH_SPANS()) {
            dd_trace_stop_span_time(&span_fci->span);
            ddtrace_close_span(span_fci TSRMLS_CC);
        } else {
            ddtrace_drop_top_open_span(TSRMLS_C);
        }
    }
    DDTRACE_G(open_spans_top) = span_fci;
}
