#ifndef DISPATCH_H
#define DISPATCH_H

#include "Zend/zend_types.h"
#include "compat_zend_string.h"

typedef struct _ddtrace_dispatch_t {
    zval callable;
    zend_uchar flags;
    zend_class_entry *clazz;
    STRING_T *function;
} ddtrace_dispatch_t;

zend_bool ddtrace_trace(zend_class_entry *, STRING_T *, zval *TSRMLS_DC);
int ddtrace_wrap_fcall(zend_execute_data *TSRMLS_DC);
void ddtrace_dispatch_init();
void ddtrace_dispatch_inject();
void ddtrace_dispatch_destroy();
void ddtrace_dispatch_reset();

#endif  // DISPATCH_H
