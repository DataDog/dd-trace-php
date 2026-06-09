#ifndef DDTRACE_HANDLERS_INTERNAL_H
#define DDTRACE_HANDLERS_INTERNAL_H

#include <php.h>

#include <ext/handlers_api.h>

void ddtrace_free_unregistered_class(zend_class_entry *ce);

void ddtrace_internal_handlers_startup(void);
void ddtrace_internal_handlers_shutdown(void);
void ddtrace_internal_handlers_rinit(void);
void ddtrace_internal_handlers_rshutdown(void);

#endif  // DDTRACE_HANDLERS_INTERNAL_H
