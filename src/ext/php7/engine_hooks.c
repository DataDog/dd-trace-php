#include "engine_hooks.h"

#include <Zend/zend_closures.h>
#include <Zend/zend_compile.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <stdbool.h>

#include <ext/spl/spl_exceptions.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "env_config.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

static void (*_prev_execute_internal)(zend_execute_data *execute_data, zval *return_value);
static void _dd_execute_internal(zend_execute_data *execute_data, zval *return_value);

// True gloals; only modify in minit/mshutdown
static user_opcode_handler_t _prev_icall_handler;
static user_opcode_handler_t _prev_ucall_handler;
static user_opcode_handler_t _prev_fcall_handler;
static user_opcode_handler_t _prev_fcall_by_name_handler;
static user_opcode_handler_t _prev_return_handler;  // TODO ZEND_RETURN_BY_REF
static user_opcode_handler_t _prev_handle_exception_handler;
static user_opcode_handler_t _prev_exit_handler;

#if PHP_VERSION_ID < 70100
#define RETURN_VALUE_USED(opline) (!((opline)->result_type & EXT_TYPE_UNUSED))
#else
#define RETURN_VALUE_USED(opline) ((opline)->result_type != IS_UNUSED)
#endif

static zval *_dd_this(zend_execute_data *call) {
    if (Z_TYPE(call->This) == IS_OBJECT && Z_OBJ(call->This) != NULL) {
        return &call->This;
    }
    return NULL;
}

static bool _dd_should_trace_call(zend_execute_data *call, zend_function *fbc, ddtrace_dispatch_t **dispatch) {
    if (DDTRACE_G(disable) || DDTRACE_G(disable_in_current_request) || DDTRACE_G(class_lookup) == NULL ||
        DDTRACE_G(function_lookup) == NULL) {
        return false;
    }

    zval fname;
    if (fbc->common.function_name) {
        ZVAL_STR_COPY(&fname, fbc->common.function_name);
    } else {
        return false;
    }

    // Don't trace closures
    if (fbc->common.fn_flags & ZEND_ACC_CLOSURE) {
        zval_ptr_dtor(&fname);
        return false;
    }

    zval *this = _dd_this(call);
    *dispatch = ddtrace_find_dispatch(this, fbc, &fname);
    zval_ptr_dtor(&fname);
    if (!*dispatch || (*dispatch)->busy) {
        return false;
    }
    if (ddtrace_tracer_is_limited() && ((*dispatch)->options & DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED) == 0) {
        return false;
    }

    return true;
}

static void _dd_copy_function_args(zend_execute_data *call, zval *user_args) {
    uint32_t i;
    zval *p, *q;
    uint32_t arg_count = ZEND_CALL_NUM_ARGS(call);

    // @see https://github.com/php/php-src/blob/PHP-7.0/Zend/zend_builtin_functions.c#L506-L562
    array_init_size(user_args, arg_count);
    if (arg_count) {
        zend_hash_real_init(Z_ARRVAL_P(user_args), 1);
        ZEND_HASH_FILL_PACKED(Z_ARRVAL_P(user_args)) {
            i = 0;
            p = ZEND_CALL_ARG(call, 1);
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

static void _dd_span_attach_exception(ddtrace_span_t *span, ddtrace_exception_t *exception) {
    if (exception) {
        GC_ADDREF(exception);
        span->exception = exception;
    }
}

static void _dd_setup_fcall(zend_execute_data *execute_data, zend_fcall_info *fci, zval **result) {
    fci->param_count = ZEND_CALL_NUM_ARGS(execute_data);
    fci->params = fci->param_count ? ZEND_CALL_ARG(execute_data, 1) : NULL;
    fci->retval = *result;
}

static bool _dd_execute_tracing_closure(zval *callable, zval *span_data, zend_execute_data *call, zval *user_args,
                                        zval *user_retval, zend_object *exception) {
    bool status = true;
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    zval rv;
    INIT_ZVAL(rv);
    zval args[4];
    zval exception_arg = {.value = {0}};
    ZVAL_NULL(&exception_arg);
    if (exception) {
        ZVAL_OBJ(&exception_arg, exception);
    }
    zval *this = _dd_this(call);

    if (!callable || !span_data || !user_args) {
        if (get_dd_trace_debug()) {
            const char *fname = ZSTR_VAL(call->func->common.function_name);
            ddtrace_log_errf("Tracing closure could not be run for %s() because it is in an invalid state", fname);
        }
        return false;
    }

    if (zend_fcall_info_init(callable, 0, &fci, &fcc, NULL, NULL) == FAILURE) {
        ddtrace_log_debug("Could not init tracing closure");
        return false;
    }

    if (this) {
        bool is_instance_method = (call->func->common.fn_flags & ZEND_ACC_STATIC) ? false : true;
        bool is_closure_static = (fcc.function_handler->common.fn_flags & ZEND_ACC_STATIC) ? true : false;
        if (is_instance_method && is_closure_static) {
            ddtrace_log_debug("Cannot trace non-static method with static tracing closure");
            return false;
        }
    }

    // Arg 0: DDTrace\SpanData $span
    ZVAL_COPY(&args[0], span_data);

    // Arg 1: array $args
    ZVAL_COPY(&args[1], user_args);

    // Arg 2: mixed $retval
    if (!user_retval || Z_TYPE_INFO_P(user_retval) == IS_UNDEF) {
        user_retval = &EG(uninitialized_zval);
    }
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
    fcc.called_scope = zend_get_called_scope(call);
    // Give the tracing closure access to private & protected class members
    fcc.function_handler->common.scope = fcc.called_scope;

    if (zend_call_function(&fci, &fcc) == FAILURE) {
        ddtrace_log_debug("Could not execute tracing closure");
        status = false;
    } else if (Z_TYPE(rv) == IS_FALSE) {
        status = false;
    }

    zend_fcall_info_args_clear(&fci, 0);
    return status;
}

static zend_class_entry *_dd_get_exception_base(zval *object) {
    return instanceof_function(Z_OBJCE_P(object), zend_ce_exception) ? zend_ce_exception : zend_ce_error;
}

#if PHP_VERSION_ID < 70100
#define ZEND_STR_MESSAGE "message"
#define GET_PROPERTY(object, name) \
    zend_read_property(_dd_get_exception_base(object), (object), name, sizeof(name) - 1, 1, &rv)
#elif PHP_VERSION_ID < 70200
#define GET_PROPERTY(object, id) \
    zend_read_property_ex(_dd_get_exception_base(object), (object), CG(known_strings)[id], 1, &rv)
#else
#define GET_PROPERTY(object, id) zend_read_property_ex(_dd_get_exception_base(object), (object), ZSTR_KNOWN(id), 1, &rv)
#endif

static bool _dd_call_sandboxed_tracing_closure(ddtrace_span_t *span, zval *user_retval) {
    zend_execute_data *call = span->call;
    ddtrace_dispatch_t *dispatch = span->dispatch;
    zend_object *exception = NULL, *prev_exception = NULL;
    zval user_args;

    if (Z_TYPE(dispatch->callable) != IS_OBJECT) {
        return true;
    }

    _dd_copy_function_args(call, &user_args);
    if (EG(exception)) {
        exception = EG(exception);
        EG(exception) = NULL;
        prev_exception = EG(prev_exception);
        EG(prev_exception) = NULL;
        zend_clear_exception();
    }

    bool keep_span = true;
    ddtrace_error_handling eh;
    ddtrace_backup_error_handling(&eh, EH_THROW);

    keep_span =
        _dd_execute_tracing_closure(&dispatch->callable, span->span_data, call, &user_args, user_retval, exception);

    if (get_dd_trace_debug() && PG(last_error_message) && eh.message != PG(last_error_message)) {
        const char *fname = Z_STRVAL(dispatch->function_name);
        ddtrace_log_errf("Error raised in tracing closure for %s(): %s in %s on line %d", fname, PG(last_error_message),
                         PG(last_error_file), PG(last_error_lineno));
    }

    ddtrace_restore_error_handling(&eh);
    // If the tracing closure threw an exception, ignore it to not impact the original call
    if (get_dd_trace_debug() && EG(exception)) {
        zend_object *ex = EG(exception);
        const char *name = Z_STR(dispatch->function_name)->val;
        const char *type = ex->ce->name->val;
        zval rv, obj;
        ZVAL_OBJ(&obj, ex);
        zval *message = GET_PROPERTY(&obj, ZEND_STR_MESSAGE);
        const char *msg =
            Z_TYPE_P(message) == IS_STRING ? Z_STR_P(message)->val : "(internal error reading exception message)";
        ddtrace_log_errf("%s thrown in tracing closure for %s: %s", type, name, msg);
        if (message == &rv) {
            zval_dtor(message);
        }
    }
    ddtrace_maybe_clear_exception();

    zval_dtor(&user_args);

    if (exception) {
        EG(exception) = exception;
        EG(prev_exception) = prev_exception;

        zend_throw_exception_internal(NULL);
    }

    return keep_span;
}

static void _dd_end_span(ddtrace_span_t *span, zval *user_retval) {
    ddtrace_dispatch_t *dispatch = span->dispatch;
    dd_trace_stop_span_time(span);

    bool keep_span = true;
    if (dispatch->options & DDTRACE_DISPATCH_POSTHOOK) {
        keep_span = _dd_call_sandboxed_tracing_closure(span, user_retval);
    }

    if (keep_span) {
        ddtrace_close_span();
    } else {
        ddtrace_drop_span();
    }
}

static void _dd_update_opcode_leave(zend_execute_data *execute_data) {
    DD_PRINTF("Update opcode leave");
    EX(call) = EX(call)->prev_execute_data;
}

static void _dd_execute_fcall(ddtrace_dispatch_t *dispatch, zval *this, zend_execute_data *execute_data,
                              zval **return_value_ptr) {
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    char *error = NULL;
    zval closure;
    INIT_ZVAL(closure);
    zend_function *current_fbc = DDTRACE_G(original_context).fbc;
    zend_class_entry *executed_method_class = NULL;
    if (this) {
        executed_method_class = Z_OBJCE_P(this);
    }

    zend_function *func;

    zend_string *func_name = zend_string_init(ZEND_STRL(DDTRACE_CALLBACK_NAME), 0);
    func = EX(func);
    zend_create_closure(&closure, (zend_function *)zend_get_closure_method_def(&dispatch->callable),
                        executed_method_class, executed_method_class, this);
    if (zend_fcall_info_init(&closure, 0, &fci, &fcc, NULL, &error) != SUCCESS) {
        if (DDTRACE_G(strict_mode)) {
            const char *scope_name, *function_name;

            scope_name = (func->common.scope) ? ZSTR_VAL(func->common.scope->name) : NULL;
            function_name = ZSTR_VAL(func->common.function_name);
            if (scope_name) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "cannot set override for %s::%s - %s",
                                        scope_name, function_name, error);
            } else {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "cannot set override for %s - %s",
                                        function_name, error);
            }
        }

        if (error) {
            efree(error);
        }
        goto _exit_cleanup;
    }

    _dd_setup_fcall(execute_data, &fci, return_value_ptr);

    // Move this to closure zval before zend_fcall_info_init()
    fcc.function_handler->common.function_name = func_name;

    zend_class_entry *orig_scope = fcc.function_handler->common.scope;
    fcc.function_handler->common.scope = DDTRACE_G(original_context).calling_fbc->common.scope;
    fcc.calling_scope = DDTRACE_G(original_context).calling_fbc->common.scope;

    zend_execute_data *prev_original_execute_data = DDTRACE_G(original_context).execute_data;
    DDTRACE_G(original_context).execute_data = execute_data;

    zend_call_function(&fci, &fcc);

    DDTRACE_G(original_context).execute_data = prev_original_execute_data;

    fcc.function_handler->common.scope = orig_scope;

    zend_string_release(func_name);
    if (fci.params) {
        zend_fcall_info_args_clear(&fci, 0);
    }

_exit_cleanup:

    if (this && (EX_CALL_INFO() & ZEND_CALL_RELEASE_THIS)) {
        OBJ_RELEASE(Z_OBJ(execute_data->This));
    }
    OBJ_RELEASE(Z_OBJ(closure));
    DDTRACE_G(original_context).fbc = current_fbc;
}

static void _dd_wrap_and_run(zend_execute_data *execute_data, ddtrace_dispatch_t *dispatch) {
    zval *this = _dd_this(EX(call));
    const zend_op *opline = EX(opline);

    zval rv;
    INIT_ZVAL(rv);

    zval *return_value = (RETURN_VALUE_USED(opline) ? EX_VAR(EX(opline)->result.var) : &rv);
    _dd_execute_fcall(dispatch, this, EX(call), &return_value);

    if (!RETURN_VALUE_USED(opline)) {
        zval_dtor(&rv);
    }
}

static int _dd_opcode_default_dispatch(zend_execute_data *execute_data) {
    if (!EX(opline)->opcode) {
        return ZEND_USER_OPCODE_DISPATCH;
    }
    switch (EX(opline)->opcode) {
        case ZEND_DO_ICALL:
            if (_prev_icall_handler) {
                return _prev_icall_handler(execute_data);
            }
            break;
        case ZEND_DO_UCALL:
            if (_prev_ucall_handler) {
                return _prev_ucall_handler(execute_data);
            }
            break;
        case ZEND_DO_FCALL:
            if (_prev_fcall_handler) {
                return _prev_fcall_handler(execute_data);
            }
            break;
        case ZEND_DO_FCALL_BY_NAME:
            if (_prev_fcall_by_name_handler) {
                return _prev_fcall_by_name_handler(execute_data);
            }
            break;
    }
    return ZEND_USER_OPCODE_DISPATCH;
}

static int _dd_begin_fcall_handler(zend_execute_data *execute_data) {
    zend_function *current_fbc = EX(call)->func;
    if (!current_fbc) {
        return _dd_opcode_default_dispatch(execute_data);
    }
    ddtrace_dispatch_t *dispatch = NULL;
    if (!_dd_should_trace_call(EX(call), current_fbc, &dispatch)) {
        return _dd_opcode_default_dispatch(execute_data);
    }
    int vm_retval = _dd_opcode_default_dispatch(execute_data);
    if (vm_retval != ZEND_USER_OPCODE_DISPATCH) {
        ddtrace_log_debugf("A neighboring extension has altered the VM state for '%s()'; cannot reliably instrument",
                           ZSTR_VAL(current_fbc->common.function_name));
        return vm_retval;
    }
    /*
    Internal functions are traced from the zend_execute_internal override for the sandbox API.
    Ideally, we'd short-circuit early for internal functions, but since we have
    to support the legacy API, we have to wait until after the dispatch hash table
    lookup to determine if we can use zend_execute_internal (which only supports sandbox API).
    */
    if (current_fbc->type == ZEND_INTERNAL_FUNCTION &&
        dispatch->options & (DDTRACE_DISPATCH_POSTHOOK | DDTRACE_DISPATCH_PREHOOK)) {
        return vm_retval;
    }
    ddtrace_class_lookup_acquire(dispatch);  // protecting against dispatch being freed during php code execution
    dispatch->busy = 1;                      // guard against recursion, catching only topmost execution

    if (dispatch->options & (DDTRACE_DISPATCH_POSTHOOK | DDTRACE_DISPATCH_PREHOOK)) {
        ddtrace_span_t *span = ddtrace_open_span(EX(call), dispatch);

        if ((dispatch->options & DDTRACE_DISPATCH_PREHOOK) && _dd_call_sandboxed_tracing_closure(span, NULL) == false) {
            ddtrace_drop_span();
            dispatch->busy = 0;
            ddtrace_class_lookup_release(dispatch);
        }

        return ZEND_USER_OPCODE_DISPATCH;
    }

    // Store original context for forwarding the call from userland
    zend_function *previous_fbc = DDTRACE_G(original_context).fbc;
    DDTRACE_G(original_context).fbc = current_fbc;
    zend_function *previous_calling_fbc = DDTRACE_G(original_context).calling_fbc;

    DDTRACE_G(original_context).calling_fbc = current_fbc->common.scope ? current_fbc : execute_data->func;

    zval *this = _dd_this(EX(call));

    zend_object *previous_this = DDTRACE_G(original_context).this;
    DDTRACE_G(original_context).this = this ? Z_OBJ_P(this) : NULL;
    zend_class_entry *previous_calling_ce = DDTRACE_G(original_context).calling_ce;

    if (DDTRACE_G(original_context).this) {
        GC_ADDREF(DDTRACE_G(original_context).this);
    }
    DDTRACE_G(original_context).calling_ce = Z_OBJ(execute_data->This) ? Z_OBJ(execute_data->This)->ce : NULL;

    _dd_wrap_and_run(execute_data, dispatch);
    if (DDTRACE_G(original_context).this) {
        GC_DELREF(DDTRACE_G(original_context).this);
    }

    // Restore original context
    DDTRACE_G(original_context).calling_ce = previous_calling_ce;
    DDTRACE_G(original_context).this = previous_this;
    DDTRACE_G(original_context).calling_fbc = previous_calling_fbc;
    DDTRACE_G(original_context).fbc = previous_fbc;

    _dd_update_opcode_leave(execute_data);

    dispatch->busy = 0;
    ddtrace_class_lookup_release(dispatch);

    EX(opline)++;

    return ZEND_USER_OPCODE_LEAVE;
}

static int _dd_return_handler(zend_execute_data *execute_data) {
    ddtrace_span_t *span = DDTRACE_G(open_spans_top);
    if (span && span->call == execute_data) {
        zval rv;
        zval *retval = NULL;
        switch (EX(opline)->op1_type) {
            case IS_CONST:
#if PHP_VERSION_ID >= 70300
                retval = RT_CONSTANT(EX(opline), EX(opline)->op1);
#else
                retval = EX_CONSTANT(EX(opline)->op1);
#endif
                break;
            case IS_TMP_VAR:
            case IS_VAR:
            case IS_CV:
                retval = EX_VAR(EX(opline)->op1.var);
                break;
                /* IS_UNUSED is NULL */
        }
        if (!retval || Z_TYPE_INFO_P(retval) == IS_UNDEF) {
            ZVAL_NULL(&rv);
            retval = &rv;
        }
        // Save pointer to dispatch since span can be dropped from _dd_end_span()
        ddtrace_dispatch_t *dispatch = span->dispatch;
        _dd_end_span(span, retval);
        if (dispatch) {
            dispatch->busy = 0;
            ddtrace_class_lookup_release(dispatch);
        }
    }

    return _prev_return_handler ? _prev_return_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static int _dd_handle_exception_handler(zend_execute_data *execute_data) {
    ddtrace_span_t *span = DDTRACE_G(open_spans_top);
    if (span && span->call == execute_data) {
        zval retval;
        ZVAL_NULL(&retval);
        if (EG(exception)) {
            _dd_span_attach_exception(span, EG(exception));
        }
        // Save pointer to dispatch since span can be dropped from _dd_end_span()
        ddtrace_dispatch_t *dispatch = span->dispatch;
        _dd_end_span(span, &retval);
        if (dispatch) {
            dispatch->busy = 0;
            ddtrace_class_lookup_release(dispatch);
        }
    }

    return _prev_handle_exception_handler ? _prev_handle_exception_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static int _dd_exit_handler(zend_execute_data *execute_data) {
    ddtrace_span_t *span;
    while ((span = DDTRACE_G(open_spans_top))) {
        zval retval;
        ZVAL_NULL(&retval);
        // Save pointer to dispatch since span can be dropped from _dd_end_span()
        ddtrace_dispatch_t *dispatch = span->dispatch;
        _dd_end_span(span, &retval);
        if (dispatch) {
            dispatch->busy = 0;
            ddtrace_class_lookup_release(dispatch);
        }
    }

    return _prev_exit_handler ? _prev_exit_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

void ddtrace_opcode_minit(void) {
    _prev_icall_handler = zend_get_user_opcode_handler(ZEND_DO_ICALL);
    _prev_ucall_handler = zend_get_user_opcode_handler(ZEND_DO_UCALL);
    _prev_fcall_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL);
    _prev_fcall_by_name_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL_BY_NAME);
    zend_set_user_opcode_handler(ZEND_DO_ICALL, _dd_begin_fcall_handler);
    zend_set_user_opcode_handler(ZEND_DO_UCALL, _dd_begin_fcall_handler);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, _dd_begin_fcall_handler);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, _dd_begin_fcall_handler);

    _prev_return_handler = zend_get_user_opcode_handler(ZEND_RETURN);
    zend_set_user_opcode_handler(ZEND_RETURN, _dd_return_handler);
    _prev_handle_exception_handler = zend_get_user_opcode_handler(ZEND_HANDLE_EXCEPTION);
    zend_set_user_opcode_handler(ZEND_HANDLE_EXCEPTION, _dd_handle_exception_handler);
    _prev_exit_handler = zend_get_user_opcode_handler(ZEND_EXIT);
    zend_set_user_opcode_handler(ZEND_EXIT, _dd_exit_handler);
}

void ddtrace_opcode_mshutdown(void) {
    zend_set_user_opcode_handler(ZEND_DO_ICALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_UCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, NULL);

    zend_set_user_opcode_handler(ZEND_RETURN, NULL);
    zend_set_user_opcode_handler(ZEND_EXIT, NULL);
}

void ddtrace_execute_internal_minit(void) {
    _prev_execute_internal = !zend_execute_internal ? execute_internal : zend_execute_internal;
    zend_execute_internal = _dd_execute_internal;
}

void ddtrace_execute_internal_mshutdown(void) {
    zend_execute_internal = _prev_execute_internal != execute_internal ? _prev_execute_internal : NULL;
}

static void _dd_execute_internal(zend_execute_data *execute_data, zval *return_value) {
    zend_function *current_fbc = EX(func);
    if (!current_fbc) {
        _prev_execute_internal(execute_data, return_value);
        return;
    }
    ddtrace_dispatch_t *dispatch = NULL;
    if (!_dd_should_trace_call(execute_data, current_fbc, &dispatch)) {
        _prev_execute_internal(execute_data, return_value);
        return;
    }
    // Legacy API not supported from zend_execute_internal override
    if (dispatch->options & DDTRACE_DISPATCH_INNERHOOK) {
        ddtrace_log_debugf("Legacy API not supported for %s()", ZSTR_VAL(current_fbc->common.function_name));
        _prev_execute_internal(execute_data, return_value);
        return;
    }

    ddtrace_class_lookup_acquire(dispatch);  // protecting against dispatch being freed during php code execution
    dispatch->busy = 1;                      // guard against recursion, catching only topmost execution

    ddtrace_span_t *span = ddtrace_open_span(execute_data, dispatch);
    if ((dispatch->options & DDTRACE_DISPATCH_PREHOOK) && _dd_call_sandboxed_tracing_closure(span, NULL) == false) {
        ddtrace_drop_span();
        dispatch->busy = 0;
        ddtrace_class_lookup_release(dispatch);

        _prev_execute_internal(execute_data, return_value);
        return;
    }
    _prev_execute_internal(execute_data, return_value);
    if (span == DDTRACE_G(open_spans_top)) {
        if (EG(exception)) {
            _dd_span_attach_exception(span, EG(exception));
        }
        _dd_end_span(span, return_value);
    } else if (get_dd_trace_debug()) {
        ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync",
                         ZSTR_VAL(current_fbc->common.function_name));
    }

    dispatch->busy = 0;
    ddtrace_class_lookup_release(dispatch);
}
