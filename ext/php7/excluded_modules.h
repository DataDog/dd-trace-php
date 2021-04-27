#ifndef DDTRACE_EXCLUDED_MODULES_H
#define DDTRACE_EXCLUDED_MODULES_H

#include <php.h>
#include <stdbool.h>

#define DDTRACE_EXCLUDED_MODULES_ERROR_MAX_LEN 255

extern bool ddtrace_has_excluded_module;

void ddtrace_excluded_modules_startup();
bool ddtrace_is_excluded_module(zend_module_entry *module, char *error);

#endif  // DDTRACE_EXCLUDED_MODULES_H
