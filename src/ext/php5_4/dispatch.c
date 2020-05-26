#include "dispatch.h"

#include <Zend/zend_exceptions.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "ddtrace.h"
#include "debug.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

// todo: is this used anywhere?
#if !defined(ZVAL_COPY_VALUE)
#define ZVAL_COPY_VALUE(z, v)      \
    do {                           \
        (z)->value = (v)->value;   \
        Z_TYPE_P(z) = Z_TYPE_P(v); \
    } while (0)
#endif

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

void ddtrace_dispatch_dtor(ddtrace_dispatch_t *dispatch) {
    zval_dtor(&dispatch->function_name);
    zval_dtor(&dispatch->callable);
}

void ddtrace_class_lookup_release_compat(void *zv) {
    ddtrace_dispatch_t *dispatch = *(ddtrace_dispatch_t **)zv;
    ddtrace_dispatch_release(dispatch);
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

    ddtrace_dispatch_copy(dispatch);
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
    fcc.called_scope = DDTRACE_G(original_context).execute_data->called_scope;

    fci.size = sizeof(fci);
    fci.function_table = EG(function_table);
    fci.object_ptr = fcc.object_ptr;
    fci.function_name = DDTRACE_G(original_context).function_name;
    fci.retval_ptr_ptr = &retval_ptr;
    fci.param_count = 0;
    fci.params = NULL;
    fci.no_separation = 1;
    fci.symbol_table = NULL;

    zval *args;
    ALLOC_INIT_ZVAL(args);
    if (0 == get_args(args, prev_ex)) {
        zval_ptr_dtor(&args);
        zend_throw_exception_ex(spl_ce_RuntimeException, 0 TSRMLS_CC, "Cannot forward original function arguments");
        return;
    }
    zend_fcall_info_args(&fci, args TSRMLS_CC);

    if (zend_call_function(&fci, &fcc TSRMLS_CC) == SUCCESS && fci.retval_ptr_ptr && *fci.retval_ptr_ptr) {
        COPY_PZVAL_TO_ZVAL(*return_value, *fci.retval_ptr_ptr);
    }

    zend_fcall_info_args_clear(&fci, 1);
    zval_ptr_dtor(&args);
}
