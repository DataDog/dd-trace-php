#include "otel_context.h"

#include <stddef.h>
#include <stdio.h>
#include <string.h>
#include <sys/syscall.h>
#include <unistd.h>

#include <ext/target_metadata.h>

#include "configuration.h"
#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "span.h"
#include "trace_context.h"

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#define DDTRACE_OTEL_ATTR_LOCAL_ROOT_SPAN_ID 0
#define DDTRACE_OTEL_ATTR_SERVICE_NAME 1
#define DDTRACE_OTEL_ATTR_DEPLOYMENT_ENVIRONMENT_NAME 2
#define DDTRACE_OTEL_ATTR_SERVICE_VERSION 3
#define DDTRACE_OTEL_ATTR_THREAD_ID 4
#define DDTRACE_OTEL_LOCAL_ROOT_SPAN_ID_ATTR_SIZE 18

_Static_assert(ATOMIC_CHAR_LOCK_FREE == 2, "OTel thread context byte atomics must always be lock-free");
_Static_assert(ATOMIC_LLONG_LOCK_FREE == 2, "OTel thread context 64-bit atomics must always be lock-free");
_Static_assert(sizeof(datadog_otel_thr_ctx_rec) == 640, "unexpected OTel thread context record size");
_Static_assert(_Alignof(datadog_otel_thr_ctx_rec) == 8, "unexpected OTel thread context record alignment");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, trace_id) == 0, "unexpected OTel thread context trace_id offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, span_id) == 16, "unexpected OTel thread context span_id offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, valid) == 24, "unexpected OTel thread context valid offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, trace_flags) == 25, "unexpected OTel thread context trace_flags offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, attrs_data_size) == 26, "unexpected OTel thread context attrs_data_size offset");
_Static_assert(offsetof(datadog_otel_thr_ctx_rec, attrs_data) == 28, "unexpected OTel thread context attrs_data offset");
_Static_assert(offsetof(ddtrace_root_span_data, otel_context) % 8 == 0, "unexpected OTel thread context placement");
_Static_assert((offsetof(ddtrace_root_span_data, otel_context) + offsetof(datadog_otel_thr_ctx_rec, span_id)) % 8 == 0,
               "unexpected OTel thread context span_id placement");

typedef struct {
    // The length of the used bytes in .value, excluding null.
    uint8_t len;
    // On Linux tids are pid_t, which are `int`, so the most negative value is
    // the longest when printed (because of sign bit).
    char value[sizeof("-2147483648")];
} ddtrace_otel_cached_tid;

static __thread ddtrace_otel_cached_tid otel_tid = {0};
__thread void *otel_thread_ctx_v1 __attribute__((visibility("default"), tls_model("global-dynamic")));

static void ddtrace_otel_record_begin_update(datadog_otel_thr_ctx_rec *record);
static void ddtrace_otel_record_end_update(datadog_otel_thr_ctx_rec *record);
static void ddtrace_otel_attach(ddtrace_root_span_data *root);
static ddtrace_root_span_data *ddtrace_otel_attr_source_root(ddtrace_root_span_data *root);
static void ddtrace_otel_record_set_trace_id(datadog_otel_thr_ctx_rec *record, datadog_trace_id trace_id);
static void ddtrace_otel_record_set_trace_flags(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root);
static void ddtrace_otel_record_set_attrs(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root, ddtrace_root_span_data *source);
static void ddtrace_otel_refresh_attribute_values(ddtrace_root_span_data *root);
static size_t ddtrace_otel_record_write_attr_zstr(datadog_otel_thr_ctx_rec *record, size_t offset, uint8_t key_index, zend_string *value);
static size_t ddtrace_otel_record_write_attr(datadog_otel_thr_ctx_rec *record, size_t offset, uint8_t key_index, const char *value, size_t value_len);
static zend_string *ddtrace_otel_attr_zstr(zend_string *value);

void ddtrace_otel_init_root_span(ddtrace_root_span_data *root) {
    root->otel_context_slot = &otel_thread_ctx_v1;
    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_otel_record_set_trace_id(record, root->trace_id);
    ddtrace_otel_record_store_span_id(record, root->span_id);
    ddtrace_otel_record_set_trace_flags(record, root);
    ddtrace_root_span_data *source = ddtrace_otel_attr_source_root(root);
    ddtrace_otel_record_set_attrs(record, root, source);
    ddtrace_root_span_data *generation_source = source ? source : root;
    root->otel_context_attributes_source_generation = generation_source->otel_context_attributes_generation;
    ddtrace_otel_record_end_update(record);
}

void ddtrace_otel_update_trace_id(ddtrace_root_span_data *root) {
    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_otel_record_begin_update(record);
    ddtrace_otel_record_set_trace_id(record, root->trace_id);
    ddtrace_otel_record_set_trace_flags(record, root);
    ddtrace_otel_record_end_update(record);
}

void ddtrace_otel_update_trace_flags(ddtrace_root_span_data *root) {
    if (!root) {
        return;
    }

    // This is a single-field atomic update, so the record remains a consistent snapshot without
    // toggling valid. Multi-field trace-id updates use the begin/end update protocol instead.
    ddtrace_otel_record_set_trace_flags(&root->otel_context, root);
}

void ddtrace_otel_update_attribute_values(ddtrace_root_span_data *root) {
    if (!root || !get_DD_TRACE_ENABLED()) {
        return;
    }

    // Every nested root inherits these attributes from its entrypoint root. Advance only that
    // entrypoint's generation so independent traces do not invalidate one another.
    ddtrace_root_span_data *source = ddtrace_otel_attr_source_root(root);
    ddtrace_root_span_data *generation_source = source ? source : root;
    ++generation_source->otel_context_attributes_generation;

    ddtrace_span_stack *active_stack = DDTRACE_G(active_stack);
    if (active_stack && active_stack->root_span) {
        ddtrace_root_span_data *active_source = ddtrace_otel_attr_source_root(active_stack->root_span);
        ddtrace_root_span_data *active_generation_source = active_source ? active_source : active_stack->root_span;
        if (active_generation_source == generation_source) {
            ddtrace_otel_refresh_attribute_values(active_stack->root_span);
        }
    }
}

void ddtrace_otel_attach_stack(ddtrace_span_stack *stack) {
    if (!stack || !stack->root_span || !stack->active) {
        ddtrace_otel_detach();
        return;
    }

    // Multiple span stacks can share one root and therefore one context record. Refresh the
    // selected span on every attach rather than relying on the last stack that updated the root.
    // stack->active may be an inherited span that has since closed on its owning parent stack,
    // so resolve the effective active span across the stack hierarchy.
    ddtrace_span_data *active_span = ddtrace_active_span();
    if (!active_span) {
        ddtrace_otel_detach();
        return;
    }

    ddtrace_root_span_data *root = stack->root_span;
    ddtrace_otel_record_store_span_id(&root->otel_context, active_span->span_id);
    ddtrace_root_span_data *source = ddtrace_otel_attr_source_root(root);
    ddtrace_root_span_data *generation_source = source ? source : root;
    // Inactive span stacks may cache stale inherited attributes. Refresh only when their own
    // entrypoint changed; see otel_thread_context_stack_switch.phpt.
    if (UNEXPECTED(root->otel_context_attributes_source_generation != generation_source->otel_context_attributes_generation)) {
        ddtrace_otel_refresh_attribute_values(root);
    }
    ddtrace_otel_attach(root);
}

void ddtrace_otel_detach(void) {
    otel_thread_ctx_v1 = NULL;
    atomic_signal_fence(memory_order_release);
}

static void ddtrace_otel_record_begin_update(datadog_otel_thr_ctx_rec *record) {
    atomic_store_explicit(&record->valid, 0, memory_order_relaxed);
    atomic_signal_fence(memory_order_acquire);
}

static void ddtrace_otel_record_end_update(datadog_otel_thr_ctx_rec *record) {
    atomic_signal_fence(memory_order_release);
    atomic_store_explicit(&record->valid, 1, memory_order_relaxed);
}

static void ddtrace_otel_attach(ddtrace_root_span_data *root) {
    atomic_signal_fence(memory_order_release);
    *root->otel_context_slot = &root->otel_context;
}

static ddtrace_root_span_data *ddtrace_otel_attr_source_root(ddtrace_root_span_data *root) {
    if (ddtrace_span_is_entrypoint_root(&root->span)) {
        return root;
    }

    for (ddtrace_span_stack *stack = root->span.stack; stack; stack = stack->parent_stack) {
        if (stack->root_span && ddtrace_span_is_entrypoint_root(&stack->root_span->span)) {
            return stack->root_span;
        }
    }

    return NULL;
}

static void ddtrace_otel_record_set_trace_id(datadog_otel_thr_ctx_rec *record, datadog_trace_id trace_id) {
    record->trace_id[0] = ddtrace_otel_u64_be(trace_id.high);
    record->trace_id[1] = ddtrace_otel_u64_be(trace_id.low);
}

static void ddtrace_otel_refresh_attribute_values(ddtrace_root_span_data *root) {
    datadog_otel_thr_ctx_rec *record = &root->otel_context;
    ddtrace_root_span_data *source = ddtrace_otel_attr_source_root(root);
    ddtrace_otel_record_begin_update(record);
    ddtrace_otel_record_set_attrs(record, root, source);
    ddtrace_root_span_data *generation_source = source ? source : root;
    root->otel_context_attributes_source_generation = generation_source->otel_context_attributes_generation;
    ddtrace_otel_record_end_update(record);
}

static void ddtrace_otel_record_set_trace_flags(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root) {
    zend_long sampling_priority =
        Z_TYPE(root->property_sampling_priority) == IS_UNDEF ? DDTRACE_PRIORITY_SAMPLING_UNKNOWN : zval_get_long(&root->property_sampling_priority);
    atomic_store_explicit(&record->trace_flags, ddtrace_compute_trace_flags(root->trace_flags, sampling_priority), memory_order_relaxed);
}

static void ddtrace_otel_record_set_attrs(datadog_otel_thr_ctx_rec *record, ddtrace_root_span_data *root, ddtrace_root_span_data *source) {
    static const uint8_t hex_digits[] = "0123456789abcdef";

    record->attrs_data[0] = DDTRACE_OTEL_ATTR_LOCAL_ROOT_SPAN_ID;
    record->attrs_data[1] = 16;
    for (uint8_t i = 0; i < 16; ++i) {
        record->attrs_data[2 + i] = hex_digits[(root->span_id >> ((15 - i) * 4)) & 0xf];
    }

    zend_string *service = NULL, *env = NULL, *version = NULL;
    datadog_populate_target_data(source ? &source->span : NULL, &service, &env, &version);

    size_t offset = DDTRACE_OTEL_LOCAL_ROOT_SPAN_ID_ATTR_SIZE;
    offset = ddtrace_otel_record_write_attr_zstr(record, offset, DDTRACE_OTEL_ATTR_SERVICE_NAME, ddtrace_otel_attr_zstr(service));
    offset = ddtrace_otel_record_write_attr_zstr(record, offset, DDTRACE_OTEL_ATTR_DEPLOYMENT_ENVIRONMENT_NAME, ddtrace_otel_attr_zstr(env));
    offset = ddtrace_otel_record_write_attr_zstr(record, offset, DDTRACE_OTEL_ATTR_SERVICE_VERSION, ddtrace_otel_attr_zstr(version));

    if (UNEXPECTED(otel_tid.len == 0)) { // should only happen 1x per thread
        long tid = syscall(SYS_gettid);
        if (EXPECTED(tid > 0)) { // this syscall is fundamental and shouldn't fail
            // tids are just pid_t but there's no safety in casting down to int
            // here, if the buffer is big enough, then we're good.
            int tid_len = snprintf(otel_tid.value, sizeof otel_tid.value, "%ld", tid);
            // Should fit, tids are `int` on Linux.
            if (EXPECTED(tid_len > 0 && (size_t)tid_len < sizeof otel_tid.value)) {
                otel_tid.len = (uint8_t)tid_len;
            }
        }
    }
    if (EXPECTED(otel_tid.len != 0)) {
        offset = ddtrace_otel_record_write_attr(record, offset, DDTRACE_OTEL_ATTR_THREAD_ID, otel_tid.value, (size_t)otel_tid.len);
    }
    record->attrs_data_size = (uint16_t)offset;

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

static size_t ddtrace_otel_record_write_attr(datadog_otel_thr_ctx_rec *record, size_t offset, uint8_t key_index, const char *value,
                                             size_t value_len) {
    if (value_len > UINT8_MAX) {
        value_len = UINT8_MAX;
    }
    if (offset + 2 + value_len > DATADOG_PHP_PROFILING_OTEL_ATTRS_DATA_SIZE) {
        return offset;
    }

    record->attrs_data[offset] = key_index;
    record->attrs_data[offset + 1] = (uint8_t)value_len;
    memcpy(record->attrs_data + offset + 2, value, value_len);
    return offset + 2 + value_len;
}

static zend_string *ddtrace_otel_attr_zstr(zend_string *value) { return value ? value : ZSTR_EMPTY_ALLOC(); }

void ddtrace_otel_tid_fork_handler(void) {
    otel_tid.len = 0;
}
