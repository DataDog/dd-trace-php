#ifndef DISPATCH_H
#define DISPATCH_H

#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>

#include "compat_zend_string.h"
#include "ddtrace.h"

typedef struct _ddtrace_dispatch_t {
    zval function_name, expected_arg_types;//, expected_return_type
    zval callable, callable_append;
    zend_bool busy;
    uint32_t acquired;
} ddtrace_dispatch_t;

typedef struct _ddtrace_lookup_data_t {
#if PHP_VERSION_ID < 70000
    const char *function_name;
    uint32_t function_name_length;
#else
    zend_string *function_name;
#endif
} ddtrace_lookup_data_t;

zend_bool ddtrace_trace(zval *, zval *, zval *, enum ddtrace_callback_behavior, zval *TSRMLS_DC);
int ddtrace_wrap_fcall(zend_execute_data *TSRMLS_DC);
void ddtrace_class_lookup_acquire(ddtrace_dispatch_t *);
void ddtrace_class_lookup_release(ddtrace_dispatch_t *);
zend_class_entry *ddtrace_target_class_entry(zval *, zval *TSRMLS_DC);
int ddtrace_find_function(HashTable *table, zval *name, zend_function **function);
void ddtrace_dispatch_init(TSRMLS_D);
void ddtrace_dispatch_inject(TSRMLS_D);
void ddtrace_dispatch_destroy(TSRMLS_D);
void ddtrace_dispatch_reset(TSRMLS_D);
void ddtrace_span_stack_init(TSRMLS_D);
void ddtrace_span_stack_destroy(TSRMLS_D);
ddtrace_span_stack_t *ddtrace_span_stack_create_and_push(TSRMLS_D);
void ddtrace_alloc_tracing_closure_args(zend_fcall_info *fci, zend_fcall_info_cache *fcc, zval *span_data, zend_execute_data *execute_data);

#endif  // DISPATCH_H
