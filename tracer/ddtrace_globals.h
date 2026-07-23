#ifndef DDTRACE_GLOBALS_H
#define DDTRACE_GLOBALS_H
#ifndef _WIN32
#include <dogstatsd_client/client.h>
#endif

#include <ext/datadog.h>

typedef struct ddtrace_span_ids_t ddtrace_span_ids_t;
typedef struct ddtrace_span_data ddtrace_span_data;
typedef struct ddtrace_inferred_span_data ddtrace_inferred_span_data;
typedef struct ddtrace_root_span_data ddtrace_root_span_data;
typedef struct ddtrace_span_stack ddtrace_span_stack;
typedef struct ddtrace_span_link ddtrace_span_link;
typedef struct ddtrace_span_event ddtrace_span_event;
typedef struct ddtrace_exception_span_event ddtrace_exception_span_event;
typedef struct datadog_git_metadata datadog_git_metadata;

typedef struct dd_refcounted_linked dd_refcounted_linked;

typedef struct {
    zend_arena *arena;
    dd_refcounted_linked *ephemerals;
} dd_capture_arena;

typedef struct {
    int type;
    zend_string *message;
} ddtrace_error_data;

// clang-format off
typedef struct {
    zend_bool api_is_loaded;
    zend_bool otel_is_loaded;
    zend_bool legacy_tracer_is_loaded;
    zend_bool openfeature_is_loaded;

    uint32_t traces_group_id;
    zend_array *additional_global_tags;
    zend_array root_span_tags_preset;
    zend_array propagated_root_span_tags;
    zend_string *tracestate;
    zend_array tracestate_unknown_dd_keys;
    ddtrace_error_data active_error;
    HashTable baggage;
#ifndef _WIN32
    dogstatsd_client dogstatsd_client;
#endif
    zend_bool in_shutdown;

    zend_long default_priority_sampling;
    zend_long propagated_priority_sampling;
    ddtrace_span_stack *active_stack; // never NULL except tracer is disabled
    ddtrace_span_stack *top_closed_stack;
    HashTable traced_spans; // tie a span to a specific active execute_data
    uint32_t open_spans_count;
    uint32_t baggage_extract_count;
    uint32_t baggage_inject_count;
    uint32_t baggage_malformed_count;
    uint32_t baggage_max_item_count;
    uint32_t baggage_max_byte_count;
    uint32_t baggage_extract_max_item_count;
    uint32_t baggage_extract_max_byte_count;
    uint32_t closed_spans_count;
    uint32_t dropped_spans_count;
    int64_t compile_time_microseconds;
    datadog_trace_id distributed_trace_id;
    uint64_t distributed_parent_trace_id;
    zend_string *dd_origin;
    zend_reference *curl_multi_injecting_spans;

    dd_capture_arena debugger_capture_arena;
    ddog_Vec_DebuggerPayload exception_debugger_buffer;
    HashTable active_live_debugger_hooks;
    HashTable *agent_rate_by_service;

    ddog_AgentRemoteConfigReader *agent_config_reader;
    HashTable telemetry_spans_created_per_integration;

#if PHP_VERSION_ID >= 80000
    HashTable curl_headers;
    // Multi-handle API: curl_multi_*()
    HashTable curl_multi_handles;
#endif

    HashTable uhook_active_hooks;
    HashTable uhook_closure_hooks;

    zend_object *git_object;

    bool inferred_span_created;
    zval pending_upstream_span_link; // span link queued by PROPAGATION_BEHAVIOR_EXTRACT=restart; consumed on root span open

    HashTable resource_weak_storage;
    dtor_func_t resource_dtor_func;

    void *ffe_exposure_buffer;
    size_t ffe_exposure_buffer_len;
    size_t ffe_exposure_buffer_cap;
    void *ffe_metric_buffer;
    size_t ffe_metric_buffer_len;
    size_t ffe_metric_buffer_cap;

} ddtrace_globals;

#define DDTRACE_G(v) (DATADOG_G(ddtrace).v)

#endif  // DDTRACE_H
