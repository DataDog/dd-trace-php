#include "handlers_internal.h"

#include "compatibility.h"

void ddtrace_replace_internal_function(const HashTable *ht, ddtrace_string fname) { UNUSED(ht, fname); }
void ddtrace_replace_internal_functions(const HashTable *ht, size_t functions_len, ddtrace_string functions[]) {
    UNUSED(ht, functions_len, functions);
}
void ddtrace_replace_internal_methods(ddtrace_string Class, size_t methods_len, ddtrace_string methods[]) {
    UNUSED(Class, methods_len, methods);
}

void ddtrace_curl_handlers_startup(void);
void ddtrace_curl_handlers_rshutdown(TSRMLS_D);
void ddtrace_pcntl_handlers_startup(void);

void ddtrace_internal_handlers_startup(void) {
    ddtrace_curl_handlers_startup();
    // pcntl handlers have to run even if tracing of pcntl extension is not enabled.
    ddtrace_pcntl_handlers_startup();
}
void ddtrace_internal_handlers_shutdown(void) {}
void ddtrace_internal_handlers_rshutdown(TSRMLS_D) { ddtrace_curl_handlers_rshutdown(TSRMLS_C); }
