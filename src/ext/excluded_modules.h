#ifndef DDTRACE_EXCLUDED_MODULES_H
#define DDTRACE_EXCLUDED_MODULES_H

#include <php.h>
#include <stdbool.h>

extern bool ddtrace_has_excluded_module;

void ddtrace_excluded_modules_startup();

#endif  // DDTRACE_EXCLUDED_MODULES_H
