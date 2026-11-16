#ifndef DATADOG_EXCLUDED_MODULES_H
#define DATADOG_EXCLUDED_MODULES_H

#include <php.h>
#include <stdbool.h>

#define DATADOG_EXCLUDED_MODULES_ERROR_MAX_LEN 255

extern bool datadog_has_excluded_module;

void datadog_excluded_modules_startup();
bool datadog_is_excluded_module(zend_module_entry *module, char *error);

#endif  // DDTRACE_EXCLUDED_MODULES_H
