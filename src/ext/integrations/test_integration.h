#ifndef DD_INTEGRATIONS_TEST_H
#define DD_INTEGRATIONS_TEST_H
#include <stdlib.h>

#include "integrations.h"
#define TEST_INTEGRATION_POOL_ID 3

static inline void _dd_load_test_integrations(TSRMLS_D) {
    char *test_deferred = getenv("_DD_LOAD_TEST_INTEGRATIONS");
    if (!test_deferred) {
        return;
    }
    if (!ddtrace_initialize_new_dispatch_pool(TEST_INTEGRATION_POOL_ID, 2)) {
        return;
    }

    DDTRACE_DEFERRED_INTEGRATION_LOADER("test", "public_static_method", "load_test_integration", TEST_INTEGRATION_POOL_ID, 0);
    DDTRACE_INTEGRATION_TRACE("test", "automaticaly_traced_method", "tracing_function", DDTRACE_DISPATCH_POSTHOOK, TEST_INTEGRATION_POOL_ID, 1);
}

#endif
