#ifndef DD_TRACE_SHARED_H
#define DD_TRACE_SHARED_H

#include <Zend/zend_string.h>

extern zend_string *ddtrace_php_version;

void ddshared_minit(void);

#endif  // DD_TRACE_SHARED_H
