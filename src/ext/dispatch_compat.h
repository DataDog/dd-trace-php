#ifndef DISPATCH_COMPAT_H
#define DISPATCH_COMPAT_H

#include <Zend/zend_types.h>

#include "dispatch.h"
#include "env_config.h"
#include "span.h"

#if PHP_VERSION_ID < 70000
#include "dispatch_compat_php5.h"
void ddtrace_class_lookup_release_compat(void *zv);

#define ddtrace_zval_ptr_dtor(x) zval_dtor(x)
#else
void ddtrace_class_lookup_release_compat(zval *zv);

#define ddtrace_zval_ptr_dtor(x) zval_ptr_dtor(x)
#define INIT_ZVAL(x) ZVAL_NULL(&x)
#endif

static zend_always_inline zval *ddtrace_this(zend_execute_data *execute_data) {
    zval *this = NULL;
#if PHP_VERSION_ID < 50500
    if (EX(opline)->opcode != ZEND_DO_FCALL && EX(object)) {
        this = EX(object);
    }
#elif PHP_VERSION_ID < 70000
    if (EX(opline)->opcode != ZEND_DO_FCALL) {
        this = EX(call) ? EX(call)->object : NULL;
    }
#else
    if (EX(call)) {
        if (Z_OBJ(EX(call)->This) != NULL) {
            this = &(EX(call)->This);
        }
    }
#endif
    if (this && Z_TYPE_P(this) != IS_OBJECT) {
        this = NULL;
    }

    return this;
}

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
void ddtrace_wrapper_forward_call_from_userland(zend_execute_data *execute_data, zval *return_value TSRMLS_DC);
BOOL_T ddtrace_should_trace_call(zend_execute_data *execute_data, zend_function **fbc,
                                 ddtrace_dispatch_t **dispatch TSRMLS_DC);
void ddtrace_copy_function_args(zend_execute_data *execute_data, zval *user_args);

#if PHP_VERSION_ID < 70000
int ddtrace_forward_call(zend_execute_data *execute_data, zend_function *fbc, zval *return_value TSRMLS_DC);
void ddtrace_span_attach_exception(ddtrace_span_t *span, zval *exception);
void ddtrace_execute_tracing_closure(zval *callable, zval *span_data, zend_execute_data *execute_data, zval *user_args,
                                     zval *user_retval, zval *exception TSRMLS_DC);
#else
int ddtrace_forward_call(zend_execute_data *execute_data, zend_function *fbc, zval *return_value, zend_fcall_info *fci,
                         zend_fcall_info_cache *fcc TSRMLS_DC);
void ddtrace_span_attach_exception(ddtrace_span_t *span, zend_object *exception);
void ddtrace_execute_tracing_closure(zval *callable, zval *span_data, zend_execute_data *execute_data, zval *user_args,
                                     zval *user_retval, zend_object *exception TSRMLS_DC);
#endif

#endif  // DISPATCH_COMPAT_H
