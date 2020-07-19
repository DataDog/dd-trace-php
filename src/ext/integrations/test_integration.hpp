#ifndef DD_INTEGRATIONS_TEST_H
#define DD_INTEGRATIONS_TEST_H
#include <stdlib.h>

#include "integrations.h"
namespace ddtrace {
static inline void _load_test_integrations(Integrations &integrations) {
    char *test_deferred = getenv("_DD_LOAD_TEST_INTEGRATIONS");
    if (!test_deferred) {
        return;
    }

    integrations.add({
        Deferred("test"_s, "public_static_method"_s, "load_test_integration"_s),
        Posthook("test"_s, "automaticaly_traced_method"_s, "tracing_function"_s),
    });
}
}  // namespace ddtrace

#endif
