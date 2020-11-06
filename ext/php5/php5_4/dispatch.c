#include "ext/php5/dispatch.h"

#include <Zend/zend_exceptions.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "ext/php5/ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

// todo: is this used anywhere?
#if !defined(ZVAL_COPY_VALUE)
#define ZVAL_COPY_VALUE(z, v)      \
    do {                           \
        (z)->value = (v)->value;   \
        Z_TYPE_P(z) = Z_TYPE_P(v); \
    } while (0)
#endif

zend_function *ddtrace_ftable_get(const HashTable *table, zval *name) {
    char *key = zend_str_tolower_dup(Z_STRVAL_P(name), Z_STRLEN_P(name));

    zend_function *fptr = NULL;

    zend_hash_find(table, key, Z_STRLEN_P(name) + 1, (void **)&fptr);

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
