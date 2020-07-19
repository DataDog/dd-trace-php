#include "integrations.h"

#include "elasticsearch.hpp"
#include "test_integration.hpp"
using namespace ddtrace;

extern "C" void dd_integrations_initialize(TSRMLS_D);

void dd_integrations_initialize(TSRMLS_D) {
    Integrations integrations;
#if PHP_VERSION_ID >= 70000
    /* Due to negative lookup caching, we need to have a list of all things we
     * might instrument so that if a call is made to something we want to later
     * instrument but is not currently instrumented, that we don't cache this.
     *
     * We should improve how this list is made in the future instead of hard-
     * coding known integrations (and for now only the problematic ones).
     */
    integrations.add({Posthook("wpdb"_s, "query"_s), Posthook("illuminate\\events\\dispatcher"_s, "fire"_s)});

    /* In PHP 5.6 currently adding deferred integrations seem to trigger increase in heap
     * size - even though the memory usage is below the limit. We still can trigger memory
     * allocation error to be issued
     */

    _es_add_deferred_integration(integrations);
#endif
    _load_test_integrations(integrations);

    integrations.reg(TSRMLS_C);
}
