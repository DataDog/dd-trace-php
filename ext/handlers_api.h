#ifndef DDTRACE_HANDLERS_API_H
#define DDTRACE_HANDLERS_API_H

// This api is used by both the tracer and profiler.

#include <php.h>

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80101 && defined(ZTS)

#  if defined(__has_attribute) && __has_attribute(tls_model)
#    define ATTR_TLS_GLOBAL_DYNAMIC __attribute__((tls_model("global-dynamic")))
#  else
#    define ATTR_TLS_GLOBAL_DYNAMIC
#  endif

extern __thread void *ATTR_TLS_GLOBAL_DYNAMIC TSRMLS_CACHE;
#endif

typedef struct datadog_php_zif_handler_s {
    const char *name;
    size_t name_len;
    void (**old_handler)(INTERNAL_FUNCTION_PARAMETERS);
    void (*new_handler)(INTERNAL_FUNCTION_PARAMETERS);
} datadog_php_zif_handler;

/**
 * Installs the `handler` if the function represented by `name` + `name_len`
 * is found; otherwise does nothing.
 */
void datadog_php_install_handler(datadog_php_zif_handler handler);

#endif  // DDTRACE_HANDLERS_API_H

