#include "engine_hooks.h"

#include <Zend/zend.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

int ddtrace_resource = -1;

// True gloals; only modify in minit/mshutdown
static user_opcode_handler_t _prev_fcall_handler;
static user_opcode_handler_t _prev_fcall_by_name_handler;
static user_opcode_handler_t _prev_exit_handler;

#define RETURN_VALUE_USED(opline) (!((opline)->result_type & EXT_TYPE_UNUSED))

#define CTOR_CALL_BIT 0x1
#define CTOR_USED_BIT 0x2
#define DECODE_CTOR(ce) ((zend_class_entry *)(((zend_uintptr_t)(ce)) & ~(CTOR_CALL_BIT | CTOR_USED_BIT)))

static zval *ddtrace_this(zend_execute_data *execute_data) {
    zval *this = NULL;
#if PHP_VERSION_ID < 50500
    if (EX(opline)->opcode != ZEND_DO_FCALL && EX(object)) {
        this = EX(object);
    }
#else
    if (EX(opline)->opcode != ZEND_DO_FCALL) {
        this = EX(call) ? EX(call)->object : NULL;
    }
#endif
    if (this && Z_TYPE_P(this) != IS_OBJECT) {
        this = NULL;
    }

    return this;
}

static void **vm_stack_push_args_with_copy(int count TSRMLS_DC) {
    zend_vm_stack p = EG(argument_stack);

    zend_vm_stack_extend(count + 1 TSRMLS_CC);

    EG(argument_stack)->top += count;
    *(EG(argument_stack)->top) = (void *)(zend_uintptr_t)count;
    while (count-- > 0) {
        void *data = *(--p->top);

        if (UNEXPECTED(p->top == ZEND_VM_STACK_ELEMETS(p))) {
            zend_vm_stack r = p;

            EG(argument_stack)->prev = p->prev;
            p = p->prev;
            efree(r);
        }
        *(ZEND_VM_STACK_ELEMETS(EG(argument_stack)) + count) = data;
    }
    return EG(argument_stack)->top++;
}

static void **vm_stack_push_args(int count TSRMLS_DC) {
    if (UNEXPECTED(EG(argument_stack)->top - ZEND_VM_STACK_ELEMETS(EG(argument_stack)) < count) ||
        UNEXPECTED(EG(argument_stack)->top == EG(argument_stack)->end)) {
        return vm_stack_push_args_with_copy(count TSRMLS_CC);
    }
    *(EG(argument_stack)->top) = (void *)(zend_uintptr_t)count;
    return EG(argument_stack)->top++;
}

static void setup_fcal_name(zend_execute_data *execute_data, zend_fcall_info *fci, zval **result TSRMLS_DC) {
    int argc = EX(opline)->extended_value + NUM_ADDITIONAL_ARGS();
    fci->param_count = argc;

    if (NUM_ADDITIONAL_ARGS()) {
        vm_stack_push_args(fci->param_count TSRMLS_CC);
    } else {
        if (fci->param_count) {
            EX(function_state).arguments = zend_vm_stack_top(TSRMLS_C);
        }
        zend_vm_stack_push((void *)(zend_uintptr_t)fci->param_count TSRMLS_CC);
    }

    if (fci->param_count) {
        fci->params = (zval ***)safe_emalloc(sizeof(zval *), fci->param_count, 0);
        zend_get_parameters_array_ex(fci->param_count, fci->params);
    }
#if PHP_VERSION_ID < 50500
    if (EG(return_value_ptr_ptr)) {
        fci->retval_ptr_ptr = EG(return_value_ptr_ptr);
    } else {
        fci->retval_ptr_ptr = result;
    }
#else
    fci->retval_ptr_ptr = result;
#endif
}

static void ddtrace_setup_fcall(zend_execute_data *execute_data, zend_fcall_info *fci, zval **result TSRMLS_DC) {
    if (EX(opline)->opcode != ZEND_DO_FCALL_BY_NAME) {
#if PHP_VERSION_ID >= 50600
        call_slot *call = EX(call_slots) + EX(opline)->op2.num;
        call->fbc = EX(function_state).function;
        call->object = NULL;
        call->called_scope = NULL;
        call->num_additional_args = 0;
        call->is_ctor_call = 0;
        EX(call) = call;
#else
        FBC() = EX(function_state).function;
#endif
    }

#if PHP_VERSION_ID < 50500
    EX(original_return_value) = EG(return_value_ptr_ptr);
    EG(return_value_ptr_ptr) = result;
#endif

    setup_fcal_name(execute_data, fci, result TSRMLS_CC);
}

static zend_function *_get_current_fbc(zend_execute_data *execute_data TSRMLS_DC) {
    if (EX(opline)->opcode == ZEND_DO_FCALL_BY_NAME) {
        return FBC();
    }
    zend_op *opline = EX(opline);
    zend_function *fbc = NULL;
    zval *fname = opline->op1.zv;

    if (CACHED_PTR(opline->op1.literal->cache_slot)) {
        return CACHED_PTR(opline->op1.literal->cache_slot);
    } else if (EXPECTED(zend_hash_quick_find(EG(function_table), Z_STRVAL_P(fname), Z_STRLEN_P(fname) + 1,
                                             Z_HASH_P(fname), (void **)&fbc) == SUCCESS)) {
        return fbc;
    } else {
        return NULL;
    }
}

// todo: use op_array.reserved slot to cache negative lookups (ones that do not trace)
static BOOL_T _dd_should_trace_call(zend_execute_data *execute_data, zend_function **fbc,
                                    ddtrace_dispatch_t **dispatch TSRMLS_DC) {
    if (DDTRACE_G(disable) || DDTRACE_G(disable_in_current_request) || DDTRACE_G(class_lookup) == NULL ||
        DDTRACE_G(function_lookup) == NULL) {
        return FALSE;
    }
    *fbc = _get_current_fbc(execute_data TSRMLS_CC);
    if (!*fbc) {
        return FALSE;
    }

    zval zv, *fname;
    fname = &zv;
    if (EX(opline)->opcode == ZEND_DO_FCALL_BY_NAME) {
        ZVAL_STRING(fname, (*fbc)->common.function_name, 0);
    } else if (EX(opline)->op1.zv) {
        fname = EX(opline)->op1.zv;
    } else {
        return FALSE;
    }

    // Don't trace closures
    if ((*fbc)->common.fn_flags & ZEND_ACC_CLOSURE) {
        return FALSE;
    }

    zval *this = ddtrace_this(execute_data);
    *dispatch = ddtrace_find_dispatch(this ? Z_OBJCE_P(this) : (*fbc)->common.scope, fname TSRMLS_CC);
    if (!*dispatch || (*dispatch)->busy) {
        return FALSE;
    }
    if (ddtrace_tracer_is_limited(TSRMLS_C) && ((*dispatch)->options & DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED) == 0) {
        return FALSE;
    }

    return TRUE;
}

int ddtrace_forward_call(zend_execute_data *execute_data, zend_function *fbc, zval *return_value TSRMLS_DC) {
    int fcall_status;

    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    zval *retval_ptr = NULL;

    fcc.initialized = 1;
    fcc.function_handler = fbc;
    fcc.object_ptr = ddtrace_this(execute_data);
    fcc.calling_scope = fbc->common.scope;  // EG(scope);
#if PHP_VERSION_ID < 50500
    fcc.called_scope = EX(called_scope);
#else
    fcc.called_scope = EX(call) ? EX(call)->called_scope : NULL;
#endif

    ddtrace_setup_fcall(execute_data, &fci, &retval_ptr TSRMLS_CC);
    fci.size = sizeof(fci);
    fci.no_separation = 1;
    fci.object_ptr = fcc.object_ptr;

    fcall_status = zend_call_function(&fci, &fcc TSRMLS_CC);
    if (fcall_status == SUCCESS && fci.retval_ptr_ptr && *fci.retval_ptr_ptr) {
        COPY_PZVAL_TO_ZVAL(*return_value, *fci.retval_ptr_ptr);
    }

    zend_fcall_info_args_clear(&fci, 1);
    return fcall_status;
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
#if PHP_VERSION_ID < 50500
    fcc.called_scope = EX(called_scope);
#else
    fcc.called_scope = EX(call) ? EX(call)->called_scope : NULL;
#endif
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

static void _dd_update_opcode_leave(zend_execute_data *execute_data TSRMLS_DC) {
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

static zend_function *datadog_current_function(zend_execute_data *execute_data) {
    if (EX(opline)->opcode == ZEND_DO_FCALL_BY_NAME) {
        return FBC();
    } else {
        return EX(function_state).function;
    }
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

static void wrap_and_run(zend_execute_data *execute_data, ddtrace_dispatch_t *dispatch TSRMLS_DC) {
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

static void ddtrace_trace_dispatch(ddtrace_dispatch_t *dispatch, zend_function *fbc,
                                   zend_execute_data *execute_data TSRMLS_DC) {
    const zend_op *opline = EX(opline);

    zval *user_retval = NULL;
    ALLOC_INIT_ZVAL(user_retval);

    ddtrace_span_t *span = ddtrace_open_span(execute_data, dispatch TSRMLS_CC);
    span->retval = user_retval;

    ddtrace_forward_call(execute_data, fbc, user_retval TSRMLS_CC);
    if (span == DDTRACE_G(open_spans_top)) {
        _dd_end_span(span, user_retval, opline TSRMLS_CC);
    } else {
        if (get_dd_trace_debug()) {
            const char *fname = Z_STRVAL(dispatch->function_name);
            ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync", fname);
        }
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

static int _dd_opcode_default_dispatch(zend_execute_data *execute_data TSRMLS_DC) {
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

static int _dd_begin_fcall_handler(zend_execute_data *execute_data TSRMLS_DC) {
    zend_function *current_fbc = NULL;
    ddtrace_dispatch_t *dispatch = NULL;
    if (!_dd_should_trace_call(execute_data, &current_fbc, &dispatch TSRMLS_CC)) {
        return _dd_opcode_default_dispatch(execute_data TSRMLS_CC);
    }
    int vm_retval = _dd_opcode_default_dispatch(execute_data TSRMLS_CC);
    if (vm_retval != ZEND_USER_OPCODE_DISPATCH) {
        if (get_dd_trace_debug()) {
            const char *fname = current_fbc->common.function_name ?: Z_STRVAL_P(EX(opline)->op1.zv);
            ddtrace_log_errf("A neighboring extension has altered the VM state for '%s()'; cannot reliably instrument",
                             fname ?: "{unknown}");
        }
        return vm_retval;
    }
    ddtrace_dispatch_copy(dispatch);  // protecting against dispatch being freed during php code execution
    dispatch->busy = 1;               // guard against recursion, catching only topmost execution

    if (dispatch->options & DDTRACE_DISPATCH_POSTHOOK) {
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

        _dd_update_opcode_leave(execute_data TSRMLS_CC);

        dispatch->busy = 0;
        ddtrace_dispatch_release(dispatch);
    }

    EX(opline)++;

    return ZEND_USER_OPCODE_LEAVE;
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

    return _prev_exit_handler ? _prev_exit_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}

void ddtrace_opcode_minit(void) {
    _prev_fcall_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL);
    _prev_fcall_by_name_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL_BY_NAME);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, _dd_begin_fcall_handler);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, _dd_begin_fcall_handler);

    _prev_exit_handler = zend_get_user_opcode_handler(ZEND_EXIT);
    zend_set_user_opcode_handler(ZEND_EXIT, _dd_exit_handler);
}

void ddtrace_opcode_mshutdown(void) {
    zend_set_user_opcode_handler(ZEND_DO_FCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, NULL);

    zend_set_user_opcode_handler(ZEND_EXIT, NULL);
}

void ddtrace_execute_internal_minit(void) {
    // TODO
}

void ddtrace_execute_internal_mshutdown(void) {
    // TODO
}
