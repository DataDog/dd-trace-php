#include "handlers_internal.h"

#include "compatibility.h"

void ddtrace_replace_internal_function(const HashTable *ht, ddtrace_string fname) { PHP5_UNUSED(ht, fname); }
void ddtrace_replace_internal_functions(const HashTable *ht, size_t functions_len, ddtrace_string functions[]) {
    PHP5_UNUSED(ht, functions_len, functions);
}
void ddtrace_replace_internal_methods(ddtrace_string Class, size_t methods_len, ddtrace_string methods[]) {
    PHP5_UNUSED(Class, methods_len, methods);
}

void ddtrace_internal_handlers_startup(void) {}
void ddtrace_internal_handlers_shutdown(void) {}
void ddtrace_internal_handlers_rshutdown(void) {}
