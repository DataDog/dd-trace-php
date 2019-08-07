// clang-format off
#include <php.h>
#include <Zend/zend.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
// clang-format on

#include <ext/spl/spl_exceptions.h>

#include "compat_zend_string.h"
#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "dispatch_compat.h"
ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

user_opcode_handler_t ddtrace_old_fcall_handler;
user_opcode_handler_t ddtrace_old_icall_handler;
user_opcode_handler_t ddtrace_old_ucall_handler;
user_opcode_handler_t ddtrace_old_fcall_by_name_handler;

#if PHP_VERSION_ID >= 70000
static inline void dispatch_table_dtor(zval *zv) {
    zend_hash_destroy(Z_PTR_P(zv));
    efree(Z_PTR_P(zv));
}
#else
static inline void dispatch_table_dtor(void *zv) {
    HashTable *ht = *(HashTable **)zv;
    zend_hash_destroy(ht);
    efree(ht);
}
#endif

void ddtrace_dispatch_init(TSRMLS_D) {
    if (!DDTRACE_G(class_lookup)) {
        ALLOC_HASHTABLE(DDTRACE_G(class_lookup));
        zend_hash_init(DDTRACE_G(class_lookup), 8, NULL, (dtor_func_t)dispatch_table_dtor, 0);
    }

    if (!DDTRACE_G(function_lookup)) {
        ALLOC_HASHTABLE(DDTRACE_G(function_lookup));
        zend_hash_init(DDTRACE_G(function_lookup), 8, NULL, (dtor_func_t)ddtrace_class_lookup_release_compat, 0);
    }
}

void ddtrace_dispatch_destroy(TSRMLS_D) {
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

void ddtrace_dispatch_reset(TSRMLS_D) {
    if (DDTRACE_G(class_lookup)) {
        zend_hash_clean(DDTRACE_G(class_lookup));
    }
    if (DDTRACE_G(function_lookup)) {
        zend_hash_clean(DDTRACE_G(function_lookup));
    }
}

void ddtrace_dispatch_inject(TSRMLS_D) {
/**
 * Replacing zend_execute_ex with anything other than original
 * changes some of the bevavior in PHP compilation and execution
 *
 * e.g. it changes compilation of function calls to produce ZEND_DO_FCALL
 * opcode instead of ZEND_DO_UCALL for user defined functions
 */
#if PHP_VERSION_ID >= 70000
    DDTRACE_G(ddtrace_old_icall_handler) = zend_get_user_opcode_handler(ZEND_DO_ICALL);
    zend_set_user_opcode_handler(ZEND_DO_ICALL, ddtrace_wrap_fcall);

    DDTRACE_G(ddtrace_old_ucall_handler) = zend_get_user_opcode_handler(ZEND_DO_UCALL);
    zend_set_user_opcode_handler(ZEND_DO_UCALL, ddtrace_wrap_fcall);
#endif
    DDTRACE_G(ddtrace_old_fcall_handler) = zend_get_user_opcode_handler(ZEND_DO_FCALL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, ddtrace_wrap_fcall);

    DDTRACE_G(ddtrace_old_fcall_by_name_handler) = zend_get_user_opcode_handler(ZEND_DO_FCALL_BY_NAME);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, ddtrace_wrap_fcall);
}

zend_bool ddtrace_trace(zval *class_name, zval *function_name, zval *callable, zend_bool append TSRMLS_DC) {
    HashTable *overridable_lookup = NULL;
    if (class_name && DDTRACE_G(class_lookup)) {
#if PHP_VERSION_ID < 70000
        overridable_lookup =
            zend_hash_str_find_ptr(DDTRACE_G(class_lookup), Z_STRVAL_P(class_name), Z_STRLEN_P(class_name));
#else
        overridable_lookup = zend_hash_find_ptr(DDTRACE_G(class_lookup), Z_STR_P(class_name));
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

        overridable_lookup = DDTRACE_G(function_lookup);
    }

    if (!overridable_lookup) {
        return 0;
    }

    ddtrace_dispatch_t dispatch;
    memset(&dispatch, 0, sizeof(ddtrace_dispatch_t));

    dispatch.callable = *callable;
    zval_copy_ctor(&dispatch.callable);
    dispatch.append = append;

#if PHP_VERSION_ID < 70000
    ZVAL_STRINGL(&dispatch.function_name, Z_STRVAL_P(function_name), Z_STRLEN_P(function_name), 1);
#else
    ZVAL_STRINGL(&dispatch.function_name, Z_STRVAL_P(function_name), Z_STRLEN_P(function_name));
#endif
    ddtrace_downcase_zval(&dispatch.function_name);  // method/function names are case insensitive in PHP

    if (ddtrace_dispatch_store(overridable_lookup, &dispatch)) {
        return 1;
    } else {
        ddtrace_dispatch_free_owned_data(&dispatch);
        return 0;
    }
}
