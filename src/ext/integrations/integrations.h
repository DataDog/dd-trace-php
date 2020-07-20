#ifndef DD_INTEGRATIONS_INTEGRATIONS_H
#define DD_INTEGRATIONS_INTEGRATIONS_H
#include <php.h>

#include "ddtrace_string.h"
#include "dispatch.h"

/**
 * pool_id and dispatch_id handling.
 * In the current implementation developer needs to make sure each integration gets pool_id and dispatch_id
 * manually assigned
 *
 * in general pool_id should be assigned per integration - for deferred integrations it will also cause
 * all dispatches with the same pool_id to be disabled prior to deferred loader being run. Making sure the loader
 * is only able to load code once.
 *
 * When integrations have more than one loader function, more pool_id's can be used.
 * Any integration with overlapping dispatch_id/pool_id will get overwritten.
 * Each integration needs to initialize pool by running
 * ddtrace_initialize_new_dispatch_pool(POOL_ID, number_of_dispatches_to store)
 *
 * pool_id and dispatch_id (both 16bit) form a union to a 32bit variable that can be used for
 * referencing the integration's dispatch in e.g. opcache cache
 *
 * Care needs to be taken to not cause collisions of pool_id, dispatch_id pointing to
 * different trace functions when making changes accross commits,
 * since in case of file opcache cache this could lead to wrong dispatch being loaded for a function
 **/

/**
 * DDTRACE_DEFERRED_INTEGRATION_LOADER(class, fname, loader_function)
 * this makro will assign a loader function for each Class, Method/Function combination
 *
 * Purpose of the loader function is to execeture arbitrary PHP code
 * before attempting to search for an integration second time.
 *
 * I.e. we can declare following loader
 * DDTRACE_DEFERRED_INTEGRATION_LOADER("SomeClass", "someMethod", "loader", pool_id, dispatch_id)
 * then if we define following PHP function
 *
 * function loader_fn () {
 *   dd_trace_method("SomeClass", "someMethod", ['prehook' => function(SpanData...) {}])
 * }
 *
 * It will be executed the first time someMethod is called, then an internal lookup will be repeated
 * for the someMethod to get the actual implementation of tracing function
 **/
#define DDTRACE_DEFERRED_INTEGRATION_LOADER(class, fname, loader_function, pool_id, dispatch_id)              \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class), DDTRACE_STRING_LITERAL(fname),                       \
                          DDTRACE_STRING_LITERAL(loader_function), DDTRACE_DISPATCH_DEFERRED_LOADER, pool_id, \
                          dispatch_id TSRMLS_CC)

/**
 * DDTRACE_INTEGRATION_TRACE(class, fname, callable, options, pool_id, dispatch_id)
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
#define DDTRACE_INTEGRATION_TRACE(class, fname, callable, options, pool_id, dispatch_id) \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class), DDTRACE_STRING_LITERAL(fname),  \
                          DDTRACE_STRING_LITERAL(callable), options, pool_id, dispatch_id TSRMLS_CC)

void dd_integrations_initialize(TSRMLS_D);

#endif
