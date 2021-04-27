#ifndef COMPAT_STRING_H
#define COMPAT_STRING_H
#include <Zend/zend.h>
#include <Zend/zend_types.h>
#include <php_version.h>

#include "compatibility.h"

void ddtrace_downcase_zval(zval *src);

// ddtrace_spprintf is a replacement for zend_spprintf, since it is not exported in many versions
int ddtrace_spprintf(char **message, size_t max_len, char *format, ...);

/**
 * dst will be IS_STRING after the call; caller must dtor.
 * Uses semantics similar to casting to string, except that:
 *   1. It will not emit warnings or throw exceptions
 *   2. Objects which cannot be converted using norm rules are in the form
 *      object(%s)#%d, where %s is the class name and %d is the object handle
 *      (like var_dump).
 **/
void ddtrace_convert_to_string(zval *dst, zval *src TSRMLS_DC);

#endif  // COMPAT_STRING_H
