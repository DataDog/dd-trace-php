#include <php.h>
#include <ext/spl/spl_exceptions.h>

#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"

#include <Zend/zend.h>
#include "compat_zend_string.h"
#include "dispatch_compat.h"

#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#if PHP_VERSION_ID < 70000
static inline void dispatch_table_dtor(void *zv) {
    HashTable *ht = *(HashTable **)zv;
    zend_hash_destroy(ht);
    efree(ht);
}
#else
static inline void dispatch_table_dtor(zval *zv) {
    zend_hash_destroy(Z_PTR_P(zv));
    efree(Z_PTR_P(zv));
}
#endif

void ddtrace_dispatch_init(TSRMLS_D) {
    zend_hash_init(&DDTRACE_G(class_lookup), 8, NULL, (dtor_func_t)dispatch_table_dtor, 0);
    zend_hash_init(&DDTRACE_G(function_lookup), 8, NULL, (dtor_func_t)ddtrace_class_lookup_release_compat, 0);
}

void ddtrace_dispatch_destroy(TSRMLS_D) {
    zend_hash_destroy(&DDTRACE_G(class_lookup));
    zend_hash_destroy(&DDTRACE_G(function_lookup));
}

void ddtrace_dispatch_reset(TSRMLS_D) {
    zend_hash_clean(&DDTRACE_G(class_lookup));
    zend_hash_clean(&DDTRACE_G(function_lookup));
}

zend_bool ddtrace_trace(zval *class_name, zval *function_name, zval *callable TSRMLS_DC) {
    HashTable *overridable_lookup = NULL;
    if (class_name) {
#if PHP_VERSION_ID < 70000
        overridable_lookup =
            zend_hash_str_find_ptr(&DDTRACE_G(class_lookup), Z_STRVAL_P(class_name), Z_STRLEN_P(class_name));
#else
        overridable_lookup = zend_hash_find_ptr(&DDTRACE_G(class_lookup), Z_STR_P(class_name));
#endif
        if (!overridable_lookup) {
            overridable_lookup = ddtrace_new_class_lookup(class_name TSRMLS_CC);
        }
    } else {
        if (DDTRACE_G(strict_mode)) {
            zend_function *function = NULL;
            if (ddtrace_find_function(EG(function_table), function_name, &function) != SUCCESS) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                                        "Failed to override function %s - the function does not exist",
                                        Z_STRVAL_P(function_name));
            }

            return 0;
        }

        overridable_lookup = &DDTRACE_G(function_lookup);
    }

    if (!overridable_lookup) {
        return 0;
    }

    ddtrace_dispatch_t dispatch;
    memset(&dispatch, 0, sizeof(ddtrace_dispatch_t));
    dispatch.callable = *callable;

#if PHP_VERSION_ID < 70000
    ZVAL_STRINGL(&dispatch.function_name, Z_STRVAL_P(function_name), Z_STRLEN_P(function_name), 1);
#else
    ZVAL_STRINGL(&dispatch.function_name, Z_STRVAL_P(function_name), Z_STRLEN_P(function_name));
#endif
    zval_copy_ctor(&dispatch.callable);

    ddtrace_downcase_zval(&dispatch.function_name);  // method/function names are case insensitive in PHP

    if (ddtrace_dispatch_store(overridable_lookup, &dispatch)) {
        return 1;
    } else {
        ddtrace_dispatch_free_owned_data(&dispatch);
        return 0;
    }
}
