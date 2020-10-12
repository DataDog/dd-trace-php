#ifndef DD_INTEGRATIONS_TEST_H
#define DD_INTEGRATIONS_TEST_H
#include <stdlib.h>

#include "integrations.h"

static inline void _dd_load_test_integrations(TSRMLS_D) {
    char *test_deferred = getenv("_DD_LOAD_TEST_INTEGRATIONS");
    if (!test_deferred) {
        return;
    }

    DDTRACE_DEFERRED_INTEGRATION_LOADER("test", "public_static_method", "ddtrace\\test\\testsandboxedintegration");
    DDTRACE_INTEGRATION_TRACE("test", "automaticaly_traced_method", "tracing_function", DDTRACE_DISPATCH_POSTHOOK);
}

#endif
