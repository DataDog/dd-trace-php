#include "dispatch.h"

#include <Zend/zend.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "debug.h"
#include "dispatch_compat.h"
#include "span.h"
#include "trace.h"

// avoid Older GCC being overly cautious over {0} struct initializer
#pragma GCC diagnostic ignored "-Wmissing-field-initializers"

#define BUSY_FLAG 1

#if PHP_VERSION_ID >= 70100
#define RETURN_VALUE_USED(opline) ((opline)->result_type != IS_UNUSED)
#else
#define RETURN_VALUE_USED(opline) (!((opline)->result_type & EXT_TYPE_UNUSED))
#endif

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static ddtrace_dispatch_t *find_function_dispatch(const HashTable *lookup, zval *fname) {
    char *key = zend_str_tolower_dup(Z_STRVAL_P(fname), Z_STRLEN_P(fname));
    ddtrace_dispatch_t *dispatch = NULL;
    dispatch = zend_hash_str_find_ptr(lookup, key, Z_STRLEN_P(fname));

    efree(key);
    return dispatch;
}

static ddtrace_dispatch_t *find_method_dispatch(const zend_class_entry *class, zval *fname TSRMLS_DC) {
    if (!fname || !Z_STRVAL_P(fname)) {
        return NULL;
    }
    HashTable *class_lookup = NULL;

#if PHP_VERSION_ID < 70000
    const char *class_name = NULL;
    size_t class_name_length = 0;
    class_name = class->name;
    class_name_length = class->name_length;
    class_lookup = zend_hash_str_find_ptr(DDTRACE_G(class_lookup), class_name, class_name_length);
#else
    class_lookup = zend_hash_find_ptr(DDTRACE_G(class_lookup), class->name);
#endif

    ddtrace_dispatch_t *dispatch = NULL;
    if (class_lookup) {
        dispatch = find_function_dispatch(class_lookup, fname);
    }

    if (dispatch) {
        return dispatch;
    }

    if (class->parent) {
        return find_method_dispatch(class->parent, fname TSRMLS_CC);
    } else {
        return NULL;
    }
}

ddtrace_dispatch_t *ddtrace_find_dispatch(zval *this, zend_function *fbc, zval *fname TSRMLS_DC) {
    zend_class_entry *class = NULL;

    if (this) {
        class = Z_OBJCE_P(this);
    } else if ((fbc->common.fn_flags & ZEND_ACC_STATIC) != 0) {
        // Check for class on static method static
        class = fbc->common.scope;
    }

    if (class) {
        return find_method_dispatch(class, fname TSRMLS_CC);
    }
    return find_function_dispatch(DDTRACE_G(function_lookup), fname);
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

#if PHP_VERSION_ID < 70000
    const char *func_name = DDTRACE_CALLBACK_NAME;
    func = datadog_current_function(execute_data);

    zend_function *callable = (zend_function *)zend_get_closure_method_def(&dispatch->callable TSRMLS_CC);

    // convert passed callable to not be static as we're going to bind it to *this
    if (this) {
        callable->common.fn_flags &= ~ZEND_ACC_STATIC;
    }

    zend_create_closure(&closure, callable, executed_method_class, this TSRMLS_CC);
#else
    zend_string *func_name = zend_string_init(ZEND_STRL(DDTRACE_CALLBACK_NAME), 0);
    func = EX(func);
    zend_create_closure(&closure, (zend_function *)zend_get_closure_method_def(&dispatch->callable),
                        executed_method_class, executed_method_class, this TSRMLS_CC);
#endif
    if (zend_fcall_info_init(&closure, 0, &fci, &fcc, NULL, &error TSRMLS_CC) != SUCCESS) {
        if (DDTRACE_G(strict_mode)) {
            const char *scope_name, *function_name;
#if PHP_VERSION_ID < 70000
            scope_name = (func->common.scope) ? func->common.scope->name : NULL;
            function_name = func->common.function_name;
#else
            scope_name = (func->common.scope) ? ZSTR_VAL(func->common.scope->name) : NULL;
            function_name = ZSTR_VAL(func->common.function_name);
#endif
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

#if PHP_VERSION_ID >= 70000
    zend_class_entry *orig_scope = fcc.function_handler->common.scope;
    fcc.function_handler->common.scope = DDTRACE_G(original_context).calling_fbc->common.scope;
    fcc.calling_scope = DDTRACE_G(original_context).calling_fbc->common.scope;
#endif

    zend_execute_data *prev_original_execute_data = DDTRACE_G(original_context).execute_data;
    DDTRACE_G(original_context).execute_data = execute_data;
#if PHP_VERSION_ID < 70000
    zval *prev_original_function_name = DDTRACE_G(original_context).function_name;
    DDTRACE_G(original_context).function_name = (*EG(opline_ptr))->op1.zv;
#endif

    zend_call_function(&fci, &fcc TSRMLS_CC);

#if PHP_VERSION_ID < 70000
    DDTRACE_G(original_context).function_name = prev_original_function_name;
#endif
    DDTRACE_G(original_context).execute_data = prev_original_execute_data;

#if PHP_VERSION_ID >= 70000
    fcc.function_handler->common.scope = orig_scope;
#endif

#if PHP_VERSION_ID < 70000
    if (fci.params) {
        efree(fci.params);
    }
#else
    zend_string_release(func_name);
    if (fci.params) {
        zend_fcall_info_args_clear(&fci, 0);
    }
#endif

_exit_cleanup:
#if PHP_VERSION_ID < 70000
    if (this) {
        Z_DELREF_P(this);
    }
    Z_DELREF(closure);
    zval_dtor(&closure);
#else
    if (this && (EX_CALL_INFO() & ZEND_CALL_RELEASE_THIS)) {
        OBJ_RELEASE(Z_OBJ(execute_data->This));
    }
    OBJ_RELEASE(Z_OBJ(closure));
#endif
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

#elif PHP_VERSION_ID < 70000
    zval *return_value = NULL;
    execute_fcall(dispatch, this, execute_data, &return_value TSRMLS_CC);

    if (return_value != NULL) {
        if (RETURN_VALUE_USED(opline)) {
            EX_TMP_VAR(execute_data, opline->result.var)->var.ptr = return_value;
        } else {
            zval_ptr_dtor(&return_value);
        }
    }

#else
    zval rv;
    INIT_ZVAL(rv);

    zval *return_value = (RETURN_VALUE_USED(opline) ? EX_VAR(EX(opline)->result.var) : &rv);
    execute_fcall(dispatch, this, EX(call), &return_value TSRMLS_CC);

    if (!RETURN_VALUE_USED(opline)) {
        zval_dtor(&rv);
    }
#endif
}

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
#elif PHP_VERSION_ID < 70000
    zend_vm_stack_clear_multiple(0 TSRMLS_CC);
    EX(call)--;
#else
    EX(call) = EX(call)->prev_execute_data;
#endif
}

static int _default_dispatch(zend_execute_data *execute_data TSRMLS_DC) {
    DD_PRINTF("calling default dispatch");
    if (EX(opline)->opcode == ZEND_DO_FCALL_BY_NAME) {
        if (DDTRACE_G(ddtrace_old_fcall_by_name_handler)) {
            return DDTRACE_G(ddtrace_old_fcall_by_name_handler)(execute_data TSRMLS_CC);
        }
    } else {
        if (DDTRACE_G(ddtrace_old_fcall_handler)) {
            return DDTRACE_G(ddtrace_old_fcall_handler)(execute_data TSRMLS_CC);
        }
    }
    // PHP 7: Handle ZEND_DO_UCALL & ZEND_DO_ICALL

    return ZEND_USER_OPCODE_DISPATCH;
}

int ddtrace_wrap_fcall(zend_execute_data *execute_data TSRMLS_DC) {
    zend_function *current_fbc = NULL;
    ddtrace_dispatch_t *dispatch = NULL;
    if (!ddtrace_should_trace_call(execute_data, &current_fbc, &dispatch TSRMLS_CC)) {
        return _default_dispatch(execute_data TSRMLS_CC);
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
#if PHP_VERSION_ID < 70000
        DDTRACE_G(original_context).calling_fbc =
            execute_data->function_state.function && execute_data->function_state.function->common.scope
                ? execute_data->function_state.function
                : current_fbc;
#else
        DDTRACE_G(original_context).calling_fbc = current_fbc->common.scope ? current_fbc : execute_data->func;
#endif
        zval *this = ddtrace_this(execute_data);
#if PHP_VERSION_ID < 70000
        zval *previous_this = DDTRACE_G(original_context).this;
        DDTRACE_G(original_context).this = this;
#else
        zend_object *previous_this = DDTRACE_G(original_context).this;
        DDTRACE_G(original_context).this = this ? Z_OBJ_P(this) : NULL;
#endif
        zend_class_entry *previous_calling_ce = DDTRACE_G(original_context).calling_ce;
#if PHP_VERSION_ID < 70000
        DDTRACE_G(original_context).calling_ce = DDTRACE_G(original_context).calling_fbc->common.scope;
#else
        if (DDTRACE_G(original_context).this) {
            GC_ADDREF(DDTRACE_G(original_context).this);
        }
        DDTRACE_G(original_context).calling_ce = Z_OBJ(execute_data->This) ? Z_OBJ(execute_data->This)->ce : NULL;
#endif

        wrap_and_run(execute_data, dispatch TSRMLS_CC);
#if PHP_VERSION_ID >= 70000
        if (DDTRACE_G(original_context).this) {
            GC_DELREF(DDTRACE_G(original_context).this);
        }
#endif

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

void ddtrace_class_lookup_acquire(ddtrace_dispatch_t *dispatch) { dispatch->acquired++; }

void ddtrace_class_lookup_release(ddtrace_dispatch_t *dispatch) {
    if (dispatch->acquired > 0) {
        dispatch->acquired--;
    }

    // free when no one has acquired this resource
    if (dispatch->acquired == 0) {
        ddtrace_dispatch_free_owned_data(dispatch);
        efree(dispatch);
    }
}

static int _find_method(zend_class_entry *ce, zval *name, zend_function **function) {
    return ddtrace_find_function(&ce->function_table, name, function);
}

zend_class_entry *ddtrace_target_class_entry(zval *class_name, zval *method_name TSRMLS_DC) {
    zend_class_entry *class = NULL;
#if PHP_VERSION_ID < 70000
    class = zend_fetch_class(Z_STRVAL_P(class_name), Z_STRLEN_P(class_name),
                             ZEND_FETCH_CLASS_DEFAULT | ZEND_FETCH_CLASS_SILENT TSRMLS_CC);
#else
    class = zend_fetch_class_by_name(Z_STR_P(class_name), NULL, ZEND_FETCH_CLASS_DEFAULT | ZEND_FETCH_CLASS_SILENT);
#endif
    zend_function *method = NULL;

    if (class && _find_method(class, method_name, &method) == SUCCESS) {
        if (method->common.scope != class) {
            class = method->common.scope;
            DD_PRINTF("Overriding Parent class method");
        }
    }

    return class;
}

int ddtrace_find_function(HashTable *table, zval *name, zend_function **function) {
    zend_function *ptr = ddtrace_function_get(table, name);
    if (!ptr) {
        return FAILURE;
    }

    if (function) {
        *function = ptr;
    }

    return SUCCESS;
}
