#include "engine_hooks.h"

#include <Zend/zend.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "dispatch_compat.h"
#include "dispatch_compat_php5.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

// True gloals; only modify in minit/mshutdown
static user_opcode_handler_t _prev_fcall_handler;
static user_opcode_handler_t _prev_fcall_by_name_handler;

#define RETURN_VALUE_USED(opline) (!((opline)->result_type & EXT_TYPE_UNUSED))

#define CTOR_CALL_BIT 0x1
#define CTOR_USED_BIT 0x2
#define DECODE_CTOR(ce) ((zend_class_entry *)(((zend_uintptr_t)(ce)) & ~(CTOR_CALL_BIT | CTOR_USED_BIT)))

static void update_opcode_leave(zend_execute_data *execute_data TSRMLS_DC) {
    DD_PRINTF("Update opcode leave");
#if PHP_VERSION_ID < 50500
    EX(function_state).function = (zend_function *)EX(op_array);
    EX(function_state).arguments = NULL;
    EG(opline_ptr) = &EX(opline);
    EG(active_op_array) = EX(op_array);

    EG(return_value_ptr_ptr) = EX(original_return_value);
    EX(original_return_value) = NULL;

    EG(active_symbol_table) = EX(symbol_table);

    EX(object) = EX(current_object);
    EX(called_scope) = DECODE_CTOR(EX(called_scope));

    zend_arg_types_stack_3_pop(&EG(arg_types_stack), &EX(called_scope), &EX(current_object), &EX(fbc));
    zend_vm_stack_clear_multiple(TSRMLS_C);
#else
    zend_vm_stack_clear_multiple(0 TSRMLS_CC);
    EX(call)--;
#endif
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

    const char *func_name = DDTRACE_CALLBACK_NAME;
    func = datadog_current_function(execute_data);

    zend_function *callable = (zend_function *)zend_get_closure_method_def(&dispatch->callable TSRMLS_CC);

    // convert passed callable to not be static as we're going to bind it to *this
    if (this) {
        callable->common.fn_flags &= ~ZEND_ACC_STATIC;
    }

    zend_create_closure(&closure, callable, executed_method_class, this TSRMLS_CC);
    if (zend_fcall_info_init(&closure, 0, &fci, &fcc, NULL, &error TSRMLS_CC) != SUCCESS) {
        if (DDTRACE_G(strict_mode)) {
            const char *scope_name, *function_name;

            scope_name = (func->common.scope) ? func->common.scope->name : NULL;
            function_name = func->common.function_name;
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

    zend_execute_data *prev_original_execute_data = DDTRACE_G(original_context).execute_data;
    DDTRACE_G(original_context).execute_data = execute_data;

    zval *prev_original_function_name = DDTRACE_G(original_context).function_name;
    DDTRACE_G(original_context).function_name = (*EG(opline_ptr))->op1.zv;

    zend_call_function(&fci, &fcc TSRMLS_CC);

    DDTRACE_G(original_context).function_name = prev_original_function_name;

    DDTRACE_G(original_context).execute_data = prev_original_execute_data;

    if (fci.params) {
        efree(fci.params);
    }

_exit_cleanup:

    if (this) {
        Z_DELREF_P(this);
    }
    Z_DELREF(closure);
    zval_dtor(&closure);
    DDTRACE_G(original_context).fbc = current_fbc;
}

static zend_always_inline void wrap_and_run(zend_execute_data *execute_data, ddtrace_dispatch_t *dispatch TSRMLS_DC) {
    zval *this = ddtrace_this(execute_data);

#if PHP_VERSION_ID < 50500
    zval *original_object = EX(object);
    if (EX(opline)->opcode == ZEND_DO_FCALL) {
        zend_op *opline = EX(opline);
        zend_ptr_stack_3_push(&EG(arg_types_stack), FBC(), EX(object), EX(called_scope));

        if (CACHED_PTR(opline->op1.literal->cache_slot)) {
            EX(function_state).function = CACHED_PTR(opline->op1.literal->cache_slot);
        } else {
            EX(function_state).function = DDTRACE_G(original_context).fbc;
            CACHE_PTR(opline->op1.literal->cache_slot, EX(function_state).function);
        }

        EX(object) = NULL;
    }
    if (this) {
        EX(object) = original_object;
    }
#endif
    const zend_op *opline = EX(opline);

#if PHP_VERSION_ID < 50500
#define EX_T(offset) (*(temp_variable *)((char *)EX(Ts) + offset))
    zval rv;
    INIT_ZVAL(rv);

    zval **return_value = NULL;
    zval *rv_ptr = &rv;

    if (RETURN_VALUE_USED(opline)) {
        EX_T(opline->result.var).var.ptr = &EG(uninitialized_zval);
        EX_T(opline->result.var).var.ptr_ptr = NULL;

        return_value = NULL;
    } else {
        return_value = &rv_ptr;
    }

    if (RETURN_VALUE_USED(opline)) {
        temp_variable *ret = &EX_T(opline->result.var);

        if (EG(return_value_ptr_ptr) && *EG(return_value_ptr_ptr)) {
            ret->var.ptr = *EG(return_value_ptr_ptr);
            ret->var.ptr_ptr = EG(return_value_ptr_ptr);
        } else {
            ret->var.ptr = NULL;
            ret->var.ptr_ptr = &ret->var.ptr;
        }

        ret->var.fcall_returned_reference =
            (DDTRACE_G(original_context).fbc->common.fn_flags & ZEND_ACC_RETURN_REFERENCE) != 0;
        return_value = ret->var.ptr_ptr;
    }

    execute_fcall(dispatch, this, execute_data, return_value TSRMLS_CC);
    EG(return_value_ptr_ptr) = EX(original_return_value);

    if (!RETURN_VALUE_USED(opline) && return_value && *return_value) {
        zval_delref_p(*return_value);
        if (Z_REFCOUNT_PP(return_value) == 0) {
            efree(*return_value);
            *return_value = NULL;
        }
    }

#else
    zval *return_value = NULL;
    execute_fcall(dispatch, this, execute_data, &return_value TSRMLS_CC);

    if (return_value != NULL) {
        if (RETURN_VALUE_USED(opline)) {
            EX_TMP_VAR(execute_data, opline->result.var)->var.ptr = return_value;
        } else {
            zval_ptr_dtor(&return_value);
        }
    }
#endif
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

void ddtrace_trace_dispatch(ddtrace_dispatch_t *dispatch, zend_function *fbc,
                            zend_execute_data *execute_data TSRMLS_DC) {
    int fcall_status;
    const zend_op *opline = EX(opline);

    zval *user_retval = NULL, *user_args;
    ALLOC_INIT_ZVAL(user_retval);
    ALLOC_INIT_ZVAL(user_args);
    zval *exception = NULL, *prev_exception = NULL;

    ddtrace_span_t *span = ddtrace_open_span(TSRMLS_C);

    fcall_status = ddtrace_forward_call(execute_data, fbc, user_retval TSRMLS_CC);
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
        zend_replace_error_handling(EH_SUPPRESS, NULL, &error_handling TSRMLS_CC);
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

    zval_ptr_dtor(&user_args);

    if (keep_span == TRUE) {
        ddtrace_close_span(TSRMLS_C);
    } else {
        ddtrace_drop_span(TSRMLS_C);
    }

    if (exception) {
        EG(exception) = exception;
        EG(prev_exception) = prev_exception;
        EG(opline_before_exception) = (zend_op *)opline;
        EG(current_execute_data)->opline = EG(exception_op);
    }

#if PHP_VERSION_ID < 50500
    (void)opline;  // TODO Make work on PHP 5.4
#else
    // Put the original return value on the opline
    if (RETURN_VALUE_USED(opline)) {
        EX_TMP_VAR(execute_data, opline->result.var)->var.ptr = user_retval;
    } else {
        zval_ptr_dtor(&user_retval);
    }
#endif

#if PHP_VERSION_ID < 50500
    // Free any remaining args
    zend_vm_stack_clear_multiple(TSRMLS_C);
#else
    // Since zend_leave_helper isn't run we have to dtor $this here
    // https://lxr.room11.org/xref/php-src%405.6/Zend/zend_vm_def.h#1905
    if (EX(call)->object) {
        zval_ptr_dtor(&EX(call)->object);
    }
    // Free any remaining args
    zend_vm_stack_clear_multiple(0 TSRMLS_CC);
    // Restore call for internal functions
    EX(call)--;
#endif
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
        DDTRACE_G(original_context).calling_fbc =
            execute_data->function_state.function && execute_data->function_state.function->common.scope
                ? execute_data->function_state.function
                : current_fbc;
        zval *this = ddtrace_this(execute_data);
        zval *previous_this = DDTRACE_G(original_context).this;
        DDTRACE_G(original_context).this = this;
        zend_class_entry *previous_calling_ce = DDTRACE_G(original_context).calling_ce;
        DDTRACE_G(original_context).calling_ce = DDTRACE_G(original_context).calling_fbc->common.scope;

        wrap_and_run(execute_data, dispatch TSRMLS_CC);

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
    _prev_fcall_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL);
    _prev_fcall_by_name_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL_BY_NAME);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, ddtrace_wrap_fcall);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, ddtrace_wrap_fcall);
}

void ddtrace_opcode_mshutdown(void) {
    zend_set_user_opcode_handler(ZEND_DO_FCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, NULL);
}

int ddtrace_opcode_default_dispatch(zend_execute_data *execute_data TSRMLS_DC) {
    if (!EX(opline)->opcode) {
        return ZEND_USER_OPCODE_DISPATCH;
    }
    switch (EX(opline)->opcode) {
        case ZEND_DO_FCALL:
            if (_prev_fcall_handler) {
                return _prev_fcall_handler(execute_data TSRMLS_CC);
            }
            break;

        case ZEND_DO_FCALL_BY_NAME:
            if (_prev_fcall_by_name_handler) {
                return _prev_fcall_by_name_handler(execute_data TSRMLS_CC);
            }
            break;
    }
    return ZEND_USER_OPCODE_DISPATCH;
}
