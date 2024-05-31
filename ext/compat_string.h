#ifndef COMPAT_STRING_H
#define COMPAT_STRING_H
#include <Zend/zend.h>
#include <Zend/zend_types.h>
#include <php_version.h>

#include "compatibility.h"

void ddtrace_downcase_zval(zval *src);

// ddtrace_spprintf is a replacement for zend_spprintf, since it is not exported in many versions
size_t ddtrace_spprintf(char **message, size_t max_len, char *format, ...);

/**
 * dst will be IS_STRING after the call; caller must dtor.
 * Uses semantics similar to casting to string, except that:
 *   1. It will not emit warnings or throw exceptions
 *   2. Objects are in the form object(%s)#%d, where %s is the class name and %d is the object handle (like var_dump).
 *      The we avoid the __toString cast here to not execute user code at this place. Also avoids possible stack
 *      overflows due to recursion stemming from our own code as the root.
 **/
void ddtrace_convert_to_string(zval *dst, zval *src);
zend_string *ddtrace_convert_to_str(const zval *op);

#endif  // COMPAT_STRING_H
