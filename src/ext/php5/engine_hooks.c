#include "engine_hooks.h"

#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <php.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "dispatch.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

// todo: implement op_array.reserved caching for calls that do not trace
int ddtrace_resource = -1;

// True globals; only modify in minit/mshutdown
static user_opcode_handler_t _dd_prev_exit_handler;

static zval *ddtrace_this(zend_execute_data *execute_data) {
    zval *This = NULL;
    if (EX(opline) && EX(opline)->opcode != ZEND_DO_FCALL) {
        This = EX(call) ? EX(call)->object : NULL;
    }
    if (This && Z_TYPE_P(This) != IS_OBJECT) {
        This = NULL;
    }

    return This;
}

BOOL_T ddtrace_execute_tracing_closure(ddtrace_dispatch_t *dispatch, zval *span_data, zend_execute_data *execute_data,
                                       zval *user_args, zval *user_retval, zval *exception TSRMLS_DC) {
    BOOL_T status = TRUE;
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    zval *retval_ptr = NULL;
    zval **args[4];
    zval *null_zval = &EG(uninitialized_zval);
    zval *this = ddtrace_this(execute_data);

    if (!span_data || !user_args || !user_retval) {
        if (get_dd_trace_debug()) {
            const char *fname = Z_STRVAL(dispatch->function_name);
            ddtrace_log_errf("Tracing closure could not be run for %s() because it is in an invalid state", fname);
        }
        return FALSE;
    }

    if (zend_fcall_info_init(&dispatch->callable, 0, &fci, &fcc, NULL, NULL TSRMLS_CC) == FAILURE) {
        ddtrace_log_debug("Could not init tracing closure");
        return FALSE;
    }

    /* Note: In PHP 5 there is a bug where closures are automatically
     * marked as static if they are defined from a static method context.
     * @see https://3v4l.org/Rgo87
     */
    if (this) {
        BOOL_T is_instance_method = (FBC()->common.fn_flags & ZEND_ACC_STATIC) ? FALSE : TRUE;
        BOOL_T is_closure_static = (fcc.function_handler->common.fn_flags & ZEND_ACC_STATIC) ? TRUE : FALSE;
        if (is_instance_method && is_closure_static) {
            ddtrace_log_debug("Cannot trace non-static method with static tracing closure");
            return FALSE;
        }
    }

    // Arg 0: DDTrace\SpanData $span
    args[0] = &span_data;

    // Arg 1: array $args
    args[1] = &user_args;

    // Arg 2: mixed $retval
    args[2] = &user_retval;
    // Arg 3: Exception|null $exception
    args[3] = exception ? &exception : &null_zval;

    fci.param_count = 4;
    fci.params = args;
    fci.retval_ptr_ptr = &retval_ptr;

    fcc.initialized = 1;
    fcc.object_ptr = this;
    fcc.called_scope = EX(call) ? EX(call)->called_scope : NULL;
    // Give the tracing closure access to private & protected class members
    fcc.function_handler->common.scope = fcc.called_scope;

    if (zend_call_function(&fci, &fcc TSRMLS_CC) == FAILURE) {
        ddtrace_log_debug("Could not execute tracing closure");
    }

    if (fci.retval_ptr_ptr && retval_ptr) {
        if (Z_TYPE_P(retval_ptr) == IS_BOOL) {
            status = Z_LVAL_P(retval_ptr) ? TRUE : FALSE;
        }
        zval_ptr_dtor(&retval_ptr);
    }
    zend_fcall_info_args_clear(&fci, 0);
    return status;
}

static void ddtrace_copy_function_args(zend_execute_data *execute_data, zval *user_args) {
    /* This is taken from func_get_args
     * PHP 5.3 - 5.5 are the same:
     * @see https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_builtin_functions.c#L445-L473
     * In 5.6 it changed:
     * @see https://github.com/php/php-src/blob/PHP-5.6/Zend/zend_builtin_functions.c#L443-L476
     */
    void **p = EX(function_state).arguments;
    if (p && *p) {
        int arg_count = (int)(zend_uintptr_t)*p;
        array_init_size(user_args, arg_count);
        for (int i = 0; i < arg_count; i++) {
#if PHP_VERSION_ID < 50600
            zval *element;

            ALLOC_ZVAL(element);
            *element = **((zval **)(p - (arg_count - i)));
            zval_copy_ctor(element);
            INIT_PZVAL(element);
#else
            zval *element, *arg;

            arg = *((zval **)(p - (arg_count - i)));
            if (!Z_ISREF_P(arg)) {
                element = arg;
                Z_ADDREF_P(element);
            } else {
                ALLOC_ZVAL(element);
                INIT_PZVAL_COPY(element, arg);
                zval_copy_ctor(element);
            }
#endif
            zend_hash_next_index_insert(Z_ARRVAL_P(user_args), &element, sizeof(zval *), NULL);
        }
    } else {
        array_init(user_args);
    }
}

static void ddtrace_span_attach_exception(ddtrace_span_t *span, ddtrace_exception_t *exception) {
    if (exception) {
        MAKE_STD_ZVAL(span->exception);
        ZVAL_COPY_VALUE(span->exception, exception);
        zval_copy_ctor(span->exception);
    }
}

static zval *ddtrace_exception_get_entry(zval *object, char *name, int name_len TSRMLS_DC) {
    zend_class_entry *exception_ce = zend_exception_get_default(TSRMLS_C);
    return zend_read_property(exception_ce, object, name, name_len, 1 TSRMLS_CC);
}

static void _dd_end_span(ddtrace_span_t *span, zval *user_retval, const zend_op *opline_before_exception TSRMLS_DC) {
    zend_execute_data *call = span->call;
    ddtrace_dispatch_t *dispatch = span->dispatch;
    zval *user_args;
    ALLOC_INIT_ZVAL(user_args);
    zval *exception = NULL, *prev_exception = NULL;

    dd_trace_stop_span_time(span);

    ddtrace_copy_function_args(call, user_args);
    if (EG(exception)) {
        exception = EG(exception);
        EG(exception) = NULL;
        prev_exception = EG(prev_exception);
        EG(prev_exception) = NULL;
        ddtrace_span_attach_exception(span, exception);
        zend_clear_exception(TSRMLS_C);
    }

    BOOL_T keep_span = TRUE;
    if (Z_TYPE(dispatch->callable) == IS_OBJECT) {
        ddtrace_error_handling eh;
        ddtrace_backup_error_handling(&eh, EH_SUPPRESS TSRMLS_CC);

        keep_span = ddtrace_execute_tracing_closure(dispatch, span->span_data, call, user_args, user_retval,
                                                    exception TSRMLS_CC);

        if (get_dd_trace_debug() && PG(last_error_message) && eh.message != PG(last_error_message)) {
            const char *fname = Z_STRVAL(dispatch->function_name);
            ddtrace_log_errf("Error raised in tracing closure for %s(): %s in %s on line %d", fname,
                             PG(last_error_message), PG(last_error_file), PG(last_error_lineno));
        }

        ddtrace_restore_error_handling(&eh TSRMLS_CC);
        // If the tracing closure threw an exception, ignore it to not impact the original call
        if (get_dd_trace_debug() && EG(exception)) {
            zval *ex = EG(exception), *message = NULL;
            const char *type = Z_OBJCE_P(ex)->name;
            const char *name = Z_STRVAL(dispatch->function_name);
            message = ddtrace_exception_get_entry(ex, ZEND_STRL("message") TSRMLS_CC);
            const char *msg = message && Z_TYPE_P(message) == IS_STRING ? Z_STRVAL_P(message)
                                                                        : "(internal error reading exception message)";
            ddtrace_log_errf("%s thrown in tracing closure for %s: %s", type, name, msg);
        }
        ddtrace_maybe_clear_exception(TSRMLS_C);
    }

    if (keep_span == TRUE) {
        ddtrace_close_span(TSRMLS_C);
    } else {
        ddtrace_drop_top_open_span(TSRMLS_C);
    }

    if (exception) {
        EG(exception) = exception;
        EG(prev_exception) = prev_exception;
        EG(opline_before_exception) = (zend_op *)opline_before_exception;
        EG(current_execute_data)->opline = EG(exception_op);
    }

    zval_ptr_dtor(&user_args);
}

static int _dd_exit_handler(zend_execute_data *execute_data TSRMLS_DC) {
    ddtrace_span_t *span;
    while ((span = DDTRACE_G(open_spans_top))) {
        if (span->retval) {
            zval_ptr_dtor(&span->retval);
            span->retval = NULL;
        }
        _dd_end_span(span, &EG(uninitialized_zval), EX(opline) TSRMLS_CC);
    }

    return _dd_prev_exit_handler ? _dd_prev_exit_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}

void ddtrace_opcode_minit(void) {
    _dd_prev_exit_handler = zend_get_user_opcode_handler(ZEND_EXIT);
    zend_set_user_opcode_handler(ZEND_EXIT, _dd_exit_handler);
}

void ddtrace_opcode_mshutdown(void) { zend_set_user_opcode_handler(ZEND_EXIT, NULL); }
static ddtrace_dispatch_t *_dd_lookup_dispatch_from_fbc(zval *this, zend_function *fbc TSRMLS_DC) {
    if (DDTRACE_G(disable) || DDTRACE_G(disable_in_current_request) || DDTRACE_G(class_lookup) == NULL ||
        DDTRACE_G(function_lookup) == NULL) {
        return FALSE;
    }
    if (!fbc) {
        return FALSE;
    }

    // Don't trace closures or functions without names
    if (fbc->common.fn_flags & ZEND_ACC_CLOSURE || !fbc->common.function_name) {
        return FALSE;
    }

    zval zv, *fname;
    fname = &zv;
    ZVAL_STRING(fname, fbc->common.function_name, 0);

    return ddtrace_find_dispatch(this ? Z_OBJCE_P(this) : fbc->common.scope, fname TSRMLS_CC);
}

static bool _dd_should_trace_dispatch(ddtrace_dispatch_t *dispatch TSRMLS_DC) {
    if (dispatch->busy) {
        return false;
    }
    if (ddtrace_tracer_is_limited(TSRMLS_C) && (dispatch->options & DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED) == 0) {
        return false;
    }

    return true;
}

static void _dd_execute_end_span(zend_execute_data *call, ddtrace_span_t *span, zval *user_retval,
                                 const zend_op *opline_before_exception TSRMLS_DC) {
    ddtrace_dispatch_t *dispatch = span->dispatch;
    zval *user_args;
    ALLOC_INIT_ZVAL(user_args);
    zval *exception = NULL, *prev_exception = NULL;

    dd_trace_stop_span_time(span);

    ddtrace_copy_function_args(call, user_args);
    if (EG(exception)) {
        exception = EG(exception);
        EG(exception) = NULL;
        prev_exception = EG(prev_exception);
        EG(prev_exception) = NULL;
        ddtrace_span_attach_exception(span, exception);
        zend_clear_exception(TSRMLS_C);
    }

    BOOL_T keep_span = TRUE;
    if (Z_TYPE(dispatch->callable) == IS_OBJECT) {
        ddtrace_error_handling eh;
        ddtrace_backup_error_handling(&eh, EH_SUPPRESS TSRMLS_CC);

        keep_span = ddtrace_execute_tracing_closure(dispatch, span->span_data, call, user_args, user_retval,
                                                    exception TSRMLS_CC);

        if (get_dd_trace_debug() && PG(last_error_message) && eh.message != PG(last_error_message)) {
            const char *fname = Z_STRVAL(dispatch->function_name);
            ddtrace_log_errf("Error raised in tracing closure for %s(): %s in %s on line %d", fname,
                             PG(last_error_message), PG(last_error_file), PG(last_error_lineno));
        }

        ddtrace_restore_error_handling(&eh TSRMLS_CC);
        // If the tracing closure threw an exception, ignore it to not impact the original call
        if (get_dd_trace_debug() && EG(exception)) {
            zval *ex = EG(exception), *message = NULL;
            const char *type = Z_OBJCE_P(ex)->name;
            const char *name = Z_STRVAL(dispatch->function_name);
            message = ddtrace_exception_get_entry(ex, ZEND_STRL("message") TSRMLS_CC);
            const char *msg = message && Z_TYPE_P(message) == IS_STRING ? Z_STRVAL_P(message)
                                                                        : "(internal error reading exception message)";
            ddtrace_log_errf("%s thrown in tracing closure for %s: %s", type, name, msg);
        }
        ddtrace_maybe_clear_exception(TSRMLS_C);
    }

    if (keep_span == TRUE) {
        ddtrace_close_span(TSRMLS_C);
    } else {
        ddtrace_drop_top_open_span(TSRMLS_C);
    }

    if (exception) {
        EG(exception) = exception;
        EG(prev_exception) = prev_exception;
        EG(opline_before_exception) = (zend_op *)opline_before_exception;
        EG(current_execute_data)->opline = EG(exception_op);
    }

    zval_ptr_dtor(&user_args);
}

void (*_dd_prev_execute_ex)(zend_execute_data *execute_data TSRMLS_DC);
void ddtrace_execute_ex(zend_execute_data *execute_data TSRMLS_DC) {
    zend_function *fbc = (zend_function *)execute_data->op_array;
    zval *This = EG(This);
    ddtrace_dispatch_t *dispatch = _dd_lookup_dispatch_from_fbc(This, fbc TSRMLS_CC);
    if (!dispatch || !_dd_should_trace_dispatch(dispatch TSRMLS_CC) ||
        !(dispatch->options & DDTRACE_DISPATCH_POSTHOOK)) {
        _dd_prev_execute_ex(execute_data TSRMLS_CC);
        return;
    }
    dispatch->busy = 1;
    ddtrace_dispatch_copy(dispatch);

    ddtrace_span_t *span = ddtrace_open_span(execute_data, dispatch TSRMLS_CC);
    zval *retval = NULL;
    zend_op *opline = EX(prev_execute_data)->opline;
    bool free_retval = 0;

    /* If the retval doesn't get used then sometimes the engine won't set the
     * retval_ptr_ptr at all. We expect it to always be present, so adjust it.
     * Be sure to dtor it later.
     */
    if (!EG(return_value_ptr_ptr)) {
        EG(return_value_ptr_ptr) = &retval;
        free_retval = 1;
    }

    _dd_prev_execute_ex(execute_data TSRMLS_CC);

    /* Sometimes the retval goes away when there is an exception, and
     * sometimes it's there but points to nothing (even excluding our fixup
     * above), so check both.
     */
    zval *actual_retval =
        (EG(return_value_ptr_ptr) && *EG(return_value_ptr_ptr)) ? *EG(return_value_ptr_ptr) : &zval_used_for_init;
    _dd_execute_end_span(execute_data->prev_execute_data, span, actual_retval, opline TSRMLS_CC);

    if (free_retval && *EG(return_value_ptr_ptr)) {
        zval_ptr_dtor(EG(return_value_ptr_ptr));
        EG(return_value_ptr_ptr) = NULL;
    }
}

void (*_dd_prev_execute_internal)(zend_execute_data *execute_data_ptr, zend_fcall_info *fci,
                                  int return_value_used TSRMLS_DC);
void ddtrace_execute_internal(zend_execute_data *execute_data, zend_fcall_info *fci, int return_value_used TSRMLS_DC) {
    zend_function *fbc = execute_data->function_state.function;
    ddtrace_dispatch_t *dispatch = _dd_lookup_dispatch_from_fbc(EX(object), fbc TSRMLS_CC);
    if (!dispatch || !_dd_should_trace_dispatch(dispatch TSRMLS_CC) ||
        !(dispatch->options & DDTRACE_DISPATCH_POSTHOOK)) {
        _dd_prev_execute_internal(execute_data, fci, return_value_used TSRMLS_CC);
        return;
    }
    dispatch->busy = 1;
    ddtrace_dispatch_copy(dispatch);
    zend_fcall_info fci_tmp;
    if (!fci) {
        fci = &fci_tmp;

        // Taken from execute_internal on PHP 5.5 and 5.6
        zval **retval_ptr_ptr = &EX_TMP_VAR(execute_data, EX(opline)->result.var)->var.ptr;
        fci->object_ptr = EX(object);
#if PHP_VERSION_ID < 50600
        fci->param_count = EX(opline)->extended_value;
        fci->retval_ptr_ptr =
            (EX(function_state).function->common.fn_flags & ZEND_ACC_RETURN_REFERENCE) ? retval_ptr_ptr : NULL;
#else
        fci->param_count = EX(opline)->extended_value + EX(call)->num_additional_args;
        fci->retval_ptr_ptr = retval_ptr_ptr;
#endif
    }

    zend_op *opline = EX(opline);
    ddtrace_span_t *span = ddtrace_open_span(execute_data, dispatch TSRMLS_CC);

    _dd_prev_execute_internal(execute_data, fci, return_value_used TSRMLS_CC);

    if (span == DDTRACE_G(open_spans_top)) {
        _dd_execute_end_span(execute_data, span, *fci->retval_ptr_ptr, opline TSRMLS_CC);
    } else {
        if (get_dd_trace_debug()) {
            const char *fname = Z_STRVAL(dispatch->function_name);
            ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync", fname);
        }
    }
}

void ddtrace_execute_internal_minit(void) {
    _dd_prev_execute_ex = zend_execute_ex;
    zend_execute_ex = ddtrace_execute_ex;

    _dd_prev_execute_internal = zend_execute_internal ?: execute_internal;
    zend_execute_internal = ddtrace_execute_internal;
}

void ddtrace_execute_internal_mshutdown(void) {
    // TODO
}
