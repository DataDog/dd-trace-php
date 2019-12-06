#include "engine_hooks.h"

#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>

#include <ext/spl/spl_exceptions.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "env_config.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

// True gloals; only modify in minit/mshutdown
static user_opcode_handler_t _prev_icall_handler;
static user_opcode_handler_t _prev_ucall_handler;
static user_opcode_handler_t _prev_fcall_handler;
static user_opcode_handler_t _prev_fcall_by_name_handler;
static user_opcode_handler_t _prev_exit_handler;

#if PHP_VERSION_ID < 70100
#define RETURN_VALUE_USED(opline) (!((opline)->result_type & EXT_TYPE_UNUSED))
#else
#define RETURN_VALUE_USED(opline) ((opline)->result_type != IS_UNUSED)
#endif

static zval *ddtrace_this(zend_execute_data *call) {
    if (Z_TYPE(call->This) == IS_OBJECT && Z_OBJ(call->This) != NULL) {
        return &call->This;
    }
    return NULL;
}

static BOOL_T ddtrace_should_trace_call(zend_execute_data *execute_data, zend_function **fbc,
                                        ddtrace_dispatch_t **dispatch) {
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

    zval *this = ddtrace_this(EX(call));
    *dispatch = ddtrace_find_dispatch(this, *fbc, &fname);
    zval_ptr_dtor(&fname);
    if (!*dispatch || (*dispatch)->busy) {
        return FALSE;
    }
    if (ddtrace_tracer_is_limited() && ((*dispatch)->options & DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED) == 0) {
        return FALSE;
    }

    return TRUE;
}

static void ddtrace_copy_function_args(zend_execute_data *call, zval *user_args) {
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

static void ddtrace_span_attach_exception(ddtrace_span_t *span, ddtrace_exception_t *exception) {
    if (exception) {
        GC_ADDREF(exception);
        span->exception = exception;
    }
}

static void ddtrace_setup_fcall(zend_execute_data *execute_data, zend_fcall_info *fci, zval **result) {
    fci->param_count = ZEND_CALL_NUM_ARGS(execute_data);
    fci->params = fci->param_count ? ZEND_CALL_ARG(execute_data, 1) : NULL;
    fci->retval = *result;
}

static int ddtrace_forward_call(zend_execute_data *execute_data, zend_function *fbc, zval *return_value,
                                zend_fcall_info *fci, zend_fcall_info_cache *fcc) {
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

static BOOL_T ddtrace_execute_tracing_closure(zval *callable, zval *span_data, zend_execute_data *call, zval *user_args,
                                              zval *user_retval, zend_object *exception) {
    BOOL_T status = TRUE;
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
    zval *this = ddtrace_this(call);

    if (zend_fcall_info_init(callable, 0, &fci, &fcc, NULL, NULL) == FAILURE) {
        ddtrace_log_debug("Could not init tracing closure");
        return FALSE;
    }

    if (this) {
        BOOL_T is_instance_method = (call->func->common.fn_flags & ZEND_ACC_STATIC) ? FALSE : TRUE;
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
    fcc.called_scope = zend_get_called_scope(call);
    // Give the tracing closure access to private & protected class members
    fcc.function_handler->common.scope = fcc.called_scope;

    if (zend_call_function(&fci, &fcc) == FAILURE) {
        ddtrace_log_debug("Could not execute tracing closure");
        status = FALSE;
    } else if (Z_TYPE(rv) == IS_FALSE) {
        status = FALSE;
    }

    zend_fcall_info_args_clear(&fci, 0);
    return status;
}

static void _end_span(ddtrace_span_t *span, zval *user_retval) {
    zend_execute_data *call = span->call;
    ddtrace_dispatch_t *dispatch = span->dispatch;
    zend_object *exception = NULL, *prev_exception = NULL;
    zval user_args;

    dd_trace_stop_span_time(span);

    ddtrace_copy_function_args(call, &user_args);
    if (EG(exception)) {
        exception = EG(exception);
        EG(exception) = NULL;
        prev_exception = EG(prev_exception);
        EG(prev_exception) = NULL;
        ddtrace_span_attach_exception(span, exception);
        zend_clear_exception();
    }

    BOOL_T keep_span = TRUE;
    if (Z_TYPE(dispatch->callable) == IS_OBJECT) {
        ddtrace_error_handling eh;
        ddtrace_backup_error_handling(&eh, EH_THROW);
        EG(error_reporting) = 0;

        keep_span = ddtrace_execute_tracing_closure(&dispatch->callable, span->span_data, call, &user_args, user_retval,
                                                    exception);

        ddtrace_restore_error_handling(&eh);
        // If the tracing closure threw an exception, ignore it to not impact the original call
        if (EG(exception)) {
            ddtrace_log_debug("Exeception thrown in the tracing closure");
            if (!DDTRACE_G(strict_mode)) {
                zend_clear_exception();
            }
        }
    }

    if (keep_span == TRUE) {
        ddtrace_close_span();
    } else {
        ddtrace_drop_span();
    }

    zval_dtor(&user_args);

    if (exception) {
        EG(exception) = exception;
        EG(prev_exception) = prev_exception;

        zend_throw_exception_internal(NULL);
    }
}

static void ddtrace_trace_dispatch(ddtrace_dispatch_t *dispatch, zend_function *fbc, zend_execute_data *execute_data) {
    const zend_op *opline = EX(opline);

    zval rv, *user_retval;
    ZVAL_NULL(&rv);
    user_retval = (RETURN_VALUE_USED(opline) ? EX_VAR(opline->result.var) : &rv);

    ddtrace_span_t *span = ddtrace_open_span(EX(call), dispatch);
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    ddtrace_forward_call(EX(call), fbc, user_retval, &fci, &fcc);

    _end_span(span, user_retval);

    zend_fcall_info_args_clear(&fci, 0);
    zval_dtor(&rv);

    // Since zend_leave_helper isn't run we have to dtor $this here
    // https://lxr.room11.org/xref/php-src%407.4/Zend/zend_vm_def.h#2888
    if (ZEND_CALL_INFO(EX(call)) & ZEND_CALL_RELEASE_THIS) {
        OBJ_RELEASE(Z_OBJ(EX(call)->This));
    }
    // Restore call for internal functions
    EX(call) = EX(call)->prev_execute_data;
}

static void update_opcode_leave(zend_execute_data *execute_data) {
    DD_PRINTF("Update opcode leave");
    EX(call) = EX(call)->prev_execute_data;
}

static void execute_fcall(ddtrace_dispatch_t *dispatch, zval *this, zend_execute_data *execute_data,
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

    ddtrace_setup_fcall(execute_data, &fci, return_value_ptr);

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

static void wrap_and_run(zend_execute_data *execute_data, ddtrace_dispatch_t *dispatch) {
    zval *this = ddtrace_this(EX(call));
    const zend_op *opline = EX(opline);

    zval rv;
    INIT_ZVAL(rv);

    zval *return_value = (RETURN_VALUE_USED(opline) ? EX_VAR(EX(opline)->result.var) : &rv);
    execute_fcall(dispatch, this, EX(call), &return_value);

    if (!RETURN_VALUE_USED(opline)) {
        zval_dtor(&rv);
    }
}

static int ddtrace_opcode_default_dispatch(zend_execute_data *execute_data) {
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

int ddtrace_wrap_fcall(zend_execute_data *execute_data) {
    zend_function *current_fbc = NULL;
    ddtrace_dispatch_t *dispatch = NULL;
    if (!ddtrace_should_trace_call(execute_data, &current_fbc, &dispatch)) {
        return ddtrace_opcode_default_dispatch(execute_data);
    }
    ddtrace_class_lookup_acquire(dispatch);  // protecting against dispatch being freed during php code execution
    dispatch->busy = 1;                      // guard against recursion, catching only topmost execution

    if (dispatch->options & DDTRACE_DISPATCH_POSTHOOK) {
        ddtrace_trace_dispatch(dispatch, current_fbc, execute_data);
    } else {
        // Store original context for forwarding the call from userland
        zend_function *previous_fbc = DDTRACE_G(original_context).fbc;
        DDTRACE_G(original_context).fbc = current_fbc;
        zend_function *previous_calling_fbc = DDTRACE_G(original_context).calling_fbc;

        DDTRACE_G(original_context).calling_fbc = current_fbc->common.scope ? current_fbc : execute_data->func;

        zval *this = ddtrace_this(EX(call));

        zend_object *previous_this = DDTRACE_G(original_context).this;
        DDTRACE_G(original_context).this = this ? Z_OBJ_P(this) : NULL;
        zend_class_entry *previous_calling_ce = DDTRACE_G(original_context).calling_ce;

        if (DDTRACE_G(original_context).this) {
            GC_ADDREF(DDTRACE_G(original_context).this);
        }
        DDTRACE_G(original_context).calling_ce = Z_OBJ(execute_data->This) ? Z_OBJ(execute_data->This)->ce : NULL;

        wrap_and_run(execute_data, dispatch);
        if (DDTRACE_G(original_context).this) {
            GC_DELREF(DDTRACE_G(original_context).this);
        }

        // Restore original context
        DDTRACE_G(original_context).calling_ce = previous_calling_ce;
        DDTRACE_G(original_context).this = previous_this;
        DDTRACE_G(original_context).calling_fbc = previous_calling_fbc;
        DDTRACE_G(original_context).fbc = previous_fbc;

        update_opcode_leave(execute_data);
    }

    dispatch->busy = 0;
    ddtrace_class_lookup_release(dispatch);

    EX(opline)++;

    return ZEND_USER_OPCODE_LEAVE;
}

static int ddtrace_exit_handler(zend_execute_data *execute_data) {
    ddtrace_span_t *span;
    while ((span = DDTRACE_G(open_spans_top))) {
        zval retval;
        ZVAL_NULL(&retval);
        _end_span(span, &retval);
        span->dispatch->busy = 0;
        ddtrace_class_lookup_release(span->dispatch);
    }

    return _prev_exit_handler ? _prev_exit_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

void ddtrace_opcode_minit(void) {
    _prev_icall_handler = zend_get_user_opcode_handler(ZEND_DO_ICALL);
    _prev_ucall_handler = zend_get_user_opcode_handler(ZEND_DO_UCALL);
    _prev_fcall_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL);
    _prev_fcall_by_name_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL_BY_NAME);
    zend_set_user_opcode_handler(ZEND_DO_ICALL, ddtrace_wrap_fcall);
    zend_set_user_opcode_handler(ZEND_DO_UCALL, ddtrace_wrap_fcall);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, ddtrace_wrap_fcall);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, ddtrace_wrap_fcall);

    _prev_exit_handler = zend_get_user_opcode_handler(ZEND_EXIT);
    zend_set_user_opcode_handler(ZEND_EXIT, ddtrace_exit_handler);
}

void ddtrace_opcode_mshutdown(void) {
    zend_set_user_opcode_handler(ZEND_DO_ICALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_UCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, NULL);

    zend_set_user_opcode_handler(ZEND_EXIT, NULL);
}
