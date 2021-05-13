#ifndef ZAI_FUNCTIONS_H
#define ZAI_FUNCTIONS_H

#include <main/php.h>
#include <stdbool.h>

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
bool zai_call_function(const char *name, size_t name_len, zval *retval, int argc, ...);

/* Calls a function the same way as zai_call_function() but without passing any
 * arguments to the function.
 */
bool zai_call_function_without_args(const char *name, size_t name_len, zval *retval);

#endif  // ZAI_FUNCTIONS_H
