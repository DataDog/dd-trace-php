#ifndef DDTRACE_OTEL_CONTEXT_H
#define DDTRACE_OTEL_CONTEXT_H

#include <zend.h>

BEGIN_EXTERN_C()

/**
 * Prefer this root span when publishing OTel thread context. UserRequest uses
 * this to keep the context aligned with the notified request root.
 *
 * While this override is set, the published trace id and local root span id
 * come from this root span. The published active span id follows
 * DDTRACE_G(active_stack)->active only when that active span belongs to this
 * root. If the active stack points at another trace/root, the active span id
 * falls back to this root span id.
 *
 * When this changes the override, it calls ddtrace_update_otel_thread_context();
 * callers need not call it again after setting the override.
 */
void ddtrace_set_otel_thread_context_root_span(zend_object *root_span);

/**
 * Stop preferring the current root span override.
 *
 * When this clears an existing override, it calls
 * ddtrace_update_otel_thread_context(); callers need not call it again after
 * clearing the override.
 *
 * Call also when cleaning up other globals (e.g. on request shutdown), as a
 * defensive measure in case the normal UserRequest finish path is skipped.
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
 * span stack, switching fibers, or setting/clearing the root span override.
 * When there is no selected tracer span/root, this detaches the OTel thread
 * context instead.
 */
void ddtrace_update_otel_thread_context(void);

/**
 * Detach and release the current OTel thread context. This also clears the root
 * span override set by ddtrace_set_otel_thread_context_root_span(), if any.
 *
 * On non-Linux builds this is a no-op.
 *
 * Call this at hard context boundaries where nothing from the previous tracer
 * context should remain visible to OTel: request start, span-stack cleanup
 * during request shutdown or tracing disable, or when
 * ddtrace_update_otel_thread_context() finds no selected tracer span/root.
 */
void ddtrace_detach_otel_thread_context(void);

END_EXTERN_C()

#endif  // DDTRACE_OTEL_CONTEXT_H
