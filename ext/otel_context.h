#ifndef DD_OTEL_CONTEXT_H
#define DD_OTEL_CONTEXT_H

#ifndef __linux__
#error "OTel Process Context publication is Linux-only"
#endif

#include <php.h>

/**
 * Publish or update process-wide Linux OTel metadata. Request-varying service
 * metadata is published only in Thread Context.
 */
void datadog_otel_process_context_publish(void);

#endif
