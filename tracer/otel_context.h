#ifndef DDTRACE_OTEL_CONTEXT_H
#define DDTRACE_OTEL_CONTEXT_H

#include <zend.h>

typedef struct ddtrace_root_span_data ddtrace_root_span_data;

BEGIN_EXTERN_C()

/**
 * Compatibility no-op for the UserRequest lifecycle. AppSec-specific
 * entrypoint context is not published through this generic OTel context path.
 */
void ddtrace_set_otel_thread_context_root_span(zend_object *root_span);

/**
 * Compatibility no-op for the UserRequest lifecycle.
 */
void ddtrace_clear_otel_thread_context_root_span(void);

/**
 * Publish the selected tracer context through Linux's OTel thread-context TLS
 * slot.
 *
 * On non-Linux builds this is a no-op.
 *
 * Call this after changing which tracer span should be visible to OTel:
 * opening a span, closing or dropping the active span, switching the active
 * span stack, switching fibers, root id changes, or service/env/version
 * changes. When there is no selected tracer span/root, this detaches the OTel
 * thread context instead.
 */
void ddtrace_update_otel_thread_context(void);

/**
 * Publish only the current active span id through the selected root span's
 * embedded OTel context record.
 *
 * On non-Linux builds this is a no-op. This path uses the spec's atomic span
 * id update rule; callers must use ddtrace_update_otel_thread_context() when
 * trace id, local root id, or attributes may have changed.
 */
void ddtrace_update_otel_thread_context_span_id(void);

/**
 * Detach the current OTel thread context.
 *
 * On non-Linux builds this is a no-op.
 *
 * Call this at hard context boundaries where nothing from the previous tracer
 * context should remain visible to OTel: request start, span-stack cleanup
 * during request shutdown or tracing disable, or when
 * ddtrace_update_otel_thread_context() finds no selected tracer span/root.
 */
void ddtrace_detach_otel_thread_context(void);

/**
 * Detach the current OTel thread context if it points at this root span's
 * embedded record.
 *
 * On non-Linux builds this is a no-op.
 */
void ddtrace_detach_otel_thread_context_for_root(ddtrace_root_span_data *root_span);

END_EXTERN_C()

#endif  // DDTRACE_OTEL_CONTEXT_H
