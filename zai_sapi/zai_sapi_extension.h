#ifndef ZAI_SAPI_EXTENSION_H
#define ZAI_SAPI_EXTENSION_H

#include <main/php.h>

/* This is an extra PHP extension that is loaded at MINIT. Modify
 * 'zai_sapi_extension' before MINIT with custom values before a test to mimic
 * the desired module behavior. This global is reset to the original state
 * automatically during SAPI initialization (SINIT).
 */
extern zend_module_entry zai_sapi_extension;

/* Resets to 'zai_sapi_extension' global to the original state. */
void zai_sapi_reset_extension_global(void);

#endif  // ZAI_SAPI_EXTENSION_H
