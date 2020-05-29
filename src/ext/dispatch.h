#ifndef DISPATCH_H
#define DISPATCH_H

#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>

#include "compatibility.h"

#define DDTRACE_DISPATCH_INNERHOOK (1u << 0u)
#define DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED (1u << 1u)
#define DDTRACE_DISPATCH_POSTHOOK (1u << 2u)
#define DDTRACE_DISPATCH_PREHOOK (1u << 3u)

typedef struct ddtrace_dispatch_t {
    uint32_t options;
    zval callable, function_name;
    zend_bool busy;
    uint32_t acquired;
} ddtrace_dispatch_t;

ddtrace_dispatch_t *ddtrace_find_dispatch(zend_class_entry *scope, zval *fname TSRMLS_DC);
zend_bool ddtrace_trace(zval *class_name, zval *function_name, zval *callable, uint32_t options TSRMLS_DC);

void ddtrace_dispatch_dtor(ddtrace_dispatch_t *dispatch);

inline void ddtrace_dispatch_copy(ddtrace_dispatch_t *dispatch) { dispatch->acquired++; }

inline void ddtrace_dispatch_release(ddtrace_dispatch_t *dispatch) {
    if (--dispatch->acquired == 0) {
        ddtrace_dispatch_dtor(dispatch);
        efree(dispatch);
    }
}

zend_class_entry *ddtrace_target_class_entry(zval *, zval *TSRMLS_DC);
int ddtrace_find_function(HashTable *table, zval *name, zend_function **function);
void ddtrace_dispatch_init(TSRMLS_D);
void ddtrace_dispatch_destroy(TSRMLS_D);
void ddtrace_dispatch_reset(TSRMLS_D);

#if PHP_VERSION_ID < 70000

#undef EX
#define EX(element) ((execute_data)->element)

#if PHP_VERSION_ID < 50600
#define FBC() (EX(call)->fbc)
#define NUM_ADDITIONAL_ARGS() (0)
#define OBJECT() (EX(call) ? EX(call)->object : NULL)
#else
#define FBC() (EX(call)->fbc)
#define NUM_ADDITIONAL_ARGS() EX(call)->num_additional_args
#define OBJECT() (EX(call) ? EX(call)->object : NULL)
#endif

void ddtrace_class_lookup_release_compat(void *zv);

#define ddtrace_zval_ptr_dtor(x) zval_dtor(x)
#else
void ddtrace_class_lookup_release_compat(zval *zv);

#define ddtrace_zval_ptr_dtor(x) zval_ptr_dtor(x)
#define INIT_ZVAL(x) ZVAL_NULL(&x)
#endif

zend_function *ddtrace_function_get(const HashTable *table, zval *name);
HashTable *ddtrace_new_class_lookup(zval *clazz TSRMLS_DC);
zend_bool ddtrace_dispatch_store(HashTable *class_lookup, ddtrace_dispatch_t *dispatch);
void ddtrace_wrapper_forward_call_from_userland(zend_execute_data *execute_data, zval *return_value TSRMLS_DC);

#endif  // DISPATCH_H
