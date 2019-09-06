#ifndef DISPATCH_H
#define DISPATCH_H

#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>

#include "compatibility.h"

typedef struct _ddtrace_dispatch_t {
    zval callable, function_name;
    zend_bool run_as_postprocess;
    zend_bool busy;
    uint32_t acquired;
} ddtrace_dispatch_t;

ddtrace_dispatch_t *ddtrace_find_dispatch(zval *this, zend_function *fbc, zval *fname TSRMLS_DC);
zend_bool ddtrace_trace(zval *class_name, zval *function_name, zval *callable, zend_bool run_as_postprocess TSRMLS_DC);
int ddtrace_wrap_fcall(zend_execute_data *TSRMLS_DC);
void ddtrace_class_lookup_acquire(ddtrace_dispatch_t *);
void ddtrace_class_lookup_release(ddtrace_dispatch_t *);
zend_class_entry *ddtrace_target_class_entry(zval *, zval *TSRMLS_DC);
int ddtrace_find_function(HashTable *table, zval *name, zend_function **function);
void ddtrace_dispatch_init(TSRMLS_D);
void ddtrace_dispatch_inject(TSRMLS_D);
void ddtrace_dispatch_destroy(TSRMLS_D);
void ddtrace_dispatch_reset(TSRMLS_D);

#endif  // DISPATCH_H
