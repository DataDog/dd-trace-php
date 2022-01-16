#ifndef TEA_EXCEPTIONS_H
#define TEA_EXCEPTIONS_H

#include "common.h"

#include <Zend/zend_exceptions.h>
#include <main/php_variables.h>

/* Throws an exception using the default exception class entry and sets the
 * 'Exception::$message' string to 'message'. Returns the class entry used for
 * the thrown exception. An execution context (an active PHP frame stack) must
 * exist or this will raise a fatal error and call zend_bailout. If the
 * exception is not handled by the PHP runtime, caller must free the exception
 * with tea_exception_ignore() before RSHUTDOWN to prevent a ZMM
 * leak.
 */
zend_class_entry *tea_exception_throw(const char *message TEA_TSRMLS_DC);

/* Returns true if there is an exception that matches the class entry
 * 'ce' and the 'Exception::$message' string is equal to 'message'.
 */
bool tea_exception_eq(zend_class_entry *ce, const char *message TEA_TSRMLS_DC);

/* Returns true if there is an exception. */
bool tea_exception_exists(TEA_TSRMLS_D);

/* Frees exception from the executor globals. */
void tea_exception_ignore(TEA_TSRMLS_D);

#endif  // TEA_EXCEPTIONS_H
