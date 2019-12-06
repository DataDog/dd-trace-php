#include "dispatch.h"

#include <Zend/zend.h>
#include <php.h>

#include "ddtrace.h"
#include "debug.h"

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

    // downcase the class name as they are case insensitive
#if PHP_VERSION_ID < 70000
    size_t class_name_length = class->name_length;
    char *class_name = zend_str_tolower_dup(class->name, class_name_length);
    class_lookup = zend_hash_str_find_ptr(DDTRACE_G(class_lookup), class_name, class_name_length);
    efree(class_name);
#else
    zend_string *class_name = zend_string_tolower(class->name);
    class_lookup = zend_hash_find_ptr(DDTRACE_G(class_lookup), class_name);
    zend_string_release(class_name);
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
