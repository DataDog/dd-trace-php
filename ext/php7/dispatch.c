#include "dispatch.h"

#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "arrays.h"
#include "compat_string.h"
#include "ddtrace.h"
#include "ddtrace_string.h"

// avoid Older GCC being overly cautious over {0} struct initializer
#pragma GCC diagnostic ignored "-Wmissing-field-initializers"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

extern inline void ddtrace_dispatch_copy(ddtrace_dispatch_t *dispatch);
extern inline void ddtrace_dispatch_release(ddtrace_dispatch_t *dispatch);

static bool dd_try_find_function_dispatch(HashTable *ht, zval *fname, ddtrace_dispatch_t **dispatch_ptr,
                                          HashTable **function_table) {
    ddtrace_dispatch_t *dispatch = ddtrace_hash_find_ptr_lc(ht, Z_STRVAL_P(fname), Z_STRLEN_P(fname));
    if (dispatch) {
        *dispatch_ptr = dispatch;
        *function_table = ht;
    }
    return dispatch;
}

static bool dd_try_find_method_dispatch(zend_class_entry *class, zval *fname, ddtrace_dispatch_t **dispatch_ptr,
                                        HashTable **function_table) {
    if (!fname || !Z_STRVAL_P(fname)) {
        return false;
    }
    HashTable *class_lookup = NULL;

    const char *class_name = ZSTR_VAL(class->name);
    size_t class_name_length = ZSTR_LEN(class->name);

    class_lookup = ddtrace_hash_find_ptr_lc(DDTRACE_G(class_lookup), class_name, class_name_length);
    if (class_lookup) {
        if (dd_try_find_function_dispatch(class_lookup, fname, dispatch_ptr, function_table)) {
            return true;
        }
    }

    return class->parent ? dd_try_find_method_dispatch(class->parent, fname, dispatch_ptr, function_table) : false;
}

bool ddtrace_try_find_dispatch(zend_class_entry *scope, zval *fname, ddtrace_dispatch_t **dispatch_ptr,
                               HashTable **function_table) {
    return scope ? dd_try_find_method_dispatch(scope, fname, dispatch_ptr, function_table)
                 : dd_try_find_function_dispatch(DDTRACE_G(function_lookup), fname, dispatch_ptr, function_table);
}

ddtrace_dispatch_t *ddtrace_find_dispatch(zend_class_entry *scope, zval *fname) {
    ddtrace_dispatch_t *dispatch = NULL;
    HashTable *function_table = NULL;

    ddtrace_try_find_dispatch(scope, fname, &dispatch, &function_table);
    return dispatch;
}

static void dispatch_table_dtor(zval *zv) {
    zend_hash_destroy(Z_PTR_P(zv));
    efree(Z_PTR_P(zv));
}

void ddtrace_dispatch_init(void) {
    if (!DDTRACE_G(class_lookup)) {
        ALLOC_HASHTABLE(DDTRACE_G(class_lookup));
        zend_hash_init(DDTRACE_G(class_lookup), 8, NULL, (dtor_func_t)dispatch_table_dtor, 0);
    }

    if (!DDTRACE_G(function_lookup)) {
        ALLOC_HASHTABLE(DDTRACE_G(function_lookup));
        zend_hash_init(DDTRACE_G(function_lookup), 8, NULL, (dtor_func_t)ddtrace_class_lookup_release_compat, 0);
    }
}

void ddtrace_dispatch_destroy(void) {
    if (DDTRACE_G(class_lookup)) {
        zend_hash_destroy(DDTRACE_G(class_lookup));
        FREE_HASHTABLE(DDTRACE_G(class_lookup));
        DDTRACE_G(class_lookup) = NULL;
    }

    if (DDTRACE_G(function_lookup)) {
        zend_hash_destroy(DDTRACE_G(function_lookup));
        FREE_HASHTABLE(DDTRACE_G(function_lookup));
        DDTRACE_G(function_lookup) = NULL;
    }
}

void ddtrace_dispatch_reset(void) {
    if (DDTRACE_G(class_lookup)) {
        zend_hash_clean(DDTRACE_G(class_lookup));
    }
    if (DDTRACE_G(function_lookup)) {
        zend_hash_clean(DDTRACE_G(function_lookup));
    }
}

static HashTable *_get_lookup_for_target(zval *class_name) {
    HashTable *overridable_lookup = NULL;
    if (class_name && DDTRACE_G(class_lookup)) {
        zend_string *class_name_lc = zend_string_tolower(Z_STR_P(class_name));
        overridable_lookup = zend_hash_find_ptr(DDTRACE_G(class_lookup), class_name_lc);
        if (!overridable_lookup) {
            zval tmp;
            ZVAL_STR(&tmp, class_name_lc);
            overridable_lookup = ddtrace_new_class_lookup(&tmp);
        }
        zend_string_release(class_name_lc);
    } else {
        overridable_lookup = DDTRACE_G(function_lookup);
    }

    return overridable_lookup;
}

zend_bool ddtrace_trace(zval *class_name, zval *function_name, zval *callable, uint32_t options) {
    HashTable *overridable_lookup = _get_lookup_for_target(class_name);
    if (overridable_lookup == NULL) {
        return false;
    }

    ddtrace_dispatch_t dispatch;
    memset(&dispatch, 0, sizeof(ddtrace_dispatch_t));
    if (callable != NULL) {
        dispatch.callable = *callable;
        zval_copy_ctor(&dispatch.callable);
    } else {
        ZVAL_NULL(&dispatch.callable);
    }

    ZVAL_COPY(&dispatch.function_name, function_name);
    ddtrace_downcase_zval(&dispatch.function_name);  // method/function names are case insensitive in PHP
    dispatch.options = options;

    if (ddtrace_dispatch_store(overridable_lookup, &dispatch)) {
        return true;
    } else {
        ddtrace_dispatch_dtor(&dispatch);
        return false;
    }
}

zend_bool ddtrace_hook_callable(ddtrace_string class_name, ddtrace_string function_name, ddtrace_string callable,
                                uint32_t options) {
    HashTable *overridable_lookup;
    ddtrace_dispatch_t dispatch;
    memset(&dispatch, 0, sizeof(ddtrace_dispatch_t));
    dispatch.options = options;
    DDTRACE_STRING_ZVAL_L(&dispatch.function_name, function_name);
    if (callable.ptr) {
        DDTRACE_STRING_ZVAL_L(&dispatch.callable, callable);
    } else {
        ZVAL_NULL(&dispatch.callable);
    }

    if (class_name.ptr) {
        zval z_class_name;
        // class name handling in get_lookup involves another copy as well as downcasing
        // TODO: we should avoid doing that
        DDTRACE_STRING_ZVAL_L(&z_class_name, class_name);
        overridable_lookup = _get_lookup_for_target(&z_class_name);
        zval_dtor(&z_class_name);
    } else {
        overridable_lookup = _get_lookup_for_target(NULL);
    }
    zend_bool dispatch_stored = false;
    if (overridable_lookup) {
        dispatch_stored = ddtrace_dispatch_store(overridable_lookup, &dispatch);
    }

    if (!dispatch_stored) {
        ddtrace_dispatch_dtor(&dispatch);
    }
    return dispatch_stored;
}
