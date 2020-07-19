#ifndef DD_INTEGRATIONS_INTEGRATIONS_H
#define DD_INTEGRATIONS_INTEGRATIONS_H
#ifdef __cplusplus
#include <cstddef>
#include <string>
#include <unordered_set>
#include <utility>
#endif

#include <php.h>

#include "ddtrace_string.h"
#include "dispatch.h"

/**
 * DDTRACE_DEFERRED_INTEGRATION_LOADER(class, fname, loader_function)
 * this makro will assign a loader function for each Class, Method/Function combination
 *
 * Purpose of the loader function is to execeture arbitrary PHP code
 * before attempting to search for an integration second time.
 *
 * I.e. we can declare following loader
 * DDTRACE_DEFERRED_INTEGRATION_LOADER("SomeClass", "someMethod", "loader")
 * then if we define following PHP function
 *
 * function loader_fn () {
 *   dd_trace_method("SomeClass", "someMethod", ['prehook' => function(SpanData...) {}])
 * }
 *
 * It will be executed the first time someMethod is called, then an internal lookup will be repeated
 * for the someMethod to get the actual implementation of tracing function
 **/
#define DDTRACE_DEFERRED_INTEGRATION_LOADER(class, fname, loader_function) \
    ddtrace_hook_callable(class##_s, fname##_s, loader_function##_s, DDTRACE_DISPATCH_DEFERRED_LOADER TSRMLS_CC)

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
#define DDTRACE_INTEGRATION_TRACE(class, fname, callable, options) \
    ddtrace_hook_callable(class##_s, fname##_s, callable##_s, options TSRMLS_CC)

#ifdef __cplusplus
extern "C" {
#endif
void dd_integrations_initialize(TSRMLS_D);
#ifdef __cplusplus
}

namespace ddtrace {
struct Integration {
    ddtrace_string klass, method, callable;
    uint16_t options;
    constexpr Integration(ddtrace_string klass, ddtrace_string method, ddtrace_string callable, uint16_t options)
        : klass(klass), method(method), callable(callable), options(options){};
    constexpr Integration(ddtrace_string klass, ddtrace_string method, uint16_t options)
        : klass(klass), method(method), callable({}), options(options){};
    void reg(TSRMLS_D) { ddtrace_hook_callable(this->klass, this->method, this->callable, this->options TSRMLS_CC); };
    bool operator==(const Integration& other) const { return (klass == other.klass && method == other.method); }
    std::size_t hash() const { return this->klass.hash ^ (this->method.hash << 1); }
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



inline constexpr struct ddtrace_string operator"" i_deferred(const char *c, size_t len) {
    return {(char *)c, (ddtrace_zppstrlen_t)len}
    ;
}
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
    void reg(TSRMLS_D) {
        for (auto i : data) {
            i.reg(TSRMLS_C);
        }
    }
};
}  // namespace ddtrace

#endif
#endif
