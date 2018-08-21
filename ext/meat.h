#ifndef MEAT_H
#define MEAT_H

#include "Zend/zend_inheritance.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_closures.h"

typedef struct _ddtrace_dispatch_t {
    zval callable;
    zend_uchar flags;
    zend_class_entry *clazz;
	zend_string *function;
} ddtrace_dispatch_t;

zend_bool ddtrace_trace(zend_class_entry *, zend_string *, zval *);
int ddtrace_wrap_fcall(zend_execute_data *);

#endif