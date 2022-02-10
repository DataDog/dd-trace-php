#ifndef DDTRACE_HANDLERS_INTERNAL_H
#define DDTRACE_HANDLERS_INTERNAL_H

#include <Zend/zend_extensions.h>
#include <php.h>

#include "ddtrace_string.h"

typedef struct dd_zif_handler {
    const char *name;
    size_t name_len;
    void (**old_handler)(INTERNAL_FUNCTION_PARAMETERS);
    void (*new_handler)(INTERNAL_FUNCTION_PARAMETERS);
} dd_zif_handler;

void dd_install_handler(dd_zif_handler handler);

void ddtrace_replace_internal_function(const HashTable *ht, ddtrace_string fname);
void ddtrace_replace_internal_functions(const HashTable *ht, size_t functions_len, ddtrace_string functions[]);
void ddtrace_replace_internal_methods(ddtrace_string Class, size_t methods_len, ddtrace_string methods[]);

void ddtrace_free_unregistered_class(zend_class_entry *ce);

void ddtrace_internal_handlers_startup(zend_extension *ddtrace_extension);
void ddtrace_internal_handlers_shutdown(void);
void ddtrace_internal_handlers_rinit(void);
void ddtrace_internal_handlers_rshutdown(void);

#endif  // DDTRACE_HANDLERS_INTERNAL_H
