#ifndef DD_INTEGRATIONS_TEST_H
#define DD_INTEGRATIONS_TEST_H
#include <stdlib.h>

#include "configuration.h"
#include "defered.h"

static inline void _dd_load_test_integrations() {
    char *test_defered = getenv("_DD_LOAD_TEST_INTEGRATIONS");
    if (!test_defered) {
        return;
    }

    static struct ddtrace_defered_integration _integrations[] = {
        DDTRACE_DEFERED_INTEGRATION_LOADER("test", "public_static_method", "load_test_integration"),
    };
    dd_load_defered_integration_list(_integrations, SIZE_OF_DEFERED_LIST(_integrations));
}

#endif
