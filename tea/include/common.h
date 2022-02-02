#ifndef HAVE_TEA_COMMON_H
#define HAVE_TEA_COMMON_H

#include <assert.h>
#include <main/php.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/types.h>

/* TSRM */
#ifndef TSRMLS_FETCH
#define TEA_TSRMLS_FETCH()
#define TEA_TSRMLS_DC
#define TEA_TSRMLS_D
#define TEA_TSRMLS_C
#define TEA_TSRMLS_CC
#else
#define TEA_TSRMLS_FETCH TSRMLS_FETCH
#define TEA_TSRMLS_DC TSRMLS_DC
#define TEA_TSRMLS_D TSRMLS_D
#define TEA_TSRMLS_C TSRMLS_C
#define TEA_TSRMLS_CC TSRMLS_CC
#endif

#if PHP_VERSION_ID >= 70000 && defined(ZTS)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

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
 *   TEA_SAPI_ABORT_ON_BAILOUT_{OPEN|CLOSE}()
 *
 * When a zend_bailout is expected, wrap the component with:
 *
 *   TEA_SAPI_BAILOUT_EXPECTED_{OPEN|CLOSE}()
 */
#define TEA_ABORT_ON_BAILOUT_OPEN() zend_first_try {
#define TEA_ABORT_ON_BAILOUT_CLOSE()                           \
    }                                                          \
    zend_catch { assert(false && "Unexpected zend_bailout"); } \
    zend_end_try();

#define TEA_BAILOUT_EXPECTED_OPEN() zend_first_try {
#define TEA_BAILOUT_EXPECTED_CLOSE()            \
    assert(false && "Expected a zend_bailout"); \
    }                                           \
    zend_end_try();

/* Obscured in the compiler directives below are the following function-call
 * wrappers:
 *
 * ---
 *
 * zend_result TEA_EVAL_STR(const char *str)
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
 * char *TEA_INI_STR(const char *name)
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
#define TEA_EVAL_STR(str) zend_eval_stringl(str, sizeof(str) - 1, NULL, "TEA")
#define TEA_INI_STR(name) zend_ini_string_ex((name), strlen(name), 0, NULL)
/********************************** </PHP 8> *********************************/
#elif PHP_VERSION_ID >= 70000
/********************************** <PHP 7> **********************************/
#define TEA_EVAL_STR(str) zend_eval_stringl((char *)str, sizeof(str) - 1, NULL, (char *)"TEA")
#define TEA_INI_STR(name) zend_ini_string_ex((char *)(name), strlen(name), 0, NULL)
/********************************** </PHP 7> *********************************/
#else
/********************************** <PHP 5> **********************************/
#define TEA_EVAL_STR(str) zend_eval_stringl((char *)str, sizeof(str) - 1, NULL, (char *)"TEA" TEA_TSRMLS_CC)
#define TEA_INI_STR(name) zend_ini_string_ex((char *)(name), sizeof(name) /* - 1 */, 0, NULL)
/********************************** </PHP 5> *********************************/
#endif

/* Executes a PHP script located at 'file'. Relative file paths are relative to
 * the path of the executable. A zend_bailout could occur here and this
 * function does not catch it. Returns false if the script exists but failed to
 * compile.
 */
static inline bool tea_execute_script(const char *file TEA_TSRMLS_DC) {
    zend_file_handle handle;

    memset((void *)&handle, 0, sizeof(zend_file_handle));
    handle.type = ZEND_HANDLE_FILENAME;
#if PHP_VERSION_ID >= 80000
    zend_stream_init_filename(&handle, file);
#else
    handle.filename = file;
#endif

    return zend_execute_scripts(ZEND_REQUIRE TEA_TSRMLS_CC, NULL, 1, &handle) == SUCCESS;
}
#endif
