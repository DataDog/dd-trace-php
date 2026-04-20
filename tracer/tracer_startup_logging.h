#ifndef DD_TRACE_STARTUP_LOGGING_H
#define DD_TRACE_STARTUP_LOGGING_H

#include <Zend/zend_smart_str.h>
#include <php.h>
#include <stdbool.h>

void ddtrace_startup_logging_extra(void (*log)(const char *format, ...));
void ddtrace_populate_startup_config(HashTable *ht);
void ddtrace_startup_diagnostics(HashTable *ht, bool quick);

#endif  // DD_TRACE_STARTUP_LOGGING_H
