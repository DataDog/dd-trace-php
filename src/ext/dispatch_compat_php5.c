#include "php.h"
#if PHP_VERSION_ID < 70000

#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "dispatch_compat.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

#undef EX  // php7 style EX
#define EX(x) ((execute_data)->x)

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
#if PHP_VERSION_ID < 50600
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
#if PHP_VERSION_ID < 50600
    EX(original_return_value) = EG(return_value_ptr_ptr);
    if (EG(return_value_ptr_ptr) && result) {
        *EG(return_value_ptr_ptr) = *result;
    }
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
    zval_ptr_dtor(&dispatch->function);
    zval_dtor(&dispatch->callable);
}

void ddtrace_class_lookup_free(void *zv) {
    ddtrace_dispatch_t **dispatch = (ddtrace_dispatch_t **)zv;
    ddtrace_dispatch_free_owned_data(*dispatch);
    efree(*dispatch);
}

HashTable *ddtrace_new_class_lookup(zend_class_entry *clazz TSRMLS_DC) {
    if (!clazz) {
        return &DDTRACE_G(function_lookup);
    }

    HashTable *class_lookup;
    ALLOC_HASHTABLE(class_lookup);
    zend_hash_init(class_lookup, 8, NULL, ddtrace_class_lookup_free, 0);

    zend_hash_update(&DDTRACE_G(class_lookup), clazz->name, clazz->name_length, &class_lookup, sizeof(HashTable *),
                     NULL);
    return class_lookup;
}

zend_bool ddtrace_dispatch_store(HashTable *lookup, ddtrace_dispatch_t *dispatch_orig) {
    ddtrace_dispatch_t *dispatch = pemalloc(sizeof(ddtrace_dispatch_t), lookup->persistent);

    memcpy(dispatch, dispatch_orig, sizeof(ddtrace_dispatch_t));
    DD_PRINTF("%s, %d", Z_STRVAL_P(dispatch->function), Z_STRLEN_P(dispatch->function));

    return zend_hash_update(lookup, Z_STRVAL_P(dispatch->function), Z_STRLEN_P(dispatch->function), &dispatch,
                            sizeof(ddtrace_dispatch_t *), NULL) == SUCCESS;
}

#endif  // PHP 5
