#include "php.h"
#if PHP_VERSION_ID < 70000

#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>

#include <ext/spl/spl_exceptions.h>

#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "dispatch_compat.h"
#include "env_config.h"
#include "logging.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

#if !defined(ZVAL_COPY_VALUE)
#define ZVAL_COPY_VALUE(z, v)      \
    do {                           \
        (z)->value = (v)->value;   \
        Z_TYPE_P(z) = Z_TYPE_P(v); \
    } while (0)
#endif

static zend_always_inline void **vm_stack_push_args_with_copy(int count TSRMLS_DC) /* {{{ */
{
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

static zend_always_inline void **vm_stack_push_args(int count TSRMLS_DC) {
    if (UNEXPECTED(EG(argument_stack)->top - ZEND_VM_STACK_ELEMETS(EG(argument_stack)) < count) ||
        UNEXPECTED(EG(argument_stack)->top == EG(argument_stack)->end)) {
        return vm_stack_push_args_with_copy(count TSRMLS_CC);
    }
    *(EG(argument_stack)->top) = (void *)(zend_uintptr_t)count;
    return EG(argument_stack)->top++;
}

static zend_always_inline void setup_fcal_name(zend_execute_data *execute_data, zend_fcall_info *fci,
                                               zval **result TSRMLS_DC) {
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

void ddtrace_setup_fcall(zend_execute_data *execute_data, zend_fcall_info *fci, zval **result TSRMLS_DC) {
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

zend_function *ddtrace_function_get(const HashTable *table, zval *name) {
    char *key = zend_str_tolower_dup(Z_STRVAL_P(name), Z_STRLEN_P(name));

    zend_function *fptr = NULL;

    zend_hash_find(table, key, Z_STRLEN_P(name) + 1, (void **)&fptr);

    DD_PRINTF("Looking for key %s (length: %d, h: 0x%lX) in table", key, Z_STRLEN_P(name),
              zend_inline_hash_func(key, Z_STRLEN_P(name) + 1));
    DD_PRINT_HASH(table);
    DD_PRINTF("Found: %s", fptr != NULL ? "true" : "false");

    efree(key);
    return fptr;
}

void ddtrace_dispatch_free_owned_data(ddtrace_dispatch_t *dispatch) {
    zval_dtor(&dispatch->function_name);
    zval_dtor(&dispatch->callable);
}

void ddtrace_class_lookup_release_compat(void *zv) {
    ddtrace_dispatch_t *dispatch = *(ddtrace_dispatch_t **)zv;
    ddtrace_class_lookup_release(dispatch);
}

HashTable *ddtrace_new_class_lookup(zval *class_name TSRMLS_DC) {
    HashTable *class_lookup;
    ALLOC_HASHTABLE(class_lookup);
    zend_hash_init(class_lookup, 8, NULL, ddtrace_class_lookup_release_compat, 0);

    zend_hash_update(DDTRACE_G(class_lookup), Z_STRVAL_P(class_name), Z_STRLEN_P(class_name), &class_lookup,
                     sizeof(HashTable *), NULL);
    return class_lookup;
}

zend_bool ddtrace_dispatch_store(HashTable *lookup, ddtrace_dispatch_t *dispatch_orig) {
    ddtrace_dispatch_t *dispatch = pemalloc(sizeof(ddtrace_dispatch_t), lookup->persistent);

    memcpy(dispatch, dispatch_orig, sizeof(ddtrace_dispatch_t));

    ddtrace_class_lookup_acquire(dispatch);
    return zend_hash_update(lookup, Z_STRVAL(dispatch->function_name), Z_STRLEN(dispatch->function_name), &dispatch,
                            sizeof(ddtrace_dispatch_t *), NULL) == SUCCESS;
}

// A modified version of func_get_args()
// https://github.com/php/php-src/blob/PHP-5.6/Zend/zend_builtin_functions.c#L445
static int get_args(zval *args, zend_execute_data *ex) {
    if (!ex || !ex->function_state.arguments) {
        return 0;
    }
    void **p = ex->function_state.arguments;
    int param_count = (int)(zend_uintptr_t)*p;

    array_init_size(args, param_count);
    for (int i = 0; i < param_count; i++) {
        zval *element, *arg;

        arg = *((zval **)(p - (param_count - i)));
        if (!Z_ISREF_P(arg)) {
            element = arg;
            Z_ADDREF_P(element);
        } else {
            ALLOC_ZVAL(element);
            INIT_PZVAL_COPY(element, arg);
            zval_copy_ctor(element);
        }
        zend_hash_next_index_insert(args->value.ht, &element, sizeof(zval *), NULL);
    }
    return 1;
}

void ddtrace_wrapper_forward_call_from_userland(zend_execute_data *execute_data, zval *return_value TSRMLS_DC) {
    zval *retval_ptr = NULL;
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;

    if (!DDTRACE_G(original_context).execute_data || !EX(prev_execute_data)) {
        zend_throw_exception_ex(spl_ce_LogicException, 0 TSRMLS_CC,
                                "Cannot use dd_trace_forward_call() outside of a tracing closure");
        return;
    }

    // Jump out of any include files
    zend_execute_data *prev_ex = EX(prev_execute_data);
    while (prev_ex->opline && prev_ex->opline->opcode == ZEND_INCLUDE_OR_EVAL) {
        prev_ex = prev_ex->prev_execute_data;
    }
    const char *callback_name = !prev_ex ? NULL : prev_ex->function_state.function->common.function_name;

    if (!callback_name || 0 != strcmp(callback_name, DDTRACE_CALLBACK_NAME)) {
        zend_throw_exception_ex(spl_ce_LogicException, 0 TSRMLS_CC,
                                "Cannot use dd_trace_forward_call() outside of a tracing closure");
        return;
    }

    fcc.initialized = 1;
    fcc.function_handler = DDTRACE_G(original_context).fbc;
    fcc.object_ptr = DDTRACE_G(original_context).this;
    fcc.calling_scope = DDTRACE_G(original_context).calling_ce;
#if PHP_VERSION_ID < 50500
    fcc.called_scope = DDTRACE_G(original_context).execute_data->called_scope;
#else
    fcc.called_scope = DDTRACE_G(original_context).execute_data->call
                           ? DDTRACE_G(original_context).execute_data->call->called_scope
                           : NULL;
#endif

    fci.size = sizeof(fci);
    fci.function_table = EG(function_table);
    fci.object_ptr = fcc.object_ptr;
    fci.function_name = DDTRACE_G(original_context).function_name;
    fci.retval_ptr_ptr = &retval_ptr;
    fci.param_count = 0;
    fci.params = NULL;
    fci.no_separation = 1;
    fci.symbol_table = NULL;

    zval args = {0};
    if (0 == get_args(&args, prev_ex)) {
        zval_dtor(&args);
        zend_throw_exception_ex(spl_ce_RuntimeException, 0 TSRMLS_CC, "Cannot forward original function arguments");
        return;
    }
    zend_fcall_info_args(&fci, &args TSRMLS_CC);

    if (zend_call_function(&fci, &fcc TSRMLS_CC) == SUCCESS && fci.retval_ptr_ptr && *fci.retval_ptr_ptr) {
        COPY_PZVAL_TO_ZVAL(*return_value, *fci.retval_ptr_ptr);
    }

    zend_fcall_info_args_clear(&fci, 1);
    zval_dtor(&args);
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

BOOL_T ddtrace_should_trace_call(zend_execute_data *execute_data, zend_function **fbc,
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
    *dispatch = ddtrace_find_dispatch(this, *fbc, fname TSRMLS_CC);
    if (!*dispatch || (*dispatch)->busy) {
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

void ddtrace_copy_function_args(zend_execute_data *execute_data, zval *user_args) {
    INIT_ZVAL(*user_args);
    // @see https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_builtin_functions.c#L447-L473
    void **p = EX(function_state).arguments;
    if (p && *p) {
        int arg_count = (int)(zend_uintptr_t)*p;
        array_init_size(user_args, arg_count);
        for (int i = 0; i < arg_count; i++) {
            zval *element;

            ALLOC_ZVAL(element);
            *element = **((zval **)(p - (arg_count - i)));
            zval_copy_ctor(element);
            INIT_PZVAL(element);
            zend_hash_next_index_insert(Z_ARRVAL_P(user_args), &element, sizeof(zval *), NULL);
        }
    } else {
        array_init_size(user_args, 0);
    }
}

BOOL_T ddtrace_execute_tracing_closure(zval *callable, zval *span_data, zend_execute_data *execute_data,
                                       zval *user_args, zval *user_retval, zval *exception TSRMLS_DC) {
    BOOL_T status = TRUE;
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    zval *retval_ptr = NULL;
    zval **args[4];
    zval *null_zval = &EG(uninitialized_zval);
    zval *this = ddtrace_this(execute_data);

    if (zend_fcall_info_init(callable, 0, &fci, &fcc, NULL, NULL TSRMLS_CC) == FAILURE) {
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

void ddtrace_span_attach_exception(ddtrace_span_t *span, zval *exception) {
    if (exception) {
        MAKE_STD_ZVAL(span->exception);
        ZVAL_COPY_VALUE(span->exception, exception);
        zval_copy_ctor(span->exception);
    }
}
#endif  // PHP 5
