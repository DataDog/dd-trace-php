#ifndef DD_ENV_CONFIG_H
#define DD_ENV_CONFIG_H
#include <stdint.h>

// make ZTS macros an optional include if ZTS is not defined
#if ZTS
#include <TSRM.h>
#define _TSRMLS_DC TSRMLS_DC
#else
#define _TSRMLS_DC
#endif

#define BOOL_T uint8_t
#define TRUE (1)
#define FALSE (0)

BOOL_T ddtrace_get_bool_config(char *name, BOOL_T def _TSRMLS_DC);
char *ddtrace_get_c_string_config(char *name _TSRMLS_DC);
int64_t ddtrace_get_int_config(char *name, int64_t def _TSRMLS_DC);
uint32_t ddtrace_get_uint32_config(char *name, uint32_t def _TSRMLS_DC);
char *ddtrace_get_c_string_config_with_default(char *name, const char *def _TSRMLS_DC);
char *ddtrace_strdup(const char *c);

#undef _TSRMLS_DC
#endif  // DD_ENV_CONFIG_H
