/*
 * The Tea Extension API may be used by internal and external components
 */
#ifndef TEA_EXTENSION_H
#define TEA_EXTENSION_H

#include "common.h"

#if PHP_VERSION_ID < 70000
#ifdef ZTS
#define TEA_EXTENSION_PARAMETERS_UNUSED() \
    (void)(ht);                           \
    (void)(return_value_ptr);             \
    (void)(return_value_used);            \
    (void)(this_ptr);                     \
    (void)(TEA_TSRMLS_C)
#else
#define TEA_EXTENSION_PARAMETERS_UNUSED() \
    (void)(ht);                           \
    (void)(return_value_ptr);             \
    (void)(return_value_used);            \
    (void)(this_ptr)
#endif
#else
#define TEA_EXTENSION_PARAMETERS_UNUSED() \
    (void)(execute_data);                 \
    (void)(return_value)
#endif

/* {{{ public typedefs */
#if PHP_VERSION_ID < 80000
typedef int zend_result_t;
#else
typedef zend_result zend_result_t;
#endif

typedef zend_result_t (*tea_extension_init_function)(INIT_FUNC_ARGS);
typedef zend_result_t (*tea_extension_shutdown_function)(SHUTDOWN_FUNC_ARGS); /* }}} */

/* {{{ prologue symbols */
/*
 * Shall give the TEA extension a specific name
 */
void tea_extension_name(const char *name, size_t len);

/*
 * Shall install an init stage handler
 */
void tea_extension_minit(tea_extension_init_function handler);
void tea_extension_rinit(tea_extension_init_function handler);

/*
 * Shall install a shutdown stage handler
 */
void tea_extension_rshutdown(tea_extension_shutdown_function handler);
void tea_extension_mshutdown(tea_extension_shutdown_function handler);

/*
 * Shall install functions
 */
void tea_extension_functions(const zend_function_entry *entry); /* }}} */

#endif  // TEA_EXTENSION_H
