#ifndef DD_OTEL_CONTEXT_H
#define DD_OTEL_CONTEXT_H

#include <php.h>

/**
 * Publish or update the configured process defaults and Linux OTel thread
 * reference data. This is a no-op on non-Linux platforms.
 */
void datadog_otel_process_context_publish(void);

/**
 * Publish using explicit service, environment, and version values. This is
 * used by configuration callbacks, which run before ZAI installs the new
 * memoized value.
 */
void datadog_otel_process_context_publish_config(
    zend_string *service,
    zend_string *env,
    zend_string *version);

#endif
