#include "handlers_internal.h"

#include "arrays.h"
#include "configuration.h"
#include "ddtrace.h"
#include "engine_hooks.h"
#include "logging.h"

void ddtrace_free_unregistered_class(zend_class_entry *ce) {
#if PHP_VERSION_ID >= 80100
    zend_property_info *prop_info;
    ZEND_HASH_FOREACH_PTR(&ce->properties_info, prop_info) {
        if (prop_info->ce == ce) {
            zend_string_release(prop_info->name);
            zend_type_release(prop_info->type, /* persistent */ 1);
            free(prop_info);
        }
    }
    ZEND_HASH_FOREACH_END();
#endif
    zend_hash_destroy(&ce->properties_info);
    if (ce->default_properties_table) {
        free(ce->default_properties_table);
    }
#if PHP_VERSION_ID >= 70400
    if (ce->properties_info_table) {
        free(ce->properties_info_table);
    }
#endif
}

void ddtrace_curl_handlers_startup(void);
void ddtrace_exception_handlers_startup(void);
void ddtrace_pcntl_handlers_startup(void);

#if PHP_VERSION_ID < 80000
void ddtrace_curl_handlers_shutdown(void);
#endif
void ddtrace_exception_handlers_shutdown(void);

void ddtrace_curl_handlers_rinit(void);
void ddtrace_exception_handlers_rinit(void);

void ddtrace_curl_handlers_rshutdown(void);

void ddtrace_internal_handlers_startup(void) {
    // curl is different; it has pieces that always run.
    ddtrace_curl_handlers_startup();
    // pcntl handlers have to run even if tracing of pcntl extension is not enabled.
    ddtrace_pcntl_handlers_startup();
    // exception handlers have to run otherwise wrapping will fail horribly
    ddtrace_exception_handlers_startup();
}

void ddtrace_internal_handlers_shutdown(void) {
    ddtrace_exception_handlers_shutdown();
#if PHP_VERSION_ID < 80000
    ddtrace_curl_handlers_shutdown();
#endif
}

void ddtrace_internal_handlers_rinit(void) {
    ddtrace_curl_handlers_rinit();
    ddtrace_exception_handlers_rinit();
}

void ddtrace_internal_handlers_rshutdown(void) { ddtrace_curl_handlers_rshutdown(); }
