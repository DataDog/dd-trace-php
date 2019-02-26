#ifndef COMPAT_ZEND_STRING_H
#define COMPAT_ZEND_STRING_H
#include "Zend/zend.h"
#include "Zend/zend_types.h"

void ddtrace_downcase_zval(zval *src);

#endif  // COMPAT_ZEND_STRING_H
