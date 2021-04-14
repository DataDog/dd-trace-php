/*                   ____   __   __    ____   __   ____  __
 *                  (__  ) / _\ (  )  / ___) / _\ (  _ \(  )
 *                   / _/ /    \ )(   \___ \/    \ ) __/ )(
 *                  (____)\_/\_/(__)  (____/\_/\_/(__)  (__)
 *
 * ZAI SAPI is designed to test software components that are tightly coupled to
 * the Zend Engine.
 *
 * ZAI SAPI is a fork the the embed SAPI:
 * https://github.com/php/php-src/tree/master/sapi/embed
 */
#ifndef ZAI_SAPI_H
#define ZAI_SAPI_H

#include <main/php.h>
#include <stdbool.h>

#if PHP_VERSION_ID >= 70000 && defined(ZTS)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

/* Initializes the SAPI, modules, and request. */
bool zai_sapi_spinup(void);
/* Shuts down the request, modules, and SAPI. */
void zai_sapi_spindown(void);

/* SINIT: SAPI initialization
 *
 * Characterized by sapi_startup().
 *   - Initialize SAPI globals
 *   - Allocate SAPI INI settings
 *   - Set startup behavior
 *   - Start up ZTS & signals
 */
bool zai_sapi_sinit(void);
void zai_sapi_sshutdown(void);

/* MINIT: Module initialization
 *
 * Basically a wrapper for php_module_startup().
 */
bool zai_sapi_minit(void);
void zai_sapi_mshutdown(void);

/* RINIT: Request initialization.
 *
 * Characterized by php_request_startup().
 *   - Set late-stage configuration
 *   - Send headers
 */
bool zai_sapi_rinit(void);
void zai_sapi_rshutdown(void);

/* Appends an INI entry to the existing 'ini_entries'.
 *
 *   zai_sapi_append_system_ini_entry("extension", "ddtrace.so");
 *
 * Must be called:
 *   - After SAPI initialization zai_sapi_sinit()
 *   - Before module initialization zai_sapi_minit()
 *
 * We cannot use PHP's zend_alter_ini_entry_*() API to modify INI entries
 * before MINIT because the configuration hash table does not exist before
 * MINIT. The SAPI 'ini_entries' (member of the 'sapi_module_struct') and any
 * INI settings from '.ini' files are parsed and added to the configuration
 * hash table in php_init_config() during MINIT. If we need to add an INI
 * setting that is accessed very early in the startup process (i.e.
 * 'disable_functions' which is read as part of MINIT), then we must reallocate
 * our C string of SAPI 'ini_entries' and append to it before MINIT occurs.
 */
bool zai_sapi_append_system_ini_entry(const char *key, const char *value);

#endif  // ZAI_SAPI_H
