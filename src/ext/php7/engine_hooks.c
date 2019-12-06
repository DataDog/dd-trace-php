#include "engine_hooks.h"

#include <Zend/zend_closures.h>
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

// True gloals; only modify in minit/mshutdown
static user_opcode_handler_t _prev_icall_handler;
static user_opcode_handler_t _prev_ucall_handler;
static user_opcode_handler_t _prev_fcall_handler;
static user_opcode_handler_t _prev_fcall_by_name_handler;

#if PHP_VERSION_ID < 70100
#define RETURN_VALUE_USED(opline) (!((opline)->result_type & EXT_TYPE_UNUSED))
#else
#define RETURN_VALUE_USED(opline) ((opline)->result_type != IS_UNUSED)
#endif

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

void ddtrace_trace_dispatch(ddtrace_dispatch_t *dispatch, zend_function *fbc,
                            zend_execute_data *execute_data TSRMLS_DC) {
    int fcall_status;
    const zend_op *opline = EX(opline);

    zval *user_retval = NULL, *user_args;
    zend_object *exception = NULL, *prev_exception = NULL;
    zval rv, user_args_zv;
    INIT_ZVAL(user_args_zv);
    INIT_ZVAL(rv);
    user_args = &user_args_zv;
    user_retval = (RETURN_VALUE_USED(opline) ? EX_VAR(opline->result.var) : &rv);

    ddtrace_span_t *span = ddtrace_open_span(TSRMLS_C);
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    fcall_status = ddtrace_forward_call(EX(call), fbc, user_retval, &fci, &fcc TSRMLS_CC);
    dd_trace_stop_span_time(span);

    ddtrace_copy_function_args(execute_data, user_args);
    if (EG(exception)) {
        exception = EG(exception);
        EG(exception) = NULL;
        prev_exception = EG(prev_exception);
        EG(prev_exception) = NULL;
        ddtrace_span_attach_exception(span, exception);
        zend_clear_exception(TSRMLS_C);
    }

    BOOL_T keep_span = TRUE;
    if (fcall_status == SUCCESS && Z_TYPE(dispatch->callable) == IS_OBJECT) {
        zend_error_handling error_handling;
        int orig_error_reporting = EG(error_reporting);
        EG(error_reporting) = 0;
        zend_replace_error_handling(EH_THROW, NULL, &error_handling TSRMLS_CC);
        keep_span = ddtrace_execute_tracing_closure(&dispatch->callable, span->span_data, execute_data, user_args,
                                                    user_retval, exception TSRMLS_CC);
        zend_restore_error_handling(&error_handling TSRMLS_CC);
        EG(error_reporting) = orig_error_reporting;
        // If the tracing closure threw an exception, ignore it to not impact the original call
        if (EG(exception)) {
            ddtrace_log_debug("Exeception thrown in the tracing closure");
            if (!DDTRACE_G(strict_mode)) {
                zend_clear_exception(TSRMLS_C);
            }
        }
    }

    zval_ptr_dtor(user_args);

    if (keep_span == TRUE) {
        ddtrace_close_span(TSRMLS_C);
    } else {
        ddtrace_drop_span(TSRMLS_C);
    }

    if (exception) {
        EG(exception) = exception;
        EG(prev_exception) = prev_exception;

        zend_throw_exception_internal(NULL TSRMLS_CC);
    }

    zend_fcall_info_args_clear(&fci, 0);
    if (!RETURN_VALUE_USED(opline)) {
        zval_dtor(&rv);
    }

    // Since zend_leave_helper isn't run we have to dtor $this here
    // https://lxr.room11.org/xref/php-src%407.4/Zend/zend_vm_def.h#2888
    if (ZEND_CALL_INFO(EX(call)) & ZEND_CALL_RELEASE_THIS) {
        OBJ_RELEASE(Z_OBJ(EX(call)->This));
    }
    // Restore call for internal functions
    EX(call) = EX(call)->prev_execute_data;
}

static void update_opcode_leave(zend_execute_data *execute_data TSRMLS_DC) {
    DD_PRINTF("Update opcode leave");
    EX(call) = EX(call)->prev_execute_data;
}
static void execute_fcall(ddtrace_dispatch_t *dispatch, zval *this, zend_execute_data *execute_data,
                          zval **return_value_ptr TSRMLS_DC) {
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
                        executed_method_class, executed_method_class, this TSRMLS_CC);
    if (zend_fcall_info_init(&closure, 0, &fci, &fcc, NULL, &error TSRMLS_CC) != SUCCESS) {
        if (DDTRACE_G(strict_mode)) {
            const char *scope_name, *function_name;

            scope_name = (func->common.scope) ? ZSTR_VAL(func->common.scope->name) : NULL;
            function_name = ZSTR_VAL(func->common.function_name);
            if (scope_name) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                                        "cannot set override for %s::%s - %s", scope_name, function_name, error);
            } else {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC, "cannot set override for %s - %s",
                                        function_name, error);
            }
        }

        if (error) {
            efree(error);
        }
        goto _exit_cleanup;
    }

    ddtrace_setup_fcall(execute_data, &fci, return_value_ptr TSRMLS_CC);

    // Move this to closure zval before zend_fcall_info_init()
    fcc.function_handler->common.function_name = func_name;

    zend_class_entry *orig_scope = fcc.function_handler->common.scope;
    fcc.function_handler->common.scope = DDTRACE_G(original_context).calling_fbc->common.scope;
    fcc.calling_scope = DDTRACE_G(original_context).calling_fbc->common.scope;

    zend_execute_data *prev_original_execute_data = DDTRACE_G(original_context).execute_data;
    DDTRACE_G(original_context).execute_data = execute_data;

    zend_call_function(&fci, &fcc TSRMLS_CC);

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

static zend_always_inline void wrap_and_run(zend_execute_data *execute_data, ddtrace_dispatch_t *dispatch TSRMLS_DC) {
    zval *this = ddtrace_this(execute_data);
    const zend_op *opline = EX(opline);

    zval rv;
    INIT_ZVAL(rv);

    zval *return_value = (RETURN_VALUE_USED(opline) ? EX_VAR(EX(opline)->result.var) : &rv);
    execute_fcall(dispatch, this, EX(call), &return_value TSRMLS_CC);

    if (!RETURN_VALUE_USED(opline)) {
        zval_dtor(&rv);
    }
}

int ddtrace_wrap_fcall(zend_execute_data *execute_data TSRMLS_DC) {
    zend_function *current_fbc = NULL;
    ddtrace_dispatch_t *dispatch = NULL;
    if (!ddtrace_should_trace_call(execute_data, &current_fbc, &dispatch TSRMLS_CC)) {
        return ddtrace_opcode_default_dispatch(execute_data TSRMLS_CC);
    }
    ddtrace_class_lookup_acquire(dispatch);  // protecting against dispatch being freed during php code execution
    dispatch->busy = 1;                      // guard against recursion, catching only topmost execution

    if (dispatch->run_as_postprocess) {
        ddtrace_trace_dispatch(dispatch, current_fbc, execute_data TSRMLS_CC);
    } else {
        // Store original context for forwarding the call from userland
        zend_function *previous_fbc = DDTRACE_G(original_context).fbc;
        DDTRACE_G(original_context).fbc = current_fbc;
        zend_function *previous_calling_fbc = DDTRACE_G(original_context).calling_fbc;

        DDTRACE_G(original_context).calling_fbc = current_fbc->common.scope ? current_fbc : execute_data->func;

        zval *this = ddtrace_this(execute_data);

        zend_object *previous_this = DDTRACE_G(original_context).this;
        DDTRACE_G(original_context).this = this ? Z_OBJ_P(this) : NULL;
        zend_class_entry *previous_calling_ce = DDTRACE_G(original_context).calling_ce;

        if (DDTRACE_G(original_context).this) {
            GC_ADDREF(DDTRACE_G(original_context).this);
        }
        DDTRACE_G(original_context).calling_ce = Z_OBJ(execute_data->This) ? Z_OBJ(execute_data->This)->ce : NULL;

        wrap_and_run(execute_data, dispatch TSRMLS_CC);
        if (DDTRACE_G(original_context).this) {
            GC_DELREF(DDTRACE_G(original_context).this);
        }

        // Restore original context
        DDTRACE_G(original_context).calling_ce = previous_calling_ce;
        DDTRACE_G(original_context).this = previous_this;
        DDTRACE_G(original_context).calling_fbc = previous_calling_fbc;
        DDTRACE_G(original_context).fbc = previous_fbc;

        update_opcode_leave(execute_data TSRMLS_CC);
    }

    dispatch->busy = 0;
    ddtrace_class_lookup_release(dispatch);

    EX(opline)++;

    return ZEND_USER_OPCODE_LEAVE;
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
}

void ddtrace_opcode_mshutdown(void) {
    zend_set_user_opcode_handler(ZEND_DO_ICALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_UCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, NULL);
}

int ddtrace_opcode_default_dispatch(zend_execute_data *execute_data) {
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
