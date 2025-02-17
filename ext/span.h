#ifndef DD_SPAN_H
#define DD_SPAN_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdbool.h>
#include <stdint.h>
#include <sys/types.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "ddtrace_export.h"
#include "priority_sampling/priority_sampling.h"

#define DDTRACE_DROPPED_SPAN (-1ull)
#define DDTRACE_SILENTLY_DROPPED_SPAN (-2ull)

#define DDTRACE_SPAN_FLAG_OPENTELEMETRY (1 << 0)
#define DDTRACE_SPAN_FLAG_OPENTRACING (1 << 1)

struct ddtrace_span_stack;

enum ddtrace_span_dataype {
    DDTRACE_INTERNAL_SPAN,
    DDTRACE_USER_SPAN,
    DDTRACE_AUTOROOT_SPAN,
    DDTRACE_INFERRED_SPAN,
    DDTRACE_SPAN_CLOSED,
};

typedef struct {
    double sampling_rate;
    int rule;
    enum dd_sampling_mechanism mechanism;
} ddtrace_rule_result;

enum ddtrace_trace_limited {
    DD_TRACE_LIMIT_UNCHECKED,
    DD_TRACE_LIMITED,
    DD_TRACE_UNLIMITED,
};

typedef union ddtrace_span_properties {
    zend_object std;
    struct {
        char object_placeholder[sizeof(zend_object) - sizeof(zval)];
        zval property_name;
        zval property_resource;
        zval property_service;
        zval property_env;
        zval property_version;
        zval property_meta_struct;
        zval property_type;
        zval property_meta;
        zval property_metrics;
        zval property_exception;
        union {
            zend_string *string_id;
            zval property_id;
        };
        zval property_links;
        zval property_events;
        zval property_peer_service_sources;
        union {
            union ddtrace_span_properties *parent;
            zval property_parent;
        };
        union {
            struct ddtrace_span_stack *stack;
            zval property_stack;
        };
    };
} ddtrace_span_properties;

// Refcounting:
// Only internal/autoroot spans retain a ref on their own
// Non-flushed closed spans also have a ref on their own
// The current active span also has a ref on its own
// Spans keep a ref to their parents (parent span property)
// Open spans as well as flushed spans keep a reference to the span stack
struct ddtrace_span_data {
    uint64_t span_id;
    uint64_t start;
    uint64_t duration_start;
    uint64_t duration;
    uint8_t flags;
    enum ddtrace_span_dataype type : 8;
    bool notify_user_req_end;
    struct ddtrace_span_data *next;
    struct ddtrace_root_span_data *root;
    bool is_child_of_inferred_span;

    union {
        ddtrace_span_properties;
        ddtrace_span_properties props;
    };
};

static inline ddtrace_span_data *OBJ_SPANDATA(zend_object *obj) {
    return (ddtrace_span_data *)((char *)(obj) - XtOffsetOf(ddtrace_span_data, std));
}

static inline ddtrace_span_data *SPANDATA(ddtrace_span_properties *obj) {
    return OBJ_SPANDATA(&obj->std);
}

struct ddtrace_root_span_data {
    ddtrace_trace_id trace_id;
    uint64_t parent_id;
    ddtrace_rule_result sampling_rule;
    bool explicit_sampling_priority;
    enum ddtrace_trace_limited trace_is_limited;
    struct ddtrace_root_span_data *child_root; // Only used when inferring proxy services (type: DDTRACE_INFERRED_SPAN)

    union {
        ddtrace_span_data;
        ddtrace_span_data span;
    };

    zval property_origin;
    zval property_propagated_tags;
    zval property_sampling_priority;
    zval property_propagated_sampling_priority;
    zval property_tracestate;
    zval property_tracestate_tags;
    zval property_parent_id;
    zval property_trace_id;
    zval property_git_metadata;
};

static inline ddtrace_root_span_data *ROOTSPANDATA(zend_object *obj) {
    return (ddtrace_root_span_data *)((char *)(obj) - XtOffsetOf(ddtrace_root_span_data, std));
}

struct ddtrace_span_stack {
    union {
        zend_object std;
        struct {
            char object_placeholder[sizeof(zend_object) - sizeof(zval)];
            union {
                zval property_parent;
                struct ddtrace_span_stack *parent_stack;  // when creating a fork on an active chunk
            };
            union {
                zval property_active;
                ddtrace_span_properties *active;
            };
        };
    };
    struct ddtrace_root_span_data *root_span;
    struct ddtrace_span_stack *root_stack;
    union {
        struct ddtrace_span_stack *next; // closed chunk chain
        struct {
            zend_function *fiber_entry_function;
#if PHP_VERSION_ID >= 80100 && PHP_VERSION_ID < 80200
            zend_execute_data *fiber_initial_execute_data;
#endif
        };
    };
    ddtrace_span_stack *top_closed_stack;
    // closed ring: linked list where the last element links to the first. The last inserted element is always reachable via closed_ring->next.
    ddtrace_span_data *closed_ring;
    ddtrace_span_data *closed_ring_flush;
};

struct ddtrace_span_link {
    union {
        zend_object std;
        struct {
            char object_placeholder[sizeof(zend_object) - sizeof(zval)];
            zval property_trace_id;
            zval property_span_id;
            zval property_trace_state;
            zval property_attributes;
            zval property_dropped_attributes_count;
        };
    };
};

struct ddtrace_span_event {
    union {
        zend_object std;
        struct {
            char object_placeholder[sizeof(zend_object) - sizeof(zval)];
            zval property_name;
            zval property_attributes;
            zval property_timestamp;
        };
    };
};

struct ddtrace_exception_span_event {
    ddtrace_span_event span_event;
    zval property_exception;
};

struct ddtrace_git_metadata {
    union {
        zend_object std;
        struct {
            char object_placeholder[sizeof(zend_object) - sizeof(zval)];
            zval property_commit;
            zval property_repository;
        };
    };
};

void ddtrace_init_span_stacks(void);
void ddtrace_free_span_stacks(bool silent);
void ddtrace_switch_span_stack(ddtrace_span_stack *target_stack);

ddtrace_span_data *ddtrace_open_span(enum ddtrace_span_dataype type);
ddtrace_span_data *ddtrace_init_dummy_span(void);
ddtrace_span_stack *ddtrace_init_span_stack(void);
ddtrace_span_stack *ddtrace_init_root_span_stack(void);
void ddtrace_push_root_span(void);
ddtrace_span_data *ddtrace_push_inferred_root_span(void);

ddtrace_span_data *ddtrace_active_span(void);
static inline ddtrace_span_properties *ddtrace_active_span_props(void) {
    ddtrace_span_data *span = ddtrace_active_span();
    return span ? &span->props : NULL;
}

ddtrace_span_data *ddtrace_alloc_execute_data_span(zend_ulong invocation, zend_execute_data *execute_data);
void ddtrace_clear_execute_data_span(zend_ulong invocation, bool keep);

// Note that this function is used externally by the appsec extension.
DDTRACE_PUBLIC zend_object *ddtrace_get_root_span(void);

uint64_t ddtrace_nanoseconds_realtime(void);
void dd_trace_stop_span_time(ddtrace_span_data *span);
bool ddtrace_has_top_internal_span(ddtrace_span_data *end);
void ddtrace_close_stack_userland_spans_until(ddtrace_span_data *until);
int ddtrace_close_userland_spans_until(ddtrace_span_data *until);
void ddtrace_close_span(ddtrace_span_data *span);
void ddtrace_close_span_restore_stack(ddtrace_span_data *);
void ddtrace_close_top_span_without_stack_swap(ddtrace_span_data *span);
void ddtrace_close_all_open_spans(bool force_close_root_span);
void ddtrace_drop_span(ddtrace_span_data *span);
void ddtrace_mark_all_span_stacks_flushable(void);
void ddtrace_serialize_closed_spans(zval *serialized);
void ddtrace_serialize_closed_spans_with_cycle(zval *serialized);
zend_string *ddtrace_span_id_as_string(uint64_t id);
zend_string *ddtrace_trace_id_as_string(ddtrace_trace_id id);
zend_string *ddtrace_span_id_as_hex_string(uint64_t id);
zend_string *ddtrace_trace_id_as_hex_string(ddtrace_trace_id id);
ddtrace_root_span_data *ddtrace_open_inferred_span(zend_array *headers);
void ddtrace_infer_proxy_services(void);

bool ddtrace_span_alter_root_span_config(zval *old_value, zval *new_value, zend_string *new_str);

static inline bool ddtrace_span_is_dropped(ddtrace_span_data *span) {
    return span->duration == DDTRACE_DROPPED_SPAN || span->duration == DDTRACE_SILENTLY_DROPPED_SPAN;
}

static inline bool ddtrace_span_is_entrypoint_root(ddtrace_span_data *span) {
    // The parent stack of a true top-level stack does never have a parent stack itself
    return span->std.ce == ddtrace_ce_root_span_data && (!span->stack->parent_stack || !span->stack->parent_stack->parent_stack) && !span->is_child_of_inferred_span;
}

#endif  // DD_SPAN_H
