#ifndef DD_INTEGRATIONS_INTEGRATIONS_H
#define DD_INTEGRATIONS_INTEGRATIONS_H
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
#define DDTRACE_DEFERRED_INTEGRATION_LOADER(Class, fname, loader_function)               \
    do {                                                                                 \
        ddtrace_string dd_tmp_class = DDTRACE_STRING_LITERAL(Class);                     \
        ddtrace_string dd_tmp_fname = DDTRACE_STRING_LITERAL(fname);                     \
        ddtrace_string dd_tmp_loader_function = DDTRACE_STRING_LITERAL(loader_function); \
        ddtrace_hook_callable(dd_tmp_class, dd_tmp_fname, dd_tmp_loader_function,        \
                              DDTRACE_DISPATCH_DEFERRED_LOADER TSRMLS_CC);               \
    } while (0)

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
#define DDTRACE_INTEGRATION_TRACE(Class, fname, callable, options)                             \
    do {                                                                                       \
        ddtrace_string dd_tmp_class = DDTRACE_STRING_LITERAL(Class);                           \
        ddtrace_string dd_tmp_fname = DDTRACE_STRING_LITERAL(fname);                           \
        ddtrace_string dd_tmp_callable = DDTRACE_STRING_LITERAL(callable);                     \
        ddtrace_hook_callable(dd_tmp_class, dd_tmp_fname, dd_tmp_callable, options TSRMLS_CC); \
    } while (0)

void dd_integrations_initialize(TSRMLS_D);
#endif
