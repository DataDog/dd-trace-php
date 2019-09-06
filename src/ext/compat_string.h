#ifndef COMPAT_STRING_H
#define COMPAT_STRING_H
#include <Zend/zend.h>
#include <Zend/zend_types.h>
#include <php_version.h>

#include "compatibility.h"

void ddtrace_downcase_zval(zval *src);

// ddtrace_spprintf is a replacement for zend_spprintf, since it is not exported in many versions
#if PHP_VERSION_ID < 70000
int ddtrace_spprintf(char **message, size_t max_len, char *format, ...);
#else
size_t ddtrace_spprintf(char **message, size_t max_len, char *format, ...);
#endif

/* dst will either be IS_STRING or IS_NULL; caller must dtor */
#if PHP_VERSION_ID < 70000
void ddtrace_try_get_string(zval *dst, zval *src ZEND_FILE_LINE_DC TSRMLS_DC);
#else
void ddtrace_try_get_string(zval *dst, zval *src);
#endif

#endif  // COMPAT_STRING_H
