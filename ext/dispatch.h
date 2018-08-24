#ifndef DISPATCH_H
#define DISPATCH_H

#include "Zend/zend_inheritance.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_closures.h"
#include "compat_zend_string.h"

typedef struct _ddtrace_dispatch_t {
    zval callable;
    zend_uchar flags;
    zend_class_entry *clazz;
	STRING_T *function;
} ddtrace_dispatch_t;

zend_bool ddtrace_trace(zend_class_entry *, STRING_T *, zval *);
int ddtrace_wrap_fcall(zend_execute_data *);
void ddtrace_dispatch_init();

#endif