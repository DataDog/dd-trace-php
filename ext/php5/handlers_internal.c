#include "handlers_internal.h"

#include "compatibility.h"

void ddtrace_replace_internal_functions(const HashTable *ht, size_t functions_len, ddtrace_string functions[]) {
    UNUSED(ht, functions_len, functions);
}
void ddtrace_replace_internal_methods(ddtrace_string Class, size_t methods_len, ddtrace_string methods[]) {
    UNUSED(Class, methods_len, methods);
}

void dd_install_handler(dd_zif_handler handler TSRMLS_DC) {
    zend_function *old_handler;
    if (zend_hash_find(CG(function_table), handler.name, handler.name_len + 1, (void **)&old_handler) == SUCCESS &&
        old_handler != NULL) {
        *handler.old_handler = old_handler->internal_function.handler;
        old_handler->internal_function.handler = handler.new_handler;
    }
}

void ddtrace_curl_handlers_startup(void);
void ddtrace_curl_handlers_rinit(TSRMLS_D);
void ddtrace_curl_handlers_rshutdown(TSRMLS_D);
void ddtrace_pcntl_handlers_startup(void);
void ddtrace_exception_handlers_startup(TSRMLS_D);
void ddtrace_exception_handlers_rinit(TSRMLS_D);
void ddtrace_exception_handlers_shutdown(void);

// Internal handlers use ddtrace_resource and only implement the sandbox API.
void ddtrace_internal_handlers_startup(TSRMLS_D) {
    ddtrace_curl_handlers_startup();
    // pcntl handlers have to run even if tracing of pcntl extension is not enabled.
    ddtrace_pcntl_handlers_startup();
    ddtrace_exception_handlers_startup(TSRMLS_C);
}

void ddtrace_internal_handlers_shutdown(void) { ddtrace_exception_handlers_shutdown(); }

void ddtrace_internal_handlers_rinit(TSRMLS_D) {
    ddtrace_curl_handlers_rinit(TSRMLS_C);
    ddtrace_exception_handlers_rinit(TSRMLS_C);
}

void ddtrace_internal_handlers_rshutdown(TSRMLS_D) { ddtrace_curl_handlers_rshutdown(TSRMLS_C); }
