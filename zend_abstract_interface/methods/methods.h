#ifndef ZAI_METHODS_H
#define ZAI_METHODS_H

#include <main/php.h>
#include <stdbool.h>

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
zend_class_entry *zai_class_lookup_ex(const char *cname, size_t cname_len TSRMLS_DC);

/* Calls a method on an instance of 'object' without passing any arguments.
 * Caller must pass in a NULL pointer-pointer for the return value 'retval'.
 * Caller must dtor a non-NULL 'retval' after the call:
 *
 *   if (retval) {
 *     zval_ptr_dtor(&retval);
 *   }
 *
 * Methods cannot be called outside of a request context so this MUST be called
 * from within a request context (after RINIT and before RSHUTDOWN).
 */
bool zai_call_method_without_args_ex(zval *object, const char *method, size_t method_len, zval **retval TSRMLS_DC);

/* Calls a static method on a class entry 'ce' without passing any arguments.
 * Return value handling is the same as zai_call_method_without_args().
 */
bool zai_call_static_method_without_args_ex(zend_class_entry *ce, const char *method, size_t method_len,
                                            zval **retval TSRMLS_DC);

/* Mask away the TSRMLS_* macros with more macros */
#define zai_class_lookup(...) zai_class_lookup_ex(__VA_ARGS__ TSRMLS_CC)
#define zai_call_method_without_args(...) zai_call_method_without_args_ex(__VA_ARGS__ TSRMLS_CC)
#define zai_call_static_method_without_args(...) zai_call_static_method_without_args_ex(__VA_ARGS__ TSRMLS_CC)

#endif  // ZAI_METHODS_H
