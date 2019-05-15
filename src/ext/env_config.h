#ifndef DD_CONFIG_H
#define DD_CONFIG_H
#include <php.h>

zend_bool ddtrace_get_bool_config(char *name, zend_bool def);
char *ddtrace_get_c_string_config(char *name);

#endif  // DD_CONFIG_H
