/*                   ____   __   __    ____   __   ____  __
 *                  (__  ) / _\ (  )  / ___) / _\ (  _ \(  )
 *                   / _/ /    \ )(   \___ \/    \ ) __/ )(
 *                  (____)\_/\_/(__)  (____/\_/\_/(__)  (__)
 *
 * ZAI SAPI is designed to test software components that are tightly coupled to
 * the Zend Engine.
 *
 * ZAI SAPI is a fork of the embed SAPI:
 * https://github.com/php/php-src/tree/master/sapi/embed
 */
#ifndef ZAI_SAPI_H
#define ZAI_SAPI_H

#include <assert.h>
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

/* Executes a PHP script located at 'file'. Relative file paths are relative to
 * the path of the executable. A zend_bailout could occur here and this
 * function does not catch it. Returns false if the script exists but failed to
 * compile.
 */
bool zai_sapi_execute_script(const char *file);

/* Returns true if 'msg' exactly matches the last error message from PHP
 * globals.
 */
bool zai_sapi_last_error_message_eq(const char *msg);

/* Returns true if 'error_type' equals the last error type from PHP globals. */
bool zai_sapi_last_error_type_eq(int error_type);

/* Handling zend_bailout
 *
 * A test will provide a false-positive when a component calls zend_bailout.
 * This is because a zend_bailout will cause an unclean shutdown for
 * RSHUTDOWN, but the process will exit normally. For this reason it is
 * always important to explicitly test for expected or unexpected
 * zend_bailouts.
 *
 * When a zend_bailout is unexpected, wrap the component with:
 *
 *   ZAI_SAPI_ABORT_ON_BAILOUT_{OPEN|CLOSE}()
 *
 * When a zend_bailout is expected, wrap the component with:
 *
 *   ZAI_SAPI_BAILOUT_EXPECTED_{OPEN|CLOSE}()
 */
#define ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() zend_first_try {
#define ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()                      \
    }                                                          \
    zend_catch { assert(false && "Unexpected zend_bailout"); } \
    zend_end_try();

#define ZAI_SAPI_BAILOUT_EXPECTED_OPEN() zend_first_try {
#define ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()       \
    assert(false && "Expected a zend_bailout"); \
    }                                           \
    zend_end_try();

/* Obscured in the compiler directives below are the following function-call
 * wrappers:
 *
 * ---
 *
 * zend_result ZAI_SAPI_EVAL_STR(const char *str)
 *
 * Evaluates PHP code from a C string and ignores the userland return value.
 * Wrapper for 'zend_eval_stringl'.
 *
 * @param str The PHP code to be evaluated without the opening PHP tag '<php'
 *
 * @return (SUCCESS|FAILURE)
 *
 * ---
 *
 * char *ZAI_SAPI_INI_STR(const char *name)
 *
 * Accesses an INI value by name. Wrapper for 'zend_ini_string_ex'.
 *
 * @param name The name of the INI setting
 *
 * @return A C string containing the INI value (if the INI setting has been
 *         modified the modified value will be returned) or NULL if the entry
 *         could not be found in the EG(ini_directives) hash table
 */

#if PHP_VERSION_ID >= 80000
/********************************** <PHP 8> **********************************/
#define ZAI_SAPI_EVAL_STR(str) zend_eval_stringl(str, sizeof(str) - 1, NULL, "ZAI SAPI")
#define ZAI_SAPI_INI_STR(name) zend_ini_string_ex((name), strlen(name), 0, NULL)
/********************************** </PHP 8> *********************************/
#elif PHP_VERSION_ID >= 70000
/********************************** <PHP 7> **********************************/
#define ZAI_SAPI_EVAL_STR(str) zend_eval_stringl((char *)str, sizeof(str) - 1, NULL, (char *)"ZAI SAPI")
#define ZAI_SAPI_INI_STR(name) zend_ini_string_ex((char *)(name), strlen(name), 0, NULL)
/********************************** </PHP 7> *********************************/
#else
/********************************** <PHP 5> **********************************/
#undef ZAI_SAPI_ABORT_ON_BAILOUT_OPEN
#define ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() \
    TSRMLS_FETCH();                      \
    zend_first_try {
#undef ZAI_SAPI_BAILOUT_EXPECTED_OPEN
#define ZAI_SAPI_BAILOUT_EXPECTED_OPEN() \
    TSRMLS_FETCH();                      \
    zend_first_try {
#define ZAI_SAPI_EVAL_STR(str) zend_eval_stringl((char *)str, sizeof(str) - 1, NULL, (char *)"ZAI SAPI" TSRMLS_CC)
#define ZAI_SAPI_INI_STR(name) zend_ini_string_ex((char *)(name), sizeof(name) /* - 1 */, 0, NULL)
/********************************** </PHP 5> *********************************/
#endif

#endif  // ZAI_SAPI_H
