#include "php.h"
#include "php/ext/spl/spl_exceptions.h"

#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "php/ext/spl/spl_exceptions.h"

#include "Zend/zend.h"
#include "compat_zend_string.h"
#include "dispatch_compat.h"

#include <php/Zend/zend_hash.h>
#include "Zend/zend_closures.h"
#include "Zend/zend_exceptions.h"
ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

user_opcode_handler_t ddtrace_old_fcall_handler;
user_opcode_handler_t ddtrace_old_fcall_by_name_handler;

#if PHP_VERSION_ID >= 70000
void (*ddtrace_original_execute_ex)(zend_execute_data *TSRMLS_DC);

static void php_execute(zend_execute_data *execute_data TSRMLS_DC) {
    if (ddtrace_original_execute_ex) {
        ddtrace_original_execute_ex(execute_data TSRMLS_CC);
    } else
        execute_ex(execute_data TSRMLS_CC);
}
#endif

void ddtrace_dispatch_init() {
/**
 * Replacing zend_execute_ex with anything other than original
 * changes some of the bevavior in PHP compilation and execution
 *
 * e.g. it changes compilation of function calls to produce ZEND_DO_FCALL
 * opcode instead of ZEND_DO_UCALL for user defined functions
 *
 * This extension could be developed by using zend_execute_ex to hook
 * into every execution, however hooking into opcode processing has the
 * advantage of not hooking into other executable things like generators which
 * gives a slight performance advantage.
 */
#if PHP_VERSION_ID >= 70000
    ddtrace_original_execute_ex = zend_execute_ex;
    zend_execute_ex = php_execute;
#endif

    ddtrace_old_fcall_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, ddtrace_wrap_fcall);

#if PHP_VERSION_ID < 70000
    ddtrace_old_fcall_by_name_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL_BY_NAME);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, ddtrace_wrap_fcall);
#endif
}

static int find_function(HashTable *table, STRING_T *name, zend_function **function) {
    zend_function *ptr = ddtrace_function_get(table, name);
    if (!ptr) {
        return FAILURE;
    }

    if (function) {
        *function = ptr;
    }

    return SUCCESS;
}

static int find_method(zend_class_entry *ce, STRING_T *name, zend_function **function) {
    return find_function(&ce->function_table, name, function);
}

zend_bool ddtrace_trace(zend_class_entry *clazz, STRING_T *name, zval *callable TSRMLS_DC) {
    zend_function *function = NULL;
    if (clazz) {
        DD_PRINTF("Looking up memthod to trace %s::%s", STRING_VAL(clazz->name), STRING_VAL_CHAR(name));
        if (find_method(clazz, name, &function) != SUCCESS) {
            zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                                    "failed to override %s::%s, the method does not exist", STRING_VAL(clazz->name),
                                    STRING_VAL_CHAR(name));
            return 0;
        }

        if (function != NULL && function->common.scope != clazz) {
        clazz = function->common.scope;
        DD_PRINTF("Overriding Parent class method");
        }
    }

    // TODO: cleanup this method (its a mess!)
    HashTable *class_lookup = NULL;
    if (clazz) {
#if PHP_VERSION_ID < 70000
        class_lookup = zend_hash_str_find_ptr(&DDTRACE_G(class_lookup), clazz->name, clazz->name_length);
#else
        class_lookup = zend_hash_find_ptr(&DDTRACE_G(class_lookup), clazz->name);
#endif

        if (!class_lookup) {
            class_lookup = ddtrace_new_class_lookup(clazz TSRMLS_CC);
        }
    } else {
        class_lookup = &DDTRACE_G(function_lookup);
    }

    if (!class_lookup) {
        return 0;
    }

    ddtrace_dispatch_t dispatch;
    memset(&dispatch, 0, sizeof(ddtrace_dispatch_t));

    dispatch.clazz = clazz;
    dispatch.function = STRING_TOLOWER(name);  // method/function names are case insensitive in PHP

    dispatch.callable = *callable;
    zval_copy_ctor(&dispatch.callable);

    if (ddtrace_dispatch_store(class_lookup, &dispatch)) {
        return 1;
    } else {
        ddtrace_dispatch_free_owned_data(&dispatch);
        return 0;
    }
}
