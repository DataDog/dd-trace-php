#ifndef DISPATCH_COMPAT_H
#define DISPATCH_COMPAT_H
#include "Zend/zend_types.h"
#include "compat_zend_string.h"
#include "dispatch.h"
#include "env_config.h"

#if PHP_VERSION_ID < 70000
#include "dispatch_compat_php5.h"
void ddtrace_class_lookup_release_compat(void *zv);

#define ddtrace_zval_ptr_dtor(x) zval_dtor(x)
#else
void ddtrace_class_lookup_release_compat(zval *zv);

#define ddtrace_zval_ptr_dtor(x) zval_ptr_dtor(x)
#define INIT_ZVAL(x) ZVAL_NULL(&x)
#endif

#define _EX(x) ((execute_data)->x)
static zend_always_inline zval *ddtrace_this(zend_execute_data *execute_data) {
    zval *this = NULL;
#if PHP_VERSION_ID < 50500
    if (_EX(opline)->opcode != ZEND_DO_FCALL && _EX(object)) {
        this = _EX(object);
    }
#elif PHP_VERSION_ID < 70000
    if (_EX(opline)->opcode != ZEND_DO_FCALL) {
        this = _EX(call) ? _EX(call)->object : NULL;
    }
#else
    if (_EX(call)) {
        this = &(_EX(call)->This);
        if (Z_OBJ_P(this) == NULL) {
            this = NULL;
        }
    }
#endif
    if (this && Z_TYPE_P(this) != IS_OBJECT) {
        this = NULL;
    }

    return this;
}
#undef _EX

#if PHP_VERSION_ID >= 70000
static zend_always_inline int ddtrace_is_all_lower(zend_string *s) {
    unsigned char *c, *e;

    c = (unsigned char *)ZSTR_VAL(s);
    e = c + ZSTR_LEN(s);

    int rv = 1;
    while (c < e) {
        if (isupper(*c)) {
            rv = 0;
            break;
        }
        c++;
    }
    return rv;
}
#endif  // PHP7.x

void ddtrace_setup_fcall(zend_execute_data *execute_data, zend_fcall_info *fci, zval **result TSRMLS_DC);
zend_function *ddtrace_function_get(const HashTable *table, zval *name);
void ddtrace_dispatch_free_owned_data(ddtrace_dispatch_t *dispatch);
HashTable *ddtrace_new_class_lookup(zval *clazz TSRMLS_DC);
zend_bool ddtrace_dispatch_store(HashTable *class_lookup, ddtrace_dispatch_t *dispatch);
void ddtrace_forward_call_from_userland(zend_execute_data *execute_data, zval *return_value TSRMLS_DC);
BOOL_T ddtrace_should_trace_call(zend_execute_data *execute_data, zend_function **fbc,
                                 ddtrace_dispatch_t **dispatch TSRMLS_DC);

/**
 * trace.c
 */
void ddtrace_forward_call(zend_execute_data *execute_data, zend_function *fbc, zval *return_value TSRMLS_DC);
void ddtrace_execute_tracing_closure(zval *callable, zval *span_data, zend_execute_data *execute_data, zval *user_retval TSRMLS_DC);

#endif  // DISPATCH_COMPAT_H
