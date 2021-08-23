#ifndef ZAI_FUNCTIONS_H
#define ZAI_FUNCTIONS_H

#include <main/php.h>
#include <stdbool.h>

#include "../zai_compat.h"

/* Work in progress
 *
 * The long-term goal is to provide ZAI data-structure shims so that the
 * following APIs are the same across all PHP versions. For now we will provide
 * ZAI seams using native Zend Engine data structures.
 */

/* Calls a function with 'argc' number of zval arguments. Caller owns the
 * arguments and the 'retval'. Caller is responsible for freeing any refcounted
 * arguments. Caller must provide a pointer to a stack-allocated zval for the
 * return value, 'retval'. In error cases, 'retval' will be set to IS_UNDEF.
 * Caller must dtor the 'retval' after the call:
 *
 *   zval_ptr_dtor(&retval);
 *
 * Functions cannot be called outside of a request context so this MUST be
 * called from within a request context (after RINIT and before RSHUTDOWN).
 */
#if PHP_VERSION_ID < 70000
bool zai_call_function_ex(const char *name, size_t name_len, zval **retval TSRMLS_DC, int argc, ...);
#else
bool zai_call_function_ex(const char *name, size_t name_len, zval *retval, int argc, ...);
#endif

/* A wrapper for zai_call_function_ex() that automatically populates 'argc'. */
#define zai_call_function(name, name_len, retval, ...)                                                      \
    zai_call_function_ex(name, name_len, retval ZAI_TSRMLS_CC, ZAI_CALL_FUNCTION_VA_ARG_COUNT(__VA_ARGS__), \
                         ##__VA_ARGS__)

/* A convenience wrapper to call zai_call_function() using a string literal.
 * This API only works when the function name is a string literal. If the
 * function name exists as a 'const char *', use zai_call_function() or
 * zai_call_function_ex() directly. */
#define zai_call_function_literal(name, retval, ...) zai_call_function(name, sizeof(name) - 1, retval, ##__VA_ARGS__)

#define ZAI_CALL_FUNCTION_VA_ARG_COUNT(...) \
    ZAI_CALL_FUNCTION_VA_ARG_MAX(ignore, ##__VA_ARGS__, 8, 7, 6, 5, 4, 3, 2, 1, 0)
#define ZAI_CALL_FUNCTION_VA_ARG_MAX(arg1, arg2, arg3, arg4, arg5, arg6, arg7, arg8, arg9, arg10, ...) arg10

#endif  // ZAI_FUNCTIONS_H
