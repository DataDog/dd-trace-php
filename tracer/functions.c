#include "ddtrace.h"
#include "configuration.h"
#include "span.h"
#include <Zend/zend_exceptions.h>
#include <exceptions/exceptions.h>
#include "auto_flush.h"
#include "code_origins.h"
#include "engine_hooks.h"
#include "ffe.h"
#include "handlers_exception.h"
#include "memory_limit.h"
#include "random.h"
#include "serializer.h"
#include "weak_resources.h"
#include <ext/startup_logging.h>
#include "handlers_http.h"
#include "ip_extraction.h"
#include "otel_context.h"
#include "tracer_telemetry.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"
#include <ext/agent_info.h>
#include <ext/ffi_utils.h>
#include <ext/process_tags.h>
#include <ext/remote_config.h>
#include <ext/sidecar.h>
#include <ext/telemetry.h>
#include <ext/standard/php_string.h>
#include <ext/json/php_json.h>
#include <json/json.h>
#ifndef _WIN32
#include "comms_php.h"
#include "coms.h"
#endif
#include <ext/zend_hrtime.h>

#define DDTRACE_FFE_TYPE_STRING 0
#define DDTRACE_FFE_TYPE_INT 1
#define DDTRACE_FFE_TYPE_FLOAT 2
#define DDTRACE_FFE_TYPE_BOOL 3
#define DDTRACE_FFE_TYPE_OBJECT 4

// On PHP 7 we cannot declare arrays as internal values. Assign null and handle in create_object where necessary.
#if PHP_VERSION_ID < 80000
#pragma push_macro("ZVAL_EMPTY_ARRAY")
#undef ZVAL_EMPTY_ARRAY
#define ZVAL_EMPTY_ARRAY ZVAL_NULL
#endif
// CG(empty_string) is not accessible during MINIT (in ZTS at least)
#if PHP_VERSION_ID < 70200
#pragma push_macro("ZVAL_EMPTY_STRING")
#undef ZVAL_EMPTY_STRING
#define ZVAL_EMPTY_STRING(z) ZVAL_NEW_STR(z, zend_string_init("", 0, 1))
#endif
#include "ddtrace_arginfo.h"
#if PHP_VERSION_ID < 70200
#pragma pop_macro("ZVAL_EMPTY_STRING")
#endif
#if PHP_VERSION_ID < 80000
#pragma pop_macro("ZVAL_EMPTY_ARRAY")
#endif

// For manual ZPP
#if PHP_VERSION_ID < 70400
#define _error_code error_code
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

static void dd_span_event_construct(ddtrace_span_event *event, zend_string *name, zend_long timestamp, zval *attributes)
{
    zval garbage_name, garbage_timestamp, garbage_attributes;

    // Copy current values to temporary zval variables
    ZVAL_COPY_VALUE(&garbage_name, &event->property_name);
    ZVAL_COPY_VALUE(&garbage_timestamp, &event->property_timestamp);
    ZVAL_COPY_VALUE(&garbage_attributes, &event->property_attributes);

    ZVAL_STR_COPY(&event->property_name, name);

    // Use the provided timestamp or the current time in nanoseconds
    if (timestamp == 0) {
        struct timespec ts;
        timespec_get(&ts, TIME_UTC);
        timestamp = ts.tv_sec * ZEND_NANO_IN_SEC + ts.tv_nsec;
    }
    ZVAL_LONG(&event->property_timestamp, timestamp);

    // Initialize attributes
    if (attributes) {
        ZVAL_COPY(&event->property_attributes, attributes);
    } else {
        array_init(&event->property_attributes);
    }

    // Free the copied values after replacement
    zval_ptr_dtor(&garbage_name);
    zval_ptr_dtor(&garbage_timestamp);
    zval_ptr_dtor(&garbage_attributes);
}

/* DDTrace\SpanEvent */
zend_class_entry *ddtrace_ce_span_event;

PHP_METHOD(DDTrace_SpanEvent, jsonSerialize) {
    ddtrace_span_event *event = (ddtrace_span_event*)Z_OBJ_P(ZEND_THIS);

    zval array;
    array_init(&array);

    Z_TRY_ADDREF(event->property_name);
    add_assoc_zval_ex(&array, ZEND_STRL("name"), &event->property_name);
    Z_TRY_ADDREF(event->property_timestamp);
    add_assoc_zval_ex(&array, ZEND_STRL("time_unix_nano"), &event->property_timestamp);

    // Handle attributes dynamically
    zval *attributes = &event->property_attributes;
    zval combined_attributes;
    array_init(&combined_attributes);

    if (instanceof_function(event->std.ce, ddtrace_ce_exception_span_event)) {
        // Handle exception attributes dynamically if an exception property exists
        ddtrace_exception_span_event *exception_event = (ddtrace_exception_span_event *) event;
        zval *exception = &exception_event->property_exception;
        if (Z_TYPE_P(exception) == IS_OBJECT && instanceof_function(Z_OBJCE_P(exception), zend_ce_throwable)) {
            // Get exception message, type, and stack trace directly
            zend_string *message = zai_exception_message(Z_OBJ_P(exception));
            if (ZSTR_LEN(message)) {
                add_assoc_str_ex(&combined_attributes, ZEND_STRL("exception.message"), zend_string_copy(message));
            }
            add_assoc_str_ex(&combined_attributes, ZEND_STRL("exception.type"), zend_string_copy(Z_OBJCE_P(exception)->name));

            // Get the exception stack trace using zai_get_trace_without_args_from_exception
            zend_string *stacktrace = zai_get_trace_without_args_from_exception(Z_OBJ_P(exception));
            add_assoc_str_ex(&combined_attributes, ZEND_STRL("exception.stacktrace"), stacktrace);
        }
    }

    if (Z_TYPE_P(attributes) == IS_ARRAY) {
        zend_hash_copy(Z_ARRVAL(combined_attributes), Z_ARRVAL_P(attributes), (copy_ctor_func_t)zval_add_ref);
    }

    if (zend_hash_num_elements(Z_ARRVAL(combined_attributes)) > 0) {
        add_assoc_zval_ex(&array, ZEND_STRL("attributes"), &combined_attributes);
    } else {
        zval_ptr_dtor(&combined_attributes); // Clean up if no elements
    }

    RETURN_ARR(Z_ARR(array)); // Return the array
}

PHP_METHOD(DDTrace_SpanEvent, __construct)
{
    UNUSED(return_value);

    zend_string *name;
    zval *attributes = NULL;
    zend_long timestamp = 0;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STR(name)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_EX(attributes, 1, 0)
        Z_PARAM_LONG(timestamp)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_span_event *event = (ddtrace_span_event*)Z_OBJ_P(ZEND_THIS);

    // Use the static function to set properties and handle cleanup
    dd_span_event_construct(event, name, timestamp, attributes);
}

/* DDTrace\ExceptionSpanEvent */
zend_class_entry *ddtrace_ce_exception_span_event;

PHP_METHOD(DDTrace_ExceptionSpanEvent, __construct)
{
    UNUSED(return_value);

    zval *exception;
    zval *attributes = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(exception, zend_ce_throwable)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_EX(attributes, 1, 0)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_exception_span_event *event = (ddtrace_exception_span_event*)Z_OBJ_P(ZEND_THIS);

    // Use the static function to set properties and handle cleanup
    zend_string *name = zend_string_init(ZEND_STRL("exception"), 0);
    dd_span_event_construct(&event->span_event, name, 0, attributes);
    zend_string_release(name);

    zval garbage;
    ZVAL_COPY_VALUE(&garbage, &event->property_exception);
    ZVAL_COPY(&event->property_exception, exception);
    zval_ptr_dtor(&garbage);
}

/* DDTrace\SpanLink */
zend_class_entry *ddtrace_ce_span_link;

PHP_METHOD(DDTrace_SpanLink, jsonSerialize) {
    ddtrace_span_link *link = (ddtrace_span_link *)Z_OBJ_P(ZEND_THIS);

    zend_array *array = zend_new_array(5);

    zend_string *trace_id = zend_string_init("trace_id", sizeof("trace_id") - 1, 0);
    zend_string *span_id = zend_string_init("span_id", sizeof("span_id") - 1, 0);
    zend_string *trace_state = zend_string_init("trace_state", sizeof("trace_state") - 1, 0);
    zend_string *attributes = zend_string_init("attributes", sizeof("attributes") - 1, 0);
    zend_string *dropped_attributes_count = zend_string_init("dropped_attributes_count", sizeof("dropped_attributes_count") - 1, 0);

    Z_TRY_ADDREF(link->property_trace_id);
    zend_hash_add(array, trace_id, &link->property_trace_id);
    Z_TRY_ADDREF(link->property_span_id);
    zend_hash_add(array, span_id, &link->property_span_id);
    Z_TRY_ADDREF(link->property_trace_state);
    zend_hash_add(array, trace_state, &link->property_trace_state);
    Z_TRY_ADDREF(link->property_attributes);
    zend_hash_add(array, attributes, &link->property_attributes);
    Z_TRY_ADDREF(link->property_dropped_attributes_count);
    zend_hash_add(array, dropped_attributes_count, &link->property_dropped_attributes_count);

    zend_string_release(trace_id);
    zend_string_release(span_id);
    zend_string_release(trace_state);
    zend_string_release(attributes);
    zend_string_release(dropped_attributes_count);

    RETURN_ARR(array);
}

static ddtrace_distributed_tracing_result dd_parse_distributed_tracing_headers_function(INTERNAL_FUNCTION_PARAMETERS, bool *success);
ZEND_METHOD(DDTrace_SpanLink, fromHeaders) {
    bool success;
    ddtrace_distributed_tracing_result result = dd_parse_distributed_tracing_headers_function(INTERNAL_FUNCTION_PARAM_PASSTHRU, &success);
    if (!success) {
        RETURN_NULL();
    }

    object_init_ex(return_value, ddtrace_ce_span_link);
    ddtrace_span_link *link = (ddtrace_span_link *)Z_OBJ_P(return_value);
    if (!get_DD_TRACE_ENABLED()) {
        return;
    }

    ZVAL_STR(&link->property_trace_id, datadog_trace_id_as_hex_string(result.trace_id));
    ZVAL_STR(&link->property_span_id, ddtrace_span_id_as_hex_string(result.parent_id));
    array_init(&link->property_attributes);
    zend_hash_copy(Z_ARR(link->property_attributes), &result.meta_tags, NULL);

    zend_string *propagated_tags = ddtrace_format_propagated_tags(&result.propagated_tags, &result.meta_tags);
    zend_string *full_tracestate = ddtrace_format_tracestate(result.tracestate, 0, result.origin, result.priority_sampling, propagated_tags, &result.tracestate_unknown_dd_keys);
    if (propagated_tags) {
        zend_string_release(propagated_tags);
    }
    if (full_tracestate) {
        ZVAL_STR(&link->property_trace_state, full_tracestate);
    }

    result.meta_tags.pDestructor = NULL; // we moved values directly
    zend_hash_destroy(&result.meta_tags);
    zend_hash_destroy(&result.propagated_tags);
    zend_hash_destroy(&result.tracestate_unknown_dd_keys);
    zend_hash_destroy(&result.baggage);

    if (result.origin) {
        zend_string_release(result.origin);
    }
    if (result.tracestate) {
        zend_string_release(result.tracestate);
    }
}

/* DDTrace\SpanData */
zend_class_entry *ddtrace_ce_span_data;
zend_class_entry *ddtrace_ce_inferred_span_data;
zend_class_entry *ddtrace_ce_root_span_data;
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100
HashTable dd_root_span_data_duplicated_properties_table;
#endif
zend_class_entry *ddtrace_ce_span_stack;
static zend_class_entry *ddtrace_ce_ffe_result;
zend_object_handlers ddtrace_span_data_handlers;
zend_object_handlers ddtrace_inferred_span_data_handlers;
zend_object_handlers ddtrace_root_span_data_handlers;
zend_object_handlers ddtrace_span_stack_handlers;

static zend_object *dd_init_span_data_object(zend_class_entry *class_type, ddtrace_span_data *span, zend_object_handlers *handlers) {
    zend_object_std_init(&span->std, class_type);
    span->std.handlers = handlers;
    object_properties_init(&span->std, class_type);
    ZVAL_NULL(&span->property_parent); // readonly prop cannot be initialized in stub
#if PHP_VERSION_ID < 80000
    // Not handled in arginfo on these old versions
    array_init(&span->property_meta);
    array_init(&span->property_metrics);
    array_init(&span->property_meta_struct);
    array_init(&span->property_links);
    array_init(&span->property_events);
    array_init(&span->property_peer_service_sources);
    array_init(&span->property_on_close);
#endif
    // Explicitly assign property-mapped NULLs
    span->stack = NULL;
    span->parent = NULL;
    return &span->std;
}

static zend_object *ddtrace_span_data_create(zend_class_entry *class_type) {
    ddtrace_span_data *span = ecalloc(1, sizeof(*span));
    dd_init_span_data_object(class_type, span, &ddtrace_span_data_handlers);
#if PHP_VERSION_ID < 80000
    // Not handled in arginfo on these old versions
    array_init(&span->property_baggage);
#endif

    return &span->std;
}

static zend_object *ddtrace_inferred_span_data_create(zend_class_entry *class_type) {
    ddtrace_inferred_span_data *span = ecalloc(1, sizeof(*span));
    return dd_init_span_data_object(class_type, &span->span, &ddtrace_inferred_span_data_handlers);
}

static zend_object *ddtrace_root_span_data_create(zend_class_entry *class_type) {
    ddtrace_root_span_data *span = ecalloc(1, sizeof(*span));
    dd_init_span_data_object(class_type, &span->span, &ddtrace_root_span_data_handlers);
#if PHP_VERSION_ID < 80000
    // Not handled in arginfo on these old versions
    array_init(&span->property_propagated_tags);
    array_init(&span->property_tracestate_tags);
    array_init(&span->property_baggage);
#endif
    return &span->std;
}

static zend_object *ddtrace_span_stack_create(zend_class_entry *class_type) {
    ddtrace_span_stack *stack = ecalloc(1, sizeof(*stack));
    zend_object_std_init(&stack->std, class_type);
    stack->root_stack = stack;
    stack->std.handlers = &ddtrace_span_stack_handlers;
    object_properties_init(&stack->std, class_type);
    ZVAL_NULL(&stack->property_parent); // readonly prop cannot be initialized in stub
    // Explicitly assign property-mapped NULLs
    stack->active = NULL;
    stack->parent_stack = NULL;
#if PHP_VERSION_ID < 80000
    // Not handled in arginfo on these old versions
    array_init(&stack->property_span_creation_observers);
#endif
    return &stack->std;
}

// Init with empty span stack if directly allocated via new()
static zend_function *ddtrace_span_data_get_constructor(zend_object *object) {
    object_init_ex(&OBJ_SPANDATA(object)->property_stack, ddtrace_ce_span_stack);
    return NULL;
}

static void ddtrace_span_stack_dtor_obj(zend_object *object) {
    // We must not invoke span stack destructors during zend_objects_store_call_destructors to avoid them not being present for appsec rshutdown
    if (EG(current_execute_data) == NULL && !DDTRACE_G(in_shutdown)) {
        GC_DEL_FLAGS(object, IS_OBJ_DESTRUCTOR_CALLED);
        return;
    }

    ddtrace_span_stack *stack = (ddtrace_span_stack *)object;
    ddtrace_span_data *top;
    while (stack->active && (top = SPANDATA(stack->active)) && top->stack == stack) {
        dd_trace_stop_span_time(top);
        // let's not stack swap to a) avoid side effects in destructors and b) avoid a crash on PHP 7.3 and older
        ddtrace_close_top_span_without_stack_swap(top);
    }
    if (stack->closed_ring || stack->closed_ring_flush) {
        // ensure dtor can be called again
        GC_DEL_FLAGS(object, IS_OBJ_DESTRUCTOR_CALLED);
    }
    zend_objects_destroy_object(object);
}

// span stacks have intrinsic properties (managing other spans, can be switched to), unlike trivial span data which are just value objects
// thus we need to cleanup a little what exactly we can copy
#if PHP_VERSION_ID < 80000
static zend_object *ddtrace_span_stack_clone_obj(zval *old_zv) {
    zend_object *old_obj = Z_OBJ_P(old_zv);
#else
static zend_object *ddtrace_span_stack_clone_obj(zend_object *old_obj) {
#endif
    zend_object *new_obj = ddtrace_span_stack_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    ddtrace_span_stack *stack = (ddtrace_span_stack *)new_obj;
    ddtrace_span_stack *oldstack = (ddtrace_span_stack *)old_obj;
    if (oldstack->parent_stack) { // if this is false, we're copying an initial stack
        stack->root_stack = stack->parent_stack->root_stack;
    }
    if (oldstack->root_stack == oldstack) {
        stack->root_stack = stack;
    }

    ddtrace_span_properties *pspan = stack->active;
    zval_ptr_dtor(&stack->property_active);
    while (pspan && pspan->stack == oldstack) {
        pspan = pspan->parent;
    }
    if (pspan) {
        ZVAL_OBJ_COPY(&stack->property_active, &pspan->std);
        stack->root_span = SPANDATA(pspan)->root;
    } else {
        stack->root_span = NULL;
        stack->active = NULL;
        ZVAL_NULL(&stack->property_active);
    }

    return new_obj;
}

static void ddtrace_span_data_free_storage(zend_object *object) {
#ifdef __linux__
    if (object->ce == ddtrace_ce_root_span_data) {
        ddtrace_detach_otel_thread_context_for_root(ROOTSPANDATA(object));
    }
#endif
    zend_object_std_dtor(object);
    // Prevent use after free after zend_objects_store_free_object_storage is called (e.g. preloading) [PHP < 8.1]
    memset(object->properties_table, 0, sizeof(ddtrace_span_data) - XtOffsetOf(ddtrace_span_data, std.properties_table));
}

#if PHP_VERSION_ID < 80000
static zend_object *ddtrace_span_data_clone_obj(zval *old_zv) {
    zend_object *old_obj = Z_OBJ_P(old_zv);
#else
static zend_object *ddtrace_span_data_clone_obj(zend_object *old_obj) {
#endif
    zend_object *new_obj = ddtrace_span_data_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    return new_obj;
}

#if PHP_VERSION_ID < 80000
static zend_object *ddtrace_inferred_span_data_clone_obj(zval *old_zv) {
    zend_object *old_obj = Z_OBJ_P(old_zv);
#else
static zend_object *ddtrace_inferred_span_data_clone_obj(zend_object *old_obj) {
#endif
    zend_object *new_obj = ddtrace_inferred_span_data_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    return new_obj;
}


#if PHP_VERSION_ID < 80000
static zend_object *ddtrace_root_span_data_clone_obj(zval *old_zv) {
    zend_object *old_obj = Z_OBJ_P(old_zv);
#else
static zend_object *ddtrace_root_span_data_clone_obj(zend_object *old_obj) {
#endif
    zend_object *new_obj = ddtrace_root_span_data_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    return new_obj;
}

#if PHP_VERSION_ID < 80000
#if PHP_VERSION_ID >= 70400
static zval *ddtrace_span_data_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#else
static void ddtrace_span_data_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#endif
    zend_object *obj = Z_OBJ_P(object);
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
#else
static zval *ddtrace_span_data_readonly(zend_object *object, zend_string *member, zval *value, void **cache_slot) {
    zend_object *obj = object;
    zend_string *prop_name = member;
#endif
    if (zend_string_equals_literal(prop_name, "parent")
     || zend_string_equals_literal(prop_name, "id")
     || zend_string_equals_literal(prop_name, "stack")) {
        zend_throw_error(zend_ce_error, "Cannot modify readonly property %s::$%s", ZSTR_VAL(obj->ce->name), ZSTR_VAL(prop_name));
#if PHP_VERSION_ID >= 70400
        return &EG(uninitialized_zval);
#else
        return;
#endif
    }

    ddtrace_span_data *span = OBJ_SPANDATA(obj);
    // As per unified service tagging spec if a span is created with a service name different from the global
    // service name it will not inherit the global version value, unless it has no ancestor traces.
    if (zend_string_equals_literal(prop_name, "service")) {
        cache_slot = NULL;
        bool is_top_level_root = span->std.ce == ddtrace_ce_root_span_data;
        if (is_top_level_root) {
            for (ddtrace_span_stack *s = span->stack->parent_stack; s != NULL; s = s->parent_stack) {
                if (s->active) {
                    is_top_level_root = false;
                    break;
                }
            }
        }
        if (ZSTR_LEN(get_DD_SERVICE()) || !is_top_level_root) {
            if (!zend_is_identical(&span->property_service, value)) {
                zval_ptr_dtor(&span->property_version);
                ZVAL_EMPTY_STRING(&span->property_version);
            }
        }
        if (Z_TYPE_P(value) == IS_STRING && !zend_is_identical(&span->property_service, value)) {
            zend_array *meta = ddtrace_property_array(&span->property_meta);
            zval val;
            ZVAL_NEW_STR(&val, zend_string_init("m", 1, 0));
            zend_hash_str_update(meta, ZEND_STRL("_dd.svc_src"), &val);
        }
    }

#if PHP_VERSION_ID >= 70400
    return zend_std_write_property(object, member, value, cache_slot);
#else
    zend_std_write_property(object, member, value, cache_slot);
#endif
}

#if PHP_VERSION_ID < 80000
#if PHP_VERSION_ID >= 70400
static zval *ddtrace_root_span_data_write(zval *object, zval *member, zval *value, void **cache_slot) {
#else
static void ddtrace_root_span_data_write(zval *object, zval *member, zval *value, void **cache_slot) {
#endif
    zend_object *obj = Z_OBJ_P(object);
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
#else
static zval *ddtrace_root_span_data_write(zend_object *object, zend_string *member, zval *value, void **cache_slot) {
    zend_object *obj = object;
    zend_string *prop_name = member;
#endif
    ddtrace_root_span_data *span = ROOTSPANDATA(obj);
    zval zv;
    bool root_span_data_changed = false;
    if (zend_string_equals_literal(prop_name, "parentId")) {
        if (Z_TYPE_P(value) == IS_LONG && Z_LVAL_P(value)) {
            span->parent_id = (uint64_t) Z_LVAL_P(value);
            ZVAL_STR(&zv, zend_strpprintf(0, "%" PRIu64, span->parent_id));
            Z_DELREF(zv); // zend_std_write_property will incref itself
            value = &zv;
        } else {
            span->parent_id = ddtrace_parse_userland_span_id(value);
            if (!span->parent_id) {
                ZVAL_EMPTY_STRING(&zv);
                Z_TRY_DELREF(zv);
                value = &zv;
            }
        }
        cache_slot = NULL;
    } else if (zend_string_equals_literal(prop_name, "traceId")) {
        span->trace_id = Z_TYPE_P(value) == IS_STRING ? ddtrace_parse_hex_trace_id(Z_STRVAL_P(value), Z_STRLEN_P(value)) : (datadog_trace_id){ 0 };
        if (!span->trace_id.low && !span->trace_id.high) {
            span->trace_id = (datadog_trace_id) {
                .low = span->span_id,
                .time = get_DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED() ? span->start / ZEND_NANO_IN_SEC : 0,
            };
            value = &span->property_id;
        }
        cache_slot = NULL;
    } else if (zend_string_equals_literal(prop_name, "service")) {
        if (ddtrace_span_is_entrypoint_root(&span->span) && !zend_is_identical(&span->property_service, value)) {
            root_span_data_changed = true;
        }
        cache_slot = NULL;
    } else if (zend_string_equals_literal(prop_name, "env")) {
        if (ddtrace_span_is_entrypoint_root(&span->span) && !zend_is_identical(&span->property_env, value)) {
            root_span_data_changed = true;
        }
        cache_slot = NULL;
    } else if (zend_string_equals_literal(prop_name, "version")) {
        if (ddtrace_span_is_entrypoint_root(&span->span) && !zend_is_identical(&span->property_version, value)) {
            root_span_data_changed = true;
        }
        cache_slot = NULL;
    } else if (zend_string_equals_literal(prop_name, "samplingPriority")) {
        span->explicit_sampling_priority = zval_get_long(value) != DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
        cache_slot = NULL;
    }

#if PHP_VERSION_ID >= 70400
    zval *ret = ddtrace_span_data_readonly(object, member, value, cache_slot);
#else
    ddtrace_span_data_readonly(object, member, value, cache_slot);
#endif
    if (root_span_data_changed) {
        ddtrace_sidecar_submit_root_span_data();
        ddtrace_update_otel_thread_context();
    }
#if PHP_VERSION_ID >= 70400
    return ret;
#endif
}

static bool ddtrace_span_stack_is_context_property(zend_string *prop_name) {
    return zend_string_equals_literal(prop_name, "active")
        || zend_string_equals_literal(prop_name, "parent");
}

#if PHP_VERSION_ID < 80000
static zval *ddtrace_span_stack_read_property(zval *object, zval *member, int type, void **cache_slot, zval *rv) {
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
    ddtrace_span_stack *stack = (ddtrace_span_stack *)Z_OBJ_P(object);
#else
static zval *ddtrace_span_stack_read_property(zend_object *object, zend_string *member, int type, void **cache_slot, zval *rv) {
    zend_string *prop_name = member;
    ddtrace_span_stack *stack = (ddtrace_span_stack *)object;
#endif
    if ((type == BP_VAR_W || type == BP_VAR_RW || type == BP_VAR_UNSET)
            && ddtrace_span_stack_is_context_property(prop_name)) {
        if (zend_string_equals_literal(prop_name, "active")) {
            ZVAL_COPY(rv, &stack->property_active);
        } else {
            ZVAL_COPY(rv, &stack->property_parent);
        }
        return rv;
    }
    return zend_std_read_property(object, member, type, cache_slot, rv);
}

#if PHP_VERSION_ID < 80000
static zval *ddtrace_span_stack_get_property_ptr_ptr(zval *object, zval *member, int type, void **cache_slot) {
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
#else
static zval *ddtrace_span_stack_get_property_ptr_ptr(zend_object *object, zend_string *member, int type, void **cache_slot) {
    zend_string *prop_name = member;
#endif
    if ((type == BP_VAR_W || type == BP_VAR_RW || type == BP_VAR_UNSET)
            && ddtrace_span_stack_is_context_property(prop_name)) {
        return NULL;  // prevent cache fill; read_property handles the copy
    }
    return zend_std_get_property_ptr_ptr(object, member, type, cache_slot);
}

#if PHP_VERSION_ID < 80000
static void ddtrace_span_stack_unset_property(zval *object, zval *member, void **cache_slot) {
    zend_object *obj = Z_OBJ_P(object);
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
#else
static void ddtrace_span_stack_unset_property(zend_object *object, zend_string *member, void **cache_slot) {
    zend_object *obj = object;
    zend_string *prop_name = member;
#endif
    if (ddtrace_span_stack_is_context_property(prop_name)) {
        zend_throw_error(zend_ce_error, "Cannot unset readonly property %s::$%s", ZSTR_VAL(obj->ce->name), ZSTR_VAL(prop_name));
        return;
    }

    zend_std_unset_property(object, member, cache_slot);
}

#if PHP_VERSION_ID < 80000
#if PHP_VERSION_ID >= 70400
static zval *ddtrace_span_stack_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#else
static void ddtrace_span_stack_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#endif
    zend_object *obj = Z_OBJ_P(object);
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
#else
static zval *ddtrace_span_stack_readonly(zend_object *object, zend_string *member, zval *value, void **cache_slot) {
    zend_object *obj = object;
    zend_string *prop_name = member;
#endif
    if (ddtrace_span_stack_is_context_property(prop_name)) {
        zend_throw_error(zend_ce_error, "Cannot modify readonly property %s::$%s", ZSTR_VAL(obj->ce->name), ZSTR_VAL(prop_name));
#if PHP_VERSION_ID >= 70400
        return &EG(uninitialized_zval);
#else
        return;
#endif
    }

#if PHP_VERSION_ID >= 70400
    return zend_std_write_property(object, member, value, cache_slot);
#else
    zend_std_write_property(object, member, value, cache_slot);
#endif
}

PHP_METHOD(DDTrace_SpanData, getDuration) {
    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(ZEND_THIS));
    RETURN_LONG(span->duration);
}

PHP_METHOD(DDTrace_SpanData, getStartTime) {
    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(ZEND_THIS));
    RETURN_LONG(span->start);
}

PHP_METHOD(DDTrace_SpanData, getLink) {
    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(ZEND_THIS));

    span->flags |= DDTRACE_SPAN_FLAG_NOT_DROPPABLE;

    zval fci_zv;
    object_init_ex(&fci_zv, ddtrace_ce_span_link);
    ddtrace_span_link *link = (ddtrace_span_link *)Z_OBJ_P(&fci_zv);

    ZVAL_STR(&link->property_trace_id, datadog_trace_id_as_hex_string(span->root ? span->root->trace_id : (datadog_trace_id){ .low = span->span_id, .high = 0 }));
    ZVAL_STR(&link->property_span_id, ddtrace_span_id_as_hex_string(span->span_id));

    RETURN_OBJ(Z_OBJ(fci_zv));
}

PHP_METHOD(DDTrace_SpanData, hexId) {
    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(ZEND_THIS));
    RETURN_STR(ddtrace_span_id_as_hex_string(span->span_id));
}

static void dd_register_span_data_ce(void) {
    ddtrace_ce_span_data = register_class_DDTrace_SpanData();
    ddtrace_ce_span_data->create_object = ddtrace_span_data_create;

    memcpy(&ddtrace_span_data_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    ddtrace_span_data_handlers.offset = XtOffsetOf(ddtrace_span_data, std);
    ddtrace_span_data_handlers.clone_obj = ddtrace_span_data_clone_obj;
    ddtrace_span_data_handlers.free_obj = ddtrace_span_data_free_storage;
    ddtrace_span_data_handlers.write_property = ddtrace_span_data_readonly;
    ddtrace_span_data_handlers.get_constructor = ddtrace_span_data_get_constructor;

    ddtrace_ce_inferred_span_data = register_class_DDTrace_InferredSpanData(ddtrace_ce_span_data);
    ddtrace_ce_inferred_span_data->create_object = ddtrace_inferred_span_data_create;

    memcpy(&ddtrace_inferred_span_data_handlers, &ddtrace_span_data_handlers, sizeof(zend_object_handlers));
    ddtrace_inferred_span_data_handlers.offset = XtOffsetOf(ddtrace_inferred_span_data, std);
    ddtrace_inferred_span_data_handlers.clone_obj = ddtrace_inferred_span_data_clone_obj;


    ddtrace_ce_root_span_data = register_class_DDTrace_RootSpanData(ddtrace_ce_span_data);
    ddtrace_ce_root_span_data->create_object = ddtrace_root_span_data_create;

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100
    // Work around wrong reference source for typed internal properties by preventing duplication of them
    // php -d extension=zend_test -r '$c = new _ZendTestChildClass; $i = &$c->intProp;'
    // php: /usr/local/src/php/Zend/zend_execute.c:3390: zend_ref_del_type_source: Assertion `source_list->ptr == prop' failed.
    zend_hash_init(&dd_root_span_data_duplicated_properties_table, zend_hash_num_elements(&ddtrace_ce_span_data->properties_info), NULL, NULL, true);
    for (uint32_t i = 0; i < zend_hash_num_elements(&ddtrace_ce_span_data->properties_info); ++i) {
        Bucket *bucket = &ddtrace_ce_root_span_data->properties_info.arData[i];
        zend_hash_add_ptr(&dd_root_span_data_duplicated_properties_table, bucket->key, Z_PTR(bucket->val));
        Z_PTR(bucket->val) = ddtrace_ce_root_span_data->properties_info_table[i] = Z_PTR(ddtrace_ce_span_data->properties_info.arData[i].val);
    }
#endif

    memcpy(&ddtrace_root_span_data_handlers, &ddtrace_span_data_handlers, sizeof(zend_object_handlers));
    ddtrace_root_span_data_handlers.offset = XtOffsetOf(ddtrace_root_span_data, std);
    ddtrace_root_span_data_handlers.clone_obj = ddtrace_root_span_data_clone_obj;
    ddtrace_root_span_data_handlers.write_property = ddtrace_root_span_data_write;

    ddtrace_ce_span_stack = register_class_DDTrace_SpanStack();
    ddtrace_ce_span_stack->create_object = ddtrace_span_stack_create;

    memcpy(&ddtrace_span_stack_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    ddtrace_span_stack_handlers.clone_obj = ddtrace_span_stack_clone_obj;
    ddtrace_span_stack_handlers.dtor_obj = ddtrace_span_stack_dtor_obj;
    ddtrace_span_stack_handlers.read_property = ddtrace_span_stack_read_property;
    ddtrace_span_stack_handlers.get_property_ptr_ptr = ddtrace_span_stack_get_property_ptr_ptr;
    ddtrace_span_stack_handlers.unset_property = ddtrace_span_stack_unset_property;
    ddtrace_span_stack_handlers.write_property = ddtrace_span_stack_readonly;

}

/* DDTrace\FatalError */
zend_class_entry *ddtrace_ce_fatal_error;

static void dd_register_fatal_error_ce(void) {
    zend_class_entry ce;
    INIT_NS_CLASS_ENTRY(ce, "DDTrace", "FatalError", NULL);
    ddtrace_ce_fatal_error = zend_register_internal_class_ex(&ce, zend_ce_exception);
}

zend_class_entry *ddtrace_ce_integration;
zend_class_entry *ddtrace_ce_git_metadata;
zend_object_handlers datadog_git_metadata_handlers;

static zend_object *datadog_git_metadata_create(zend_class_entry *class_type) {
    zend_object *object = zend_objects_new(class_type);
    object_properties_init(object, class_type);
    object->handlers = &datadog_git_metadata_handlers;
    return object;
}

static void ddtrace_free_obj_wrapper(zend_object *object) {
    zend_object_std_dtor(object);
}

void ddtrace_register_functions_and_classes(int module_number) {
    register_ddtrace_symbols(module_number);

    dd_register_span_data_ce();
    dd_register_fatal_error_ce();
    ddtrace_ce_integration = register_class_DDTrace_Integration();
    ddtrace_ce_ffe_result = register_class_DDTrace_FfeResult();
    ddtrace_ce_span_link = register_class_DDTrace_SpanLink(php_json_serializable_ce);
    ddtrace_ce_span_event = register_class_DDTrace_SpanEvent(php_json_serializable_ce);
    ddtrace_ce_exception_span_event = register_class_DDTrace_ExceptionSpanEvent(ddtrace_ce_span_event);

    ddtrace_ce_git_metadata = register_class_DDTrace_GitMetadata();
    ddtrace_ce_git_metadata->create_object = datadog_git_metadata_create;
    memcpy(&datadog_git_metadata_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    // We need a free_obj wrapper as zend_objects_store_free_object_storage will skip freeing of classes with the default free_obj handler when fast_shutdown is active. This will mess with our refcount and leak cached git metadata.
    datadog_git_metadata_handlers.free_obj = ddtrace_free_obj_wrapper;

    zend_register_functions(NULL, ext_functions, NULL, datadog_module_entry.type);
}

void ddtrace_unregister_functions_and_classes() {
#if PHP_VERSION_ID < 80300
    zend_unregister_functions(ext_functions, sizeof(ext_functions) / sizeof(zend_function_entry) - 1, NULL);
#endif

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100
    // See dd_register_span_data_ce for explanation
    zend_string *key;
    void *prop_info;
    ZEND_HASH_FOREACH_STR_KEY_PTR(&dd_root_span_data_duplicated_properties_table, key, prop_info) {
        ZVAL_PTR(zend_hash_find(&ddtrace_ce_root_span_data->properties_info, key), prop_info); // no update to avoid dtor
    } ZEND_HASH_FOREACH_END();
#endif
}

/* {{{ proto string DDTrace\add_global_tag(string $key, string $value) */
PHP_FUNCTION(DDTrace_add_global_tag) {
    UNUSED(execute_data);

    zend_string *key, *val;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SS", &key, &val) == FAILURE) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    zval value_zv;
    ZVAL_STR_COPY(&value_zv, val);
    zend_hash_update(DDTRACE_G(additional_global_tags), key, &value_zv);

    RETURN_NULL();
}

/* {{{ proto string DDTrace\add_distributed_tag(string $key, string $value) */
PHP_FUNCTION(DDTrace_add_distributed_tag) {
    UNUSED(execute_data);

    zend_string *key, *val;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SS", &key, &val) == FAILURE) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    zend_string *prefixed_key = zend_strpprintf(0, "_dd.p.%s", ZSTR_VAL(key));

    zend_array *target_table, *propagated;
    if (DDTRACE_G(active_stack)->root_span) {
        target_table = ddtrace_property_array(&DDTRACE_G(active_stack)->root_span->property_meta);
        propagated = ddtrace_property_array(&DDTRACE_G(active_stack)->root_span->property_propagated_tags);
    } else {
        target_table = &DDTRACE_G(root_span_tags_preset);
        propagated = &DDTRACE_G(propagated_root_span_tags);
    }

    zval value_zv;
    ZVAL_STR_COPY(&value_zv, val);
    zend_hash_update(target_table, prefixed_key, &value_zv);

    zend_hash_add_empty_element(propagated, prefixed_key);

    zend_string_release(prefixed_key);

    RETURN_NULL();
}

static void _ddtrace_set_user(zend_string *user_id, zend_array *metadata, zend_bool propagate) {
    zend_array *target_table, *propagated;
    if (DDTRACE_G(active_stack)->root_span) {
        target_table = ddtrace_property_array(&DDTRACE_G(active_stack)->root_span->property_meta);
        propagated = ddtrace_property_array(&DDTRACE_G(active_stack)->root_span->property_propagated_tags);
    } else {
        target_table = &DDTRACE_G(root_span_tags_preset);
        propagated = &DDTRACE_G(propagated_root_span_tags);
    }

    zval user_id_zv;
    ZVAL_STR_COPY(&user_id_zv, user_id);
    zend_hash_str_update(target_table, ZEND_STRL("usr.id"), &user_id_zv);

    if (propagate) {
        zval value_zv;
        zend_string *encoded_user_id = php_base64_encode_str(user_id);
        ZVAL_STR(&value_zv, encoded_user_id);
        zend_hash_str_update(target_table, ZEND_STRL("_dd.p.usr.id"), &value_zv);

        zend_hash_str_add_empty_element(propagated, ZEND_STRL("_dd.p.usr.id"));
    }

    if (metadata != NULL) {
        zend_string *key;
        zval *value;
        ZEND_HASH_FOREACH_STR_KEY_VAL(metadata, key, value)
        {
            if (!key || Z_TYPE_P(value) != IS_STRING) {
                continue;
            }

            zend_string *prefixed_key = zend_strpprintf(0, "usr.%s", ZSTR_VAL(key));

            zval value_copy;
            ZVAL_COPY(&value_copy, value);
            zend_hash_update(target_table, prefixed_key, &value_copy);

            zend_string_release(prefixed_key);
        }
        ZEND_HASH_FOREACH_END();
    }
}

PHP_FUNCTION(DDTrace_set_user) {
    UNUSED(execute_data);

    zend_string *user_id;
    HashTable *metadata = NULL;
    zend_bool propagate = get_DD_TRACE_PROPAGATE_USER_ID_DEFAULT();
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S|hb", &user_id, &metadata, &propagate) == FAILURE) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    if (user_id == NULL || ZSTR_LEN(user_id) == 0) {
        LOG_LINE(WARN, "Unexpected empty user id in DDTrace\\set_user");
        RETURN_NULL();
    }

    _ddtrace_set_user(user_id, metadata, propagate);

    RETURN_NULL();
}

PHP_FUNCTION(datadog_appsec_v2_track_user_login_success) {
    UNUSED(execute_data);

    zend_string *login;
    zval *user = NULL;
    zend_array *metadata = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S|zh", &login, &user, &metadata) == FAILURE) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    zend_array *target_table;
    if (DDTRACE_G(active_stack)->root_span) {
        target_table = ddtrace_property_array(&DDTRACE_G(active_stack)->root_span->property_meta);
    } else {
        target_table = &DDTRACE_G(root_span_tags_preset);
    }

    if (ZSTR_LEN(login) == 0) {
        LOG_LINE(WARN, "Unexpected empty login in datadog\\appsec\\v2\\track_user_login_success");
        RETURN_NULL();
    }

#define DDTRACE_ATO_V2_EVENT_USERS_LOGIN_SUCCESS "appsec.events.users.login.success"
    zend_string *user_id = NULL;
    if (user != NULL && Z_TYPE_P(user) == IS_STRING) {
        user_id = Z_STR_P(user);
    } else if (user != NULL && Z_TYPE_P(user) == IS_ARRAY) {
        // This is required to avoid writting to metadata if no id
        zval *user_id_zv = zend_hash_str_find(Z_ARR_P(user), ZEND_STRL("id"));
        if (user_id_zv != NULL && Z_TYPE_P(user_id_zv) == IS_STRING) {
            user_id = Z_STR_P(user_id_zv);
        }
        zend_string *user_key;
        zval *user_value;
        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARR_P(user), user_key, user_value) {
            if (!user_key || Z_TYPE_P(user_value) != IS_STRING) {
                continue;
            }

            zend_string *key = zend_strpprintf(0, "%s.usr.%s", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_SUCCESS, ZSTR_VAL(user_key));
            zval value_copy;
            ZVAL_COPY(&value_copy, user_value);
            zend_hash_update(target_table, key, &value_copy);

            zend_string_release(key);
        }
        ZEND_HASH_FOREACH_END();
    }

    // appsec.events.users.login.success.usr.login
    zend_string *prefixed_key = zend_strpprintf(0, "%s.%s", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_SUCCESS, "usr.login");
    zval value_zv;
    ZVAL_STR_COPY(&value_zv, login);
    zend_hash_update(target_table, prefixed_key, &value_zv);
    zend_string_release(prefixed_key);

    // appsec.events.users.login.success.track
    prefixed_key = zend_strpprintf(0, "%s.track", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_SUCCESS);
    zval true_value_zv;
    ZVAL_STRING(&true_value_zv, "true");
    zend_hash_update(target_table, prefixed_key, &true_value_zv);
    zend_string_release(prefixed_key);

    //_dd.appsec.events.users.login.success.sdk
    prefixed_key = zend_strpprintf(0, "_dd.%s.sdk", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_SUCCESS);
    Z_TRY_ADDREF_P(&true_value_zv);
    zend_hash_update(target_table, prefixed_key, &true_value_zv);
    zend_string_release(prefixed_key);

    if (user_id != NULL) {
        //_dd.appsec.user.collection_mode: "sdk"
        prefixed_key = zend_strpprintf(0, "_dd.appsec.user.collection_mode");
        zval collection_mode_zv;
        ZVAL_STRING(&collection_mode_zv, "sdk");
        zend_hash_update(target_table, prefixed_key, &collection_mode_zv);
        zend_string_release(prefixed_key);

        // appsec.events.users.login.success.usr.id
        prefixed_key = zend_strpprintf(0, "%s.%s", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_SUCCESS, "usr.id");
        zval user_id_zv;
        ZVAL_STR_COPY(&user_id_zv, user_id);
        zend_hash_update(target_table, prefixed_key, &user_id_zv);
        zend_string_release(prefixed_key);
    }

    if (metadata != NULL) {
        zend_string *key;
        zval *value;
        ZEND_HASH_FOREACH_STR_KEY_VAL(metadata, key, value) {
            if (!key || Z_TYPE_P(value) != IS_STRING) {
                continue;
            }

            zend_string *prefixed_key = zend_strpprintf(0, "%s.%s", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_SUCCESS, ZSTR_VAL(key));
            zval value_copy;
            ZVAL_COPY(&value_copy, value);
            zend_hash_update(target_table, prefixed_key, &value_copy);

            zend_string_release(prefixed_key);
        }
        ZEND_HASH_FOREACH_END();
    }

    if (user_id != NULL) {
        _ddtrace_set_user(user_id, metadata, false);
    }
}

PHP_FUNCTION(datadog_appsec_v2_track_user_login_failure) {
    UNUSED(execute_data);

    zend_string *login = NULL;
    zend_bool exists = false;
    zend_array *metadata = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "Sb|h", &login, &exists, &metadata) == FAILURE) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    zend_array *target_table;
    if (DDTRACE_G(active_stack)->root_span) {
        target_table = ddtrace_property_array(&DDTRACE_G(active_stack)->root_span->property_meta);
    } else {
        target_table = &DDTRACE_G(root_span_tags_preset);
    }

    if (ZSTR_LEN(login) == 0) {
        LOG_LINE(WARN, "Unexpected empty login in datadog\\appsec\\v2\\track_user_login_failure");
        RETURN_NULL();
    }

#define DDTRACE_ATO_V2_EVENT_USERS_LOGIN_FAILURE "appsec.events.users.login.failure"

    // appsec.events.users.login.failure.usr.login: <login>
    zend_string *prefixed_key = zend_strpprintf(0, "%s.%s", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_FAILURE, "usr.login");
    zval value_zv;
    ZVAL_STR_COPY(&value_zv, login);
    zend_hash_update(target_table, prefixed_key, &value_zv);
    zend_string_release(prefixed_key);

    // appsec.events.users.login.failure.usr.exists: <"true"|"false">
    prefixed_key = zend_strpprintf(0, "%s.%s", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_FAILURE, "usr.exists");
    zval exists_zv;
    ZVAL_STRING(&exists_zv, exists ? "true" : "false");
    zend_hash_update(target_table, prefixed_key, &exists_zv);
    zend_string_release(prefixed_key);

    // appsec.events.users.login.failure.track: "true"
    prefixed_key = zend_strpprintf(0, "%s.track", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_FAILURE);
    zval true_value_zv;
    ZVAL_STRING(&true_value_zv, "true");
    zend_hash_update(target_table, prefixed_key, &true_value_zv);
    zend_string_release(prefixed_key);

    //_dd.appsec.events.users.login.failure.sdk: "true"
    prefixed_key = zend_strpprintf(0, "_dd.%s.sdk", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_FAILURE);
    Z_TRY_ADDREF_P(&true_value_zv);
    zend_hash_update(target_table, prefixed_key, &true_value_zv);
    zend_string_release(prefixed_key);

    if (metadata != NULL) {
        zend_string *key;
        zval *value;
        ZEND_HASH_FOREACH_STR_KEY_VAL(metadata, key, value) {
            if (!key || Z_TYPE_P(value) != IS_STRING) {
                continue;
            }

            zend_string *prefixed_key = zend_strpprintf(0, "%s.%s", DDTRACE_ATO_V2_EVENT_USERS_LOGIN_FAILURE, ZSTR_VAL(key));
            zval value_copy;
            ZVAL_COPY(&value_copy, value);
            zend_hash_update(target_table, prefixed_key, &value_copy);

            zend_string_release(prefixed_key);
        }
        ZEND_HASH_FOREACH_END();
    }
}

PHP_FUNCTION(dd_trace_serialize_closed_spans) {
    UNUSED(execute_data);

    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (!get_DD_TRACE_ENABLED()) {
        array_init(return_value);
        return;
    }

    ddtrace_mark_all_span_stacks_flushable();

    ddog_TracesBytes *traces = ddog_get_traces();
    ddtrace_serialize_closed_spans_with_cycle(traces, false);

    zval traces_zv = dd_serialize_rust_traces_to_zval(traces);

    if (zend_hash_num_elements(Z_ARR(traces_zv)) == 1) {
        ZVAL_COPY(return_value, zend_hash_get_current_data(Z_ARR(traces_zv)));
    } else {
        array_init(return_value);
        zval *spans;
        ZEND_HASH_FOREACH_VAL(Z_ARR(traces_zv), spans) {
            zval *span;
            ZEND_HASH_FOREACH_VAL(Z_ARR_P(spans), span) {
                Z_ADDREF_P(span);
                zend_hash_next_index_insert_new(Z_ARR_P(return_value), span);
            } ZEND_HASH_FOREACH_END();
        } ZEND_HASH_FOREACH_END();
    }

    ddog_free_traces(traces);
    zval_ptr_dtor(&traces_zv);

    ddtrace_free_span_stacks(false);
    ddtrace_init_span_stacks();
}

PHP_FUNCTION(dd_trace_env_config) {
    UNUSED(execute_data);
    zend_string *env_name;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &env_name) == FAILURE) {
        RETURN_NULL();
    }

    zai_config_id id;
    if (zai_config_get_id_by_name((zai_str)ZAI_STR_FROM_ZSTR(env_name), &id)) {
        RETURN_COPY(zai_config_get_value(id));
    } else {
        RETURN_NULL();
    }
}

PHP_FUNCTION(dd_trace_disable_in_request) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    datadog_disable_tracing_in_current_request();

    RETURN_BOOL(1);
}

PHP_FUNCTION(dd_trace_reset) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (datadog_disable) {
        RETURN_BOOL(0);
    }

    // TODO ??
    RETURN_BOOL(1);
}

/* {{{ proto string dd_trace_serialize_msgpack(array trace_array) */
PHP_FUNCTION(dd_trace_serialize_msgpack) {
    zval *trace_array;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &trace_array) == FAILURE) {
        RETURN_THROWS();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    if (ddtrace_serialize_simple_array(trace_array, return_value) != 1) {
        RETURN_BOOL(0);
    }
} /* }}} */

// method used to be able to easily breakpoint the execution at specific PHP line in GDB
PHP_FUNCTION(dd_trace_noop) {
    UNUSED(execute_data);

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    RETURN_BOOL(1);
}

/* {{{ proto int dd_trace_dd_get_memory_limit() */
PHP_FUNCTION(dd_trace_dd_get_memory_limit) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_LONG(ddtrace_get_memory_limit());
}

/* {{{ proto bool dd_trace_check_memory_under_limit() */
PHP_FUNCTION(dd_trace_check_memory_under_limit) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_BOOL(ddtrace_is_memory_under_limit());
}

PHP_FUNCTION(ddtrace_config_app_name) {
    zend_string *default_app_name = NULL, *app_name = get_DD_SERVICE();
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|S", &default_app_name) != SUCCESS) {
        RETURN_NULL();
    }

    if (default_app_name == NULL && ZSTR_LEN(app_name) == 0) {
        RETURN_NULL();
    }

    RETURN_STR(php_trim(ZSTR_LEN(app_name) ? app_name : default_app_name, NULL, 0, 3));
}

PHP_FUNCTION(ddtrace_config_distributed_tracing_enabled) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_BOOL(get_DD_DISTRIBUTED_TRACING());
}

PHP_FUNCTION(ddtrace_config_trace_enabled) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_BOOL(get_DD_TRACE_ENABLED());
}

PHP_FUNCTION(ddtrace_config_integration_enabled) {
    zend_string *name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &name) != SUCCESS) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_FALSE;
    }

    ddtrace_integration *integration = ddtrace_get_integration_from_string(name);
    if (integration == NULL) {
        RETURN_TRUE;
    }
    RETVAL_BOOL(ddtrace_integrations[integration->name].is_enabled());
}

PHP_FUNCTION(DDTrace_Config_integration_analytics_enabled) {
    zend_string *name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &name) != SUCCESS) {
        RETURN_NULL();
    }
    ddtrace_integration *integration = ddtrace_get_integration_from_string(name);
    if (integration == NULL) {
        RETURN_FALSE;
    }
    RETVAL_BOOL(integration->is_analytics_enabled());
}

PHP_FUNCTION(DDTrace_Config_integration_analytics_sample_rate) {
    zend_string *name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &name) != SUCCESS) {
        RETURN_NULL();
    }
    ddtrace_integration *integration = ddtrace_get_integration_from_string(name);
    if (integration == NULL) {
        RETURN_DOUBLE(DD_INTEGRATION_ANALYTICS_SAMPLE_RATE_DEFAULT);
    }
    RETVAL_DOUBLE(integration->get_sample_rate());
}

/* This is only exposed to serialize the container ID into an HTTP Agent header for the userland transport
 * (`DDTrace\Transport\Http`). The background sender (extension-level transport) is decoupled from userland
 * code to create any HTTP Agent headers. Once the dependency on the userland transport has been removed,
 * this function can also be removed.
 */
PHP_FUNCTION(DDTrace_System_container_id) {
    UNUSED(execute_data);
    ddog_CharSlice id = ddtrace_get_container_id();
    if (id.len) {
        RETVAL_STRINGL(id.ptr, id.len);
    } else {
        RETURN_NULL();
    }
}

PHP_FUNCTION(DDTrace_System_process_tags_base_hash) {
    UNUSED(execute_data);

    zend_string *base_hash = datadog_process_tags_get_base_hash();
    if (base_hash) {
        RETVAL_STRINGL(ZSTR_VAL(base_hash), ZSTR_LEN(base_hash));
    } else {
        RETURN_NULL();
    }
}

PHP_FUNCTION(DDTrace_Testing_trigger_error) {
    zend_string *message;
    zend_long error_type;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "Sl", &message, &error_type) != SUCCESS) {
        RETURN_NULL();
    }

    int level = (int)error_type;
    switch (level) {
        case E_ERROR:
        case E_WARNING:
        case E_PARSE:
        case E_NOTICE:
        case E_CORE_ERROR:
        case E_CORE_WARNING:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_USER_WARNING:
        case E_USER_NOTICE:
        case E_STRICT:
        case E_RECOVERABLE_ERROR:
        case E_DEPRECATED:
        case E_USER_DEPRECATED:
            zend_error(level, "%s", ZSTR_VAL(message));
            break;

        default:
            LOG_LINE(WARN, "Invalid error type specified: %i", level);
            break;
    }
}

PHP_FUNCTION(DDTrace_Testing_normalize_tag_value) {
    zend_string *value;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &value) != SUCCESS) {
        RETURN_EMPTY_STRING();
    }

    const char* normalized = ddog_normalize_process_tag_value(dd_zend_string_to_CharSlice(value));

    if (normalized) {
        zend_string *result = zend_string_init(normalized, strlen(normalized), 0);
        ddog_free_normalized_tag_value(normalized);
        RETURN_STR(result);
    } else {
        RETURN_EMPTY_STRING();
    }
}

PHP_FUNCTION(DDTrace_Internal_add_span_flag) {
    zend_object *span;
    zend_long flag;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJ_OF_CLASS_EX(span, ddtrace_ce_span_data, 0, 1)
        Z_PARAM_LONG(flag)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_span_data *span_data = OBJ_SPANDATA(span);
    span_data->flags |= (uint8_t)flag;

    RETURN_NULL();
}

/* {{{ proto void DDTrace\handle_fork(): void */
PHP_FUNCTION(DDTrace_Internal_handle_fork) {
    UNUSED(execute_data);
    UNUSED(return_value);
    datadog_internal_handle_fork();
}

PHP_FUNCTION(DDTrace_Testing_flush_ffe_exposures) {
    ZEND_PARSE_PARAMETERS_NONE();

    RETURN_BOOL(ddtrace_ffe_flush_exposures());
}

PHP_FUNCTION(DDTrace_dogstatsd_count) {
    zend_string *metric;
    zend_long value;
    zval *tags = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
    Z_PARAM_STR(metric)
    Z_PARAM_LONG(value)
    Z_PARAM_OPTIONAL
    Z_PARAM_ARRAY(tags)
    ZEND_PARSE_PARAMETERS_END();

    datadog_sidecar_dogstatsd_count(metric, value, tags);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_dogstatsd_distribution) {
    zend_string *metric;
    double value;
    zval *tags = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
    Z_PARAM_STR(metric)
    Z_PARAM_DOUBLE(value)
    Z_PARAM_OPTIONAL
    Z_PARAM_ARRAY(tags)
    ZEND_PARSE_PARAMETERS_END();

    datadog_sidecar_dogstatsd_distribution(metric, value, tags);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_dogstatsd_gauge) {
    zend_string *metric;
    double value;
    zval *tags = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
    Z_PARAM_STR(metric)
    Z_PARAM_DOUBLE(value)
    Z_PARAM_OPTIONAL
    Z_PARAM_ARRAY(tags)
    ZEND_PARSE_PARAMETERS_END();

    datadog_sidecar_dogstatsd_gauge(metric, value, tags);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_dogstatsd_histogram) {
    zend_string *metric;
    double value;
    zval *tags = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
    Z_PARAM_STR(metric)
    Z_PARAM_DOUBLE(value)
    Z_PARAM_OPTIONAL
    Z_PARAM_ARRAY(tags)
    ZEND_PARSE_PARAMETERS_END();

    datadog_sidecar_dogstatsd_histogram(metric, value, tags);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_dogstatsd_set) {
    zend_string *metric;
    zend_long value;
    zval *tags = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
    Z_PARAM_STR(metric)
    Z_PARAM_LONG(value)
    Z_PARAM_OPTIONAL
    Z_PARAM_ARRAY(tags)
    ZEND_PARSE_PARAMETERS_END();

    datadog_sidecar_dogstatsd_set(metric, value, tags);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_are_endpoints_collected) {
    UNUSED(execute_data);

    if (!DATADOG_G(sidecar) || !datadog_sidecar_instance_id || !DATADOG_G(sidecar_queue_id)) {
        RETURN_TRUE; // Skip overhead if unnecessary
    }

    if (!DATADOG_G(last_service_name) || !DATADOG_G(last_env_name)) {
        RETURN_FALSE;
    }

    ddog_CharSlice service_name = dd_zend_string_to_CharSlice(DATADOG_G(last_service_name));
    ddog_CharSlice env_name = dd_zend_string_to_CharSlice(DATADOG_G(last_env_name));

    RETURN_BOOL(ddog_sidecar_telemetry_are_endpoints_collected(datadog_telemetry_cache(), service_name, env_name));
}

static ddog_Method dd_string_to_method(zend_string *method) {
    if (zend_string_equals_literal(method, "GET")) {
        return DDOG_METHOD_GET;
    }
    if (zend_string_equals_literal(method, "POST")) {
        return DDOG_METHOD_POST;
    }
    if (zend_string_equals_literal(method, "PUT")) {
        return DDOG_METHOD_PUT;
    }
    if (zend_string_equals_literal(method, "DELETE")) {
        return DDOG_METHOD_DELETE;
    }
    if (zend_string_equals_literal(method, "PATCH")) {
        return DDOG_METHOD_PATCH;
    }
    if (zend_string_equals_literal(method, "HEAD")) {
        return DDOG_METHOD_HEAD;
    }
    if (zend_string_equals_literal(method, "OPTIONS")) {
        return DDOG_METHOD_OPTIONS;
    }
    if (zend_string_equals_literal(method, "TRACE")) {
        return DDOG_METHOD_TRACE;
    }
    if (zend_string_equals_literal(method, "CONNECT")) {
        return DDOG_METHOD_CONNECT;
    }
    return DDOG_METHOD_OTHER;
}

PHP_FUNCTION(DDTrace_add_endpoint) {
    UNUSED(execute_data);
    zend_string *path = NULL;
    zend_string *operation_name = NULL;
    zend_string *resource_name = NULL;
    zend_string *method = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SSSS", &path, &operation_name, &resource_name, &method) == FAILURE) {
        RETURN_FALSE;
    }

    if (!DATADOG_G(sidecar) || !datadog_sidecar_instance_id || !DATADOG_G(sidecar_queue_id)) {
        RETURN_FALSE;
    }

    ddog_Method method_enum = dd_string_to_method(method);
    ddog_CharSlice path_slice = dd_zend_string_to_CharSlice(path);
    ddog_CharSlice operation_name_slice = dd_zend_string_to_CharSlice(operation_name);
    ddog_CharSlice resource_name_slice = dd_zend_string_to_CharSlice(resource_name);

    LOG_LINE(DEBUG,
             "Adding endpoint: %.*s (%zu) - operation_name: %.*s (%zu) - resource_name: %.*s (%zu) - method: %.*s (%zu)",
             (int)path_slice.len, (char *)path_slice.ptr, path_slice.len,
             (int)operation_name_slice.len, (char *)operation_name_slice.ptr, operation_name_slice.len,
             (int)resource_name_slice.len, (char *)resource_name_slice.ptr, resource_name_slice.len,
             (int)method->len, ZSTR_VAL(method), (size_t)method->len);

    ddog_sidecar_telemetry_addEndpoint_buffer(datadog_telemetry_buffer(), method_enum, path_slice, operation_name_slice, resource_name_slice);

    RETURN_TRUE;
}

PHP_FUNCTION(DDTrace_flush_endpoints) {
    UNUSED(execute_data);
    UNUSED(return_value);

    if (!DATADOG_G(sidecar) || !datadog_sidecar_instance_id || !DATADOG_G(sidecar_queue_id) || !DATADOG_G(telemetry_buffer)) {
        return;
    }

    if (!DATADOG_G(last_service_name) || !DATADOG_G(last_env_name)) {
        return;
    }

    ddog_CharSlice service_name = dd_zend_string_to_CharSlice(DATADOG_G(last_service_name));
    ddog_CharSlice env_name = dd_zend_string_to_CharSlice(DATADOG_G(last_env_name));

    datadog_ffi_try("Failed flushing endpoint telemetry buffer",
        ddog_sidecar_telemetry_filter_flush(&DATADOG_G(sidecar), datadog_sidecar_instance_id, &DATADOG_G(sidecar_queue_id), datadog_telemetry_buffer(), datadog_telemetry_cache(), service_name, env_name));
}

PHP_FUNCTION(DDTrace_ffe_has_config) {
    ZEND_PARSE_PARAMETERS_NONE();

    RETURN_BOOL(ddog_ffe_has_config());
}

PHP_FUNCTION(DDTrace_ffe_config_version) {
    ZEND_PARSE_PARAMETERS_NONE();

    RETURN_LONG((zend_long) ddog_ffe_config_version());
}

PHP_FUNCTION(DDTrace_Testing_ffe_load_config) {
    zend_string *json;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(json)
    ZEND_PARSE_PARAMETERS_END();

    RETURN_BOOL(ddog_ffe_load_config(dd_zend_string_to_CharSlice(json)));
}

static const char *ddtrace_ffe_reason_name(int32_t reason) {
    switch (reason) {
        case 0:
            return "STATIC";
        case 2:
            return "TARGETING_MATCH";
        case 3:
            return "SPLIT";
        case 4:
            return "DISABLED";
        case 5:
            return "ERROR";
        case 1:
        default:
            return "DEFAULT";
    }
}

static const char *ddtrace_ffe_error_name(int32_t error_code) {
    switch (error_code) {
        case 0:
            return NULL;
        case 1:
            return "TYPE_MISMATCH";
        case 2:
            return "PARSE_ERROR";
        case 3:
            return "FLAG_NOT_FOUND";
        case 6:
            return "PROVIDER_NOT_READY";
        case 7:
        default:
            return "GENERAL";
    }
}

static int32_t ddtrace_ffe_effective_reason(int32_t reason, int32_t error_code) {
    return error_code == 0 ? reason : 5;
}

static void ddtrace_ffe_record_evaluation_metric_result(
    zend_string *flag_key,
    zend_string *variant,
    zend_string *allocation_key,
    int32_t reason,
    int32_t error_code
) {
    const char *reason_name = ddtrace_ffe_reason_name(ddtrace_ffe_effective_reason(reason, error_code));
    const char *error_name = ddtrace_ffe_error_name(error_code);
    ddtrace_ffe_record_evaluation_metric(flag_key, variant, reason_name, error_name, allocation_key);
}

static zend_string *ddtrace_ffe_attributes_json(zval *attrs_zv) {
    smart_str buf = {0};
    zai_json_encode(&buf, attrs_zv, 0);
    if (!buf.s) {
        return zend_string_init("{}", sizeof("{}") - 1, 0);
    }
    smart_str_0(&buf);
    return smart_str_extract(&buf);
}

static void ddtrace_ffe_update_property(zval *object, const char *name, size_t name_len, zval *value) {
    zend_string *property_name = zend_string_init(name, name_len, 0);
    zend_update_property_ex(ddtrace_ce_ffe_result, Z_OBJ_P(object), property_name, value);
    zend_string_release(property_name);
}

static void ddtrace_ffe_update_nullable_string_property(zval *object, const char *name, size_t name_len, zend_string *value) {
    zval property_value;

    if (value == NULL) {
        ZVAL_NULL(&property_value);
        ddtrace_ffe_update_property(object, name, name_len, &property_value);
        return;
    }

    ZVAL_STR(&property_value, value);
    ddtrace_ffe_update_property(object, name, name_len, &property_value);
    zval_ptr_dtor(&property_value);
}

static void ddtrace_ffe_update_long_property(zval *object, const char *name, size_t name_len, zend_long value) {
    zval property_value;

    ZVAL_LONG(&property_value, value);
    ddtrace_ffe_update_property(object, name, name_len, &property_value);
}

static void ddtrace_ffe_update_bool_property(zval *object, const char *name, size_t name_len, bool value) {
    zval property_value;

    ZVAL_BOOL(&property_value, value);
    ddtrace_ffe_update_property(object, name, name_len, &property_value);
}

static void ddtrace_ffe_update_empty_array_property(zval *object, const char *name, size_t name_len) {
    zval property_value;

    array_init(&property_value);
    ddtrace_ffe_update_property(object, name, name_len, &property_value);
    zval_ptr_dtor(&property_value);
}

PHP_FUNCTION(DDTrace_ffe_evaluate) {
    zend_string *flag_key;
    zend_long type_id_zl;
    zend_string *targeting_key = NULL;
    zval *attrs_zv;
    int32_t type_id;
    struct ddog_FfeAttribute *c_attrs = NULL;
    zend_string **owned_attr_keys = NULL;
    size_t attrs_count = 0;
    HashTable *attributes;
    size_t idx = 0;
    zend_ulong num_key;
    zend_string *key;
    zval *value;
    struct ddog_FfeResult result;
    zend_bool record_metric = true;
    zend_string *value_json;
    zend_string *variant;
    zend_string *allocation_key;

    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_STR(flag_key)
        Z_PARAM_LONG(type_id_zl)
        Z_PARAM_STR_OR_NULL(targeting_key)
        Z_PARAM_ARRAY(attrs_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(record_metric)
    ZEND_PARSE_PARAMETERS_END();

    type_id = (int32_t) type_id_zl;
    attributes = Z_ARRVAL_P(attrs_zv);
    attrs_count = zend_hash_num_elements(attributes);

    if (attrs_count > 0) {
        c_attrs = ecalloc(attrs_count, sizeof(struct ddog_FfeAttribute));
        owned_attr_keys = ecalloc(attrs_count, sizeof(zend_string *));
        ZEND_HASH_FOREACH_KEY_VAL(attributes, num_key, key, value) {
            zend_string *owned_key = NULL;

            if (idx >= attrs_count) {
                continue;
            }

            if (!key) {
                owned_key = zend_long_to_str((zend_long) num_key);
                key = owned_key;
            }

            switch (Z_TYPE_P(value)) {
                case IS_STRING:
                    c_attrs[idx].value_type = 0;
                    c_attrs[idx].string_value = dd_zend_string_to_CharSlice(Z_STR_P(value));
                    break;
                case IS_LONG:
                    c_attrs[idx].value_type = 1;
                    c_attrs[idx].number_value = (double) Z_LVAL_P(value);
                    break;
                case IS_DOUBLE:
                    c_attrs[idx].value_type = 1;
                    c_attrs[idx].number_value = Z_DVAL_P(value);
                    break;
                case IS_TRUE:
                    c_attrs[idx].value_type = 2;
                    c_attrs[idx].bool_value = true;
                    break;
                case IS_FALSE:
                    c_attrs[idx].value_type = 2;
                    c_attrs[idx].bool_value = false;
                    break;
                default:
                    if (owned_key) {
                        zend_string_release(owned_key);
                    }
                    continue;
            }

            c_attrs[idx].key = dd_zend_string_to_CharSlice(key);
            owned_attr_keys[idx] = owned_key;
            idx++;
        } ZEND_HASH_FOREACH_END();
        attrs_count = idx;
    }

    result = ddog_ffe_evaluate(
        dd_zend_string_to_CharSlice(flag_key),
        type_id,
        dd_zend_string_to_CharSlice(targeting_key),
        c_attrs,
        attrs_count
    );
    if (c_attrs) {
        efree(c_attrs);
    }
    if (owned_attr_keys) {
        for (size_t i = 0; i < attrs_count; i++) {
            if (owned_attr_keys[i]) {
                zend_string_release(owned_attr_keys[i]);
            }
        }
        efree(owned_attr_keys);
    }

    if (!result.valid) {
        if (record_metric) {
            ddtrace_ffe_record_evaluation_metric(flag_key, NULL, "ERROR", "PROVIDER_NOT_READY", NULL);
        }
        RETURN_NULL();
    }

    value_json = result.value_json;
    variant = result.variant;
    allocation_key = result.allocation_key;

    if (record_metric) {
        ddtrace_ffe_record_evaluation_metric_result(
            flag_key,
            variant,
            allocation_key,
            result.reason,
            result.error_code);
    }

    if (result.do_log && allocation_key && variant) {
        zend_string *subject_attributes_json = ddtrace_ffe_attributes_json(attrs_zv);
        ddtrace_ffe_record_exposure(
            flag_key,
            targeting_key,
            subject_attributes_json,
            allocation_key,
            variant
        );
        zend_string_release(subject_attributes_json);
    }

    object_init_ex(return_value, ddtrace_ce_ffe_result);
    ddtrace_ffe_update_nullable_string_property(return_value, ZEND_STRL("valueJson"), value_json);
    ddtrace_ffe_update_nullable_string_property(return_value, ZEND_STRL("variant"), variant);
    ddtrace_ffe_update_nullable_string_property(return_value, ZEND_STRL("allocationKey"), allocation_key);
    ddtrace_ffe_update_long_property(return_value, ZEND_STRL("reason"), ddtrace_ffe_effective_reason(result.reason, result.error_code));
    ddtrace_ffe_update_long_property(return_value, ZEND_STRL("errorCode"), result.error_code);
    ddtrace_ffe_update_bool_property(return_value, ZEND_STRL("doLog"), result.do_log);
    ddtrace_ffe_update_empty_array_property(return_value, ZEND_STRL("providerState"));
}

PHP_FUNCTION(dd_trace_send_traces_via_thread) {
    char *payload = NULL;
    zend_long num_traces = 0;
    size_t payload_len = 0;
    zval *curl_headers = NULL;

    // Agent HTTP headers are now set at the extension level so 'curl_headers' from userland is ignored
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "las", &num_traces, &curl_headers, &payload,
                                 &payload_len) == FAILURE) {
        RETURN_THROWS();
    }
#ifndef _WIN32
    bool result = ddtrace_send_traces_via_thread(num_traces, payload, payload_len);
    dd_prepare_for_new_trace();
    RETURN_BOOL(result);
#else
    RETURN_FALSE;
#endif
}

PHP_FUNCTION(dd_trace_buffer_span) {
    zval *trace_array = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &trace_array) == FAILURE) {
        RETURN_THROWS();
    }

#ifndef _WIN32
    if (!get_DD_TRACE_ENABLED() || get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        RETURN_BOOL(0);
    }

    char *data;
    size_t size;
    if (ddtrace_serialize_simple_array_into_c_string(trace_array, &data, &size)) {
        RETVAL_BOOL(ddtrace_coms_buffer_data(DDTRACE_G(traces_group_id), data, size));

        free(data);
        return;
    } else {
        RETURN_FALSE;
    }
#else
    RETURN_BOOL(0);
#endif
}

PHP_FUNCTION(dd_trace_coms_trigger_writer_flush) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

#ifndef _WIN32
    if (!get_DD_TRACE_ENABLED() || get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        RETURN_LONG(0);
    }

    RETURN_LONG(ddtrace_coms_trigger_writer_flush());
#else
    RETURN_BOOL(0);
#endif
}

#define FUNCTION_NAME_MATCHES(function) zend_string_equals_literal(function_val, function)

PHP_FUNCTION(dd_trace_internal_fn) {
    UNUSED(execute_data);
    zval ***params = NULL;
    uint32_t params_count = 0;

    zend_string *function_val = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S*", &function_val, &params, &params_count) != SUCCESS) {
        RETURN_BOOL(0);
    }

    RETVAL_FALSE;
    if (ZSTR_LEN(function_val) > 0) {
        if (FUNCTION_NAME_MATCHES("finalize_telemetry")) {
            ddog_QueueId queueId = DATADOG_G(sidecar_queue_id);
            datadog_sidecar_finalize(false);
            DATADOG_G(sidecar_queue_id) = queueId; // usually we want to stop using it, except here
            datadog_telemetry_lifecycle_end();
            RETVAL_TRUE;
        } else if (params_count == 3 && FUNCTION_NAME_MATCHES("force_overwrite_property")) {
            zval *obj = ZVAL_VARARG_PARAM(params, 0);
            zval *name = ZVAL_VARARG_PARAM(params, 1);
            zval *value = ZVAL_VARARG_PARAM(params, 2);
            if (Z_TYPE_P(obj) == IS_OBJECT && Z_TYPE_P(name) == IS_STRING) {
#if PHP_VERSION_ID < 80000
                zend_std_write_property(obj, name, value, NULL);
                RETVAL_TRUE;
#else
                if (&EG(error_zval) != zend_std_write_property(Z_OBJ_P(obj), Z_STR_P(name), value, NULL)) {
                    RETVAL_TRUE;
                }
#endif
            }
        } else if (params_count == 1 && FUNCTION_NAME_MATCHES("detect_composer_installed_json")) {
            ddog_CharSlice path = dd_zend_string_to_CharSlice(Z_STR_P(ZVAL_VARARG_PARAM(params, 0)));
            ddtrace_detect_composer_installed_json(&DATADOG_G(sidecar), datadog_sidecar_instance_id, &DATADOG_G(sidecar_queue_id), path);
            RETVAL_TRUE;
        } else if (params_count == 2 && FUNCTION_NAME_MATCHES("mark_integration_loaded")) {
            zval *name = ZVAL_VARARG_PARAM(params, 0);
            zval *version = ZVAL_VARARG_PARAM(params, 1);
            if (Z_TYPE_P(name) == IS_STRING && Z_TYPE_P(version) == IS_STRING) {
                ddtrace_telemetry_notify_integration_version(Z_STRVAL_P(name), Z_STRLEN_P(name), Z_STRVAL_P(version), Z_STRLEN_P(version));
            }
        } else if (params_count == 2 && FUNCTION_NAME_MATCHES("track_otel_config")) {
            zval *config_name = ZVAL_VARARG_PARAM(params, 0);
            zval *config_value = ZVAL_VARARG_PARAM(params, 1);
            if (Z_TYPE_P(config_name) == IS_STRING) {
                // Store the config name and value in the HashTable
                zval value_copy;
                ZVAL_COPY(&value_copy, config_value);
                zend_hash_update(&DATADOG_G(otel_config_telemetry), Z_STR_P(config_name), &value_copy);
                RETVAL_TRUE;
            }
        } else if (params_count == 3 && FUNCTION_NAME_MATCHES("track_telemetry_metrics")) {
            zval *metric_name = ZVAL_VARARG_PARAM(params, 0);
            zval *metric_value = ZVAL_VARARG_PARAM(params, 1);
            zval *tags = ZVAL_VARARG_PARAM(params, 2);
            if (Z_TYPE_P(metric_name) == IS_STRING && Z_TYPE_P(tags) == IS_STRING) {
                datadog_metric_register_buffer(Z_STR_P(metric_name), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
                datadog_metric_add_point(Z_STR_P(metric_name), zval_get_double(metric_value), Z_STR_P(tags));
                RETVAL_TRUE;
            }
        } else if (FUNCTION_NAME_MATCHES("dump_sidecar")) {
            if (!DATADOG_G(sidecar)) {
                RETURN_FALSE;
            }
            ddog_CharSlice slice = ddog_sidecar_dump(&DATADOG_G(sidecar));
            RETVAL_STRINGL(slice.ptr, slice.len);
            free((void *) slice.ptr);
        } else if (FUNCTION_NAME_MATCHES("stats_sidecar")) {
            if (!DATADOG_G(sidecar)) {
                RETURN_FALSE;
            }
            ddog_CharSlice slice = ddog_sidecar_stats(&DATADOG_G(sidecar));
            RETVAL_STRINGL(slice.ptr, slice.len);
            free((void *) slice.ptr);
        } else if (FUNCTION_NAME_MATCHES("break_sidecar_connection")) {
            if (!DATADOG_G(sidecar)) {
                RETURN_FALSE;
            }
            ddog_sidecar_send_garbage(&DATADOG_G(sidecar));
            datadog_force_new_instance_id();
            RETURN_TRUE;
        } else if (FUNCTION_NAME_MATCHES("reload_process_tags")) {
            if (datadog_process_tags_enabled()) {
                datadog_process_tags_reload();
                datadog_sidecar_update_process_tags();
            }
            RETVAL_TRUE;
        } else if (params_count == 1 && FUNCTION_NAME_MATCHES("set_container_tags_hash")) {
            zval *container_tags_hash = ZVAL_VARARG_PARAM(params, 0);
            if (Z_TYPE_P(container_tags_hash) == IS_STRING) {
                // zend_string_dup does not dup request-local interned strings...
                zend_string *hash = zend_string_init(Z_STRVAL_P(container_tags_hash), Z_STRLEN_P(container_tags_hash), 1);
                datadog_process_tags_set_container_tags_hash(hash);
                zend_string_release(hash);
                RETVAL_TRUE;
            } else {
                RETVAL_FALSE;
            }
        } else if (FUNCTION_NAME_MATCHES("synchronous_flush")) {
            uint32_t timeout = 1000;
            if (params_count == 1) {
                timeout = Z_LVAL_P(ZVAL_VARARG_PARAM(params, 0));
            }
#ifndef _WIN32
            if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
                if (dd_rinit_once_done) {
                    ddtrace_coms_synchronous_flush(timeout);
                }
            } else
#endif
            if (DATADOG_G(sidecar)) {
                datadog_ffi_try("Failed synchronously flushing traces", ddog_sidecar_flush(&DATADOG_G(sidecar), (ddog_SidecarFlushOptions){.traces_and_stats = true}));
            }
            RETVAL_TRUE;
#ifndef _WIN32
        } else if (FUNCTION_NAME_MATCHES("init_and_start_writer")) {
            RETVAL_BOOL(ddtrace_coms_init_and_start_writer());
        } else if (FUNCTION_NAME_MATCHES("ddtrace_coms_next_group_id")) {
            RETVAL_LONG(ddtrace_coms_next_group_id());
        } else if (params_count == 2 && FUNCTION_NAME_MATCHES("ddtrace_coms_buffer_span")) {
            zval *group_id = ZVAL_VARARG_PARAM(params, 0);
            zval *trace_array = ZVAL_VARARG_PARAM(params, 1);
            char *data = NULL;
            size_t size = 0;
            if (ddtrace_serialize_simple_array_into_c_string(trace_array, &data, &size)) {
                RETVAL_BOOL(ddtrace_coms_buffer_data(Z_LVAL_P(group_id), data, size));
                free(data);
            } else {
                RETVAL_FALSE;
            }
        } else if (params_count == 2 && FUNCTION_NAME_MATCHES("ddtrace_coms_buffer_data")) {
            zval *group_id = ZVAL_VARARG_PARAM(params, 0);
            zval *data = ZVAL_VARARG_PARAM(params, 1);
            RETVAL_BOOL(ddtrace_coms_buffer_data(Z_LVAL_P(group_id), Z_STRVAL_P(data), Z_STRLEN_P(data)));
        } else if (FUNCTION_NAME_MATCHES("shutdown_writer")) {
            RETVAL_BOOL(ddtrace_coms_flush_shutdown_writer_synchronous());
        } else if (params_count == 1 && FUNCTION_NAME_MATCHES("set_writer_send_on_flush")) {
            RETVAL_BOOL(ddtrace_coms_set_writer_send_on_flush(IS_TRUE_P(ZVAL_VARARG_PARAM(params, 0))));
        } else if (FUNCTION_NAME_MATCHES("test_consumer")) {
            ddtrace_coms_test_consumer();
            RETVAL_TRUE;
        } else if (FUNCTION_NAME_MATCHES("test_writers")) {
            ddtrace_coms_test_writers();
            RETVAL_TRUE;
        } else if (FUNCTION_NAME_MATCHES("test_msgpack_consumer")) {
            ddtrace_coms_test_msgpack_consumer();
            RETVAL_TRUE;
#endif
        } else if (FUNCTION_NAME_MATCHES("test_logs")) {
            ddog_logf(DDOG_LOG_WARN, false, "foo");
            ddog_logf(DDOG_LOG_WARN, false, "bar");
            ddog_logf(DDOG_LOG_ERROR, false, "Boum");
            RETVAL_TRUE;
        } else if (FUNCTION_NAME_MATCHES("await_agent_info")) {
            // Block until the sidecar has received and applied the agent /info response.
            // This ensures peer-tag keys and span kinds are initialised before the caller
            // makes requests that produce stats.  Times out after 5 seconds.
            uint32_t timeout_ms = 5000;
            if (params_count == 1) {
                timeout_ms = (uint32_t)Z_LVAL_P(ZVAL_VARARG_PARAM(params, 0));
            }
            uint32_t waited = 0;
            while (!ddog_is_agent_info_ready() && waited < timeout_ms) {
                // Actively read the SHM so we pick up the update the sidecar wrote.
                datadog_apply_agent_info();
                usleep(10000); // 10ms
                waited += 10;
            }
            RETVAL_BOOL(ddog_is_agent_info_ready());
        } else if (FUNCTION_NAME_MATCHES("get_loaded_remote_configs")) {
            // Returns a PHP array mapping loaded RC config IDs to their content summary.
            // e.g. ["datadog/2/LIVE_DEBUGGING/logProbe_log.../config" => ["type"=>"probe","id"=>"log..."]]
            if (DATADOG_G(remote_config_state)) {
                char *rc_json = ddog_remote_config_get_loaded_configs(DATADOG_G(remote_config_state));
                if (zai_json_decode_assoc_safe(return_value, rc_json, strlen(rc_json), 128, false) != SUCCESS) {
                    array_init(return_value);
                }
                ddog_remote_config_loaded_configs_free(rc_json);
            } else {
                array_init(return_value);
            }
        } else if (FUNCTION_NAME_MATCHES("await_remote_config")) {
            uint32_t timeout_sec = 10;
            if (params_count == 1) {
                timeout_sec = (uint32_t)Z_LVAL_P(ZVAL_VARARG_PARAM(params, 0));
            }
            php_sleep(timeout_sec);
            RETURN_BOOL(DATADOG_G(reread_remote_configuration));
        } else if (FUNCTION_NAME_MATCHES("get_agent_info")) {
            // Returns a PHP array decoded from the agent /info JSON payload.
            if (DATADOG_G(agent_info_reader)) {
                char *info_json = ddog_agent_info_as_json(DATADOG_G(agent_info_reader));
                if (info_json) {
                    if (zai_json_decode_assoc_safe(return_value, info_json, strlen(info_json), 128, false) != SUCCESS) {
                        array_init(return_value);
                    }
                    ddog_agent_info_json_free(info_json);
                } else {
                    array_init(return_value);
                }
            } else {
                array_init(return_value);
            }
        } else if (FUNCTION_NAME_MATCHES("get_agent_sampling_config")) {
            // Returns a PHP array decoded from the agent sampling/remote-config JSON payload.
            if (DDTRACE_G(agent_config_reader)) {
                ddog_CharSlice agent_rc_data = {0};
                ddog_agent_remote_config_read(DDTRACE_G(agent_config_reader), &agent_rc_data);
                if (agent_rc_data.len > 0) {
                    if (zai_json_decode_assoc_safe(return_value, agent_rc_data.ptr, (int)agent_rc_data.len, 128, false) != SUCCESS) {
                        array_init(return_value);
                    }
                } else {
                    array_init(return_value);
                }
            } else {
                array_init(return_value);
            }
        }
    }
}

/* {{{ proto int DDTrace\close_spans_until(DDTrace\SpanData) */
PHP_FUNCTION(DDTrace_close_spans_until) {
    zval *spanzv = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "O!", &spanzv, ddtrace_ce_span_data) == FAILURE) {
        RETURN_THROWS();
    }

    int closed_spans = ddtrace_close_userland_spans_until(spanzv ? OBJ_SPANDATA(Z_OBJ_P(spanzv)) : NULL);

    if (closed_spans == -1) {
        RETURN_FALSE;
    }
    RETURN_LONG(closed_spans);
}

/* {{{ proto string dd_trace_set_trace_id() */
PHP_FUNCTION(dd_trace_set_trace_id) {
    UNUSED(execute_data);

    zend_string *trace_id = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &trace_id) == FAILURE) {
        RETURN_THROWS();
    }

    datadog_trace_id new_trace_id = ddtrace_parse_userland_trace_id(trace_id);
    if (new_trace_id.low || new_trace_id.high || (ZSTR_LEN(trace_id) == 1 && ZSTR_VAL(trace_id)[0] == '0')) {
        DDTRACE_G(distributed_trace_id) = new_trace_id;
        RETURN_TRUE;
    }

    RETURN_FALSE;
}

/* {{{ proto string dd_trace_peek_span_id() */
PHP_FUNCTION(dd_trace_peek_span_id) {
    UNUSED(execute_data);
    RETURN_STR(ddtrace_span_id_as_string(ddtrace_peek_span_id()));
}

/* {{{ proto void dd_trace_close_all_spans_and_flush() */
PHP_FUNCTION(dd_trace_close_all_spans_and_flush) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }
    ddtrace_close_all_spans_and_flush();
    RETURN_NULL();
}

/* {{{ proto void dd_trace_synchronous_flush(int) */
PHP_FUNCTION(dd_trace_synchronous_flush) {
    zend_long timeout = 100;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &timeout) == FAILURE) {
        RETURN_THROWS();
    }

    // If zend_long is not a uint32_t, we can't pass it to ddtrace_coms_synchronous_flush
    if (timeout < 0 || timeout > UINT32_MAX) {
        LOG_LINE_ONCE(ERROR, "dd_trace_synchronous_flush() expects a timeout in milliseconds");
        RETURN_NULL();
    }

#ifndef _WIN32
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        if (dd_rinit_once_done) {
            ddtrace_coms_synchronous_flush(timeout);
        }
    } else
#endif
    if (DATADOG_G(sidecar)) {
        datadog_ffi_try("Failed synchronously flushing traces", ddog_sidecar_flush(&DATADOG_G(sidecar), (ddog_SidecarFlushOptions){.traces_and_stats = true}));
    }
    RETURN_NULL();
}

static void dd_ensure_root_span(void) {
    if (!DDTRACE_G(active_stack)->root_span && DDTRACE_G(active_stack)->parent_stack == NULL && get_DD_TRACE_GENERATE_ROOT_SPAN()) {
        ddtrace_push_root_span();  // ensure root span always exists, especially after serialization for testing
    }
}

/* {{{ proto string DDTrace\active_span() */
PHP_FUNCTION(DDTrace_active_span) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }
    dd_ensure_root_span();
    ddtrace_span_data *span = ddtrace_active_span();
    if (span) {
        RETURN_OBJ_COPY(&span->std);
    }
    RETURN_NULL();
}

/* {{{ proto string DDTrace\root_span() */
PHP_FUNCTION(DDTrace_root_span) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }
    dd_ensure_root_span();
    ddtrace_root_span_data *span = DDTRACE_G(active_stack)->root_span;
    if (span) {
        RETURN_OBJ_COPY(&span->std);
    }
    RETURN_NULL();
}

static inline void dd_start_span(INTERNAL_FUNCTION_PARAMETERS) {
    double start_time_seconds = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|d", &start_time_seconds) != SUCCESS) {
        LOG_LINE_ONCE(WARN, "unexpected parameter, expecting double for start time");
        RETURN_FALSE;
    }

    ddtrace_span_data *span;

    if (get_DD_TRACE_ENABLED()) {
        span = ddtrace_open_span(DDTRACE_USER_SPAN);
    } else {
        span = ddtrace_init_dummy_span();
    }

    if (start_time_seconds > 0) {
        span->start = (uint64_t)(start_time_seconds * ZEND_NANO_IN_SEC);
    }

    if (get_DD_TRACE_ENABLED()) {
        ddtrace_observe_opened_span(span);
    }

    RETURN_OBJ(&span->std);
}

/* {{{ proto string DDTrace\start_span() */
PHP_FUNCTION(DDTrace_start_span) {
    dd_start_span(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

/* {{{ proto string DDTrace\start_trace_span() */
PHP_FUNCTION(DDTrace_start_trace_span) {
    if (get_DD_TRACE_ENABLED()) {
        ddtrace_span_stack *stack = ddtrace_init_root_span_stack();
        ddtrace_switch_span_stack(stack);
        GC_DELREF(&stack->std); // We don't retain a ref to it, it's now the active_stack
    }
    dd_start_span(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

static void dd_set_span_finish_time(ddtrace_span_data *span, double finish_time_seconds) {
    // we do not expose the monotonic time here, so do not use it as reference time to calculate difference
    uint64_t start_time = span->start;
    uint64_t finish_time = (uint64_t)(finish_time_seconds * 1000000000);
    if (finish_time < start_time) {
        dd_trace_stop_span_time(span);
    } else {
        span->duration = finish_time - start_time;
    }
}

/* {{{ proto string DDTrace\close_span() */
PHP_FUNCTION(DDTrace_close_span) {
    double finish_time_seconds = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|d", &finish_time_seconds) != SUCCESS) {
        LOG_LINE_ONCE(WARN, "unexpected parameter, expecting double for finish time");
        RETURN_FALSE;
    }

    ddtrace_span_data *top_span = ddtrace_active_span();

    if (!top_span || top_span->type != DDTRACE_USER_SPAN) {
        LOG(ERROR, "There is no user-span on the top of the stack. Cannot close.");
        RETURN_NULL();
    }

    dd_set_span_finish_time(top_span, finish_time_seconds);

    ddtrace_close_span(top_span);
    RETURN_NULL();
}

/* {{{ proto string DDTrace\update_span_duration() */
PHP_FUNCTION(DDTrace_update_span_duration) {
    double finish_time_seconds = 0;
    zval *spanzv = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "O|d", &spanzv, ddtrace_ce_span_data, &finish_time_seconds) != SUCCESS) {
        RETURN_FALSE;
    }

    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(spanzv));

    if (span->duration == 0) {
        LOG(ERROR, "Cannot update the span duration of an unfinished span.");
        RETURN_NULL();
    }

    if (span->duration == DDTRACE_DROPPED_SPAN || span->duration == DDTRACE_SILENTLY_DROPPED_SPAN) {
        RETURN_NULL();
    }

    dd_set_span_finish_time(span, finish_time_seconds);

    RETURN_NULL();
}

/* {{{ proto string DDTrace\try_drop_span() */
PHP_FUNCTION(DDTrace_try_drop_span) {
    zval *spanzv = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "O", &spanzv, ddtrace_ce_span_data) != SUCCESS) {
        RETURN_FALSE;
    }

    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ_P(spanzv));

    if (span->flags & DDTRACE_SPAN_FLAG_NOT_DROPPABLE) {
        RETURN_FALSE;
    }

    if (span->duration == DDTRACE_DROPPED_SPAN || span->duration == DDTRACE_SILENTLY_DROPPED_SPAN) {
        RETURN_TRUE;
    }

    ddtrace_span_stack *active_stack = DDTRACE_G(active_stack);
    if (span->active_child_spans) {
        RETURN_FALSE;
    }

    // Assert span stack for manual instantiations of SpanStack/clones.
    if (!span->stack || span->stack->active != &span->props) {
        RETURN_FALSE;
    }

    bool on_active_stack = active_stack == span->stack;
    if (!on_active_stack) {
        GC_ADDREF(&active_stack->std);
    }
    ddtrace_drop_span(span);
    if (!on_active_stack) {
        ddtrace_switch_span_stack(active_stack);
        GC_DELREF(&active_stack->std);
    }

    RETURN_TRUE;
}

/* {{{ proto string DDTrace\active_stack() */
PHP_FUNCTION(DDTrace_active_stack) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (!DDTRACE_G(active_stack)) {
        RETURN_NULL();
    }
    RETURN_OBJ_COPY(&DDTRACE_G(active_stack)->std);
}

/* {{{ proto string DDTrace\create_stack() */
PHP_FUNCTION(DDTrace_create_stack) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_OBJ(&ddtrace_init_root_span_stack()->std);
    }

    ddtrace_span_stack *stack = ddtrace_init_span_stack();
    ddtrace_switch_span_stack(stack);
    RETURN_OBJ(&stack->std);
}

/* {{{ proto string DDTrace\switch_stack(DDTrace\SpanData|DDTrace\SpanStack) */
PHP_FUNCTION(DDTrace_switch_stack) {
    ddtrace_span_stack *stack = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        DD_PARAM_PROLOGUE(0, 0);
        if (Z_TYPE_P(_arg) == IS_OBJECT && (instanceof_function(Z_OBJCE_P(_arg), ddtrace_ce_span_data) || Z_OBJCE_P(_arg) == ddtrace_ce_span_stack)) {
            stack = (ddtrace_span_stack *) Z_OBJ_P(_arg);
            if (instanceof_function(Z_OBJCE_P(_arg), ddtrace_ce_span_data)) {
                stack = OBJ_SPANDATA(Z_OBJ_P(_arg))->stack;
            }
        } else {
            zend_argument_type_error(1, "must be of type DDTrace\\SpanData|DDTrace\\SpanStack, %s given", zend_zval_value_name(_arg));
            _error_code = ZPP_ERROR_FAILURE;
            break;
        }
    ZEND_PARSE_PARAMETERS_END();

    if (!DDTRACE_G(active_stack)) {
        RETURN_NULL();
    }

    if (stack) {
        ddtrace_switch_span_stack(stack);
    } else if (DDTRACE_G(active_stack)->parent_stack) {
        ddtrace_switch_span_stack(DDTRACE_G(active_stack)->parent_stack);
    }

    RETURN_OBJ_COPY(&DDTRACE_G(active_stack)->std);
}

PHP_FUNCTION(DDTrace_flush) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    if (get_DD_AUTOFINISH_SPANS()) {
        ddtrace_close_userland_spans_until(NULL);
    }
    if (ddtrace_flush_tracer(false, get_DD_TRACE_FLUSH_COLLECT_CYCLES(), false) == FAILURE) {
        LOG_LINE(WARN, "Unable to flush the tracer");
    }
    RETURN_NULL();
}

/* {{{ proto string \DDTrace\trace_id() */
PHP_FUNCTION(DDTrace_trace_id) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_STR(datadog_trace_id_as_string(ddtrace_peek_trace_id()));
}

/* {{{ proto string \DDTrace\logs_correlation_trace_id() */
PHP_FUNCTION(DDTrace_logs_correlation_trace_id) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    datadog_trace_id trace_id = ddtrace_peek_trace_id();

    if (get_DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED()) {
        // The format of the injected trace id is conditional based on the higher-order 64 bits of the trace id
        uint64_t high = trace_id.high;
        if (high == 0) {
            // If zero, the injected trace id will be its decimal string encoding (preserving the current behavior of 64-bit TraceIds)
            RETURN_STR(datadog_trace_id_as_string(trace_id));
        } else {
            // The injected trace id will be encoded as 32 lower-case hexadecimal characters with zero-padding as necessary
            RETURN_STR(datadog_trace_id_as_hex_string(trace_id));
        }
    } else {
        // The injected trace id is the decimal encoding of the lower-order 64-bits of the trace id
        RETURN_STR(ddtrace_span_id_as_string(trace_id.low));
    }
}

/* {{{ proto array \DDTrace\current_context() */
PHP_FUNCTION(DDTrace_current_context) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    array_init(return_value);

    add_assoc_str_ex(return_value, ZEND_STRL("trace_id"), datadog_trace_id_as_string(ddtrace_peek_trace_id()));
    add_assoc_str_ex(return_value, ZEND_STRL("span_id"), ddtrace_span_id_as_string(ddtrace_peek_span_id()));

    zval zv;

    // Add Version
    ZVAL_STR_COPY(&zv, get_DD_VERSION());
    if (Z_STRLEN(zv) == 0) {
        zend_string_release(Z_STR(zv));
        ZVAL_NULL(&zv);
    }
    add_assoc_zval_ex(return_value, ZEND_STRL("version"), &zv);

    // Add Env
    ZVAL_STR_COPY(&zv, get_DD_ENV());
    if (Z_STRLEN(zv) == 0) {
        zend_string_release(Z_STR(zv));
        ZVAL_NULL(&zv);
    }
    add_assoc_zval_ex(return_value, ZEND_STRL("env"), &zv);

    if (DDTRACE_G(active_stack) && DDTRACE_G(active_stack)->active) {
        ddtrace_root_span_data *root = SPANDATA(DDTRACE_G(active_stack)->active)->root;
        zval *origin = &root->property_origin;
        if (Z_TYPE_P(origin) > IS_NULL && (Z_TYPE_P(origin) != IS_STRING || Z_STRLEN_P(origin))) {
            Z_TRY_ADDREF_P(origin);
            zend_hash_str_add_new(Z_ARR_P(return_value), ZEND_STRL("distributed_tracing_origin"), origin);
        }

        zval *parent_id = &root->property_parent_id;
        if (Z_TYPE_P(parent_id) == IS_STRING && Z_STRLEN_P(parent_id)) {
            Z_TRY_ADDREF_P(parent_id);
            zend_hash_str_add_new(Z_ARR_P(return_value), ZEND_STRL("distributed_tracing_parent_id"), parent_id);
        }
    } else {
        if (DDTRACE_G(dd_origin)) {
            add_assoc_str_ex(return_value, ZEND_STRL("distributed_tracing_origin"), zend_string_copy(DDTRACE_G(dd_origin)));
        }

        if (DDTRACE_G(distributed_parent_trace_id)) {
            add_assoc_str_ex(return_value, ZEND_STRL("distributed_tracing_parent_id"),
                             ddtrace_span_id_as_string(DDTRACE_G(distributed_parent_trace_id)));
        }
    }

    zval tags;
    array_init(&tags);
    if (get_DD_TRACE_ENABLED()) {
        ddtrace_get_propagated_tags(Z_ARR(tags));
    }
    add_assoc_zval_ex(return_value, ZEND_STRL("distributed_tracing_propagated_tags"), &tags);
}

/* {{{ proto bool set_distributed_tracing_context(string $trace_id, string $parent_id, ?string $origin, array|string|null $tags) */
PHP_FUNCTION(DDTrace_set_distributed_tracing_context) {
    zend_string *trace_id_str, *parent_id_str, *origin = NULL;
    zval *tags = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SS|S!z!", &trace_id_str, &parent_id_str, &origin, &tags) != SUCCESS) {
        RETURN_THROWS();
    }

    if (tags && Z_TYPE_P(tags) > IS_FALSE && Z_TYPE_P(tags) != IS_ARRAY && Z_TYPE_P(tags) != IS_STRING) {
        zend_type_error("DDTrace\\set_distributed_tracing_context expects parameter 4 to be of type array, string or null, %s given", zend_zval_value_name(tags));
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_FALSE;
    }

    datadog_trace_id new_trace_id;
    if (ZSTR_LEN(trace_id_str) == 1 && ZSTR_VAL(trace_id_str)[0] == '0') {
        new_trace_id = (datadog_trace_id){ 0 };
    } else if (!(new_trace_id = ddtrace_parse_userland_trace_id(trace_id_str)).low && !new_trace_id.high) {
        RETURN_FALSE;
    }

    zval parent_zv;
    ZVAL_STR(&parent_zv, parent_id_str);
    uint64_t new_parent_id;
    if (ZSTR_LEN(parent_id_str) == 1 && ZSTR_VAL(parent_id_str)[0] == '0') {
        new_parent_id = 0;
    } else if (!(new_parent_id = ddtrace_parse_userland_span_id(&parent_zv))) {
        RETURN_FALSE;
    }

    ddtrace_root_span_data *root_span = DDTRACE_G(active_stack)->root_span;
    if (root_span) {
        root_span->parent_id = new_parent_id;
        if (!new_trace_id.low && !new_trace_id.high) {
            root_span->trace_id = (datadog_trace_id) {
                .low = root_span->span_id,
                .time = get_DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED() ? root_span->start / ZEND_NANO_IN_SEC : 0,
            };
        } else {
            root_span->trace_id = new_trace_id;
        }
        ddtrace_update_root_id_properties(root_span);
    } else {
        DDTRACE_G(distributed_trace_id) = new_trace_id;
        DDTRACE_G(distributed_parent_trace_id) = new_parent_id;
    }

    if (origin) {
        if (root_span) {
            zval zv;
            ZVAL_STR_COPY(&zv, origin);
            datadog_assign_variable(&root_span->property_origin, &zv);
        } else {
            if (DDTRACE_G(dd_origin)) {
                zend_string_release(DDTRACE_G(dd_origin));
            }
            DDTRACE_G(dd_origin) = ZSTR_LEN(origin) ? zend_string_copy(origin) : NULL;
        }
    }

    if (tags) {
        zend_array *root_meta = &DDTRACE_G(root_span_tags_preset);
        zend_array *propagated_tags = &DDTRACE_G(propagated_root_span_tags);
        if (root_span) {
            root_meta = ddtrace_property_array(&root_span->property_meta);
            propagated_tags = ddtrace_property_array(&root_span->property_propagated_tags);
        }

        if (Z_TYPE_P(tags) == IS_STRING) {
            ddtrace_add_tracer_tags_from_header(Z_STR_P(tags), root_meta, propagated_tags);
        } else if (Z_TYPE_P(tags) == IS_ARRAY) {
            ddtrace_add_tracer_tags_from_array(Z_ARR_P(tags), root_meta, propagated_tags);
        }
    }

    RETURN_TRUE;
}

typedef struct {
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
} dd_fci_fcc_pair;

static bool dd_read_userspace_header(zai_str zai_header, const char *lowercase_header, zend_string **header_value, void *data) {
    UNUSED(zai_header);
    dd_fci_fcc_pair *func = (dd_fci_fcc_pair *) data;
    zval retval, arg;
    func->fci.params = &arg;
    ZVAL_STRING(&arg, lowercase_header);

    if (zend_call_function_with_return_value(&func->fci, &func->fcc, &retval) != SUCCESS || Z_TYPE(retval) <= IS_NULL) {
        zval_ptr_dtor(&arg);
        return false;
    }

    *header_value = zval_get_string(&retval);

    zval_ptr_dtor(&arg);
    zval_ptr_dtor(&retval);

    return true;
}

static bool parse_tracing_headers_common(INTERNAL_FUNCTION_PARAMETERS, dd_fci_fcc_pair *func, bool *use_server_headers, zend_array **array) {
    UNUSED(return_value);
    *use_server_headers = false;
    *array = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        DD_PARAM_PROLOGUE(0, 0);
        if (Z_TYPE_P(_arg) == IS_NULL) {
            *use_server_headers = true;
        } else if (UNEXPECTED(!zend_parse_arg_func(_arg, &func->fci, &func->fcc, false, &_error, true))) {
            if (!_error) {
                zend_argument_type_error(1, "must be a valid callback or of type array, %s given", zend_zval_value_name(_arg));
                _error_code = ZPP_ERROR_FAILURE;
                break;
            } else if (Z_TYPE_P(_arg) == IS_ARRAY) {
                *array = Z_ARR_P(_arg);
                efree(_error);
            } else {
                _error_code = ZPP_ERROR_WRONG_CALLBACK;
                break;
            }
#if PHP_VERSION_ID < 70300
        } else if (UNEXPECTED(_error != NULL)) {
#if PHP_VERSION_ID < 70200
            zend_wrong_callback_error(E_DEPRECATED, 1, _error);
#else
            zend_wrong_callback_error(_flags & ZEND_PARSE_PARAMS_THROW, E_DEPRECATED, 1, _error);
#endif
#endif
        }
    ZEND_PARSE_PARAMETERS_END_EX(return false);

    return true;
}

static ddtrace_distributed_tracing_result dd_parse_distributed_tracing_headers_function(INTERNAL_FUNCTION_PARAMETERS, bool *success) {
    dd_fci_fcc_pair func;
    bool use_server_headers;
    zend_array *array;

    *success = parse_tracing_headers_common(INTERNAL_FUNCTION_PARAM_PASSTHRU, &func, &use_server_headers, &array);
    if (!*success || !get_DD_TRACE_ENABLED()) {
        return (ddtrace_distributed_tracing_result){0};
    }

    func.fci.param_count = 1;

    if (array) {
        return ddtrace_read_distributed_tracing_ids(ddtrace_read_array_header, array);
    } else if (use_server_headers) {
        return ddtrace_read_distributed_tracing_ids(ddtrace_read_zai_header, &func);
    } else {
        return ddtrace_read_distributed_tracing_ids(dd_read_userspace_header, &func);
    }
}

static ddtrace_inferred_proxy_result dd_parse_inferred_proxy_headers_function(INTERNAL_FUNCTION_PARAMETERS, bool *success) {
    dd_fci_fcc_pair func;
    bool use_server_headers;
    zend_array *array;

    *success = parse_tracing_headers_common(INTERNAL_FUNCTION_PARAM_PASSTHRU, &func, &use_server_headers, &array);
    if (!*success || !get_DD_TRACE_ENABLED() || !get_DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED()) {
        return (ddtrace_inferred_proxy_result){0};
    }

    func.fci.param_count = 1;

    if (array) {
        return ddtrace_read_inferred_proxy_headers(ddtrace_read_array_header, array);
    } else if (use_server_headers) {
        return ddtrace_read_inferred_proxy_headers(ddtrace_read_zai_header, &func);
    } else {
        return ddtrace_read_inferred_proxy_headers(dd_read_userspace_header, &func);
    }
}

PHP_FUNCTION(DDTrace_consume_distributed_tracing_headers) {
    bool success;

    ddtrace_inferred_proxy_result inferred_result = dd_parse_inferred_proxy_headers_function(INTERNAL_FUNCTION_PARAM_PASSTHRU, &success);
    if (success && get_DD_TRACE_ENABLED() && DDTRACE_G(active_stack)->root_span && Z_TYPE(DDTRACE_G(active_stack)->root_span->property_inferred_span) != IS_OBJECT) {
        ddtrace_open_inferred_span(&inferred_result, DDTRACE_G(active_stack)->root_span);
    }

    ddtrace_distributed_tracing_result result = dd_parse_distributed_tracing_headers_function(INTERNAL_FUNCTION_PARAM_PASSTHRU, &success);
    if (success && get_DD_TRACE_ENABLED()) {
        ddtrace_apply_distributed_tracing_result(&result, DDTRACE_G(active_stack)->root_span);
    }

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_Internal_record_ffe_evaluation_metric) {
    zend_string *flag_key;
    zend_string *variant = NULL;
    zend_string *reason = NULL;
    zend_string *error_type = NULL;
    zend_string *allocation_key = NULL;

    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_STR(flag_key)
        Z_PARAM_STR_OR_NULL(variant)
        Z_PARAM_STR_OR_NULL(reason)
        Z_PARAM_STR_OR_NULL(error_type)
        Z_PARAM_STR_OR_NULL(allocation_key)
    ZEND_PARSE_PARAMETERS_END();

    RETURN_BOOL(ddtrace_ffe_record_evaluation_metric(
        flag_key,
        variant,
        reason ? ZSTR_VAL(reason) : NULL,
        error_type ? ZSTR_VAL(error_type) : NULL,
        allocation_key));
}

PHP_FUNCTION(DDTrace_Internal_flush_ffe_evaluation_metrics) {
    ZEND_PARSE_PARAMETERS_NONE();

    RETURN_BOOL(ddtrace_ffe_flush_evaluation_metrics());
}

/* {{{ proto array generate_distributed_tracing_headers() */
PHP_FUNCTION(DDTrace_generate_distributed_tracing_headers) {
    zend_array *inject = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT_EX(inject, true, false)
    ZEND_PARSE_PARAMETERS_END();

    array_init(return_value);
    if (get_DD_TRACE_ENABLED()) {
        if (inject) {
            zend_array *inject_set = zend_new_array(zend_hash_num_elements(inject));
            zval *val;
            ZEND_HASH_FOREACH_VAL(inject, val) {
                if (Z_TYPE_P(val) == IS_STRING) {
                    zend_hash_add_empty_element(inject_set, Z_STR_P(val));
                }
            } ZEND_HASH_FOREACH_END();
            ddtrace_inject_distributed_headers_config(Z_ARR_P(return_value), HEADER_MODE_KV_PAIRS, inject_set);
            zend_array_destroy(inject_set);
        } else {
            ddtrace_inject_distributed_headers(Z_ARR_P(return_value), HEADER_MODE_KV_PAIRS);
        }
    }
}

/* {{{ proto string dd_trace_closed_spans_count() */
PHP_FUNCTION(dd_trace_closed_spans_count) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_LONG(DDTRACE_G(closed_spans_count));
}

bool ddtrace_tracer_is_limited(void) {
    int64_t limit = get_DD_TRACE_SPANS_LIMIT();
    if (limit >= 0) {
        int64_t open_spans = DDTRACE_G(open_spans_count);
        int64_t closed_spans = DDTRACE_G(closed_spans_count);
        if ((open_spans + closed_spans) >= limit) {
            return true;
        }
    }
    return !ddtrace_is_memory_under_limit();
}

/* {{{ proto string dd_trace_tracer_is_limited() */
PHP_FUNCTION(dd_trace_tracer_is_limited) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_BOOL(ddtrace_tracer_is_limited() == true ? 1 : 0);
}

/* {{{ proto string dd_trace_compile_time_microseconds() */
PHP_FUNCTION(dd_trace_compile_time_microseconds) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    RETURN_LONG(ddtrace_compile_time_get());
}

PHP_FUNCTION(DDTrace_set_priority_sampling) {
    bool global = false;
    zend_long priority;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l|b", &priority, &global) == FAILURE) {
        RETURN_THROWS();
    }

    if (global || !DDTRACE_G(active_stack) || !DDTRACE_G(active_stack)->root_span) {
        DDTRACE_G(default_priority_sampling) = priority;
    } else {
        ddtrace_set_priority_sampling_on_root(priority, DD_MECHANISM_MANUAL);
    }
}

PHP_FUNCTION(DDTrace_get_priority_sampling) {
    zend_bool global = false;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|b", &global) == FAILURE) {
        RETURN_THROWS();
    }

    if (global || !DDTRACE_G(active_stack) || !DDTRACE_G(active_stack)->root_span) {
        RETURN_LONG(DDTRACE_G(default_priority_sampling));
    }

    RETURN_LONG(ddtrace_fetch_priority_sampling_from_root());
}

PHP_FUNCTION(DDTrace_get_sanitized_exception_trace) {
    zend_object *ex;
    zend_long skip = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJ_OF_CLASS(ex, zend_ce_throwable)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(skip)
    ZEND_PARSE_PARAMETERS_END();

    RETURN_STR(zai_get_trace_without_args_from_exception_skip_frames(ex, skip));
}

PHP_FUNCTION(DDTrace_collect_code_origins) {
    zend_long skip = 0;

    ZEND_PARSE_PARAMETERS_START(0, 1)
            Z_PARAM_OPTIONAL
            Z_PARAM_LONG(skip)
    ZEND_PARSE_PARAMETERS_END();

    if (!get_DD_CODE_ORIGIN_FOR_SPANS_ENABLED()) {
        return;
    }

    ddtrace_span_data *span = ddtrace_active_span();
    if (!span) {
        return;
    }

    ddtrace_add_code_origin_information(span, skip + 1 /* skip the collect call */);
}

PHP_FUNCTION(DDTrace_startup_logs) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    smart_str buf = {0};
    datadog_startup_logging_json(&buf, 0);
    ZVAL_NEW_STR(return_value, buf.s);
}

PHP_FUNCTION(DDTrace_find_active_exception) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    zend_object *ex = ddtrace_find_active_exception();
    if (ex) {
        RETURN_OBJ_COPY(ex);
    }
}

PHP_FUNCTION(DDTrace_extract_ip_from_headers) {
    zval *arr;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &arr) == FAILURE) {
        return;
    }

    zval meta;
    array_init(&meta);
    ddtrace_extract_ip_from_headers(arr, Z_ARR(meta));

    RETURN_ARR(Z_ARR(meta));
}

PHP_FUNCTION(DDTrace_curl_multi_exec_get_request_spans) {
    ZEND_PARSE_PARAMETERS_NONE();

    if (get_DD_TRACE_ENABLED()) {
        // Reset it, if it got corrupted
        if (DDTRACE_G(curl_multi_injecting_spans) && Z_TYPE(DDTRACE_G(curl_multi_injecting_spans)->val) != IS_ARRAY) {
            if (GC_DELREF(DDTRACE_G(curl_multi_injecting_spans)) == 0) {
                rc_dtor_func((zend_refcounted *) DDTRACE_G(curl_multi_injecting_spans));
            }
            DDTRACE_G(curl_multi_injecting_spans) = NULL;
        }

        if (!DDTRACE_G(curl_multi_injecting_spans)) {
            ZVAL_NEW_EMPTY_REF(return_value);
            ZVAL_EMPTY_ARRAY(Z_REFVAL_P(return_value));
            DDTRACE_G(curl_multi_injecting_spans) = Z_REF_P(return_value);
        } else {
            ZVAL_REF(return_value, DDTRACE_G(curl_multi_injecting_spans));
        }

        Z_ADDREF_P(return_value);
    } else {
        ZVAL_NEW_EMPTY_REF(return_value);
        ZVAL_EMPTY_ARRAY(Z_REFVAL_P(return_value));
    }
}

PHP_FUNCTION(DDTrace_resource_weak_store) {
    zval *rsrc, *value;
    zend_string *key;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_RESOURCE(rsrc)
        Z_PARAM_STR(key)
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();

    Z_TRY_ADDREF_P(value);
    ddtrace_weak_resource_update(Z_RES_P(rsrc), key, value);
}

PHP_FUNCTION(DDTrace_resource_weak_get) {
    zval *rsrc;
    zend_string *key;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_RESOURCE(rsrc)
        Z_PARAM_STR(key)
    ZEND_PARSE_PARAMETERS_END();

    zval *ret = ddtrace_weak_resource_get(Z_RES_P(rsrc), key);
    if (!ret) {
        RETURN_NULL();
    }
    RETURN_COPY(ret);
}
