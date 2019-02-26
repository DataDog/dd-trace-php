#ifndef COMPAT_ZEND_STRING_H
#define COMPAT_ZEND_STRING_H
#include "Zend/zend.h"

#if PHP_VERSION_ID < 70000
#include "Zend/zend_types.h"

#define STRING_T zval
#define STRING_TOLOWER(x) ddtrace_string_tolower(x)
#define STRING_VAL(x) (x)
#define STRING_VAL_CHAR(x) (Z_STRVAL_P(x))
zval *ddtrace_string_tolower(zval *str);
#else
#define STRING_VAL(x) ZSTR_VAL(x)
#define STRING_VAL_CHAR(x) ZSTR_VAL(x)
#define STRING_T zend_string
#define STRING_TOLOWER(x) zend_string_tolower(x)
#endif  // PHP_VERSION_ID < 70000

#endif  // COMPAT_ZEND_STRING_H
