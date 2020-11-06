#ifndef DDTRACE_HANDLERS_INTERNAL_H
#define DDTRACE_HANDLERS_INTERNAL_H

#include <php.h>

#include "ddtrace_string.h"

void ddtrace_replace_internal_function(const HashTable *ht, ddtrace_string fname);
void ddtrace_replace_internal_functions(const HashTable *ht, size_t functions_len, ddtrace_string functions[]);
void ddtrace_replace_internal_methods(ddtrace_string Class, size_t methods_len, ddtrace_string methods[]);

void ddtrace_internal_handlers_startup(void);
void ddtrace_internal_handlers_shutdown(void);
void ddtrace_internal_handlers_rshutdown(void);

#endif  // DDTRACE_HANDLERS_INTERNAL_H
