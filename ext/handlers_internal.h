#ifndef DDTRACE_HANDLERS_INTERNAL_H
#define DDTRACE_HANDLERS_INTERNAL_H

#include <php.h>

#include "handlers_api.h"
#include "ddtrace_string.h"

void ddtrace_replace_internal_function(const HashTable *ht, ddtrace_string fname);
void ddtrace_replace_internal_functions(const HashTable *ht, size_t functions_len, ddtrace_string functions[]);

void ddtrace_free_unregistered_class(zend_class_entry *ce);

void ddtrace_internal_handlers_startup(void);
void ddtrace_internal_handlers_shutdown(void);
void ddtrace_internal_handlers_rinit(void);
void ddtrace_internal_handlers_rshutdown(void);

#endif  // DDTRACE_HANDLERS_INTERNAL_H
