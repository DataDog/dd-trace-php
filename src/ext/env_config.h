#ifndef DD_ENV_CONFIG_H
#define DD_ENV_CONFIG_H
#include <stdint.h>

unsigned char ddtrace_get_bool_config(char *name, unsigned char def);
char *ddtrace_get_c_string_config(char *name);
int64_t ddtrace_get_int_config(char *name, int64_t def);

#endif  // DD_ENV_CONFIG_H
