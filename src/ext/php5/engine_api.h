#ifndef DDTRACE_PHP_5_ENGINE_API_H
#define DDTRACE_PHP_5_ENGINE_API_H

/* This file is for things that make working with the engine easier. Good
 * candidates include:
 *   - Wrappers for functions which change signature/semantics over time.
 *   - Wrappers that reduce verbosity when working with zend_* functions.
 *   - Functions that perform high-level language tasks, such as reading and
 *     writing object properties, calling functions, calling methods, etc.
 */

#include <php.h>

int ddtrace_call_sandboxed_function(const char *name, size_t name_len, zval **retval, int argc,
                                    zval **argv[] TSRMLS_DC);

#endif  // DDTRACE_PHP_5_ENGINE_API_H
