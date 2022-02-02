/*
 * TEA SAPI is designed to test software components that are tightly coupled to
 * the Zend Engine.
 *
 * TEA SAPI is a fork of the embed SAPI:
 * https://github.com/php/php-src/tree/master/sapi/embed
 */
#ifndef TEA_SAPI_H
#define TEA_SAPI_H

#include "common.h"

#include <main/SAPI.h>

/*
 * TODO reset 'tea_sapi_module' every SINIT to make sure no state lingers from
 * test to test.
 */
extern sapi_module_struct tea_sapi_module;

/* Initializes the SAPI, modules, and request. */
bool tea_sapi_spinup(void);
/* Shuts down the request, modules, and SAPI. */
void tea_sapi_spindown(void);

/* SINIT: SAPI initialization
 *
 * Characterized by sapi_startup().
 *   - Initialize SAPI globals
 *   - Allocate SAPI INI settings
 *   - Set startup behavior
 *   - Start up ZTS & signals
 */
bool tea_sapi_sinit(void);
void tea_sapi_sshutdown(void);

/* MINIT: Module initialization
 *
 * Basically a wrapper for php_module_startup().
 */
bool tea_sapi_minit(void);
void tea_sapi_mshutdown(void);

/* RINIT: Request initialization.
 *
 * Characterized by php_request_startup().
 *   - Set late-stage configuration
 *   - Send headers
 */
bool tea_sapi_rinit(void);
void tea_sapi_rshutdown(void);

/* Appends an INI entry to the existing 'ini_entries'.
 *
 *   tea_sapi_append_system_ini_entry("extension", "ddtrace.so");
 *
 * Must be called:
 *   - After SAPI initialization tea_sapi_sinit()
 *   - Before module initialization tea_sapi_minit()
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
bool tea_sapi_append_system_ini_entry(const char *key, const char *value);

/* Called from sapi_module_struct.register_server_variables */
extern void (*tea_sapi_register_custom_server_variables)(zval *track_vars_server_array TEA_TSRMLS_DC);

#endif  // TEA_SAPI_H
