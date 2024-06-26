#include "span.h"

#include <SAPI.h>
#include "priority_sampling/priority_sampling.h"
#include <time.h>
#include "zend_hrtime.h"

#include "auto_flush.h"
#include "compat_string.h"
#include "configuration.h"
#include "ddtrace.h"
#include <components/log/log.h>
#include "random.h"
#include "serializer.h"
#include "telemetry.h"
#include "ext/standard/php_string.h"
#include <hook/hook.h>
#include "user_request.h"
#include "zend_types.h"
#include "sidecar.h"

#define USE_REALTIME_CLOCK 0
#define USE_MONOTONIC_CLOCK 1

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static void dd_reset_span_counters(void) {
    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(dropped_spans_count) = 0;
    DDTRACE_G(closed_spans_count) = 0;
}

void ddtrace_init_span_stacks(void) {
    DDTRACE_G(top_closed_stack) = NULL;
    dd_reset_span_counters();
}

static void dd_drop_span_nodestroy(ddtrace_span_data *span, bool silent) {
    if (span->notify_user_req_end) {
        ddtrace_user_req_notify_finish(span);
        span->notify_user_req_end = false;
    }
    span->duration = silent ? DDTRACE_SILENTLY_DROPPED_SPAN : DDTRACE_DROPPED_SPAN;

    if (span->std.ce == ddtrace_ce_root_span_data) {
        ddtrace_root_span_data *root = ROOTSPANDATA(&span->std);
        LOG(SPAN_TRACE, "Dropping root span: trace_id=%s, span_id=%" PRIu64, Z_STRVAL(root->property_trace_id), span->span_id);
    } else {
        LOG(SPAN_TRACE, "Dropping span: trace_id=%s, span_id=%" PRIu64, Z_STRVAL(span->root->property_trace_id), span->span_id);
    }
}

static void dd_drop_span(ddtrace_span_data *span, bool silent) {
    dd_drop_span_nodestroy(span, silent);
    OBJ_RELEASE(&span->std);
}

#define DD_RC_CLOSED_MARKER 0x80000000

static void dd_free_span_ring(ddtrace_span_data *span) {
    if (span != NULL) {
        ddtrace_span_data *cur = span;
        do {
            ddtrace_span_data *tmp = cur;
            cur = cur->next;
#if PHP_VERSION_ID < 70400
            // remove the artificially increased RC while closing again
            GC_SET_REFCOUNT(&tmp->std, GC_REFCOUNT(&tmp->std) + DD_RC_CLOSED_MARKER);
#endif
            OBJ_RELEASE(&tmp->std);
        } while (cur != span);
    }
}

void ddtrace_free_span_stacks(bool silent) {
    // ensure automatic stacks of trace root spans are popped
    while (DDTRACE_G(active_stack)->root_span && DDTRACE_G(active_stack) == DDTRACE_G(active_stack)->root_span->stack) {
        ddtrace_switch_span_stack(DDTRACE_G(active_stack)->parent_stack);
    }

    zend_objects_store *objects = &EG(objects_store);
    zend_object **end = objects->object_buckets + 1;
    zend_object **obj_ptr = objects->object_buckets + objects->top;

    do {
        obj_ptr--;
        zend_object *obj = *obj_ptr;
        if (IS_OBJ_VALID(obj) && obj->ce == ddtrace_ce_span_stack) {
            ddtrace_span_stack *stack = (ddtrace_span_stack *)obj;

            // temporarily addref to avoid freeing the stack during it being processed
            GC_ADDREF(&stack->std);

            if (stack->active && SPANDATA(stack->active)->stack == stack) {
                ddtrace_span_data *active_span = SPANDATA(stack->active);
                stack->root_span = NULL;

                ddtrace_span_data *span = active_span->parent ? SPANDATA(active_span->parent) : NULL;
                while (span && span->stack == stack) {
                    dd_drop_span_nodestroy(span, silent);
                    span = span->parent ? SPANDATA(span->parent) : NULL;
                }

                stack->active = NULL;
                ZVAL_NULL(&stack->property_active);

                // drop the active span last, it holds the start of the span "chain" of parents which each hold a ref to the next
                dd_drop_span(active_span, silent);
            } else if (stack->active) {
                ddtrace_span_data *parent_span = SPANDATA(stack->active);
                stack->active = NULL;
                stack->root_span = NULL;
                ZVAL_NULL(&stack->property_active);
                OBJ_RELEASE(&parent_span->std);
            }

            dd_free_span_ring(stack->closed_ring);
            stack->closed_ring = NULL;

            // We hold a ref if it's waiting for being flushed
            if (stack->closed_ring_flush != NULL) {
                GC_DELREF(&stack->std);
            }
            dd_free_span_ring(stack->closed_ring_flush);
            stack->closed_ring_flush = NULL;

            stack->top_closed_stack = NULL;

            OBJ_RELEASE(&stack->std);
        }
    } while (obj_ptr != end);

    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(dropped_spans_count) = 0;
    DDTRACE_G(closed_spans_count) = 0;
    DDTRACE_G(top_closed_stack) = NULL;
}

static ddtrace_span_data *ddtrace_init_span(enum ddtrace_span_dataype type, zend_class_entry *ce) {
    zval fci_zv;
    object_init_ex(&fci_zv, ce);
    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ(fci_zv));
    span->type = type;
    return span;
}

uint64_t ddtrace_nanoseconds_realtime(void) {
    struct timespec ts;
    timespec_get(&ts, TIME_UTC);
    return ts.tv_sec * ZEND_NANO_IN_SEC + ts.tv_nsec;
}

ddtrace_span_data *ddtrace_open_span(enum ddtrace_span_dataype type) {
    ddtrace_span_stack *stack = DDTRACE_G(active_stack);
    // The primary stack is ancestor to all stacks, which signifies that any root spans created on top of it will inherit the distributed tracing context
    bool primary_stack = stack->parent_stack == NULL;

    if (primary_stack) {
        stack = ddtrace_init_root_span_stack();
        ddtrace_switch_span_stack(stack);
        // We don't hold a direct reference to the active stack
        GC_DELREF(&stack->std);
    }

    // ensure dtor can be called again
    GC_DEL_FLAGS(&stack->std, IS_OBJ_DESTRUCTOR_CALLED);

    bool root_span = DDTRACE_G(active_stack)->root_span == NULL;
    ddtrace_span_data *span = ddtrace_init_span(type, root_span ? ddtrace_ce_root_span_data : ddtrace_ce_span_data);

    // All open spans hold a ref to their stack
    ZVAL_OBJ_COPY(&span->property_stack, &stack->std);

    span->duration_start = zend_hrtime();
    // Start time is nanoseconds from unix epoch
    // @see https://docs.datadoghq.com/api/?lang=python#send-traces
    span->start = ddtrace_nanoseconds_realtime();

    span->span_id = ddtrace_generate_span_id();

    ddtrace_span_data *parent_span = SPANDATA(DDTRACE_G(active_stack)->active);
    ZVAL_OBJ(&DDTRACE_G(active_stack)->property_active, &span->std);
    ++DDTRACE_G(open_spans_count);

    // It just became the active span, so incref it.
    GC_ADDREF(&span->std);

    if (root_span) {
        ddtrace_root_span_data *root = ROOTSPANDATA(&span->std);
        DDTRACE_G(active_stack)->root_span = root;

        if (primary_stack && (DDTRACE_G(distributed_trace_id).low || DDTRACE_G(distributed_trace_id).high)) {
            root->trace_id = DDTRACE_G(distributed_trace_id);
            root->parent_id = DDTRACE_G(distributed_parent_trace_id);
        } else {
            root->trace_id = (ddtrace_trace_id) {
                    .low = span->span_id,
                    .time = get_DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED() ? span->start / ZEND_NANO_IN_SEC : 0,
            };
            root->parent_id = 0;
        }

        ZVAL_NULL(&span->property_parent);
        span->parent = NULL;

        ddtrace_set_root_span_properties(root);
    } else {
        // do not copy the parent, it was active span before, just transfer that reference
        ZVAL_OBJ(&span->property_parent, &parent_span->std);
        ddtrace_inherit_span_properties(span, parent_span);
    }

    span->root = DDTRACE_G(active_stack)->root_span;

    ddtrace_set_global_span_properties(span);

    if (root_span) {
        ddtrace_root_span_data *root = ROOTSPANDATA(&span->std);
        LOG(SPAN_TRACE, "Starting new root span: trace_id=%s, span_id=%" PRIu64 ", parent_id=%" PRIu64 ", SpanStack=%d, parent_SpanStack=%d", Z_STRVAL(root->property_trace_id), span->span_id, root->parent_id, root->stack->std.handle, root->stack->parent_stack->std.handle);
    } else {
        LOG(SPAN_TRACE, "Starting new span: trace_id=%s, span_id=%" PRIu64 ", parent_id=%" PRIu64 ", SpanStack=%d", Z_STRVAL(span->root->property_trace_id), span->span_id, SPANDATA(span->parent)->span_id, span->stack->std.handle);
    }

    return span;
}

// += 2 increment to avoid zval type ever being 0
ddtrace_span_data *ddtrace_alloc_execute_data_span(zend_ulong index, zend_execute_data *execute_data) {
    zval *span_zv = zend_hash_index_find(&DDTRACE_G(traced_spans), index);
    ddtrace_span_data *span;
    if (span_zv) {
        span = Z_PTR_P(span_zv);
        Z_TYPE_INFO_P(span_zv) += 2;
    } else {
        span = ddtrace_open_span(DDTRACE_INTERNAL_SPAN);

        // SpanData::$name defaults to fully qualified called name
        zval *prop_name = &span->property_name;

        if (EX(func) && (EX(func)->common.fn_flags & (ZEND_ACC_CLOSURE | ZEND_ACC_FAKE_CLOSURE)) == ZEND_ACC_CLOSURE) {
            zend_function *containing_function = zai_hook_find_containing_function(EX(func));
            if (containing_function) {
                // possible class name followed by function name
                zval_ptr_dtor(prop_name);
                if (EX(func)->common.scope) {
                    ZVAL_STR(prop_name, strpprintf(0, "%s.%s.{closure}",
                                                   ZSTR_VAL(containing_function->common.scope->name),
                                                   ZSTR_VAL(containing_function->common.function_name)));
                } else {
                    ZVAL_STR(prop_name, strpprintf(0, "%s.{closure}", ZSTR_VAL(containing_function->common.function_name)));
                }
            } else if (EX(func)->common.function_name && ZSTR_LEN(EX(func)->common.function_name) >= strlen("{closure}")) {
                // namespace followed by filename and lineno
                zval_ptr_dtor(prop_name);
                zend_string *basename = php_basename(ZSTR_VAL(EX(func)->op_array.filename), ZSTR_LEN(EX(func)->op_array.filename), NULL, 0);
                ZVAL_STR(prop_name, strpprintf(0, "%.*s%s:%d\\{closure}",
                                               (int)ZSTR_LEN(EX(func)->common.function_name) - (int)strlen("{closure}"),
                                               ZSTR_VAL(EX(func)->common.function_name),
                                               ZSTR_VAL(basename),
                                               EX(func)->op_array.opcodes->lineno));
                zend_string_release(basename);
            }

            zend_array *meta = ddtrace_property_array(&span->property_meta);
            zval location;
            ZVAL_STR(&location, zend_strpprintf(0, "%s:%d", ZSTR_VAL(EX(func)->op_array.filename), EX(func)->op_array.opcodes->lineno));
            zend_hash_str_add_new(meta, ZEND_STRL("closure.declaration"), &location);
        } else if (EX(func) && EX(func)->common.function_name) {
            zval_ptr_dtor(prop_name);

            zend_class_entry *called_scope = EX(func)->common.scope ? zend_get_called_scope(execute_data) : NULL;
            if (called_scope) {
                // This cannot be cached on the dispatch since subclasses can share the same parent dispatch
                ZVAL_STR(prop_name, strpprintf(0, "%s.%s", ZSTR_VAL(called_scope->name), ZSTR_VAL(EX(func)->common.function_name)));
            } else {
                ZVAL_STR_COPY(prop_name, EX(func)->common.function_name);
            }
        } else if (EX(func) && ZEND_USER_CODE(EX(func)->type) && EX(func)->op_array.filename) {
            zval_ptr_dtor(prop_name);
            ZVAL_STR_COPY(prop_name, EX(func)->op_array.filename);
        }

        zval zv;
        Z_PTR(zv) = span;
        Z_TYPE_INFO(zv) = 3;
        zend_hash_index_add_new(&DDTRACE_G(traced_spans), index, &zv);
    }
    return span;
}

void ddtrace_clear_execute_data_span(zend_ulong index, bool keep) {
    zval *span_zv = zend_hash_index_find(&DDTRACE_G(traced_spans), index);
    ddtrace_span_data *span = Z_PTR_P(span_zv);
    if ((Z_TYPE_INFO_P(span_zv) -= 2) == 1 || !keep) {
        if (!ddtrace_span_is_dropped(span)) {
            if (keep) {
                ddtrace_close_span(span);
            } else {
                ddtrace_drop_span(span);
                span->duration = DDTRACE_SILENTLY_DROPPED_SPAN;
            }
        }
    }
    if (Z_TYPE_INFO_P(span_zv) == 1) {
        OBJ_RELEASE(&span->std);
        zend_hash_index_del(&DDTRACE_G(traced_spans), index);
    }
}

void ddtrace_switch_span_stack(ddtrace_span_stack *target_stack) {
    if (target_stack->active) {
        ddtrace_span_data *span = SPANDATA(target_stack->active);
        LOG(SPAN_TRACE, "Switching to different SpanStack: %d, top of stack: trace_id=%s, span_id=%" PRIu64, target_stack->std.handle, Z_STRVAL(span->root->property_trace_id), span->span_id);
    } else {
        LOG(SPAN_TRACE, "Switching to different SpanStack: %d", target_stack->std.handle);
    }

    GC_ADDREF(&target_stack->std);
    OBJ_RELEASE(&DDTRACE_G(active_stack)->std);
    DDTRACE_G(active_stack) = target_stack;
}

ddtrace_span_data *ddtrace_init_dummy_span(void) {
    ddtrace_span_data *span = ddtrace_init_span(DDTRACE_USER_SPAN, ddtrace_ce_span_data);
    span->std.handlers->get_constructor(&span->std);
    span->duration = DDTRACE_SILENTLY_DROPPED_SPAN;
    return span;
}

static ddtrace_span_stack *dd_alloc_span_stack(void) {
    zval fci_zv;
    object_init_ex(&fci_zv, ddtrace_ce_span_stack);
    ddtrace_span_stack *span_stack = (ddtrace_span_stack *)Z_OBJ(fci_zv);
    return span_stack;
}

ddtrace_span_stack *ddtrace_init_root_span_stack(void) {
    ddtrace_span_stack *span_stack = dd_alloc_span_stack();
    if (DDTRACE_G(active_stack)) {
        ZVAL_OBJ_COPY(&span_stack->property_parent, &DDTRACE_G(active_stack)->std);
    } else {
        ZVAL_NULL(&span_stack->property_parent);
        span_stack->parent_stack = NULL;
    }
    ZVAL_NULL(&span_stack->property_active);
    span_stack->root_stack = span_stack;
    span_stack->root_span = NULL;

    LOG(SPAN_TRACE, "Creating new root SpanStack: %d, parent_stack: %d", span_stack->std.handle, span_stack->parent_stack ? span_stack->parent_stack->std.handle : 0);

    return span_stack;
}

ddtrace_span_stack *ddtrace_init_span_stack(void) {
    ddtrace_span_stack *span_stack = dd_alloc_span_stack();
    ZVAL_OBJ_COPY(&span_stack->property_parent, &DDTRACE_G(active_stack)->std);
    ZVAL_COPY(&span_stack->property_active, &DDTRACE_G(active_stack)->property_active);
    span_stack->root_stack = DDTRACE_G(active_stack)->root_stack;
    span_stack->root_span = DDTRACE_G(active_stack)->root_span;

    LOG(SPAN_TRACE, "Creating new SpanStack: %d, parent_stack: %d", span_stack->std.handle, span_stack->parent_stack ? span_stack->parent_stack->std.handle : 0);

    return span_stack;
}

void ddtrace_push_root_span(void) {
    ddtrace_span_data *span = ddtrace_open_span(DDTRACE_AUTOROOT_SPAN);
    // We opened the span, but are not going to hold a reference to it directly - the stack will manage it.
    GC_DELREF(&span->std);
}

DDTRACE_PUBLIC zend_object *ddtrace_get_root_span()
{
    if (!DDTRACE_G(active_stack)) {
        return NULL;
    }

    ddtrace_root_span_data *rsd = DDTRACE_G(active_stack)->root_span;
    if (!rsd) {
        return NULL;
    }
    return &rsd->std;
}

bool ddtrace_span_alter_root_span_config(zval *old_value, zval *new_value, zend_string *new_str) {
    UNUSED(new_str);

    if (Z_TYPE_P(old_value) == Z_TYPE_P(new_value) || !DDTRACE_G(active_stack)) {
        return true;
    }

    if (Z_TYPE_P(old_value) == IS_FALSE) {
        if (DDTRACE_G(active_stack)->root_span == NULL) {
            ddtrace_push_root_span();
            return true;
        }
        return false;
    } else {
        ddtrace_root_span_data *root_span = DDTRACE_G(active_stack)->root_span;
        if (root_span == NULL) {
            return true;  // might be the case after serialization
        }
        if (DDTRACE_G(active_stack)->active == &root_span->props && DDTRACE_G(active_stack)->closed_ring == NULL) {
            ddtrace_span_stack *root_stack = root_span->stack->parent_stack;
            DDTRACE_G(active_stack)->root_span = NULL; // As a special case, always hard-drop a root span dropped due to a config change
            ddtrace_drop_span(&root_span->span);
            ddtrace_switch_span_stack(root_stack);
            return true;
        } else {
            return false;
        }
    }
}

void dd_trace_stop_span_time(ddtrace_span_data *span) {
    span->duration = zend_hrtime() - span->duration_start;
}

bool ddtrace_has_top_internal_span(ddtrace_span_data *end) {
    ddtrace_span_properties *pspan = end->stack->active;
    while (pspan) {
        if (pspan == &end->props) {
            return true;
        }
        if (SPANDATA(pspan)->type != DDTRACE_USER_SPAN) {
            return false;
        }

        pspan = pspan->parent;
    }
    return false;
}

void ddtrace_close_stack_userland_spans_until(ddtrace_span_data *until) {
    ddtrace_span_properties *pspan;
    while ((pspan = until->stack->active) && pspan->stack == until->stack && pspan != &until->props && SPANDATA(pspan)->type != DDTRACE_AUTOROOT_SPAN) {
        ddtrace_span_data *span = SPANDATA(pspan);
        if (span->type == DDTRACE_INTERNAL_SPAN) {
            LOG(ERROR, "Found internal span data while closing userland spans");
        }

        zend_string *name = ddtrace_convert_to_str(&span->property_name);
        LOG(WARN, "Found unfinished span while automatically closing spans with name '%s'", ZSTR_VAL(name));
        zend_string_release(name);

        if (get_DD_AUTOFINISH_SPANS()) {
            dd_trace_stop_span_time(span);
            ddtrace_close_span(span);
        } else {
            ddtrace_drop_span(span);
        }
    }
}

// may be called with NULL
int ddtrace_close_userland_spans_until(ddtrace_span_data *until) {
    if (until) {
        ddtrace_span_data *span = ddtrace_active_span();
        while (span && span != until && span->type != DDTRACE_INTERNAL_SPAN) {
            span = SPANDATA(span->parent);
        }
        if (span != until) {
            return -1;
        }
    }

    int closed_spans = 0;
    ddtrace_span_data *span;
    while ((span = ddtrace_active_span()) && span != until && span->type != DDTRACE_INTERNAL_SPAN) {
        dd_trace_stop_span_time(span);
        ddtrace_close_span(span);
        ++closed_spans;
    }

    return closed_spans;
}

static void dd_mark_closed_spans_flushable(ddtrace_span_stack *stack) {
    if (stack->closed_ring) {
        // Note: here is the reason why the closed spans need to be a ring instead of having a NULL sentinel in ->next:
        // It's impossible to fuse two simple linked lists without walking one to its end and updating its last ->next pointer.
        // Having a circular structure just allows to splice the one into the other at an arbitrary location
        if (stack->closed_ring_flush) {
            ddtrace_span_data *next = stack->closed_ring->next;
            stack->closed_ring->next = stack->closed_ring_flush->next;
            stack->closed_ring_flush->next = next;
        } else {
            stack->closed_ring_flush = stack->closed_ring;

            // As long as there's something to flush, we must hold a reference (to avoid cycle collection)
            GC_ADDREF(&stack->std);

            if (stack->root_span && (stack->root_span->stack == stack || stack->root_span->type == DDTRACE_SPAN_CLOSED)) {
                stack->next = DDTRACE_G(top_closed_stack);
                DDTRACE_G(top_closed_stack) = stack;
            } else {
                // we'll just attach it so that it'll be flushed together (i.e. chunks are not flushed _before_ the root stack)
                stack->next = stack->root_stack->top_closed_stack;
                stack->root_stack->top_closed_stack = stack;
            }
        }
        stack->closed_ring = NULL;
    }
}

// closing a chunks last span:
// check if any parent has open spans
// if not, autoflush / add to closed stacks chain
// if yes, do nothing
static void dd_close_entry_span_of_stack(ddtrace_span_stack *stack) {
    // We need to track complete finished span stacks separately in order to mark them flushable
    dd_mark_closed_spans_flushable(stack);

    if (!stack->root_span || stack->root_span->stack == stack) {
        // Ensure the root span is cleared before allocations may happen in priority sampling deciding
        ddtrace_root_span_data *root_span = stack->root_span;
        if (stack->root_span) {
            // Root span stacks are automatic and tied to the lifetime of that root
            stack->root_span = NULL;

            // Enforce a sampling decision here
            ddtrace_fetch_priority_sampling_from_span(root_span);
        }
        if (stack == stack->root_stack && DDTRACE_G(active_stack) == stack) {
            // We are always active stack except if ddtrace_close_top_span_without_stack_swap is used
            ddtrace_switch_span_stack(stack->parent_stack);
        }

        if (get_DD_TRACE_AUTO_FLUSH_ENABLED() && ddtrace_flush_tracer(false, get_DD_TRACE_FLUSH_COLLECT_CYCLES()) == FAILURE) {
            // In case we have root spans enabled, we need to always flush if we close that one (RSHUTDOWN)
            LOG(WARN, "Unable to auto flush the tracer");
        }
    }
}

void ddtrace_close_span(ddtrace_span_data *span) {
    if (span == NULL || !ddtrace_has_top_internal_span(span) || span->type == DDTRACE_SPAN_CLOSED) {
        return;
    }

    // Closing a span (esp. when leaving a traced function) autoswitches the stacks if necessary
    if (span->stack != DDTRACE_G(active_stack)) {
        ddtrace_switch_span_stack(span->stack);
    }

    // Telemetry: increment the spans_created counter
    // Must be done at closing because we need to read the "component" span's meta which is not available at creation
    ddtrace_telemetry_inc_spans_created(span);

    ddtrace_close_stack_userland_spans_until(span);

    ddtrace_close_top_span_without_stack_swap(span);
}

void ddtrace_close_span_restore_stack(ddtrace_span_data *span) {
    assert(span != NULL);
    if (span->type == DDTRACE_SPAN_CLOSED) {
        return;
    }

    // switches to the stack of the passed span, closes the span and switches back to the original stack
    ddtrace_span_stack *active_stack_before = DDTRACE_G(active_stack);
    assert(active_stack_before != NULL);
    GC_ADDREF(&active_stack_before->std);

    ddtrace_close_span(span);

    ddtrace_switch_span_stack(active_stack_before);
    GC_DELREF(&active_stack_before->std);
}

void ddtrace_close_top_span_without_stack_swap(ddtrace_span_data *span) {
    ddtrace_span_stack *stack = span->stack;

    span->type = DDTRACE_SPAN_CLOSED;

    stack->active = span->parent;
    // The top span is always referenced by the span stack
    if (stack->active) {
        GC_ADDREF(&stack->active->std);
    } else {
        ZVAL_NULL(&stack->property_active);
    }
#if PHP_VERSION_ID < 70400
    // On PHP 7.3 and prior PHP will just destroy all unchanged references in cycle collection, in particular given that it does not appear in get_gc
    // Artificially increase refcount here thus.
    GC_SET_REFCOUNT(&span->std, GC_REFCOUNT(&span->std) + DD_RC_CLOSED_MARKER);
#endif

    ++DDTRACE_G(closed_spans_count);
    --DDTRACE_G(open_spans_count);

    // Move the reference ("top span") to the closed list
    if (stack->closed_ring) {
        span->next = stack->closed_ring->next;
        stack->closed_ring->next = span;
    } else {
        span->next = span;
        stack->closed_ring = span;
    }

    ddtrace_decide_on_closed_span_sampling(span);
    if (span->notify_user_req_end) {
        ddtrace_user_req_notify_finish(span);
        span->notify_user_req_end = false;
    }

    if (span->std.ce == ddtrace_ce_root_span_data) {
        ddtrace_root_span_data *root = ROOTSPANDATA(&span->std);
        LOG(SPAN_TRACE, "Closing root span: trace_id=%s, span_id=%" PRIu64, Z_STRVAL(root->property_trace_id), span->span_id);
    } else {
        LOG(SPAN_TRACE, "Closing span: trace_id=%s, span_id=%" PRIu64, Z_STRVAL(span->root->property_trace_id), span->span_id);
    }

    if (!stack->active || SPANDATA(stack->active)->stack != stack) {
        dd_close_entry_span_of_stack(stack);
    }
}

// i.e. what DDTrace\active_span() reports. DDTrace\active_stack()->active is the active span which will be used as parent for new spans on that stack
ddtrace_span_data *ddtrace_active_span(void) {
    ddtrace_span_stack *stack = DDTRACE_G(active_stack);
    if (!stack) {
        return NULL;
    }

    ddtrace_span_stack *end = stack->root_stack->parent_stack;

    do {
        if (stack->active && SPANDATA(stack->active)->stack == stack) {
            return SPANDATA(stack->active);
        }
        stack = stack->parent_stack;
    } while (stack != end);

    return NULL;
}

void ddtrace_close_all_open_spans(bool force_close_root_span) {
    zend_objects_store *objects = &EG(objects_store);
    zend_object **end = objects->object_buckets + 1;
    zend_object **obj_ptr = objects->object_buckets + objects->top;

    do {
        obj_ptr--;
        zend_object *obj = *obj_ptr;
        if (IS_OBJ_VALID(obj) && obj->ce == ddtrace_ce_span_stack) {
            ddtrace_span_stack *stack = (ddtrace_span_stack *)obj;

            // temporarily addref to avoid freeing the stack during it being processed
            GC_ADDREF(&stack->std);

            ddtrace_span_data *span;
            while (stack->active && (span = SPANDATA(stack->active))->stack == stack) {
                LOG(SPAN_TRACE, "Automatically finishing the next span (in shutdown or force flush requested)");
                if (get_DD_AUTOFINISH_SPANS() || (force_close_root_span && span->type == DDTRACE_AUTOROOT_SPAN)) {
                    dd_trace_stop_span_time(span);
                    ddtrace_close_span(span);
                } else {
                    ddtrace_drop_span(span);
                }
            }

            OBJ_RELEASE(&stack->std);
        }
    } while (obj_ptr != end);
}

void ddtrace_mark_all_span_stacks_flushable(void) {
    zend_objects_store *objects = &EG(objects_store);
    zend_object **end = objects->object_buckets + 1;
    zend_object **obj_ptr = objects->object_buckets + objects->top;

    do {
        obj_ptr--;
        zend_object *obj = *obj_ptr;
        if (IS_OBJ_VALID(obj) && obj->ce == ddtrace_ce_span_stack) {
            dd_mark_closed_spans_flushable((ddtrace_span_stack *)obj);
        }
    } while (obj_ptr != end);
}

void ddtrace_drop_span(ddtrace_span_data *span) {
    ddtrace_span_stack *stack = span->stack;

    // Closing/Dropping a span (esp. when leaving a traced function) autoswitches the stacks if necessary
    if (stack != DDTRACE_G(active_stack)) {
        ddtrace_switch_span_stack(span->stack);
    }

    // As a special case dropping a root span rejects it to avoid traces without root span
    // It's safe to just drop RC=2 root spans, they're referenced nowhere else
    if (&stack->root_span->span == span && GC_REFCOUNT(&span->std) > 2) {
        ddtrace_set_priority_sampling_on_root(PRIORITY_SAMPLING_USER_REJECT, DD_MECHANISM_MANUAL);
        dd_trace_stop_span_time(span);
        ddtrace_close_span(span);
        return;
    }

    stack->active = span->parent;
    // The top span is always referenced by the span stack
    if (stack->active) {
        GC_ADDREF(&stack->active->std);
    } else {
        ZVAL_NULL(&stack->property_active);
    }

    ++DDTRACE_G(dropped_spans_count);
    --DDTRACE_G(open_spans_count);

    if (&stack->root_span->span == span) {
        ddtrace_switch_span_stack(stack->parent_stack);
        stack->root_span = NULL;
    } else if (!stack->active || SPANDATA(stack->active)->stack != stack) {
        dd_close_entry_span_of_stack(stack);
    }

    dd_drop_span(span, false);
}

void ddtrace_serialize_closed_spans(zval *serialized) {
    if (DDTRACE_G(top_closed_stack)) {
        memset(&DDTRACE_G(exception_debugger_buffer), 0, sizeof(DDTRACE_G(exception_debugger_buffer)));

        ddtrace_span_stack *rootstack = DDTRACE_G(top_closed_stack);
        DDTRACE_G(top_closed_stack) = NULL;
        do {
            ddtrace_span_stack *stack = rootstack;
            rootstack = rootstack->next;
            ddtrace_span_stack *next_stack = stack->top_closed_stack;
            stack->top_closed_stack = NULL;
            do {
                // Note this ->next: We always splice in new spans at next, so start at next to mostly preserve order
                ddtrace_span_data *span = stack->closed_ring_flush->next, *end = span;
                stack->closed_ring_flush = NULL;
                do {
                    ddtrace_span_data *tmp = span;
                    span = tmp->next;
                    ddtrace_serialize_span_to_array(tmp, serialized);
#if PHP_VERSION_ID < 70400
                    // remove the artificially increased RC while closing again
                    GC_SET_REFCOUNT(&tmp->std, GC_REFCOUNT(&tmp->std) - DD_RC_CLOSED_MARKER);
#endif
                    OBJ_RELEASE(&tmp->std);
                } while (span != end);
                // We hold a reference to stacks with flushable spans
                OBJ_RELEASE(&stack->std);
                // Note: if a stack gets a fresh closed_ring_flush (e.g. due to gc during serialization), the root span will have been closed by now.
                // Thus it's appended to top_closed_stack and we do not need to recheck closed_ring_flush here.

                stack = next_stack;
                if (stack) {
                    next_stack = stack->next;
                }
            } while (stack);
        } while (rootstack);

        if (ddtrace_exception_debugging_is_active()) {
            ddtrace_sidecar_send_debugger_data(DDTRACE_G(exception_debugger_buffer));
            if (DDTRACE_G(exception_debugger_arena)) {
                zend_arena_destroy(DDTRACE_G(exception_debugger_arena));
                DDTRACE_G(exception_debugger_arena) = NULL;
            }
        }
    }

    // Reset closed span counter for limit-refresh, don't touch open spans
    DDTRACE_G(closed_spans_count) = 0;
    DDTRACE_G(dropped_spans_count) = 0;
}

void ddtrace_serialize_closed_spans_with_cycle(zval *serialized) {
    // We need to loop here, as closing the last span root stack could add other spans here
    while (DDTRACE_G(top_closed_stack)) {
        ddtrace_serialize_closed_spans(serialized);
        // Also flush possible cycles here
        gc_collect_cycles();
    }
}

zend_string *ddtrace_span_id_as_string(uint64_t id) { return zend_strpprintf(0, "%" PRIu64, id); }

zend_string *ddtrace_trace_id_as_string(ddtrace_trace_id id) {
    uint8_t reverse[DD_TRACE_MAX_ID_LEN];
    int len = ddtrace_conv10_trace_id(id, reverse);
    zend_string *str = zend_string_alloc(len, 0);
    for (int i = 0; i <= len; ++i) {
        ZSTR_VAL(str)[i] = reverse[len - i];
    }
    return str;
}

zend_string *ddtrace_span_id_as_hex_string(uint64_t id) {
    zend_string *str = zend_string_alloc(16, 0);
    snprintf(ZSTR_VAL(str), 17, "%016" PRIx64, id);
    return str;
}

zend_string *ddtrace_trace_id_as_hex_string(ddtrace_trace_id id) {
    zend_string *str = zend_string_alloc(32, 0);
    snprintf(ZSTR_VAL(str), 33, "%016" PRIx64 "%016" PRIx64, id.high, id.low);
    return str;
}
