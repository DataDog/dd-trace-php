#ifndef DD_OTEL_CONTEXT_H
#define DD_OTEL_CONTEXT_H

#include <php.h>

/**
 * Publish or update process-wide metadata and Linux OTel thread reference
 * data. Request-varying service metadata is published only in Thread Context.
 * This is a no-op on non-Linux platforms.
 */
void datadog_otel_process_context_publish(void);

#endif
