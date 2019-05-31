#ifndef DD_ENV_CONFIG_H
#define DD_ENV_CONFIG_H
#include <stdint.h>
#define BOOL_T uint8_t
#define TRUE (1)
#define FALSE (0)

BOOL_T ddtrace_get_bool_config(char *name, BOOL_T def);
char *ddtrace_get_c_string_config(char *name);
int64_t ddtrace_get_int_config(char *name, int64_t def);
uint32_t ddtrace_get_uint32_config(char *name, uint32_t def);

void ddtrace_env_free(void *ptr);

#endif  // DD_ENV_CONFIG_H
