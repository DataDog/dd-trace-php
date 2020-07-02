#include "integrations.h"

#include "elasticsearch.h"
#include "test_integration.h"

#if PHP_VERSION_ID >= 70000
#define DDTRACE_KNOWN_INTEGRATION(class_str, fname_str)                                         \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class_str), DDTRACE_STRING_LITERAL(fname_str), \
                          DDTRACE_STRING_LITERAL(NULL), DDTRACE_DISPATCH_POSTHOOK)

static void _dd_register_known_calls(void) {
    DDTRACE_KNOWN_INTEGRATION("wpdb", "query");
    DDTRACE_KNOWN_INTEGRATION("illuminate\\events\\dispatcher", "fire");
}
#endif

void dd_integrations_initialize(TSRMLS_D) {
#if PHP_VERSION_ID >= 70000
    /* Due to negative lookup caching, we need to have a list of all things we
     * might instrument so that if a call is made to something we want to later
     * instrument but is not currently instrumented, that we don't cache this.
     *
     * We should improve how this list is made in the future instead of hard-
     * coding known integrations (and for now only the problematic ones).
     */
    _dd_register_known_calls();

    /* In PHP 5.6 currently adding defered integrations seem to trigger increase in heap
     * size - even though the memory usage is below the limit. We still can trigger memory
     * allocation error to be issued
     */

    _dd_es_initialize_defered_integration(TSRMLS_C);
#endif
    _dd_load_test_integrations(TSRMLS_C);
}
