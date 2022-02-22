#ifndef DDTRACE_ENGINE_API_H
#define DDTRACE_ENGINE_API_H

/* This file is for things that make working with the engine easier. Good
 * candidates include:
 *   - Wrappers for functions which change signature/semantics over time.
 *   - Wrappers that reduce verbosity when working with zend_* functions.
 *   - Functions that perform high-level language tasks, such as reading and
 *     writing object properties, calling functions, calling methods, etc.
 */

#include <php.h>
#include <stdbool.h>

/* Returns a zval containing a copy of the string; caller must release.
 * Makes initialization easier e.g.
 *     zval putForResource = ddtrace_zval_stringl(ZEND_STRL("putForResource"));
 *     // don't forget!
 *     zend_string_release(Z_STR(putForResource));
 */
zval ddtrace_zval_stringl(const char *str, size_t len);

static inline zval ddtrace_zval_zstr(zend_string *str) {
    zval zv;
    ZVAL_STR(&zv, str);  // does not copy
    return zv;
}

inline zval ddtrace_zval_long(zend_long num) {
    zval zv;
    ZVAL_LONG(&zv, num);
    return zv;
}

inline zval ddtrace_zval_null(void) {
    zval zv;
    ZVAL_NULL(&zv);
    return zv;
}

inline zval ddtrace_zval_undef(void) {
    zval zv;
    ZVAL_UNDEF(&zv);
    return zv;
}

void ddtrace_write_property(zval *obj, const char *prop, size_t prop_len, zval *value);
bool ddtrace_property_exists(zval *object, zval *property);
ZEND_RESULT_CODE ddtrace_read_property(zval *dest, zval *obj, const char *prop, size_t prop_len);

#endif  // DDTRACE_ENGINE_API_H
