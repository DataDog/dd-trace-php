#ifndef DD_CONFIG_H
#define DD_CONFIG_H
#include <php.h>

zend_bool ddtrace_get_bool_config(char *name, zend_bool def);

#endif  // DD_CONFIG_H
