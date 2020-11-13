#include "ext/php8/dispatch.h"

#include <Zend/zend_exceptions.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "ext/php8/arrays.h"
#include "ext/php8/compatibility.h"
#include "ext/php8/ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

void ddtrace_dispatch_dtor(ddtrace_dispatch_t *dispatch) {
    zval_ptr_dtor(&dispatch->function_name);
    zval_ptr_dtor(&dispatch->callable);
}

void ddtrace_class_lookup_release_compat(zval *zv) {
    ddtrace_dispatch_t *dispatch = Z_PTR_P(zv);
    ddtrace_dispatch_release(dispatch);
}

HashTable *ddtrace_new_class_lookup(zval *class_name) {
    HashTable *class_lookup;

    ALLOC_HASHTABLE(class_lookup);
    zend_hash_init(class_lookup, 8, NULL, ddtrace_class_lookup_release_compat, 0);
    zend_hash_update_ptr(DDTRACE_G(class_lookup), Z_STR_P(class_name), class_lookup);

    return class_lookup;
}

zend_bool ddtrace_dispatch_store(HashTable *lookup, ddtrace_dispatch_t *dispatch_orig) {
    ddtrace_dispatch_t *dispatch = pemalloc(sizeof(ddtrace_dispatch_t), lookup->u.flags & IS_ARRAY_PERSISTENT);

    memcpy(dispatch, dispatch_orig, sizeof(ddtrace_dispatch_t));
    ddtrace_dispatch_copy(dispatch);
    return zend_hash_update_ptr(lookup, Z_STR(dispatch->function_name), dispatch) != NULL;
}
