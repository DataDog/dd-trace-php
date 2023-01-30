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

#define DDTRACE_DROPPED_SPAN (-1ull)
#define DDTRACE_SILENTLY_DROPPED_SPAN (-2ull)

struct ddtrace_span_stack;

enum ddtrace_span_dataype {
    DDTRACE_INTERNAL_SPAN,
    DDTRACE_USER_SPAN,
    DDTRACE_AUTOROOT_SPAN,
    DDTRACE_SPAN_CLOSED,
};

// Refcounting:
// Only internal/autoroot spans retain a ref on their own
// Non-flushed closed spans also have a ref on their own
// The current active span also has a ref on its own
// Spans keep a ref to their parents (parent span property)
// Open spans as well as flushed spans keep a reference to the span stack
struct ddtrace_span_data {
    zend_object std;
    zval properties_table_placeholder[7];
    union {
        struct ddtrace_span_data *parent;
        zval property_parent;
    };
    union {
        struct ddtrace_span_stack *stack;
        zval property_stack;
    };
    ddtrace_trace_id trace_id;
    uint64_t parent_id;
    uint64_t span_id;
    uint64_t start;
    uint64_t duration_start;
    uint64_t duration;
    enum ddtrace_span_dataype type;
    struct ddtrace_span_data *next;
    struct ddtrace_span_data *root;
};

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
                struct ddtrace_span_data *active;
            };
        };
    };
    struct ddtrace_span_data *root_span;
    struct ddtrace_span_stack *root_stack;
    union {
        struct ddtrace_span_stack *next; // closed chunk chain
        zend_function *fiber_entry_function;
    };
    struct ddtrace_span_stack *top_closed_stack;
    // closed ring: linked list where the last element links to the first. The last inserted element is always reachable via closed_ring->next.
    struct ddtrace_span_data *closed_ring;
    struct ddtrace_span_data *closed_ring_flush;
};

void ddtrace_init_span_stacks(void);
void ddtrace_free_span_stacks(bool silent);
void ddtrace_switch_span_stack(ddtrace_span_stack *target_stack);

void ddtrace_open_span(ddtrace_span_data *span);
ddtrace_span_data *ddtrace_init_span(enum ddtrace_span_dataype type);
ddtrace_span_stack *ddtrace_init_span_stack(void);
ddtrace_span_stack *ddtrace_init_root_span_stack(void);
void ddtrace_push_root_span(void);

ddtrace_span_data *ddtrace_active_span(void);

ddtrace_span_data *ddtrace_alloc_execute_data_span(zend_ulong invocation, zend_execute_data *execute_data);
void ddtrace_clear_execute_data_span(zend_ulong invocation, bool keep);

// Note that this function is used externally by the appsec extension.
DDTRACE_PUBLIC bool ddtrace_root_span_add_tag(zend_string *tag, zval *value);

void dd_trace_stop_span_time(ddtrace_span_data *span);
bool ddtrace_has_top_internal_span(ddtrace_span_data *end);
void ddtrace_close_stack_userland_spans_until(ddtrace_span_data *until);
int ddtrace_close_userland_spans_until(ddtrace_span_data *until);
void ddtrace_close_span(ddtrace_span_data *span);
void ddtrace_close_top_span_without_stack_swap(ddtrace_span_data *span);
void ddtrace_close_all_open_spans(bool force_close_root_span);
void ddtrace_drop_span(ddtrace_span_data *span);
void ddtrace_mark_all_span_stacks_flushable(void);
void ddtrace_serialize_closed_spans(zval *serialized);
zend_string *ddtrace_span_id_as_string(uint64_t id);
zend_string *ddtrace_trace_id_as_string(ddtrace_trace_id id);

bool ddtrace_span_alter_root_span_config(zval *old_value, zval *new_value);

static inline bool ddtrace_span_is_dropped(ddtrace_span_data *span) {
    return span->duration == DDTRACE_DROPPED_SPAN || span->duration == DDTRACE_SILENTLY_DROPPED_SPAN;
}

#endif  // DD_SPAN_H
