#include "otel_context.h"

#include "ddtrace.h"
#include "span.h"
#include "tracer_api.h"
#include <ext/ffi_utils.h>

#ifdef __linux__
#include "configuration.h"
#include <components-rs/otel-thread-ctx.h>
#include <string.h>
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#ifdef __linux__
_Static_assert(sizeof(ddog_ThreadContextRecord) == 640, "unexpected OTel thread context record size");
_Static_assert(_Alignof(ddog_ThreadContextRecord) == 8, "unexpected OTel thread context record alignment");

static ddtrace_span_data *ddtrace_otel_context_span(void);
static inline void ddtrace_write_u64_be(uint8_t dest[8], uint64_t value);
static void ddtrace_trace_id_to_otel_bytes(datadog_trace_id trace_id, uint8_t dest[16]);

void ddtrace_set_otel_thread_context_root_span(zend_object *root_span) {
    (void) root_span;
}
void ddtrace_clear_otel_thread_context_root_span(void) {}

void ddtrace_detach_otel_thread_context(void) {
    ddog_otel_thread_ctx_detach_record();
}

void ddtrace_detach_otel_thread_context_for_root(ddtrace_root_span_data *root_span) {
    if (root_span) {
        ddog_otel_thread_ctx_detach_record_if_current(&root_span->otel_context);
    }
}

void ddtrace_update_otel_thread_context_span_id(void) {
    ddtrace_span_data *span = ddtrace_otel_context_span();
    if (!span || !span->root) {
        ddtrace_detach_otel_thread_context();
        return;
    }

    uint8_t span_id[8];
    ddtrace_write_u64_be(span_id, span->span_id);
    ddog_otel_thread_ctx_record_update_span_id(&span->root->otel_context, &span_id);
    ddog_otel_thread_ctx_attach_record(&span->root->otel_context);
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

    zend_string *service = NULL, *env = NULL, *version = NULL;
    ddtrace_populate_span_data(span, &service, &env, &version);

    enum {
        DDTRACE_OTEL_THREAD_CONTEXT_SERVICE_NAME_INDEX = 1,
        DDTRACE_OTEL_THREAD_CONTEXT_SERVICE_VERSION_INDEX = 2,
        DDTRACE_OTEL_THREAD_CONTEXT_DEPLOYMENT_ENV_INDEX = 3,
    };

    ddog_OtelThreadContextAttribute attrs[3];
    size_t attrs_len = 0;

    if (service && ZSTR_LEN(service)) {
        attrs[attrs_len++] = (ddog_OtelThreadContextAttribute){
            .key_index = DDTRACE_OTEL_THREAD_CONTEXT_SERVICE_NAME_INDEX,
            .value = dd_zend_string_to_CharSlice(service),
        };
    }
    if (version && ZSTR_LEN(version)) {
        attrs[attrs_len++] = (ddog_OtelThreadContextAttribute){
            .key_index = DDTRACE_OTEL_THREAD_CONTEXT_SERVICE_VERSION_INDEX,
            .value = dd_zend_string_to_CharSlice(version),
        };
    }
    if (env && ZSTR_LEN(env)) {
        attrs[attrs_len++] = (ddog_OtelThreadContextAttribute){
            .key_index = DDTRACE_OTEL_THREAD_CONTEXT_DEPLOYMENT_ENV_INDEX,
            .value = dd_zend_string_to_CharSlice(env),
        };
    }

    ddog_otel_thread_ctx_record_update(&root->otel_context, &trace_id, &span_id, &local_root_span_id, attrs, attrs_len);
    ddog_otel_thread_ctx_attach_record(&root->otel_context);

    if (service) {
        zend_string_release(service);
    }
    if (env) {
        zend_string_release(env);
    }
    if (version) {
        zend_string_release(version);
    }
}

static ddtrace_span_data *ddtrace_otel_context_span(void) {
    if (!get_DD_TRACE_ENABLED()) {
        return NULL;
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
void ddtrace_detach_otel_thread_context_for_root(ddtrace_root_span_data *root_span) {}
void ddtrace_update_otel_thread_context_span_id(void) {}
void ddtrace_update_otel_thread_context(void) {}
#endif
