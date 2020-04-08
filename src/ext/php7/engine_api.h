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

// Do not pass things like "parent", "self", "static" -- fully qualified names only!
zend_class_entry *ddtrace_lookup_ce(const char *str, size_t len);

/* Returns a zval containing a copy of the string; caller must release.
 * Makes initialization easier e.g.
 *     zval putForResource = ddtrace_zval_stringl(ZEND_STRL("putForResource"));
 *     // don't forget!
 *     zend_string_release(Z_STR(putForResource));
 */
zval ddtrace_zval_stringl(const char *str, size_t len);

inline zval ddtrace_zval_long(zend_long num) {
    zval zv;
    ZVAL_LONG(&zv, num)
    return zv;
}

inline zval ddtrace_zval_null(void) {
    zval zv;
    ZVAL_NULL(&zv);
    return zv;
}

ZEND_RESULT_CODE ddtrace_call_method(zend_object *obj, zend_class_entry *ce, zend_function **fn_proxy,
                                     const char *fname, size_t fname_len, zval *retval, int argc, zval *argv);
ZEND_RESULT_CODE ddtrace_call_function(const char *name, size_t name_len, zval *retval, int argc, zval argv[]);

void ddtrace_write_property(zval *obj, const char *prop, size_t prop_len, zval *value);
bool ddtrace_property_exists(zval *object, zval *property);
ZEND_RESULT_CODE ddtrace_read_property(zval *dest, zval *obj, const char *prop, size_t prop_len);

#endif  // DDTRACE_ENGINE_API_H
