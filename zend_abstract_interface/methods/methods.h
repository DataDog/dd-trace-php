#ifndef ZAI_METHODS_H
#define ZAI_METHODS_H

#include <main/php.h>
#include <stdbool.h>

#include "../zai_compat.h"

/* Work in progress
 *
 * The long-term goal is to provide ZAI data-structure shims so that the
 * following APIs are the same across all PHP versions. For now we will provide
 * ZAI seams using native Zend Engine data structures.
 */

/* Looks up a class entry directly from the CG(class_table). The lookup is
 * case-sensitive so the class name should be lowercase. The class name should
 * not contain a root-scope '\' prefix. Does not invoke the autoloader. Returns
 * NULL if the class entry could not be found. Because the class lookup occurs
 * from the compiler globals, this can be called safely outside of a request
 * context.
 */
zend_class_entry *zai_class_lookup_ex(const char *cname, size_t cname_len ZAI_TSRMLS_DC);

/* Calls a method on an instance of 'object'. In error cases, 'retval' will be
 * allocated and set to IS_NULL. Caller must dtor the 'retval' after the call:
 *
 *   zval_ptr_dtor(&retval);
 *
 * Methods cannot be called outside of a request context so this MUST be called
 * from within a request context (after RINIT and before RSHUTDOWN).
 */
bool zai_call_method_ex(zval *object, const char *method, size_t method_len, zval **retval ZAI_TSRMLS_DC, int argc,
                        ...);

/* Calls a static method on a class entry 'ce'. Return value handling is the
 * same as zai_call_method().
 */
bool zai_call_static_method_ex(zend_class_entry *ce, const char *method, size_t method_len, zval **retval ZAI_TSRMLS_DC,
                               int argc, ...);

/* A convenience wrapper to call zai_class_lookup() using a string literal. This
 * API only works when the class name is a string literal. If the class name
 * exists as a 'const char *', use zai_class_lookup_ex() directly.
 */
#define zai_class_lookup_literal(cname) zai_class_lookup_ex(cname, (sizeof(cname) - 1) ZAI_TSRMLS_CC)

/* Convenience wrappers for string literals and populates 'argc'. */
#define zai_call_method_literal(object, method_name, retval, ...)                          \
    zai_call_method_ex(object, method_name, sizeof(method_name) - 1, retval ZAI_TSRMLS_CC, \
                       ZAI_CALL_METHOD_VA_ARG_COUNT(__VA_ARGS__), ##__VA_ARGS__)
#define zai_call_static_method_literal(ce, method_name, retval, ...)                          \
    zai_call_static_method_ex(ce, method_name, sizeof(method_name) - 1, retval ZAI_TSRMLS_CC, \
                              ZAI_CALL_METHOD_VA_ARG_COUNT(__VA_ARGS__), ##__VA_ARGS__)

#define ZAI_CALL_METHOD_VA_ARG_COUNT(...) ZAI_CALL_METHOD_VA_ARG_MAX(ignore, ##__VA_ARGS__, 8, 7, 6, 5, 4, 3, 2, 1, 0)
#define ZAI_CALL_METHOD_VA_ARG_MAX(arg1, arg2, arg3, arg4, arg5, arg6, arg7, arg8, arg9, arg10, ...) arg10

#endif  // ZAI_METHODS_H
