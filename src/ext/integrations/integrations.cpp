#include "integrations.h"

#include <string>
#include <unordered_set>

#include "elasticsearch.h"
#include "php_hash.hpp"
#include "test_integration.h"
using namespace ddtrace;

namespace ddtrace {
struct Integration {
    ddtrace_string klass, method, callable;
    uint16_t options;
    constexpr Integration(ddtrace_string klass, ddtrace_string method, ddtrace_string callable, uint16_t options)
        : klass(klass), method(method), callable(callable), options(options){};
    constexpr Integration(ddtrace_string klass, ddtrace_string method, uint16_t options)
        : klass(klass), method(method), callable({}), options(options){};
    void reg() { ddtrace_hook_callable(this->klass, this->method, this->callable, this->options); };
    bool operator==(const Integration& other) const { return (klass == other.klass && method == other.method); }
     std::size_t hash() const {
        return this->klass.hash ^ (this->method.hash << 1);
    }
};

struct IntegrationHash {
    std::size_t operator()(Integration const& i) const noexcept { return i.hash(); };
};
struct Posthook : Integration {
    constexpr Posthook(ddtrace_string klass, ddtrace_string method, ddtrace_string callable)
        : Integration(klass, method, callable, DDTRACE_DISPATCH_POSTHOOK){};
    constexpr Posthook(ddtrace_string klass, ddtrace_string method)
        : Integration(klass, method, DDTRACE_DISPATCH_POSTHOOK){};
};

struct Deferred : Integration {
    constexpr Deferred(ddtrace_string klass, ddtrace_string method, ddtrace_string callable)
        : Integration(klass, method, callable, DDTRACE_DISPATCH_DEFERRED_LOADER){};
    constexpr Deferred(ddtrace_string klass, ddtrace_string method)
        : Integration(klass, method, DDTRACE_DISPATCH_DEFERRED_LOADER){};
};

struct Integrations {
    std::unordered_set<Integration, IntegrationHash> data;
    Integrations() {}
    Integrations& add(Integration const& i) {
        data.insert(i);
        return *this;
    };

    Integrations& add(std::initializer_list<Integration> l) {
        data.insert(l);
        return *this;
    }
    void reg() {
        for (auto i : data) {
            i.reg();
        }
    }
};
}  // namespace ddtrace

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

    _dd_es_initialize_deferred_integration(TSRMLS_C);
#endif
    // DDTRACE_DEFERRED_INTEGRATION_LOADER("test", "public_static_method", "load_test_integration");
    // DDTRACE_INTEGRATION_TRACE("test", "automaticaly_traced_method", "tracing_function", DDTRACE_DISPATCH_POSTHOOK);

    integrations.add({
        Deferred("test"_s, "public_static_method"_s, "load_test_integration"_s),
        Posthook("test"_s, "automaticaly_traced_method"_s, "tracing_function"_s),
    }).reg();
    // _dd_load_test_integrations(TSRMLS_C);
}
