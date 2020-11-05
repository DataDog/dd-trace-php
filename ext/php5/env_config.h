#ifndef DD_ENV_CONFIG_H
#define DD_ENV_CONFIG_H
#include <stdint.h>

#include "compatibility.h"

#define BOOL_T uint8_t
#define TRUE (1)
#define FALSE (0)

/* ddtrace_getenv duplicates; efree it when done.
 * Do not call ddtrace_getenv from the background thread.
 * Returns the sapi_getenv or getenv.
 */
char *ddtrace_getenv(char *name, size_t name_len TSRMLS_DC);

/* ddtrace_getenv_multi duplicates; efree it when done.
 * Do not call ddtrace_getenv_multi from the background thread.
 * Returns the sapi_getenv or getenv.
 * If a value for primary exists then it uses it, otherwise the fallback name secondary is used.
 * This can be used in the common case when for BC compatibility we need to support multiple names for the same
 * environment variable.
 */
char *ddtrace_getenv_multi(char *primary, size_t primary_len, char *secondary, size_t secondary_len TSRMLS_DC);

BOOL_T ddtrace_get_bool_config(char *name, BOOL_T def TSRMLS_DC);
char *ddtrace_get_c_string_config(char *name TSRMLS_DC);
int64_t ddtrace_get_int_config(char *name, int64_t def TSRMLS_DC);
uint32_t ddtrace_get_uint32_config(char *name, uint32_t def TSRMLS_DC);
double ddtrace_get_double_config(char *name, double def TSRMLS_DC);
double ddtrace_char_to_double(char *subject, double default_value);
char *ddtrace_get_c_string_config_with_default(char *name, const char *def TSRMLS_DC);
char *ddtrace_strdup(const char *source);

#endif  // DD_ENV_CONFIG_H
