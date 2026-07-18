#include "otel_context.h"

#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "span.h"
#include "trace_context.h"
#include <ext/target_metadata.h>

#if defined(__linux__) || defined(__APPLE__)
#include <stdatomic.h>
#include <stddef.h>
#include <string.h>

#include <ext/datadog_export.h>

#include "configuration.h"
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#if defined(__linux__) || defined(__APPLE__)

#define DDTRACE_OTEL_ATTR_LOCAL_ROOT_SPAN_ID 0
#define DDTRACE_OTEL_ATTR_SERVICE_NAME 1
#define DDTRACE_OTEL_ATTR_SERVICE_VERSION 2
#define DDTRACE_OTEL_ATTR_DEPLOYMENT_ENVIRONMENT_NAME 3
#define DDTRACE_OTEL_LOCAL_ROOT_SPAN_ID_ATTR_SIZE 18

_Static_assert(sizeof(datadog_otel_thr_ctx_rec) == 640, "unexpected OTel thread context record size");
_Static_assert(_Alignof(datadog_otel_thr_ctx_rec) == 8, "unexpected OTel thread context record alignment");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, trace_id) == 0, "unexpected OTel thread context trace_id offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, span_id) == 16, "unexpected OTel thread context span_id offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, valid) == 24, "unexpected OTel thread context valid offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, trace_flags) == 25, "unexpected OTel thread context trace_flags offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, attrs_data_size) == 26, "unexpected OTel thread context attrs_data_size offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, attrs_data) == 28, "unexpected OTel thread context attrs_data offset");
_Static_assert(offsetof(ddtrace_root_span_data, otel_context) % 8 == 0, "unexpected OTel thread context placement");
_Static_assert(
    (offsetof(ddtrace_root_span_data, otel_context) + offsetof(datadog_otel_thr_ctx_rec, span_id)) % 8 == 0,
    "unexpected OTel thread context span_id placement");

DATADOG_PUBLIC __thread void *otel_thread_ctx_v1 = NULL;

static void ddtrace_otel_record_begin_update(datadog_otel_thr_ctx_rec *record);
static void ddtrace_otel_record_end_update(datadog_otel_thr_ctx_rec *record);
static void ddtrace_otel_attach(datadog_otel_thr_ctx_rec *record);
static ddtrace_span_data *ddtrace_otel_entrypoint_span(void);
static ddtrace_span_data *ddtrace_otel_attr_source_span(ddtrace_root_span_data *source_root);
static void ddtrace_otel_record_set_trace_id(datadog_otel_thr_ctx_rec *record, datadog_trace_id trace_id);
static void ddtrace_otel_record_set_span_id(datadog_otel_thr_ctx_rec *record, uint64_t span_id);
static void ddtrace_otel_record_set_trace_flags(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root);
static void ddtrace_otel_record_set_attrs(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root);
static void ddtrace_otel_record_set_attrs_from_values(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root, zend_string *service, zend_string *env, zend_string *version);
static size_t ddtrace_otel_record_write_attr_zstr(datadog_otel_thr_ctx_rec *record, size_t offset, uint8_t key_index, zend_string *value);
static zend_string *ddtrace_otel_attr_zstr(zend_string *value);
static uint64_t ddtrace_u64_be(uint64_t value);

void ddtrace_otel_init_root_span(ddtrace_root_span_data *root) {
    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_span_data *span = &root->span;
    ddtrace_otel_record_set_trace_id(record, root->trace_id);
    ddtrace_otel_record_set_span_id(record, span->span_id);
    ddtrace_otel_record_set_trace_flags(record, root);
    ddtrace_otel_record_set_attrs(record, root);
    atomic_store_explicit(&record->valid, 1, memory_order_relaxed);
}

void ddtrace_otel_update_trace_flags(ddtrace_root_span_data *root) {
    if (!root) {
        return;
    }

    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_otel_record_begin_update(record);
    ddtrace_otel_record_set_trace_flags(record, root);
    ddtrace_otel_record_end_update(record);
}

void ddtrace_otel_update_trace_id(ddtrace_root_span_data *root) {
    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_otel_record_begin_update(record);
    ddtrace_otel_record_set_trace_id(record, root->trace_id);
    ddtrace_otel_record_set_trace_flags(record, root);
    ddtrace_otel_record_end_update(record);
}

void ddtrace_otel_update_span_id(ddtrace_root_span_data *root, uint64_t span_id) {
    if (!root) {
        return;
    }

    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_otel_record_set_span_id(record, span_id);
}

void ddtrace_otel_update_attribute_values(ddtrace_root_span_data *root) {
    if (!get_DD_TRACE_ENABLED()) {
        return;
    }

    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_otel_record_begin_update(record);
    ddtrace_otel_record_set_attrs(record, root);
    ddtrace_otel_record_end_update(record);
}

void ddtrace_otel_attach_stack(ddtrace_span_stack *stack) {
    if (!stack || !stack->root_span || !stack->active) {
        ddtrace_otel_detach();
        return;
    }

    ddtrace_root_span_data *root = stack->root_span;
    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_otel_record_begin_update(record);
    ddtrace_otel_record_set_span_id(record, SPANDATA(stack->active)->span_id);
    ddtrace_otel_record_set_trace_flags(record, root);
    ddtrace_otel_record_set_attrs(record, root);
    ddtrace_otel_record_end_update(record);
    ddtrace_otel_attach(record);
}

void ddtrace_otel_detach(void) {
    __atomic_signal_fence(__ATOMIC_RELEASE);
    otel_thread_ctx_v1 = NULL;
}

void ddtrace_detach_otel_thread_context_for_root(ddtrace_root_span_data *root_span) {
    if (root_span && otel_thread_ctx_v1 == &root_span->otel_context) {
        ddtrace_otel_detach();
    }
}

static void ddtrace_otel_record_begin_update(datadog_otel_thr_ctx_rec *record) {
    atomic_store_explicit(&record->valid, 0, memory_order_relaxed);
    __atomic_signal_fence(__ATOMIC_ACQUIRE);
}

static void ddtrace_otel_record_end_update(datadog_otel_thr_ctx_rec *record) {
    __atomic_signal_fence(__ATOMIC_RELEASE);
    atomic_store_explicit(&record->valid, 1, memory_order_relaxed);
}

static void ddtrace_otel_attach(datadog_otel_thr_ctx_rec *record) {
    __atomic_signal_fence(__ATOMIC_RELEASE);
    otel_thread_ctx_v1 = record;
}

static ddtrace_span_data *ddtrace_otel_entrypoint_span(void) {
    for (ddtrace_span_stack *stack = DDTRACE_G(active_stack); stack; stack = stack->parent_stack) {
        if (stack->root_span && ddtrace_span_is_entrypoint_root(&stack->root_span->span)) {
            return &stack->root_span->span;
        }
    }

    return NULL;
}

static ddtrace_span_data *ddtrace_otel_attr_source_span(ddtrace_root_span_data *source_root) {
    if (source_root && ddtrace_span_is_entrypoint_root(&source_root->span)) {
        return &source_root->span;
    }

    return ddtrace_otel_entrypoint_span();
}

static void ddtrace_otel_record_set_trace_id(datadog_otel_thr_ctx_rec *record, datadog_trace_id trace_id) {
    record->trace_id[0] = ddtrace_u64_be(trace_id.high);
    record->trace_id[1] = ddtrace_u64_be(trace_id.low);
}

static void ddtrace_otel_record_set_span_id(datadog_otel_thr_ctx_rec *record, uint64_t span_id) {
    atomic_store_explicit(&record->span_id, ddtrace_u64_be(span_id), memory_order_relaxed);
}

static void ddtrace_otel_record_set_trace_flags(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root) {
    zend_long sampling_priority = Z_TYPE(root->property_sampling_priority) == IS_UNDEF
        ? DDTRACE_PRIORITY_SAMPLING_UNKNOWN
        : zval_get_long(&root->property_sampling_priority);
    record->trace_flags = ddtrace_compute_trace_flags(root->trace_flags, sampling_priority);
}

static void ddtrace_otel_record_set_attrs_from_values(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root, zend_string *service, zend_string *env, zend_string *version) {
    static const uint8_t hex_digits[] = "0123456789abcdef";

    uint64_t span_id = root->span_id;
    // attrs_data entries store UTF-8 string values. Key index 0 is reserved
    // for the local root span id as a fixed 16-char lowercase hex string.
    record->attrs_data[0] = DDTRACE_OTEL_ATTR_LOCAL_ROOT_SPAN_ID;
    record->attrs_data[1] = 16;
    for (uint8_t i = 0; i < 16; ++i) {
        record->attrs_data[2 + i] = hex_digits[(span_id >> ((15 - i) * 4)) & 0xf];
    }

    size_t offset = DDTRACE_OTEL_LOCAL_ROOT_SPAN_ID_ATTR_SIZE;
    offset = ddtrace_otel_record_write_attr_zstr(record, offset, DDTRACE_OTEL_ATTR_SERVICE_NAME, ddtrace_otel_attr_zstr(service));
    offset = ddtrace_otel_record_write_attr_zstr(record, offset, DDTRACE_OTEL_ATTR_SERVICE_VERSION, ddtrace_otel_attr_zstr(version));
    offset = ddtrace_otel_record_write_attr_zstr(record, offset, DDTRACE_OTEL_ATTR_DEPLOYMENT_ENVIRONMENT_NAME, ddtrace_otel_attr_zstr(env));
    record->attrs_data_size = (uint16_t)offset;
}

static void ddtrace_otel_record_set_attrs(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root) {
    zend_string *cfg_service = get_DD_SERVICE(),
                *cfg_env = get_DD_ENV(),
                *cfg_version = get_DD_VERSION();
    zend_string *service = NULL, *env = NULL, *version = NULL;

    ddtrace_span_data *span_for_service_env = ddtrace_otel_attr_source_span(root);
    datadog_populate_target_data_with_defaults(span_for_service_env, &service, &env, &version, cfg_service, cfg_env, cfg_version);

    ddtrace_otel_record_set_attrs_from_values(record, root, service, env, version);

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

static size_t ddtrace_otel_record_write_attr_zstr(datadog_otel_thr_ctx_rec *record, size_t offset, uint8_t key_index, zend_string *value) {
    size_t value_len = ZSTR_LEN(value);
    if (value_len > UINT8_MAX) {
        value_len = UINT8_MAX;
    }
    if (offset + 2 + value_len > DATADOG_PHP_PROFILING_OTEL_ATTRS_DATA_SIZE) {
        return offset;
    }

    record->attrs_data[offset] = key_index;
    record->attrs_data[offset + 1] = (uint8_t)value_len;
    memcpy(record->attrs_data + offset + 2, ZSTR_VAL(value), value_len);
    return offset + 2 + value_len;
}

static zend_string *ddtrace_otel_attr_zstr(zend_string *value) {
    return value ? value : ZSTR_EMPTY_ALLOC();
}

static uint64_t ddtrace_u64_be(uint64_t value) {
#if __BYTE_ORDER__ == __ORDER_LITTLE_ENDIAN__
    return __builtin_bswap64(value);
#elif __BYTE_ORDER__ == __ORDER_BIG_ENDIAN__
    return value;
#else
#error "Unsupported byte order"
#endif
}

#else // neither Linux nor macOS

void ddtrace_otel_init_root_span(ddtrace_root_span_data *root) {
    UNUSED(root);
}

void ddtrace_otel_update_trace_id(ddtrace_root_span_data *root) {
    UNUSED(root);
}

void ddtrace_otel_update_trace_flags(ddtrace_root_span_data *root) {
    UNUSED(root);
}

void ddtrace_otel_update_span_id(ddtrace_root_span_data *root, uint64_t span_id) {
    UNUSED(root);
    UNUSED(span_id);
}

void ddtrace_otel_update_attribute_values(ddtrace_root_span_data *root) {
    UNUSED(root);
}

void ddtrace_otel_attach_stack(ddtrace_span_stack *stack) {
    UNUSED(stack);
}

void ddtrace_otel_detach(void) {
}

void ddtrace_detach_otel_thread_context_for_root(ddtrace_root_span_data *root_span) {
    UNUSED(root_span);
}

#endif
