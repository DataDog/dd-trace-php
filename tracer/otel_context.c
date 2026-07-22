#include "otel_context.h"

#include "ddtrace.h"
#include "span.h"
#include <ext/target_metadata.h>

#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#include <stdlib.h>
#define atomic_store_explicit(object, desired, order) atomic_store((object), (desired))
#define atomic_signal_fence(order) _ReadWriteBarrier()
#endif
#include <stddef.h>
#include <stdio.h>
#include <string.h>
#if defined(__linux__)
#include <sys/syscall.h>
#include <unistd.h>
#elif defined(__APPLE__)
#include <pthread.h>
#elif defined(_WIN32)
#include <windows.h>
#endif

#include <ext/datadog_export.h>

#include "configuration.h"

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#define DDTRACE_OTEL_ATTR_LOCAL_ROOT_SPAN_ID 0
#define DDTRACE_OTEL_ATTR_SERVICE_NAME 1
#define DDTRACE_OTEL_ATTR_SERVICE_VERSION 2
#define DDTRACE_OTEL_ATTR_DEPLOYMENT_ENVIRONMENT_NAME 3
#define DDTRACE_OTEL_ATTR_THREAD_ID 4
#define DDTRACE_OTEL_LOCAL_ROOT_SPAN_ID_ATTR_SIZE 18

_Static_assert(sizeof(datadog_otel_thr_ctx_rec) == 640, "unexpected OTel thread context record size");
_Static_assert(_Alignof(datadog_otel_thr_ctx_rec) == 2, "unexpected OTel thread context record alignment");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, trace_id) == 0, "unexpected OTel thread context trace_id offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, span_id) == 16, "unexpected OTel thread context span_id offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, valid) == 24, "unexpected OTel thread context valid offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, reserved) == 25, "unexpected OTel thread context reserved offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, attrs_data_size) == 26, "unexpected OTel thread context attrs_data_size offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, attrs_data) == 28, "unexpected OTel thread context attrs_data offset");
_Static_assert(offsetof(ddtrace_root_span_data, otel_context) % 8 == 0, "unexpected OTel thread context placement");
_Static_assert(
    (offsetof(ddtrace_root_span_data, otel_context) + offsetof(datadog_otel_thr_ctx_rec, span_id)) % 8 == 0,
    "unexpected OTel thread context span_id placement");

#if defined(__linux__)
extern __thread void *otel_thread_ctx_v1;

static void **ddtrace_otel_thread_ctx_slot(void) {
    return &otel_thread_ctx_v1;
}
#elif defined(__APPLE__)
DATADOG_PUBLIC __thread void *otel_thread_ctx_v1 = NULL;

static void **ddtrace_otel_thread_ctx_slot(void) {
    return &otel_thread_ctx_v1;
}

DATADOG_PUBLIC void **ddog_thread_ctx_v1(void) {
    return ddtrace_otel_thread_ctx_slot();
}
#elif defined(_WIN32)
static __declspec(thread) void *ddog_php_thread_ctx_v1 = NULL;

static void **ddtrace_otel_thread_ctx_slot(void) {
    return &ddog_php_thread_ctx_v1;
}

DATADOG_PUBLIC void **ddog_thread_ctx_v1(void) {
    return ddtrace_otel_thread_ctx_slot();
}
#else
static __thread void *ddog_php_thread_ctx_v1 = NULL;

static void **ddtrace_otel_thread_ctx_slot(void) {
    return &ddog_php_thread_ctx_v1;
}

DATADOG_PUBLIC void **ddog_thread_ctx_v1(void) {
    return ddtrace_otel_thread_ctx_slot();
}
#endif

static void ddtrace_otel_record_begin_update(datadog_otel_thr_ctx_rec *record);
static void ddtrace_otel_record_end_update(datadog_otel_thr_ctx_rec *record);
static void ddtrace_otel_attach(datadog_otel_thr_ctx_rec *record);
static ddtrace_span_data *ddtrace_otel_entrypoint_span(void);
static ddtrace_span_data *ddtrace_otel_attr_source_span(ddtrace_root_span_data *source_root);
static void ddtrace_otel_record_set_trace_id(datadog_otel_thr_ctx_rec *record, datadog_trace_id trace_id);
static void ddtrace_otel_record_set_span_id(datadog_otel_thr_ctx_rec *record, uint64_t span_id);
static void ddtrace_otel_record_set_attrs(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root);
static void ddtrace_otel_record_set_attrs_from_values(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root, zend_string *service, zend_string *env, zend_string *version);
static size_t ddtrace_otel_record_write_attr_zstr(datadog_otel_thr_ctx_rec *record, size_t offset, uint8_t key_index, zend_string *value);
static size_t ddtrace_otel_record_write_attr(datadog_otel_thr_ctx_rec *record, size_t offset, uint8_t key_index, const char *value, size_t value_len);
static zend_string *ddtrace_otel_attr_zstr(zend_string *value);
static uint64_t ddtrace_otel_current_thread_id(void);
static uint64_t ddtrace_u64_be(uint64_t value);
static void ddtrace_otel_store_bytes(_Atomic(uint8_t) *destination, const uint8_t *source, size_t size);

void ddtrace_otel_init_root_span(ddtrace_root_span_data *root) {
    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_span_data *span = &root->span;
    ddtrace_otel_record_set_trace_id(record, root->trace_id);
    ddtrace_otel_record_set_span_id(record, span->span_id);
    ddtrace_otel_record_set_attrs(record, root);
    atomic_store_explicit(&record->valid, 1, memory_order_relaxed);
}

void ddtrace_otel_update_trace_id(ddtrace_root_span_data *root) {
    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_otel_record_begin_update(record);
    ddtrace_otel_record_set_trace_id(record, root->trace_id);
    ddtrace_otel_record_end_update(record);
}

void ddtrace_otel_update_span_id(ddtrace_root_span_data *root, uint64_t span_id) {
    if (!root) {
        return;
    }

    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_otel_record_begin_update(record);
    ddtrace_otel_record_set_span_id(record, span_id);
    ddtrace_otel_record_end_update(record);
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
    ddtrace_otel_record_set_attrs(record, root);
    ddtrace_otel_record_end_update(record);
    ddtrace_otel_attach(record);
}

void ddtrace_otel_detach(void) {
    atomic_signal_fence(memory_order_release);
    *ddtrace_otel_thread_ctx_slot() = NULL;
}

void ddtrace_detach_otel_thread_context_for_root(ddtrace_root_span_data *root_span) {
    if (root_span && *ddtrace_otel_thread_ctx_slot() == &root_span->otel_context) {
        ddtrace_otel_detach();
    }
}

static void ddtrace_otel_record_begin_update(datadog_otel_thr_ctx_rec *record) {
    atomic_store_explicit(&record->valid, 0, memory_order_relaxed);
    atomic_signal_fence(memory_order_acquire);
}

static void ddtrace_otel_record_end_update(datadog_otel_thr_ctx_rec *record) {
    atomic_signal_fence(memory_order_release);
    atomic_store_explicit(&record->valid, 1, memory_order_relaxed);
}

static void ddtrace_otel_attach(datadog_otel_thr_ctx_rec *record) {
    atomic_signal_fence(memory_order_release);
    *ddtrace_otel_thread_ctx_slot() = record;
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
    uint64_t big_endian[2] = {ddtrace_u64_be(trace_id.high), ddtrace_u64_be(trace_id.low)};
    ddtrace_otel_store_bytes(record->trace_id, (const uint8_t *)big_endian, sizeof(big_endian));
}

static void ddtrace_otel_record_set_span_id(datadog_otel_thr_ctx_rec *record, uint64_t span_id) {
    uint64_t big_endian = ddtrace_u64_be(span_id);
    ddtrace_otel_store_bytes(record->span_id, (const uint8_t *)&big_endian, sizeof(big_endian));
}

static void ddtrace_otel_record_set_attrs_from_values(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root, zend_string *service, zend_string *env, zend_string *version) {
    static const uint8_t hex_digits[] = "0123456789abcdef";

    uint64_t span_id = root->span_id;
    // attrs_data entries store UTF-8 string values. Key index 0 is reserved
    // for the local root span id as a fixed 16-char lowercase hex string.
    atomic_store_explicit(&record->attrs_data[0], DDTRACE_OTEL_ATTR_LOCAL_ROOT_SPAN_ID, memory_order_relaxed);
    atomic_store_explicit(&record->attrs_data[1], 16, memory_order_relaxed);
    for (uint8_t i = 0; i < 16; ++i) {
        atomic_store_explicit(&record->attrs_data[2 + i], hex_digits[(span_id >> ((15 - i) * 4)) & 0xf], memory_order_relaxed);
    }

    size_t offset = DDTRACE_OTEL_LOCAL_ROOT_SPAN_ID_ATTR_SIZE;
    offset = ddtrace_otel_record_write_attr_zstr(record, offset, DDTRACE_OTEL_ATTR_SERVICE_NAME, ddtrace_otel_attr_zstr(service));
    offset = ddtrace_otel_record_write_attr_zstr(record, offset, DDTRACE_OTEL_ATTR_SERVICE_VERSION, ddtrace_otel_attr_zstr(version));
    offset = ddtrace_otel_record_write_attr_zstr(record, offset, DDTRACE_OTEL_ATTR_DEPLOYMENT_ENVIRONMENT_NAME, ddtrace_otel_attr_zstr(env));
    char thread_id[32];
    int thread_id_len = snprintf(thread_id, sizeof(thread_id), "%llu", (unsigned long long)ddtrace_otel_current_thread_id());
    if (thread_id_len > 0) {
        offset = ddtrace_otel_record_write_attr(record, offset, DDTRACE_OTEL_ATTR_THREAD_ID, thread_id, (size_t)thread_id_len);
    }
    atomic_store_explicit(&record->attrs_data_size, (uint16_t)offset, memory_order_relaxed);
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
    return ddtrace_otel_record_write_attr(record, offset, key_index, ZSTR_VAL(value), ZSTR_LEN(value));
}

static size_t ddtrace_otel_record_write_attr(datadog_otel_thr_ctx_rec *record, size_t offset, uint8_t key_index, const char *value, size_t value_len) {
    if (value_len > UINT8_MAX) {
        value_len = UINT8_MAX;
    }
    if (offset + 2 + value_len > DATADOG_PHP_PROFILING_OTEL_ATTRS_DATA_SIZE) {
        return offset;
    }

    atomic_store_explicit(&record->attrs_data[offset], key_index, memory_order_relaxed);
    atomic_store_explicit(&record->attrs_data[offset + 1], (uint8_t)value_len, memory_order_relaxed);
    ddtrace_otel_store_bytes(record->attrs_data + offset + 2, (const uint8_t *)value, value_len);
    return offset + 2 + value_len;
}

static zend_string *ddtrace_otel_attr_zstr(zend_string *value) {
    return value ? value : ZSTR_EMPTY_ALLOC();
}

static uint64_t ddtrace_otel_current_thread_id(void) {
#if defined(__linux__)
    return (uint64_t)syscall(SYS_gettid);
#elif defined(__APPLE__)
    uint64_t thread_id = 0;
    return pthread_threadid_np(NULL, &thread_id) == 0 ? thread_id : 0;
#elif defined(_WIN32)
    return (uint64_t)GetCurrentThreadId();
#else
#error "Unsupported platform for OTel thread context"
#endif
}

static uint64_t ddtrace_u64_be(uint64_t value) {
#ifdef _WIN32
    return _byteswap_uint64(value);
#elif __BYTE_ORDER__ == __ORDER_LITTLE_ENDIAN__
    return __builtin_bswap64(value);
#elif __BYTE_ORDER__ == __ORDER_BIG_ENDIAN__
    return value;
#else
#error "Unsupported byte order"
#endif
}

static void ddtrace_otel_store_bytes(_Atomic(uint8_t) *destination, const uint8_t *source, size_t size) {
    for (size_t i = 0; i < size; ++i) {
        atomic_store_explicit(&destination[i], source[i], memory_order_relaxed);
    }
}
