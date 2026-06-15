#include "otel_context.h"

#include "ddtrace.h"
#include "span.h"

#ifdef __linux__
#include "configuration.h"
#include <components-rs/otel-thread-ctx.h>
#include <string.h>
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#ifdef __linux__
static ddtrace_root_span_data *ddtrace_root_span_from_zobj(zend_object *root_span);
static DDOG_CHECK_RETURN ddtrace_root_span_data *ddtrace_replace_otel_context_root_span_override(
    ddtrace_root_span_data *root);
static ddtrace_span_data *ddtrace_otel_context_span(void);
static inline void ddtrace_write_u64_be(uint8_t dest[8], uint64_t value);
static void ddtrace_trace_id_to_otel_bytes(datadog_trace_id trace_id, uint8_t dest[16]);

void ddtrace_set_otel_thread_context_root_span(zend_object *root_span) {
    ddtrace_root_span_data *root = ddtrace_root_span_from_zobj(root_span);
    if (DDTRACE_G(otel_context_root_span_override) == root) {
        return;
    }

    ddtrace_root_span_data *old = ddtrace_replace_otel_context_root_span_override(root);
    ddtrace_update_otel_thread_context();
    if (old) {
        OBJ_RELEASE(&old->std);
    }
}

void ddtrace_clear_otel_thread_context_root_span(void) {
    ddtrace_root_span_data *old = ddtrace_replace_otel_context_root_span_override(NULL);
    if (!old) {
        return;
    }
    ddtrace_update_otel_thread_context();
    OBJ_RELEASE(&old->std);
}

void ddtrace_detach_otel_thread_context(void) {
    ddtrace_root_span_data *old = ddtrace_replace_otel_context_root_span_override(NULL);
    struct ddog_ThreadContextHandle *ctx = ddog_otel_thread_ctx_detach();
    if (ctx) {
        ddog_otel_thread_ctx_free(ctx);
    }
    if (old) {
        OBJ_RELEASE(&old->std);
    }
}

void ddtrace_update_otel_thread_context(void) {
    ddtrace_span_data *span = ddtrace_otel_context_span();
    if (!span || !span->root) {
        ddtrace_detach_otel_thread_context();
        return;
    }

    ddtrace_root_span_data *root = span->root;

    uint8_t trace_id[16];
    uint8_t span_id[8];
    uint8_t local_root_span_id[8];

    ddtrace_trace_id_to_otel_bytes(root->trace_id, trace_id);
    ddtrace_write_u64_be(span_id, span->span_id);
    ddtrace_write_u64_be(local_root_span_id, root->span_id);

    ddog_otel_thread_ctx_update(&trace_id, &span_id, &local_root_span_id);
}

static ddtrace_root_span_data *ddtrace_root_span_from_zobj(zend_object *root_span) {
    if (!root_span || root_span->ce != ddtrace_ce_root_span_data) {
        return NULL;
    }

    return ROOTSPANDATA(root_span);
}

static DDOG_CHECK_RETURN ddtrace_root_span_data *ddtrace_replace_otel_context_root_span_override(
    ddtrace_root_span_data *root) {
    ddtrace_root_span_data *old = DDTRACE_G(otel_context_root_span_override);
    if (root) {
        GC_ADDREF(&root->std);
    }
    DDTRACE_G(otel_context_root_span_override) = root;
    return old;
}

static ddtrace_span_data *ddtrace_otel_context_span(void) {
    if (!get_DD_TRACE_ENABLED()) {
        return NULL;
    }

    if (DDTRACE_G(otel_context_root_span_override)) {
        ddtrace_root_span_data *root = DDTRACE_G(otel_context_root_span_override);

        if (DDTRACE_G(active_stack) && DDTRACE_G(active_stack)->active) {
            ddtrace_span_data *active = SPANDATA(DDTRACE_G(active_stack)->active);
            if (active->root == root) {
                return active;
            }
        }

        return &root->span;
    }

    if (DDTRACE_G(active_stack) && DDTRACE_G(active_stack)->root_span && DDTRACE_G(active_stack)->active) {
        return SPANDATA(DDTRACE_G(active_stack)->active);
    }

    return NULL;
}

static inline void ddtrace_write_u64_be(uint8_t dest[8], uint64_t value) {
    uint64_t be_value =
#if __BYTE_ORDER__ == __ORDER_LITTLE_ENDIAN__
        __builtin_bswap64(value);
#elif __BYTE_ORDER__ == __ORDER_BIG_ENDIAN__
        value;
#else
#error "Unsupported byte order"
#endif
    memcpy(dest, &be_value, sizeof(be_value));
}

static void ddtrace_trace_id_to_otel_bytes(datadog_trace_id trace_id, uint8_t dest[16]) {
    ddtrace_write_u64_be(dest, trace_id.high);
    ddtrace_write_u64_be(dest + 8, trace_id.low);
}
#else // !__linux__

void ddtrace_set_otel_thread_context_root_span(zend_object *root_span) {}
void ddtrace_clear_otel_thread_context_root_span(void) {}
void ddtrace_detach_otel_thread_context(void) {}
void ddtrace_update_otel_thread_context(void) {}
#endif
