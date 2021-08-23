#include "integrations.h"

#include "ext/php5/configuration.h"
#include "ext/php5/ddtrace_string.h"
#undef INTEGRATION

#define DDTRACE_DEFERRED_INTEGRATION_LOADER(class, fname, integration_name)             \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class), DDTRACE_STRING_LITERAL(fname), \
                          DDTRACE_STRING_LITERAL(integration_name), DDTRACE_DISPATCH_DEFERRED_LOADER TSRMLS_CC)

#define DD_SET_UP_DEFERRED_LOADING_BY_METHOD(name, Class, fname, integration)                                \
    dd_set_up_deferred_loading_by_method(name, DDTRACE_STRING_LITERAL(Class), DDTRACE_STRING_LITERAL(fname), \
                                         DDTRACE_STRING_LITERAL(integration))
/**
 * DDTRACE_INTEGRATION_TRACE(class, fname, callable, options)
 *
 * This macro can be used to assign a tracing callable to Class, Method/Function name combination
 * the callable can be any callable string thats recognized by PHP. It needs to have the signature
 * expected by normal DDTrace.
 *
 * function tracing_function(SpanData $span, array $args) { }
 *
 * options need to specify either DDTRACE_DISPATCH_POSTHOOK or DDTRACE_DISPATCH_PREHOOK
 * in order for the callable to be called by the hooks
 **/
#define DDTRACE_INTEGRATION_TRACE(class, fname, callable, options)                      \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class), DDTRACE_STRING_LITERAL(fname), \
                          DDTRACE_STRING_LITERAL(callable), options TSRMLS_CC)

#define INTEGRATION(id, int_name)                                      \
    {                                                                  \
        .name = DDTRACE_INTEGRATION_##id,                              \
        .name_ucase = #id,                                             \
        .name_lcase = (int_name),                                      \
        .name_len = sizeof(int_name) - 1,                              \
        .is_enabled = get_DD_TRACE_##id##_ENABLED,                     \
        .is_analytics_enabled = get_DD_TRACE_##id##_ANALYTICS_ENABLED, \
        .get_sample_rate = get_DD_TRACE_##id##_ANALYTICS_SAMPLE_RATE,  \
    },
ddtrace_integration ddtrace_integrations[] = {DD_INTEGRATIONS};
size_t ddtrace_integrations_len = sizeof ddtrace_integrations / sizeof ddtrace_integrations[0];

// Map of lowercase strings to the ddtrace_integration equivalent
static HashTable _dd_string_to_integration_name_map;

static void _dd_add_integration_to_map(char *name, size_t name_len, ddtrace_integration *integration);

void ddtrace_integrations_minit(void) {
    zend_hash_init(&_dd_string_to_integration_name_map, ddtrace_integrations_len, NULL, NULL, 1);

    for (size_t i = 0; i < ddtrace_integrations_len; ++i) {
        char *name = ddtrace_integrations[i].name_lcase;
        size_t name_len = ddtrace_integrations[i].name_len;
        _dd_add_integration_to_map(name, name_len, &ddtrace_integrations[i]);
    }
}

void ddtrace_integrations_mshutdown(void) { zend_hash_destroy(&_dd_string_to_integration_name_map); }

#define DDTRACE_KNOWN_INTEGRATION(class_str, fname_str)                                         \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class_str), DDTRACE_STRING_LITERAL(fname_str), \
                          DDTRACE_STRING_LITERAL(NULL), DDTRACE_DISPATCH_POSTHOOK TSRMLS_CC)

/* Due to negative lookup caching, we need to have a list of all things we
 * might instrument so that if a call is made to something we want to later
 * instrument but is not currently instrumented, that we don't cache this.
 *
 * We should improve how this list is made in the future instead of hard-
 * coding known integrations (and for now only the problematic ones).
 */
static void dd_register_known_calls(TSRMLS_D) {
    DDTRACE_KNOWN_INTEGRATION("wpdb", "query");
    DDTRACE_KNOWN_INTEGRATION("illuminate\\events\\dispatcher", "fire");
}

static void dd_load_test_integrations(TSRMLS_D) {
    char *test_deferred = getenv("_DD_LOAD_TEST_INTEGRATIONS");
    if (!test_deferred) {
        return;
    }

    DDTRACE_DEFERRED_INTEGRATION_LOADER("test", "public_static_method", "ddtrace\\test\\testsandboxedintegration");
    DDTRACE_INTEGRATION_TRACE("test", "automaticaly_traced_method", "tracing_function", DDTRACE_DISPATCH_POSTHOOK);
}

void ddtrace_integrations_rinit(TSRMLS_D) {
    /* In PHP 5.6 currently adding deferred integrations seem to trigger increase in heap
     * size - even though the memory usage is below the limit. We still can trigger memory
     * allocation error to be issued
     */
    dd_load_test_integrations(TSRMLS_C);

    dd_register_known_calls(TSRMLS_C);
}

ddtrace_integration *ddtrace_get_integration_from_string(ddtrace_string integration) {
    ddtrace_integration **tmp;
    if (zend_hash_find(&_dd_string_to_integration_name_map, integration.ptr, integration.len + 1, (void **)&tmp) ==
        SUCCESS) {
        return *tmp;
    }
    return NULL;
}

static void _dd_add_integration_to_map(char *name, size_t name_len, ddtrace_integration *integration) {
    zend_hash_add(&_dd_string_to_integration_name_map, name, name_len + 1, (void **)&integration, sizeof(integration),
                  NULL);
}
