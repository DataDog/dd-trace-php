#include "php.h"
#if PHP_VERSION_ID >= 70000

#include "ddtrace.h"
#include "dispatch.h"
#include "dispatch_compat.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

void ddtrace_setup_fcall(zend_execute_data *execute_data, zend_fcall_info *fci, zval **result) {
    fci->param_count = ZEND_CALL_NUM_ARGS(execute_data);
    fci->params = ZEND_CALL_ARG(execute_data, 1);
    fci->retval = *result;
}

zend_function *ddtrace_function_get(const HashTable *table, zend_string *name) {
    zend_string *key = zend_string_tolower(name);
    zend_function *ptr = zend_hash_find_ptr(table, key);

    zend_string_release(key);
    return ptr;
}

void ddtrace_dispatch_free_owned_data(ddtrace_dispatch_t *dispatch) {
    zend_string_release(dispatch->function);
    zval_dtor(&dispatch->callable);
}

void ddtrace_class_lookup_free(zval *zv) {
    ddtrace_dispatch_t *dispatch = Z_PTR_P(zv);
    ddtrace_dispatch_free_owned_data(dispatch);
    efree(dispatch);
}

HashTable *ddtrace_new_class_lookup(zend_class_entry *clazz) {
    HashTable *class_lookup;

    ALLOC_HASHTABLE(class_lookup);
    zend_hash_init(class_lookup, 8, NULL, ddtrace_class_lookup_free, 0);
    zend_hash_update_ptr(&DDTRACE_G(class_lookup), clazz->name, class_lookup);

    return class_lookup;
}

zend_bool ddtrace_dispatch_store(HashTable *lookup, ddtrace_dispatch_t *dispatch_orig) {
#if PHP_VERSION_ID >= 70300
    ddtrace_dispatch_t *dispatch = pemalloc(sizeof(ddtrace_dispatch_t), lookup->u.flags & IS_ARRAY_PERSISTENT);
#else
    ddtrace_dispatch_t *dispatch = pemalloc(sizeof(ddtrace_dispatch_t), lookup->u.flags & HASH_FLAG_PERSISTENT);
#endif

    memcpy(dispatch, dispatch_orig, sizeof(ddtrace_dispatch_t));
    return zend_hash_update_ptr(lookup, dispatch->function, dispatch) != NULL;
}
#endif  // PHP_VERSION_ID >= 70000
