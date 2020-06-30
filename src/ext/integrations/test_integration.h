#ifndef DD_INTEGRATIONS_TEST_H
#define DD_INTEGRATIONS_TEST_H
#include <stdlib.h>

#include "integrations.h"

static inline void _dd_load_test_integrations() {
    char *test_defered = getenv("_DD_LOAD_TEST_INTEGRATIONS");
    if (!test_defered) {
        return;
    }

    DDTRACE_DEFERED_INTEGRATION_LOADER("test", "public_static_method", "load_test_integration");
}

#endif
