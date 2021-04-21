#ifndef ZAI_SAPI_FUNCTIONS_H
#define ZAI_SAPI_FUNCTIONS_H

#include <main/php.h>

/* These functions only exist in the ZAI SAPI for testing at the C unit test
 * level and are not shipped as a public userland API in the PHP tracer.
 */
extern const zend_function_entry zai_sapi_functions[];

#endif  // ZAI_SAPI_FUNCTIONS_H
