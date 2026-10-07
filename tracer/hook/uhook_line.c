#include "../ddtrace.h"
#include "../configuration.h"
#include "../span.h"
#include <php.h>

#include <zend_closures.h>
#include <zend_exceptions.h>

#include <hook/line_hooks.h>
#include <sandbox/sandbox.h>

#include "uhook.h"
#include "uhook_line.h"

ZEND_EXTERN_MODULE_GLOBALS(datadog);

zend_class_entry *ddtrace_line_hook_data_ce;

/* One installed probe. Lives in the manager's aux slot and outlives any single invocation. */
typedef struct {
    zend_long id;
    dd_uhook_callback begin;
    dd_uhook_callback end;
    /* Number of this probe's callbacks on the stack.
       A count handles nested ends and fibers that finish out of order. */
    uint32_t running;
} dd_line_def;

/* Private fields precede zend_object because subclass properties extend its trailing property table. */
typedef struct {
    zend_execute_data *frame;
    zai_line_invocation *invocation;
    const zend_op *begin_opline;
    struct dd_line_frame_data *frame_state; /* shared by every range open in this frame */
    zval span;
    zval prior_stack; /* must stay adjacent to span: get_gc reports the pair */
    bool is_begin;
    bool opened; /* the manager holds an open range for this invocation */
    zend_object std;
    // first property is $data
    zval property_id;
    zval property_file;
    zval property_line;
} dd_line_hook_data;

/* Carried from a begin to its end. The manager allocates, zeroes and frees it. */
typedef struct {
    dd_line_hook_data *data;
} dd_line_dynamic;

/* Restore a frame's caller stack when its last line span closes. */
typedef struct dd_line_frame_data {
    zend_ulong key; /* its own slot in dd_line_frames */
    uint32_t spans;
    ddtrace_span_stack *caller_stack;
    ddtrace_span_stack *resume_stack;
} dd_line_frame_data;

ZEND_TLS HashTable dd_line_frames; /* frame or generator address -> dd_line_frame_data* */

/* Line spans are rare; skip the frame lookup entirely when none are open anywhere. */
ZEND_TLS uint32_t dd_line_active_spans;

static void dd_line_frame_data_free(zval *zv) {
    dd_line_frame_data *state = Z_PTR_P(zv);
    if (state->caller_stack) {
        OBJ_RELEASE(&state->caller_stack->std);
    }
    if (state->resume_stack) {
        OBJ_RELEASE(&state->resume_stack->std);
    }
    efree(state);
}

static dd_line_frame_data *dd_line_frame_get(zend_execute_data *frame, bool create) {
    if (!ZEND_USER_CODE(frame->func->type)) {
        return NULL;
    }
    /* PHP copies a generator's frame header during destruction; return_value still identifies the generator. */
    zend_ulong key = (zend_ulong)(uintptr_t)((frame->func->common.fn_flags & ZEND_ACC_GENERATOR) ? (void *)frame->return_value : (void *)frame);
    dd_line_frame_data *state = zend_hash_index_find_ptr(&dd_line_frames, key);
    if (!state && create) {
        state = ecalloc(1, sizeof(*state));
        state->key = key;
        zend_hash_index_add_ptr(&dd_line_frames, key, state);
    }
    return state;
}

static zend_object_handlers dd_line_hook_data_handlers;

static inline dd_line_hook_data *dd_line_hook_data_from_obj(zend_object *obj) {
    return (dd_line_hook_data *)((char *)obj - XtOffsetOf(dd_line_hook_data, std));
}

zend_object *ddtrace_line_hook_data_create(zend_class_entry *class_type) {
    dd_line_hook_data *data = ecalloc(1, XtOffsetOf(dd_line_hook_data, std) + sizeof(zend_object) + zend_object_properties_size(class_type));
    zend_object_std_init(&data->std, class_type);
    object_properties_init(&data->std, class_type);
    data->std.handlers = &dd_line_hook_data_handlers;
    return &data->std;
}

static void dd_line_hook_data_free(zend_object *object) {
    dd_line_hook_data *data = dd_line_hook_data_from_obj(object);
    zval_ptr_dtor(&data->span);
    zval_ptr_dtor(&data->prior_stack);
    zend_object_std_dtor(object);
}

#if PHP_VERSION_ID < 80000
static zend_object *dd_line_hook_data_clone(zval *old_zv) {
    zend_object *old = Z_OBJ_P(old_zv);
#else
static zend_object *dd_line_hook_data_clone(zend_object *old) {
#endif
    zend_object *clone = ddtrace_line_hook_data_create(old->ce);
    zend_objects_clone_members(clone, old);
    return clone;
}

#if PHP_VERSION_ID < 80000
static HashTable *dd_line_hook_data_gc(zval *object, zval **table, int *n) {
    dd_line_hook_data *data = dd_line_hook_data_from_obj(Z_OBJ_P(object));
#else
static HashTable *dd_line_hook_data_gc(zend_object *object, zval **table, int *n) {
    dd_line_hook_data *data = dd_line_hook_data_from_obj(object);
#endif
    *table = &data->span;
    *n = 2;
    return zend_std_get_properties(object);
}

ZEND_METHOD(DDTrace_LineHookData, var) {
    zend_string *name;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(name)
    ZEND_PARSE_PARAMETERS_END();

    dd_line_hook_data *data = dd_line_hook_data_from_obj(Z_OBJ_P(getThis()));
    HashTable *symbol_table = dd_uhook_symbol_table(data->frame);
    zval *var = symbol_table ? zend_hash_find_ind(symbol_table, name) : NULL;
    if (!var) {
        RETURN_NULL();
    }
    ZVAL_COPY_DEREF(return_value, var);
}

ZEND_METHOD(DDTrace_LineHookData, vars) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    dd_line_hook_data *data = dd_line_hook_data_from_obj(Z_OBJ_P(getThis()));
    array_init(return_value);
    HashTable *symbol_table = dd_uhook_symbol_table(data->frame);
    if (!symbol_table) {
        return;
    }

    zend_string *name;
    zval *var;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(symbol_table, name, var) {
        if (name) {
            zval value;
            ZVAL_COPY_DEREF(&value, var);
            zend_hash_update(Z_ARRVAL_P(return_value), name, &value);
        }
    } ZEND_HASH_FOREACH_END();
}

static bool dd_line_call(zend_execute_data *frame, dd_line_def *def, dd_uhook_callback *callback, dd_line_hook_data *data, uint32_t line, bool is_begin) {
    if (!callback->closure) {
        return true;
    }
    /* Suppress nested begins while either callback runs.
       Always deliver owed ends: the manager removes each instance before calling, so the same instance cannot close recursively. */
    if (is_begin && def->running) {
        return true;
    }
    ++def->running;

    zval rv;
    ZVAL_UNDEF(&rv);

    /* Expose the frame only during the callback; var() must not read it afterward. */
    data->frame = frame;
    data->is_begin = is_begin;
    /* The matching end reuses this object but reports its current location. */
    ZVAL_LONG(&data->property_line, line);

    zval arg;
    ZVAL_OBJ(&arg, &data->std);

    zval zclosure;
    ZVAL_OBJ(&zclosure, callback->closure);

    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
    char *error = NULL;
    /* Initialize fcc.closure so zend_call_function balances its closure reference. */
    if (zend_fcall_info_init(&zclosure, 0, &fci, &fcc, NULL, &error) != SUCCESS) {
        if (error) {
            efree(error);
        }
        data->frame = NULL;
        data->is_begin = false;
        --def->running;
        return true;
    }
    fci.retval = &rv;
    fci.param_count = 1;
    fci.params = &arg;

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);
    /* Contain callback exceptions and bailouts at the opline boundary. */
    if (!zai_sandbox_call(&sandbox, &fci, &fcc) || PG(last_error_message)) {
        dd_uhook_report_sandbox_error(frame, callback->closure);
    }

    bool keep_span = Z_TYPE(rv) != IS_FALSE;
    /* Destroy callback results inside the sandbox; their destructors can execute PHP. */
    volatile bool bailed = false;
    zend_try {
        zval_ptr_dtor(&rv);
    } zend_catch {
        bailed = true;
    } zend_end_try();
    if (bailed) {
        zai_sandbox_bailout(&sandbox);
    }

    zai_sandbox_close(&sandbox);

    data->frame = NULL;
    data->is_begin = false;
    --def->running;
    return keep_span;
}

static dd_line_hook_data *dd_line_hook_data_new(dd_line_def *def, zend_execute_data *frame, uint32_t line) {
    dd_line_hook_data *data = dd_line_hook_data_from_obj(ddtrace_line_hook_data_create(ddtrace_line_hook_data_ce));
    ZVAL_LONG(&data->property_id, def->id);
    ZVAL_LONG(&data->property_line, line);
    if (ZEND_USER_CODE(frame->func->type) && frame->func->op_array.filename) {
        ZVAL_STR_COPY(&data->property_file, frame->func->op_array.filename);
    } else {
        ZVAL_EMPTY_STRING(&data->property_file);
    }
    return data;
}

/* Begin-only probes acquire exit sites and a frame record only when they first create a span. */
static bool dd_line_track_span(dd_line_hook_data *data) {
    if (data->opened) {
        return true;
    }
    if (!zai_line_hooks_open_range(data->invocation)) {
        return false;
    }
    data->opened = true;
    return true;
}

static void dd_line_span(INTERNAL_FUNCTION_PARAMETERS, bool unlimited) {
    zval *parent = NULL;
#if PHP_VERSION_ID < 80000
    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_THROW, 0, 1)
#else
    ZEND_PARSE_PARAMETERS_START(0, 1)
#endif
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(parent)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_span_stack *stack = NULL;
    if (parent && Z_TYPE_P(parent) != IS_NULL) {
        if (Z_TYPE_P(parent) == IS_OBJECT && instanceof_function(Z_OBJCE_P(parent), ddtrace_ce_span_data)) {
            stack = OBJ_SPANDATA(Z_OBJ_P(parent))->stack;
        } else if (Z_TYPE_P(parent) == IS_OBJECT && Z_OBJCE_P(parent) == ddtrace_ce_span_stack) {
            stack = (ddtrace_span_stack *)Z_OBJ_P(parent);
        } else {
            zend_type_error("DDTrace\\LineHookData::%s(): Argument #1 ($parent) must be of type DDTrace\\SpanData|DDTrace\\SpanStack|null, %s given",
                            unlimited ? "unlimitedSpan" : "span", zend_zval_value_name(parent));
            return;
        }
    }

    dd_line_hook_data *data = dd_line_hook_data_from_obj(Z_OBJ_P(ZEND_THIS));
    if (Z_TYPE(data->span) == IS_OBJECT) {
        RETURN_COPY(&data->span);
    }

    dd_line_frame_data *state = NULL;
    if (data->is_begin && data->frame && get_DD_TRACE_ENABLED() && (unlimited || !ddtrace_tracer_is_limited()) && dd_line_track_span(data)) {
        state = dd_line_frame_get(data->frame, true);
        data->frame_state = state;
    }
    if (!state) {
        ZVAL_OBJ(&data->span, &ddtrace_init_dummy_span()->std);
        RETURN_COPY(&data->span);
    }

    bool generator = (data->frame->func->common.fn_flags & ZEND_ACC_GENERATOR) != 0;
    bool first_generator_span = generator && !state->caller_stack;
    if (first_generator_span) {
        state->caller_stack = DDTRACE_G(active_stack);
        GC_ADDREF(&state->caller_stack->std);
    }
    if (stack) {
        ZVAL_OBJ_COPY(&data->prior_stack, &DDTRACE_G(active_stack)->std);
        ddtrace_switch_span_stack(stack);
    }
    if (first_generator_span || (generator && stack)) {
        ddtrace_span_stack *generator_stack = ddtrace_init_span_stack();
        ddtrace_switch_span_stack(generator_stack);
        OBJ_RELEASE(&generator_stack->std);
    }

    ddtrace_span_data *span = ddtrace_open_span(DDTRACE_INTERNAL_SPAN);
    ZVAL_OBJ(&data->span, &span->std);
    ++state->spans;
    ++dd_line_active_spans;
    zval_ptr_dtor(&span->property_name);
    ZVAL_STR(&span->property_name, strpprintf(0, "%s:%u", ZSTR_VAL(data->frame->func->op_array.filename), data->begin_opline->lineno));
    ddtrace_observe_opened_span(span);
    RETURN_COPY(&data->span);
}

ZEND_METHOD(DDTrace_LineHookData, span) {
    dd_line_span(INTERNAL_FUNCTION_PARAM_PASSTHRU, false);
}

ZEND_METHOD(DDTrace_LineHookData, unlimitedSpan) {
    dd_line_span(INTERNAL_FUNCTION_PARAM_PASSTHRU, true);
}

static void dd_line_restore_caller(dd_line_frame_data *state) {
    if (state->caller_stack) {
        ddtrace_span_stack *stack = state->caller_stack;
        state->caller_stack = NULL;
        ddtrace_switch_span_stack(stack);
        OBJ_RELEASE(&stack->std);
    }
}

static void dd_line_finish_span(dd_line_hook_data *data, bool keep) {
    zval span_zv;
    ZVAL_COPY_VALUE(&span_zv, &data->span);
    ZVAL_UNDEF(&data->span);
    ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ(span_zv));
    if (span->start && !ddtrace_span_is_dropped(span) && span->type != DDTRACE_SPAN_CLOSED) {
        if (keep) {
            ddtrace_close_span(span);
        } else if (ddtrace_has_top_internal_span(span)) {
            ddtrace_drop_span(span);
        }
    }
    if (Z_TYPE(data->prior_stack) == IS_OBJECT) {
        zval prior_stack;
        ZVAL_COPY_VALUE(&prior_stack, &data->prior_stack);
        ZVAL_UNDEF(&data->prior_stack);
        ddtrace_switch_span_stack((ddtrace_span_stack *)Z_OBJ(prior_stack));
        zval_ptr_dtor(&prior_stack);
    }
    zval_ptr_dtor(&span_zv);
}

static bool dd_line_begin(zai_line_invocation *invocation, zend_execute_data *frame, const zend_op *opline, uint32_t line, void *auxiliary, void *dynamic) {
    dd_line_def *def = auxiliary;
    if (def->running) {
        /* A suppressed begin must not leave a range open: a generator resumed later could otherwise deliver an unmatched end.
           Declining makes the manager unwind the one it opened eagerly. */
        return false;
    }

    dd_line_hook_data *data = dd_line_hook_data_new(def, frame, line);
    data->invocation = invocation;
    data->begin_opline = opline;
    /* A probe with an end callback had its range opened before this ran; see the install below. */
    data->opened = def->end.closure != NULL;
    ((dd_line_dynamic *)dynamic)->data = data;

    dd_line_call(frame, def, &def->begin, data, line, true);
    data->invocation = NULL;

    if (!data->opened) {
        /* Begin-only, and the callback never asked for a span: nothing is owed, so release the object here rather than making the manager carry a third callback for it. */
        ((dd_line_dynamic *)dynamic)->data = NULL;
        OBJ_RELEASE(&data->std);
        return false;
    }
    return true;
}

static void dd_line_end(zend_execute_data *frame, uint32_t line, void *auxiliary, void *dynamic) {
    dd_line_def *def = auxiliary;
    dd_line_hook_data *data = ((dd_line_dynamic *)dynamic)->data;
    if (!data) {
        return;
    }

    bool real_span = false;
    if (Z_TYPE(data->span) == IS_OBJECT) {
        ddtrace_span_data *span = OBJ_SPANDATA(Z_OBJ(data->span));
        real_span = span->start != 0;
        if (real_span && !ddtrace_span_is_dropped(span) && span->type != DDTRACE_SPAN_CLOSED) {
            if (EG(exception) && Z_TYPE(span->property_exception) <= IS_FALSE) {
                ZVAL_OBJ_COPY(&span->property_exception, EG(exception));
            }
            dd_trace_stop_span_time(span);
        }
    }

    bool keep_span = dd_line_call(frame, def, &def->end, data, line, false);
    if (Z_TYPE(data->span) == IS_OBJECT) {
        dd_line_finish_span(data, keep_span);
    }

    dd_line_frame_data *state = data->frame_state;
    if (real_span) {
        if (state) {
            --state->spans;
        }
        --dd_line_active_spans;
    }
    if (state && !state->spans) {
        dd_line_restore_caller(state);
        /* Keep the record while a suspended generator still has a stack parked in it; the frame may resume and open another span.
           It goes at the latest with the request. */
        if (!state->resume_stack) {
            data->frame_state = NULL;
            zend_hash_index_del(&dd_line_frames, state->key);
        }
    }

    data->opened = false;
    OBJ_RELEASE(&data->std);
}

static void dd_line_suspend(dd_line_frame_data *state) {
    if (state && state->caller_stack) {
        state->resume_stack = DDTRACE_G(active_stack);
        GC_ADDREF(&state->resume_stack->std);
        dd_line_restore_caller(state);
    }
}

static void dd_line_resume(dd_line_frame_data *state) {
    if (state && state->resume_stack) {
        state->caller_stack = DDTRACE_G(active_stack);
        GC_ADDREF(&state->caller_stack->std);
        ddtrace_switch_span_stack(state->resume_stack);
        OBJ_RELEASE(&state->resume_stack->std);
        state->resume_stack = NULL;
    }
}

/* Delivered once per open range; repeated transitions are no-ops. */
static void dd_line_frame_suspend(zend_execute_data *frame, void *auxiliary, void *dynamic) {
    (void)frame, (void)auxiliary;
    dd_line_hook_data *data = ((dd_line_dynamic *)dynamic)->data;
    dd_line_suspend(data ? data->frame_state : NULL);
}

static void dd_line_frame_resume(zend_execute_data *frame, void *auxiliary, void *dynamic) {
    (void)frame, (void)auxiliary;
    dd_line_hook_data *data = ((dd_line_dynamic *)dynamic)->data;
    dd_line_resume(data ? data->frame_state : NULL);
}

void ddtrace_line_hook_data_minit(void) {
    dd_line_hook_data_handlers = *zend_get_std_object_handlers();
    dd_line_hook_data_handlers.offset = XtOffsetOf(dd_line_hook_data, std);
    dd_line_hook_data_handlers.free_obj = dd_line_hook_data_free;
    dd_line_hook_data_handlers.clone_obj = dd_line_hook_data_clone;
    dd_line_hook_data_handlers.get_gc = dd_line_hook_data_gc;
}

static void dd_line_def_free(void *ptr) {
    dd_line_def *def = ptr;
    dd_uhook_callback_destroy(&def->begin);
    dd_uhook_callback_destroy(&def->end);
    efree(def);
}

void ddtrace_uhook_line_rinit(void) {
    dd_line_active_spans = 0;
    zend_hash_init(&dd_line_frames, 8, NULL, dd_line_frame_data_free, 0);
    zai_line_hooks_rinit();
}

void ddtrace_uhook_line_rshutdown(void) {
    zai_line_hooks_rshutdown();
    zend_hash_destroy(&dd_line_frames);
}

void ddtrace_uhook_line_close_frame(zend_execute_data *frame) {
    if (!dd_line_active_spans) {
        return;
    }
    dd_line_frame_data *state = dd_line_frame_get(frame, false);
    if (state && state->spans) {
        zai_line_hooks_close_frame(frame);
    }
}

/* Any span held alive across a whole generator must attach it to the generators stack. */
void ddtrace_uhook_line_suspend_frame(zend_execute_data *frame) {
    if (dd_line_active_spans) {
        dd_line_suspend(dd_line_frame_get(frame, false));
    }
}

void ddtrace_uhook_line_resume_frame(zend_execute_data *frame) {
    if (dd_line_active_spans) {
        dd_line_resume(dd_line_frame_get(frame, false));
    }
}

bool ddtrace_uhook_line_remove(zend_long id) { return zai_line_hooks_remove(id); }

const char *ddtrace_uhook_line_conflict(zend_string *file, zend_long line, zend_long end_line) {
    return zai_line_hooks_conflict(file, (uint32_t)line, (uint32_t)end_line);
}

zend_long ddtrace_uhook_line_install(zend_string *file, zend_long line, zend_long end_line, zend_object *begin, zend_object *end) {
    dd_line_def *def = ecalloc(1, sizeof(*def));
    if (begin) {
        def->begin.closure = begin;
        GC_ADDREF(begin);
    }
    if (end) {
        def->end.closure = end;
        GC_ADDREF(end);
    }

    /* Only a probe with an end callback needs a range opened at every begin.
       A begin-only probe asks for one through zai_line_hooks_open_range() if it creates a span, and otherwise costs no exit sites. */
    zend_long id = zai_line_hooks_install_generator(file, (uint32_t)line, (uint32_t)end_line,
                                                   dd_line_begin, dd_line_frame_suspend, dd_line_frame_resume, dd_line_end,
                                                   end != NULL, ZAI_LINE_HOOKS_AUX(def, dd_line_def_free), sizeof(dd_line_dynamic));
    if (id) {
        def->id = id;
    }
    return id;
}
