#ifndef DD_INTEGRATIONS_INTEGRATIONS_H
#define DD_INTEGRATIONS_INTEGRATIONS_H
#include <php.h>

#include "ddtrace_string.h"
#include "dispatch.h"

#define DDTRACE_LONGEST_INTEGRATION_NAME_LEN 13  // "zendframework" FTW!

typedef enum {
    DDTRACE_INTEGRATION_CAKEPHP,
    DDTRACE_INTEGRATION_CODEIGNITER,
    DDTRACE_INTEGRATION_CURL,
    DDTRACE_INTEGRATION_ELASTICSEARCH,
    DDTRACE_INTEGRATION_ELOQUENT,
    DDTRACE_INTEGRATION_GUZZLE,
    DDTRACE_INTEGRATION_LARAVEL,
    DDTRACE_INTEGRATION_LUMEN,
    DDTRACE_INTEGRATION_MEMCACHED,
    DDTRACE_INTEGRATION_MONGO,
    DDTRACE_INTEGRATION_MYSQLI,
    DDTRACE_INTEGRATION_PDO,
    DDTRACE_INTEGRATION_PHPREDIS,
    DDTRACE_INTEGRATION_PREDIS,
    DDTRACE_INTEGRATION_SLIM,
    DDTRACE_INTEGRATION_SYMFONY,
    DDTRACE_INTEGRATION_WEB,
    DDTRACE_INTEGRATION_WORDPRESS,
    DDTRACE_INTEGRATION_YII,
    DDTRACE_INTEGRATION_ZENDFRAMEWORK,
} ddtrace_integration_name;

struct ddtrace_integration {
    ddtrace_integration_name name;
    char *name_ucase;
    char *name_lcase;
    size_t name_len;
};
typedef struct ddtrace_integration ddtrace_integration;

extern ddtrace_integration ddtrace_integrations[];
extern size_t ddtrace_integrations_len;

/**
 * DDTRACE_DEFERRED_INTEGRATION_LOADER(class, fname, integration_name)
 * This macro will assign a fully qualified Integration name for each
 * Class, Method/Function combination.
 *
 * The integration_name will be constructed and `init()` method called when the
 * deferred dispatch loader code is triggered.
 */
#define DDTRACE_DEFERRED_INTEGRATION_LOADER(class, fname, integration_name)             \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class), DDTRACE_STRING_LITERAL(fname), \
                          DDTRACE_STRING_LITERAL(integration_name), DDTRACE_DISPATCH_DEFERRED_LOADER TSRMLS_CC)

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
#define DDTRACE_INTEGRATION_TRACE(class, fname, callable, options)                      \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class), DDTRACE_STRING_LITERAL(fname), \
                          DDTRACE_STRING_LITERAL(callable), options TSRMLS_CC)

void ddtrace_integrations_minit(void);
void ddtrace_integrations_mshutdown(void);
void ddtrace_integrations_rinit(TSRMLS_D);

ddtrace_integration *ddtrace_get_integration_from_string(ddtrace_string integration);

#endif  // DD_INTEGRATIONS_INTEGRATIONS_H
