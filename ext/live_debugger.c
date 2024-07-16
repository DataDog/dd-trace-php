#include "live_debugger.h"
#include "ddtrace.h"
#include "zai_string/string.h"
#include "span.h"
#include "hook/uhook.h"
#include "sidecar.h"
#include "hook/hook.h"
#include "serializer.h"
#include "configuration.h"
#include "compat_string.h"
#include "zend_interfaces.h"
#include "zend_hrtime.h"
#include "components-rs/common.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

struct eval_ctx {
    zend_execute_data *frame;
    zend_arena *arena;
    zval *retval;
    const ddog_CaptureConfiguration *config;
};

static void clean_ctx(struct eval_ctx *ctx) {
    if (ctx->arena) {
        zend_arena *arena = ctx->arena;
        do {
            zend_arena *prev = arena->prev;
            for (zval *cur = (zval *)((char *)arena + ZEND_MM_ALIGNED_SIZE(sizeof(zend_arena))); cur < (zval *)arena->ptr; ++cur) {
                zval_ptr_dtor(cur);
            }
            arena = prev;
        } while (arena);
        zend_arena_destroy(ctx->arena);
    }
}

static ddog_ConditionEvaluationResult dd_eval_condition(const ddog_ProbeCondition *condition, zval *retval) {
    ddog_CaptureConfiguration config = ddog_capture_defaults();
    struct eval_ctx ctx = {
        .frame = EG(current_execute_data),
        .arena = NULL,
        .retval = retval,
        .config = &config,
    };
    ddog_ConditionEvaluationResult result = ddog_evaluate_condition(condition, &ctx);
    clean_ctx(&ctx);
    return result;
}

static ddog_ValueEvaluationResult dd_eval_value(const ddog_ProbeValue *value, zval *retval) {
    ddog_CaptureConfiguration config = ddog_capture_defaults();
    struct eval_ctx ctx = {
        .frame = EG(current_execute_data),
        .arena = NULL,
        .retval = retval,
        .config = &config,
    };
    ddog_ValueEvaluationResult result = ddog_evaluate_value(value, &ctx);
    clean_ctx(&ctx);
    return result;
}

static zend_string *dd_eval_string(const ddog_DslString *string, const ddog_CaptureConfiguration *config, zval *retval, ddog_Vec_SnapshotEvaluationError **error) {
    struct eval_ctx ctx = {
        .frame = EG(current_execute_data),
        .arena = NULL,
        .retval = retval,
        .config = config,
    };
    ddog_VoidCollection bytes = ddog_evaluate_unmanaged_string(string, &ctx, error);
    zend_string *str = zend_string_init(bytes.elements, bytes.count, 0);
    bytes.free(bytes);
    clean_ctx(&ctx);
    return str;
}

static zval *dd_persist_eval_arena(struct eval_ctx *eval_ctx, zval *zv) {
    if (!eval_ctx->arena) {
        eval_ctx->arena = zend_arena_create(4096);
    }
    zval *zvp = zend_arena_alloc(&eval_ctx->arena, sizeof(zval));
    ZVAL_COPY_VALUE(zvp, zv);
    return zvp;
}

static ddog_CharSlice dd_persist_str_eval_arena(struct eval_ctx *eval_ctx, zend_string *str) {
    zval zv;
    ZVAL_STR(&zv, str);
    if (Z_REFCOUNTED(zv)) {
        if (Z_REFCOUNT(zv) == 1) {
            dd_persist_eval_arena(eval_ctx, &zv);
        } else {
            Z_DELREF(zv);
        }
    }
    return dd_zend_string_to_CharSlice(Z_STR(zv));
}

typedef struct {
    ddtrace_span_data *span;
} dd_span_probe_dynamic;

typedef struct {
    ddog_Probe probe;
    zend_string *function;
    zend_string *scope;
    zend_string *file;
    zend_string *probe_id;
} dd_probe_def;

static bool dd_probe_file_mismatch(dd_probe_def *def, zend_execute_data *execute_data) {
    return def->file && (!execute_data->func->op_array.filename || !ddtrace_uhook_match_filepath(execute_data->func->op_array.filename, def->file));
}

static void dd_probe_dtor(void *data) {
    dd_probe_def *def = data;
    if (def->probe.probe.tag == DDOG_PROBE_TYPE_SPAN_DECORATION) {
        drop_span_decoration_probe(def->probe.probe.span_decoration);
    }
    if (def->file) {
        zend_string_release(def->file);
    }
    if (def->scope) {
        zend_string_release(def->scope);
    }
    if (def->function) {
        zend_string_release(def->function);
    }
    zend_string_release(def->probe_id);
    efree(def);
}

static void dd_probe_resolved(void *data, bool found) {
    dd_probe_def *def = data;
    if (found) {
        def->probe.status = DDOG_PROBE_STATUS_INSTALLED;
    } else {
        def->probe.status = DDOG_PROBE_STATUS_ERROR;
        def->probe.status_msg = DDOG_CHARSLICE_C("Method does not exist on the given class");
        def->probe.status_exception = DDOG_CHARSLICE_C("METHOD_NOT_FOUND");
    }
    ddog_send_debugger_diagnostics(DDTRACE_G(remote_config_state), &ddtrace_sidecar, ddtrace_sidecar_instance_id, DDTRACE_G(telemetry_queue_id), &def->probe, ddtrace_nanoseconds_realtime());
}

static int64_t dd_init_live_debugger_probe(const ddog_Probe *probe, dd_probe_def *def, zai_hook_begin begin, zai_hook_end end, void (*def_dtor)(void *), size_t dynamic) {
    def->probe = *probe;
    def->probe_id = dd_CharSlice_to_zend_string(probe->id);
    def->file = NULL;
    def->function = NULL;
    def->scope = NULL;

    const ddog_ProbeTarget *target = &probe->target;
    if (target->type_name.len) {
        if (!ddog_type_can_be_instrumented(DDTRACE_G(remote_config_state), target->type_name)) {
            def->probe.status = DDOG_PROBE_STATUS_BLOCKED;
            goto error;
        }

        def->scope = dd_CharSlice_to_zend_string(target->type_name);
    }
    if (target->method_name.len) {
        def->function = dd_CharSlice_to_zend_string(target->method_name);
    } else if (target->source_file.len) {
        def->file = dd_CharSlice_to_zend_string(target->source_file);
    } else {
        def->probe.status = DDOG_PROBE_STATUS_ERROR;
        def->probe.status_msg = DDOG_CHARSLICE_C("Target is not supported");
        def->probe.status_exception = DDOG_CHARSLICE_C("UNSUPPORTED_TARGET");
        goto error;
    }

    zend_long id = zai_hook_install(
            def->scope ? (zai_str) ZAI_STR_FROM_ZSTR(def->scope) : (zai_str) ZAI_STR_EMPTY,
            def->function ? (zai_str) ZAI_STR_FROM_ZSTR(def->function) : (zai_str) ZAI_STR_EMPTY,
            begin,
            end,
            ZAI_HOOK_AUX_RESOLVED(def, def_dtor, dd_probe_resolved),
            dynamic);

    if (id < 0) {
        def->probe.status = DDOG_PROBE_STATUS_ERROR;
        def->probe.status_msg = DDOG_CHARSLICE_C("Method does not exist on the given class");
        def->probe.status_exception = DDOG_CHARSLICE_C("METHOD_NOT_FOUND");
error:
        ddog_send_debugger_diagnostics(DDTRACE_G(remote_config_state), &ddtrace_sidecar, ddtrace_sidecar_instance_id, DDTRACE_G(telemetry_queue_id), &def->probe, ddtrace_nanoseconds_realtime());
        def_dtor(def);
        return -1;
    }

    if (def->probe.status != DDOG_PROBE_STATUS_INSTALLED) {
        def->probe.status = DDOG_PROBE_STATUS_RECEIVED;
        ddog_send_debugger_diagnostics(DDTRACE_G(remote_config_state), &ddtrace_sidecar, ddtrace_sidecar_instance_id, DDTRACE_G(telemetry_queue_id), &def->probe, ddtrace_nanoseconds_realtime());
    }

    zend_hash_index_add_new_ptr(&DDTRACE_G(active_rc_hooks), id, def);
    return id;
}

static void dd_probe_mark_active(dd_probe_def *def) {
    if (def->probe.status != DDOG_PROBE_STATUS_EMITTING) {
        def->probe.status = DDOG_PROBE_STATUS_EMITTING;
        ddog_send_debugger_diagnostics(DDTRACE_G(remote_config_state), &ddtrace_sidecar, ddtrace_sidecar_instance_id, DDTRACE_G(telemetry_queue_id), &def->probe, ddtrace_nanoseconds_realtime());
    }
}

static bool dd_span_probe_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_probe_def *def = auxiliary;
    dd_span_probe_dynamic *dyn = dynamic;

    if (dd_probe_file_mismatch(def, execute_data)) {
        dyn->span = NULL;
        return true;
    }

    dd_probe_mark_active(def);

    dyn->span = ddtrace_alloc_execute_data_span(invocation, execute_data);

    zval garbage;
    ZVAL_COPY_VALUE(&garbage, &dyn->span->property_resource);
    ZVAL_COPY_VALUE(&dyn->span->property_resource, &dyn->span->property_name);
    ZVAL_STRING(&dyn->span->property_name, "dd.dynamic.span");
    zval_ptr_dtor(&garbage);

    zval probe_id;
    ZVAL_STR_COPY(&probe_id, def->probe_id);
    zend_hash_str_update(ddtrace_property_array(&dyn->span->property_meta), ZEND_STRL("debugger.probeid"), &probe_id);

    return true;
}

static void dd_span_probe_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_span_probe_dynamic *dyn = dynamic;

    UNUSED(execute_data, retval, auxiliary);

    if (dyn->span) {
        ddtrace_clear_execute_data_span(invocation, true);
    }
}

static int64_t dd_set_span_probe(const ddog_Probe *probe) {
    dd_probe_def *def = emalloc(sizeof(*def));
    return dd_init_live_debugger_probe(probe, def, dd_span_probe_begin, dd_span_probe_end, dd_probe_dtor, sizeof(dd_span_probe_dynamic));
}

static void dd_submit_probe_eval_error_snapshot(const ddog_Probe *probe, ddog_Vec_SnapshotEvaluationError *error) {
    zend_string *service_name = ddtrace_active_service_name();
    ddog_DebuggerPayload *snapshot = ddog_evaluation_error_snapshot(probe,
                                                                    (ddog_CharSlice){ .ptr = ZSTR_VAL(service_name), .len = ZSTR_LEN(service_name) },
                                                                    DDOG_CHARSLICE_C("php"),
                                                                    error,
                                                                    ddtrace_nanoseconds_realtime());
    ddtrace_sidecar_send_debugger_datum(snapshot);
    zend_string_release(service_name);
}

static void dd_span_decoration_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_probe_def *def = auxiliary;
    ddtrace_span_data *span = ddtrace_active_span();
    if (!span) {
        return;
    }
    UNUSED(invocation, dynamic);
    if (dd_probe_file_mismatch(def, execute_data)) {
        return;
    }

    dd_probe_mark_active(def);

    if (def->probe.probe.span_decoration.target == DDOG_SPAN_PROBE_TARGET_ROOT) {
        span = &span->stack->root_span->span;
    }
    zend_array *meta = ddtrace_property_array(&span->property_meta);

    bool condition_result = true;
    const ddog_ProbeCondition *const *condition = def->probe.probe.span_decoration.conditions;
    for (uintptr_t i = 0; i < def->probe.probe.span_decoration.span_tags_num; ++i) {
        const ddog_SpanProbeTag *spanTag = def->probe.probe.span_decoration.span_tags + i;
        if (spanTag->next_condition) {
            ddog_ConditionEvaluationResult result = dd_eval_condition(*(condition++), retval);
            if (result.tag == DDOG_CONDITION_EVALUATION_RESULT_ERROR) {
                dd_submit_probe_eval_error_snapshot(&def->probe, result.error);
                condition_result = false;
            } else {
                condition_result = result.tag == DDOG_CONDITION_EVALUATION_RESULT_SUCCESS;
            }
        }
        if (condition_result) {
            zval zv;
            ddog_Vec_SnapshotEvaluationError *error;
            ddog_CaptureConfiguration config_defaults = ddog_capture_defaults();
            ZVAL_STR(&zv, dd_eval_string(spanTag->tag.value, &config_defaults, retval, &error));
            zend_hash_str_update(meta, spanTag->tag.name.ptr, spanTag->tag.name.len, &zv);

            zend_string *tag = zend_strpprintf(0, "_dd.di.%.*s.probe_id", (int)spanTag->tag.name.len, spanTag->tag.name.ptr);
            ZVAL_STR_COPY(&zv, def->probe_id);
            zend_hash_update(meta, tag, &zv);
            zend_string_release(tag);

            if (error) {
                ddog_CharSlice msg = ddog_evaluation_error_first_msg(error);

                tag = zend_strpprintf(0, "_dd.di.%.*s.evaluation_error", (int)spanTag->tag.name.len, spanTag->tag.name.ptr);
                ZVAL_STR(&zv, dd_CharSlice_to_zend_string(msg));
                zend_hash_update(meta, tag, &zv);
                zend_string_release(tag);

                ddog_evaluation_error_drop(error);
            }
        }
    }
}

static bool dd_span_decoration_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    zval retval;
    ZVAL_NULL(&retval);
    dd_span_decoration_end(invocation, execute_data, &retval, auxiliary, dynamic);
    return true;
}

static int64_t dd_set_span_decoration(const ddog_Probe *probe) {
    dd_probe_def *def = emalloc(sizeof(*def));

    zai_hook_begin begin = NULL;
    zai_hook_end end = NULL;
    if (probe->target.in_body_location == DDOG_IN_BODY_LOCATION_START) {
        begin = dd_span_decoration_begin;
    } else {
        end = dd_span_decoration_end;
    }
    return dd_init_live_debugger_probe(probe, def, begin, end, dd_probe_dtor, 0);
}

typedef struct {
    dd_probe_def parent;
    const ddog_MaybeShmLimiter *limiter;
} dd_log_probe_def;

typedef struct {
    bool rejected;
    ddog_DebuggerPayload *payload;
    zend_string *service;
    zend_arena *capture_arena;
} dd_log_probe_dyn;

static bool dd_log_probe_eval_condition(dd_log_probe_def *def, zend_execute_data *execute_data, zval *retval) {
    if (dd_probe_file_mismatch(&def->parent, execute_data)) {
        return false;
    }

    ddog_ConditionEvaluationResult condition = dd_eval_condition(def->parent.probe.probe.log.when, retval);
    switch (condition.tag) {
        case DDOG_CONDITION_EVALUATION_RESULT_SUCCESS:
            return ddog_shm_limiter_inc(def->limiter, def->parent.probe.probe.log.sampling_snapshots_per_second) && ddog_global_log_probe_limiter_inc(DDTRACE_G(remote_config_state));
        case DDOG_CONDITION_EVALUATION_RESULT_ERROR:
            dd_submit_probe_eval_error_snapshot(&def->parent.probe, condition.error);
            break;
        case DDOG_CONDITION_EVALUATION_RESULT_FAILURE:
            break;
    }
    return false;
}

static void dd_log_probe_ensure_payload(dd_log_probe_dyn *dyn, dd_log_probe_def *def, ddog_CharSlice *msg) {
    if (dyn->payload) {
        ddog_update_payload_message(dyn->payload, *msg);
    } else {
        dyn->service = ddtrace_active_service_name();
        dyn->payload = ddog_create_log_probe_snapshot(&def->parent.probe, msg, dd_zend_string_to_CharSlice(dyn->service), DDOG_CHARSLICE_C("php"), ddtrace_nanoseconds_realtime());
    }
}

static void dd_log_probe_capture_snapshot(ddog_DebuggerCapture *capture, dd_log_probe_def *def, zend_execute_data *execute_data) {
    const ddog_CaptureConfiguration *capture_config = def->parent.probe.probe.log.capture;
    if (ZEND_USER_CODE(EX(func)->type)) {
        zend_array *symbol_table = zend_rebuild_symbol_table();
        zend_string *symbol;
        zval *variable;
        ZEND_HASH_FOREACH_STR_KEY_VAL_IND(symbol_table, symbol, variable) {
            if (symbol) {
                struct ddog_CaptureValue capture_value = {0};
                ddog_CharSlice name_slice = dd_zend_string_to_CharSlice(symbol);
                ddtrace_snapshot_redacted_name(&capture_value, name_slice);
                ddtrace_create_capture_value(variable, &capture_value, capture_config, capture_config->max_reference_depth);
                ddog_FieldType type = EX_VAR_NUM(0) <= variable && variable < EX_VAR_NUM(EX(func)->op_array.num_args) ? DDOG_FIELD_TYPE_ARG : DDOG_FIELD_TYPE_LOCAL;
                ddog_snapshot_add_field(capture, type, name_slice, capture_value);
            }
        } ZEND_HASH_FOREACH_END();
    } else if (EX(func)->internal_function.arg_info) {
        uint32_t num_args = EX(func)->internal_function.num_args;
        for (uintptr_t i = 0; i < num_args; ++i) {
            const char *name = EX(func)->internal_function.arg_info[i].name;
            ddog_CharSlice name_slice = { .len = strlen(name), .ptr = name };
            struct ddog_CaptureValue capture_value = {0};
            ddtrace_snapshot_redacted_name(&capture_value, name_slice);
            ddtrace_create_capture_value(EX_VAR_NUM(i), &capture_value, capture_config, capture_config->max_reference_depth);
            ddog_snapshot_add_field(capture, DDOG_FIELD_TYPE_ARG, name_slice, capture_value);
        }
    }
    if (hasThis()) {
        struct ddog_CaptureValue capture_value = {0};
        ddtrace_create_capture_value(&EX(This), &capture_value, capture_config, capture_config->max_reference_depth);
        ddog_snapshot_add_field(capture, DDOG_FIELD_TYPE_ARG, DDOG_CHARSLICE_C("this"), capture_value);
    }
}

static void dd_log_probe_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_log_probe_dyn *dyn = dynamic;
    dd_log_probe_def *def = auxiliary;
    UNUSED(invocation);

    if (dyn->rejected) {
        return;
    }

    if (def->parent.probe.evaluate_at == DDOG_EVALUATE_AT_EXIT && !dd_log_probe_eval_condition(def, execute_data, retval)) {
        return;
    }

    ddog_Vec_SnapshotEvaluationError *errors;
    zend_string *result = dd_eval_string(def->parent.probe.probe.log.segments, def->parent.probe.probe.log.capture, retval, &errors);
    if (errors) {
        dd_submit_probe_eval_error_snapshot(&def->parent.probe, errors);
    }
    ddog_CharSlice result_msg = dd_zend_string_to_CharSlice(result);
    dd_log_probe_ensure_payload(dyn, def, &result_msg);

    if (def->parent.probe.probe.log.capture_snapshot) {
        DDTRACE_G(debugger_capture_arena) = dyn->capture_arena ? dyn->capture_arena : zend_arena_create(65536);
        ddog_DebuggerCapture *capture = ddog_snapshot_exit(dyn->payload);
        dd_log_probe_capture_snapshot(capture, def, execute_data);
        const ddog_CaptureConfiguration *capture_config = def->parent.probe.probe.log.capture;
        struct ddog_CaptureValue capture_value = {0};
        ddtrace_create_capture_value(&EX(This), &capture_value, capture_config, capture_config->max_reference_depth);
        ddog_snapshot_add_field(capture, DDOG_FIELD_TYPE_ARG, DDOG_CHARSLICE_C("@return"), capture_value);
    }
    ddtrace_sidecar_send_debugger_datum(dyn->payload);
    if (DDTRACE_G(debugger_capture_arena)) {
        zend_arena_destroy(DDTRACE_G(debugger_capture_arena));
        DDTRACE_G(debugger_capture_arena) = NULL;
    }
    zend_string_release(result);
    zend_string_release(dyn->service);
}

static bool dd_log_probe_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_log_probe_dyn *dyn = dynamic;
    dd_log_probe_def *def = auxiliary;
    UNUSED(invocation);

    dd_probe_mark_active(&def->parent);

    zval retval;
    ZVAL_NULL(&retval);

    dyn->payload = NULL;
    dyn->rejected = def->parent.probe.evaluate_at == DDOG_EVALUATE_AT_ENTRY && !dd_log_probe_eval_condition(def, execute_data, &retval);
    dyn->capture_arena = NULL;

    if (!dyn->rejected && def->parent.probe.evaluate_at == DDOG_EVALUATE_AT_ENTRY) {
        dd_log_probe_ensure_payload(dyn, def, NULL);
        if (def->parent.probe.probe.log.capture_snapshot) {
            ddog_DebuggerCapture *capture = ddog_snapshot_entry(dyn->payload);
            DDTRACE_G(debugger_capture_arena) = zend_arena_create(65536);
            dd_log_probe_capture_snapshot(capture, def, execute_data);
            dyn->capture_arena = DDTRACE_G(debugger_capture_arena);
            DDTRACE_G(debugger_capture_arena) = NULL;
        }
    }

    return true;
}

static int64_t dd_set_log_probe(const ddog_Probe *probe, const ddog_MaybeShmLimiter *limiter) {
    dd_log_probe_def *def = emalloc(sizeof(*def));
    def->limiter = limiter;
    return dd_init_live_debugger_probe(probe, &def->parent, dd_log_probe_begin, dd_log_probe_end, dd_probe_dtor, sizeof(dd_log_probe_dyn));
}

static void dd_metric_probe_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_probe_def *def = auxiliary;
    UNUSED(invocation, dynamic);
    if (dd_probe_file_mismatch(def, execute_data)) {
        return;
    }

    dd_probe_mark_active(def);

    ddog_CharSlice *name = &def->probe.probe.metric.name;
    zend_string *metric_name = zend_strpprintf(0, "dynamic.instrumentation.metric.probe.%.*s", (int)name->len, name->ptr);

    ddog_ValueEvaluationResult result = dd_eval_value(def->probe.probe.metric.value, retval);
    if (result.tag == DDOG_VALUE_EVALUATION_RESULT_ERROR) {
        dd_submit_probe_eval_error_snapshot(&def->probe, result.error);
        return;
    }

    ddog_IntermediateValue value = ddog_evaluated_value_get(result.success);
    double metric_value = 0;
    switch (value.tag) {
        case DDOG_INTERMEDIATE_VALUE_NULL:
            break;
        case DDOG_INTERMEDIATE_VALUE_STRING: ;
            zend_string *str = dd_CharSlice_to_zend_string(value.string);
            metric_value = zend_strtod(ZSTR_VAL(str), NULL);
            zend_string_release(str);
            break;
        case DDOG_INTERMEDIATE_VALUE_NUMBER:
            metric_value = value.number;
            break;
        case DDOG_INTERMEDIATE_VALUE_BOOL:
            metric_value = value.bool_;
            break;
        case DDOG_INTERMEDIATE_VALUE_REFERENCED:
            metric_value = zval_get_double(value.referenced);
            break;
    }

    switch (def->probe.probe.metric.kind) {
        case DDOG_METRIC_KIND_COUNT:
            ddtrace_sidecar_dogstatsd_count(metric_name, (zend_long)metric_value, NULL);
            break;
        case DDOG_METRIC_KIND_GAUGE:
            ddtrace_sidecar_dogstatsd_gauge(metric_name, metric_value, NULL);
            break;
        case DDOG_METRIC_KIND_HISTOGRAM:
            ddtrace_sidecar_dogstatsd_histogram(metric_name, metric_value, NULL);
            break;
        case DDOG_METRIC_KIND_DISTRIBUTION:
            ddtrace_sidecar_dogstatsd_distribution(metric_name, metric_value, NULL);
            break;
    }

    ddog_evaluated_value_drop(result.success);
    zend_string_release(metric_name);
}

static bool dd_metric_probe_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    zval retval;
    ZVAL_NULL(&retval);
    dd_metric_probe_end(invocation, execute_data, &retval, auxiliary, dynamic);
    return true;
}

static int64_t dd_set_metric_probe(const ddog_Probe *probe) {
    dd_probe_def *def = emalloc(sizeof(*def));

    zai_hook_begin begin = NULL;
    zai_hook_end end = NULL;
    if (probe->target.in_body_location == DDOG_IN_BODY_LOCATION_START) {
        begin = dd_metric_probe_begin;
    } else {
        end = dd_metric_probe_end;
    }
    return dd_init_live_debugger_probe(probe, def, begin, end, dd_probe_dtor, 0);
}

static int64_t dd_set_probe(const ddog_Probe probe, const ddog_MaybeShmLimiter *limiter) {
    switch (probe.probe.tag) {
        case DDOG_PROBE_TYPE_METRIC:
            return dd_set_metric_probe(&probe);
        case DDOG_PROBE_TYPE_LOG:
            return dd_set_log_probe(&probe, limiter);
        case DDOG_PROBE_TYPE_SPAN:
            return dd_set_span_probe(&probe);
        case DDOG_PROBE_TYPE_SPAN_DECORATION:
            return dd_set_span_decoration(&probe);
    }
    return -1;
}

static void dd_remove_live_debugger_probe(int64_t id) {
    dd_probe_def *def;
    if ((def = zend_hash_index_find_ptr(&DDTRACE_G(active_rc_hooks), (zend_ulong)id))) {
        zai_hook_remove(
                def->scope ? (zai_str)ZAI_STR_FROM_ZSTR(def->scope) : (zai_str)ZAI_STR_EMPTY,
                def->function ? (zai_str)ZAI_STR_FROM_ZSTR(def->function) : (zai_str)ZAI_STR_EMPTY,
                id);
    }
}

static void dd_free_void_collection_none(struct ddog_VoidCollection collection) {
    UNUSED(collection);
}

static ddog_VoidCollection dd_empty_collection = {
    .free = dd_free_void_collection_none,
    .count = 0,
    .elements = NULL,
};

static void dd_free_void_collection(struct ddog_VoidCollection collection) {
    efree((void *)collection.elements);
}

static ddog_VoidCollection dd_alloc_void_collection(uint32_t elements) {
    return (ddog_VoidCollection){
        .free = dd_free_void_collection,
        .count = elements,
        .elements = emalloc(sizeof(void *)),
    };
}

static void dd_intermediate_to_zval(struct ddog_IntermediateValue val, zval *zv) {
    switch (val.tag) {
        case DDOG_INTERMEDIATE_VALUE_STRING:
            ZVAL_STRINGL(zv, val.string.ptr, val.string.len);
            break;
        case DDOG_INTERMEDIATE_VALUE_NUMBER:
            ZVAL_DOUBLE(zv, val.number);
            break;
        case DDOG_INTERMEDIATE_VALUE_BOOL:
            ZVAL_BOOL(zv, val.bool_);
            break;
        case DDOG_INTERMEDIATE_VALUE_NULL:
            ZVAL_NULL(zv);
            break;
        case DDOG_INTERMEDIATE_VALUE_REFERENCED:
            ZVAL_COPY(zv, val.referenced);
            break;
    }
}

static zend_long dd_zval_convert_index(zval *zvp, bool *success) {
    zval *dim = (zval *) zvp;

    ZVAL_DEREF(dim);
    switch (Z_TYPE_P(dim)) {
        case IS_LONG:
            *success = true;
            return Z_LVAL_P(dim);
        case IS_STRING: ;
            zend_long off;
            *success = IS_LONG == is_numeric_string_ex(Z_STRVAL_P(dim), Z_STRLEN_P(dim), &off, NULL, true, NULL, NULL);
            return off;
        default:
            *success = false;
            return 0;
    }
}

static inline int dd_eval_cmp(struct ddog_IntermediateValue a, struct ddog_IntermediateValue b) {
    zval zva, zvb;
    dd_intermediate_to_zval(a, &zva);
    dd_intermediate_to_zval(b, &zvb);

    bool objectA = Z_TYPE(zva) == IS_OBJECT || (Z_TYPE(zva) == IS_REFERENCE && Z_TYPE_P(Z_REFVAL(zva)) == IS_OBJECT);
    bool objectB = Z_TYPE(zvb) == IS_OBJECT || (Z_TYPE(zvb) == IS_REFERENCE && Z_TYPE_P(Z_REFVAL(zvb)) == IS_OBJECT);

    int ret;
    if (objectA != objectB) {
        // Avoid object casting, which may lead to notices
        ret = objectA - objectB;
    } else {
        ret = zend_compare(&zva, &zvb);
    }

    zval_ptr_dtor(&zva);
    zval_ptr_dtor(&zvb);

    return ret;
}

static bool dd_eval_equals(void *ctx, struct ddog_IntermediateValue a, struct ddog_IntermediateValue b) {
    UNUSED(ctx);
#define TAGCASE(a, b) MIN(a, b) + (MAX(a, b) << 4)
    switch (TAGCASE(a.tag, b.tag)) {
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_STRING, DDOG_INTERMEDIATE_VALUE_STRING):
            return a.string.len == b.string.len && memcmp(a.string.ptr, b.string.ptr, a.string.len) == 0;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_NUMBER, DDOG_INTERMEDIATE_VALUE_NUMBER):
            return a.number == b.number;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_BOOL, DDOG_INTERMEDIATE_VALUE_BOOL):
            return a.bool_ == b.bool_;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_BOOL, DDOG_INTERMEDIATE_VALUE_NUMBER):
            return a.tag == DDOG_INTERMEDIATE_VALUE_BOOL ? a.number == b.bool_ : a.bool_ == b.number;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_NULL, DDOG_INTERMEDIATE_VALUE_NULL):
            return true;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_NULL, DDOG_INTERMEDIATE_VALUE_BOOL):
            return (b.tag == DDOG_INTERMEDIATE_VALUE_NULL ? a.bool_ : b.bool_) == false;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_NUMBER, DDOG_INTERMEDIATE_VALUE_NULL):
            return (b.tag == DDOG_INTERMEDIATE_VALUE_NULL ? a.number : b.number) == 0;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_STRING, DDOG_INTERMEDIATE_VALUE_NULL):
            return (b.tag == DDOG_INTERMEDIATE_VALUE_NULL ? a.string : b.string).len == 0;
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_STRING, DDOG_INTERMEDIATE_VALUE_BOOL):
            return ((b.tag == DDOG_INTERMEDIATE_VALUE_BOOL ? a.string : b.string).len == 0) != (b.tag != DDOG_INTERMEDIATE_VALUE_BOOL ? a.bool_ : b.bool_);
        case TAGCASE(DDOG_INTERMEDIATE_VALUE_STRING, DDOG_INTERMEDIATE_VALUE_REFERENCED): ;
            // avoid copies for ref == str
            const zval *zv = a.tag == DDOG_INTERMEDIATE_VALUE_REFERENCED ? a.referenced : b.referenced;
            if (Z_TYPE_P(zv) == IS_STRING) {
                ddog_CharSlice *str = a.tag == DDOG_INTERMEDIATE_VALUE_REFERENCED ? &b.string : &a.string;
                return zend_string_equals_cstr(Z_STR_P(zv), str->ptr, str->len);
            }
    }

    return dd_eval_cmp(a, b) == 0;
}

static bool dd_eval_greater_than(void *ctx, struct ddog_IntermediateValue a, struct ddog_IntermediateValue b) {
    UNUSED(ctx);
    return dd_eval_cmp(a, b) > 0;
}

static bool dd_eval_greater_or_equals(void *ctx, struct ddog_IntermediateValue a, struct ddog_IntermediateValue b) {
    UNUSED(ctx);
    return dd_eval_cmp(a, b) >= 0;
}

static const void *dd_eval_fetch_identifier(void *ctx, const ddog_CharSlice *name) {
    struct eval_ctx *eval_ctx = ctx;
    zend_execute_data *execute_data = eval_ctx->frame;

    if (EX(func)) {
        if (ZEND_USER_CODE(EX(func)->type)) {
            zend_execute_data *current_execute_data = EG(current_execute_data);
            EG(current_execute_data) = execute_data;
            zval *zvp = zend_hash_str_find_ind(zend_rebuild_symbol_table(), name->ptr, name->len);
            EG(current_execute_data) = current_execute_data;
            if (zvp) {
                return zvp;
            }
        } else {
            int call_args = MIN(EX_NUM_ARGS(), EX(func)->common.num_args);
            for (int i = 0; i < call_args; ++i) {
                const char *argname = EX(func)->internal_function.arg_info[i].name;
                if (zend_binary_strcmp(argname, strlen(argname), name->ptr, name->len) == 0) {
                    return EX_VAR_NUM(i);
                }
            }
        }
    }

    if (name->len == 4 && memcmp(name->ptr, ZEND_STRL("this")) == 0) {
        if (hasThis()) {
            return &EX(This);
        }
        return NULL;
    }

    if (name->len == sizeof("duration") && memcmp(name->ptr, ZEND_STRL("@duration")) == 0) {
        ddtrace_span_data *span = ddtrace_active_span();
        if (span) {
            zval zv; // milliseconds
            ZVAL_DOUBLE(&zv, (zend_hrtime() - span->duration_start) / 1000000.);
            return dd_persist_eval_arena(eval_ctx, &zv);
        } else {
            return NULL;
        }
    }

    if (name->len == sizeof("return") && memcmp(name->ptr, ZEND_STRL("@return")) == 0) {
        return eval_ctx->retval;
    }

    if (name->len == sizeof("exception") && memcmp(name->ptr, ZEND_STRL("@exception")) == 0) {
        if (EG(exception)) {
            zval zv;
            ZVAL_OBJ_COPY(&zv, EG(exception));
            return dd_persist_eval_arena(eval_ctx, &zv);
        }
        return NULL;
    }
    
    return NULL;
}

static const void *dd_eval_fetch_nested(void *ctx, const void *container_ptr, struct ddog_IntermediateValue index) {
    zval *container = (zval *)container_ptr, *dim = (zval *)index.referenced;
    ZVAL_DEREF(container);
    switch (Z_TYPE_P(container)) {
        case IS_OBJECT: ;
            if (ddog_snapshot_redacted_type(dd_zend_string_to_CharSlice(Z_OBJCE_P(container)->name))) {
                return ddog_EVALUATOR_RESULT_REDACTED;
            }
            zend_property_info *prop;
            zend_string *prop_name;
            if (index.tag == DDOG_INTERMEDIATE_VALUE_STRING) {
                prop = zend_hash_str_find_ptr(&Z_OBJCE_P(container)->properties_info, index.string.ptr, index.string.len);
                if (!prop) {
                    prop_name = zend_string_init(index.string.ptr, index.string.len, 0);
                } else {
                    prop_name = zend_string_copy(prop->name);
                }
            } else if (index.tag == DDOG_INTERMEDIATE_VALUE_REFERENCED) {
                ZVAL_DEREF(dim);
                if (Z_TYPE_P(dim) != IS_STRING) {
                    return ddog_EVALUATOR_RESULT_INVALID;
                }
                prop = zend_hash_find_ptr(&Z_OBJCE_P(container)->properties_info, Z_STR_P(dim));
                prop_name = zend_string_copy(Z_STR_P(dim));
            } else {
                return ddog_EVALUATOR_RESULT_INVALID;
            }
            zval rv;
            uint32_t *guard, orig_guard;
            if (Z_OBJCE_P(container)->ce_flags & ZEND_ACC_USE_GUARDS) {
                guard = zend_get_property_guard(Z_OBJ_P(container), prop_name);
                orig_guard = *guard;
                *guard |= ZEND_GUARD_PROPERTY_MASK; // bypass __magicMethods
            } else {
                guard = NULL;
            }
#if PHP_VERSION_ID < 80000
            zval *ret = zend_read_property_ex(prop ? prop->ce : Z_OBJCE_P(container), container, prop_name, 1, &rv);
#else
            zval *ret = zend_read_property_ex(prop ? prop->ce : Z_OBJCE_P(container), Z_OBJ_P(container), prop_name, 1, &rv);
#endif
            if (guard) {
                *guard = orig_guard;
            }
            zend_string_release(prop_name);
            if (ret == &EG(uninitialized_zval)) {
                return NULL;
            }
            if (ret == &rv) {
                ret = dd_persist_eval_arena(ctx, ret);
            }
            return ret;
        case IS_ARRAY:
            switch (index.tag) {
                case DDOG_INTERMEDIATE_VALUE_STRING:
                    return zend_symtable_str_find(Z_ARR_P(container), index.string.ptr, index.string.len);
                case DDOG_INTERMEDIATE_VALUE_NUMBER:
                    return zend_hash_index_find(Z_ARR_P(container), (zend_ulong)index.number);
                case DDOG_INTERMEDIATE_VALUE_REFERENCED:
                    ZVAL_DEREF(dim);
                    switch (Z_TYPE_P(dim)) {
                        case IS_STRING:
                            return zend_symtable_find(Z_ARR_P(container), Z_STR_P(dim));
                        case IS_LONG:
                            return zend_hash_index_find(Z_ARR_P(container), (zend_ulong) Z_LVAL_P(dim));
                        case IS_DOUBLE:
                            return zend_hash_index_find(Z_ARR_P(container), (zend_ulong) Z_DVAL_P(dim));
                    }
                    return ddog_EVALUATOR_RESULT_INVALID;
                default:
                    return ddog_EVALUATOR_RESULT_INVALID;
            }
        case IS_STRING: ;
            zend_long off;
            switch (index.tag) {
                case DDOG_INTERMEDIATE_VALUE_STRING: ;
                    char *end = (char *)index.string.ptr + index.string.len;
                    off = strtoll(index.string.ptr, &end, 10);
                    break;
                case DDOG_INTERMEDIATE_VALUE_NUMBER:
                    off = (zend_long)index.number;
                    break;
                case DDOG_INTERMEDIATE_VALUE_REFERENCED: ;
                    bool success;
                    off = dd_zval_convert_index((zval *)index.referenced, &success);
                    if (!success) {
                        return ddog_EVALUATOR_RESULT_INVALID;
                    }
                    break;
                default:
                    return ddog_EVALUATOR_RESULT_INVALID;
            }
            zval zv;
            if (off < 0 || off >= (zend_long)Z_STRLEN_P(container)) {
                ZVAL_EMPTY_STRING(&zv);
            } else {
                char chr = Z_STRVAL_P(container)[off];
#if PHP_VERSION_ID < 70200
                ZVAL_STRINGL(&zv, &chr, 1);
#else
                ZVAL_STR_COPY(&zv, zend_one_char_string[(unsigned char) chr]);
#endif
            }
            return dd_persist_eval_arena(ctx, &zv);
        default:
            return ddog_EVALUATOR_RESULT_INVALID;
    }
}

static void dd_sandboxed_read_dimension(zval *container, zval *dim, zval **ret, zval *rv) {
    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    // We expect read access to have no real side effects, but it still might throw for invalid offsets etc.
    zend_try {
#if PHP_VERSION_ID < 80000
        if (Z_OBJ_HANDLER_P(container, has_dimension)(container, dim, 0)) {
            *ret = Z_OBJ_HANDLER_P(container, read_dimension)(container, dim, BP_VAR_IS, rv);
#else
        if (Z_OBJ_HANDLER_P(container, has_dimension)(Z_OBJ_P(container), dim, 0)) {
            *ret = Z_OBJ_HANDLER_P(container, read_dimension)(Z_OBJ_P(container), dim, BP_VAR_IS, rv);
#endif
        } else {
            *ret = NULL;
        }
    } zend_catch {
        zai_sandbox_bailout(&sandbox);
    } zend_end_try();

    zai_sandbox_close(&sandbox);
}

static const void *dd_eval_fetch_index(void *ctx, const void *container_ptr, struct ddog_IntermediateValue index) {
    zval *container = (zval *)container_ptr;
    ZVAL_DEREF(container);
    if (Z_TYPE_P(container) == IS_OBJECT) {
        if (Z_OBJ_HANDLER_P(container, read_dimension) != zend_std_read_dimension) { // of internal classes like weakmap
            zval dim, rv, *ret = (zval *)ddog_EVALUATOR_RESULT_INVALID;
            dd_intermediate_to_zval(index, &dim);
            dd_sandboxed_read_dimension(container, &dim, &ret, &rv);

            zval_ptr_dtor(&dim);
            if (ret == &rv) {
                return dd_persist_eval_arena(ctx, &rv);
            }
            return ret;
        }
    }
    return dd_eval_fetch_nested(ctx, container_ptr, index);
}

static uintptr_t dd_eval_length(void *ctx, const void *zvp) {
    UNUSED(ctx);
    const zval *zv = zvp;
    retry:
    switch (Z_TYPE_P(zv)) {
        case IS_REFERENCE:
            ZVAL_DEREF(zv);
            goto retry;

        case IS_ARRAY:
            return zend_array_count(Z_ARRVAL_P(zv));

        case IS_OBJECT:
            // Internal handler
            if (Z_OBJ_HANDLER_P(zv, count_elements)) {
                zend_long num;
                zend_object *ex = EG(exception);
#if PHP_VERSION_ID < 80000
                if (SUCCESS == Z_OBJ_HANDLER_P(zv, count_elements)((zval *)zv, &num)) {
#else
                if (SUCCESS == Z_OBJ_HANDLER_P(zv, count_elements)(Z_OBJ_P(zv), &num)) {
#endif
                    EG(exception) = ex;
                    return (uint64_t)num;
                }
                if (EG(exception)) {
                    zend_clear_exception();
                }
                EG(exception) = ex;
            }

            return zend_array_count(Z_OBJPROP_P(zv));

        case IS_STRING:
            return Z_STRLEN_P(zv);

        case IS_DOUBLE:
        case IS_LONG: ;
            zend_string *str = ddtrace_convert_to_str(zv);
            uint64_t len = ZSTR_LEN(str);
            zend_string_release(str);
            return len;

        default:
            return 0;
    }
}

static ddog_VoidCollection dd_eval_try_enumerate(void *ctx, const void *zvp) {
    UNUSED(ctx);
    const zval *zv = zvp;
    HashTable *values;
    retry:
    switch (Z_TYPE_P(zv)) {
        case IS_REFERENCE:
            ZVAL_DEREF(zv);
            goto retry;

        case IS_ARRAY:
            values = Z_ARR_P(zv);
            break;

        case IS_OBJECT:
            if (ddog_snapshot_redacted_type(dd_zend_string_to_CharSlice(Z_OBJCE_P(zv)->name))) {
                ddog_VoidCollection collection = dd_empty_collection;
                collection.count = (intptr_t)ddog_EVALUATOR_RESULT_REDACTED;
                return collection;
            }
            values = Z_OBJPROP_P(zv);
            break;

        default: ;
            ddog_VoidCollection collection = dd_empty_collection;
            collection.count = (intptr_t)ddog_EVALUATOR_RESULT_INVALID;
            return collection;
    }

    zval *val;
    int idx = 0;
    ddog_VoidCollection collection = dd_alloc_void_collection(zend_hash_num_elements(values));
    ZEND_HASH_FOREACH_VAL_IND(values, val) {
        ((zval **)collection.elements)[idx++] = val;
    } ZEND_HASH_FOREACH_END();
    collection.count = idx;
    return collection;
}

static void dd_stringify_limited_str(zend_string *string, smart_str *str, const ddog_CaptureConfiguration *config) {
    if (ZSTR_LEN(string) <= config->max_length) {
        smart_str_append(str, string);
    } else {
        smart_str_appendl(str, ZSTR_VAL(string), config->max_length);
        smart_str_appends(str, "...");
    }
}

static void dd_stringify_zval(const zval *zv, smart_str *str, const ddog_CaptureConfiguration *config, int remaining_nesting) {
    ZVAL_DEREF(zv);
    switch (Z_TYPE_P(zv)) {
        case IS_FALSE:
            smart_str_appends(str, "false");
            break;

        case IS_TRUE:
            smart_str_appends(str, "true");
            break;

        case IS_LONG:
            smart_str_append_long(str, Z_LVAL_P(zv));
            break;

        case IS_DOUBLE:
            smart_str_append_double(str, Z_DVAL_P(zv), EG(precision), 0);
            break;

        case IS_STRING:
            dd_stringify_limited_str(Z_STR_P(zv), str, config);
            break;

        case IS_ARRAY: {
            if (remaining_nesting == 0) {
                smart_str_appends(str, "[...]");
                break;
            }
            zval *val;
            bool first = true;
            smart_str_appendc(str, '[');
            if (zend_array_is_list(Z_ARR_P(zv))) {
                int remaining_fields = config->max_collection_size;
                ZEND_HASH_FOREACH_VAL(Z_ARR_P(zv), val) {
                    if (!first) {
                        smart_str_appends(str, ", ");
                    }
                    first = false;
                    if (remaining_fields-- == 0) {
                        smart_str_appends(str, "...]");
                        break;
                    }

                    dd_stringify_zval(val, str, config, remaining_nesting - 1);
                } ZEND_HASH_FOREACH_END();
            } else {
                zend_long idx;
                zend_string *key;
                int remaining_fields = config->max_collection_size;
                ZEND_HASH_FOREACH_KEY_VAL(Z_ARR_P(zv), idx, key, val) {
                    if (!first) {
                        smart_str_appends(str, ", ");
                    }
                    first = false;
                    if (remaining_fields-- == 0) {
                        smart_str_appends(str, "...]");
                        break;
                    }

                    if (key) {
                        dd_stringify_limited_str(key, str, config);
                        if (ddog_snapshot_redacted_name(dd_zend_string_to_CharSlice(key))) {
                            smart_str_appends(str, " => {redacted}");
                            continue;
                        }
                    } else {
                        smart_str_append_long(str, idx);
                    }
                    smart_str_appends(str, " => ");
                    dd_stringify_zval(val, str, config, remaining_nesting - 1);
                } ZEND_HASH_FOREACH_END();
            }
            smart_str_appendc(str, ']');
            break;
        }

        case IS_OBJECT: {
            zend_class_entry *ce = Z_OBJCE_P(zv);
            smart_str_appendc(str, '(');
            smart_str_append(str, ce->name);
            smart_str_appendc(str, ')');
            smart_str_appendc(str, '{');
            if (ddog_snapshot_redacted_type(dd_zend_string_to_CharSlice(ce->name))) {
                smart_str_appends(str, "redacted}");
                break;
            }
            if (remaining_nesting == 0) {
                smart_str_appends(str, "...}");
                break;
            }
            zval *val;
            zend_string *key;
            int remaining_fields = config->max_field_count;
#if PHP_VERSION_ID < 70400
            int is_temp = 0;
#endif
            // reverse to prefer child class properties first
            HashTable *ht = ce->type == ZEND_INTERNAL_CLASS ?
#if PHP_VERSION_ID < 70400
                    Z_OBJDEBUG_P(zv, is_temp)
#else
                    zend_get_properties_for((zval *)zv, ZEND_PROP_PURPOSE_DEBUG)
#endif
                    : Z_OBJPROP_P(zv);
            bool first = true;
            ZEND_HASH_REVERSE_FOREACH_STR_KEY_VAL(ht, key, val) {
                if (!key) {
                    continue;
                }

                if (!first) {
                    smart_str_appends(str, ", ");
                }
                first = false;
                if (remaining_fields-- == 0) {
                    smart_str_appends(str, "...}");
                    break;
                }

                ddog_CharSlice name;
                if (ZSTR_LEN(key) < 3 || ZSTR_VAL(key)[0]) {
                    smart_str_append(str, key);
                    name = dd_zend_string_to_CharSlice(key);
                } else if (ZSTR_VAL(key)[1] == '*') { // skip \0*\0
                    name = (ddog_CharSlice){ .len = ZSTR_LEN(key) - 3, .ptr = ZSTR_VAL(key) + 3 };
                    smart_str_appendl(str, name.ptr, name.len);
                } else {
                    int classname_len = (int)strlen(ZSTR_VAL(key) + 1);
                    smart_str_appendl(str, ZSTR_VAL(key) + 1, classname_len);
                    smart_str_appends(str, "::");
                    name = (ddog_CharSlice){ .len = ZSTR_LEN(key) - classname_len - 2, .ptr = ZSTR_VAL(key) + classname_len + 2 };
                    smart_str_appendl(str, name.ptr, name.len);
                }
                smart_str_appends(str, ": ");
                if (ddog_snapshot_redacted_name(name)) {
                    smart_str_appends(str, "{redacted}");
                } else {
                    ZVAL_DEINDIRECT(val);
                    dd_stringify_zval(val, str, config, remaining_nesting - 1);
                }
            } ZEND_HASH_FOREACH_END();
            if (ce->type == ZEND_INTERNAL_CLASS) {
#if PHP_VERSION_ID < 70400
                if (is_temp) {
                    zend_array_release(ht);
                }
#else
                zend_release_properties(ht);
#endif
            }
            smart_str_appendc(str, '}');
            break;
        }

        case IS_RESOURCE: {
            smart_str_appends(str, zend_rsrc_list_get_rsrc_type(Z_RES_P(zv)));
            smart_str_appendc(str, '#');
            smart_str_append_long(str, Z_RES_P(zv)->handle);
            break;
        }

        default:
            smart_str_appends(str, "null");
    }
}


static ddog_CharSlice dd_eval_get_string(void *ctx, const void *zvp) {
    struct eval_ctx *eval_ctx = ctx;
    const zval *zv = zvp;

    switch (Z_TYPE_P(zv)) {
        case IS_STRING:
            return dd_zend_string_to_CharSlice(Z_STR_P(zv));
        case IS_TRUE:
            return DDOG_CHARSLICE_C("true");
        case IS_FALSE:
            return DDOG_CHARSLICE_C("false");
        case IS_NULL:
            return DDOG_CHARSLICE_C("null");
    }

    smart_str str = {0};
    dd_stringify_zval(zv, &str, eval_ctx->config, eval_ctx->config->max_reference_depth);
    if (!str.s) {
        return DDOG_CHARSLICE_C("");
    }
    smart_str_0(&str);
    return dd_persist_str_eval_arena(ctx, str.s);
}

static ddog_CharSlice dd_eval_stringify(void *ctx, const void *zvp) {
    UNUSED(ctx);
    const zval *zv = zvp;
    zend_string *str = ddtrace_convert_to_str(zv);
    return dd_persist_str_eval_arena(ctx, str);
}

static intptr_t dd_eval_convert_index(void *ctx, const void *zvp) {
    UNUSED(ctx);
    bool success;
    intptr_t index = dd_zval_convert_index((zval *)zvp, &success);
    if (success) {
        return index;
    }
    return (intptr_t)ddog_EVALUATOR_RESULT_INVALID;
}

static bool dd_eval_instanceof(void *ctx, const void *zvp, const ddog_CharSlice *class) {
    UNUSED(ctx);
    const zval *zv = zvp;
    ZVAL_DEREF(zv);
    if (Z_TYPE_P(zv) == IS_OBJECT) {
        if (zend_binary_strcasecmp(ZEND_STRL("object"), class->ptr, class->len) == 0) {
            return true;
        }
        zend_string *class_str = dd_CharSlice_to_zend_string(*class);
#if PHP_VERSION_ID < 70400
        zend_class_entry *ce = zend_lookup_class_ex(class_str, NULL, 0);
#else
        zend_class_entry *ce = zend_lookup_class_ex(class_str, NULL, ZEND_FETCH_CLASS_NO_AUTOLOAD);
#endif
        zend_string_release(class_str);
        return ce && instanceof_function(Z_OBJCE_P(zv), ce);
    }

    const char *name = zend_zval_type_name(zv);
    return zend_binary_strcasecmp(name, strlen(name), class->ptr, class->len) == 0;
}

const ddog_Evaluator dd_evaluator = {
    .equals = dd_eval_equals,
    .greater_than = dd_eval_greater_than,
    .greater_or_equals = dd_eval_greater_or_equals,
    .fetch_identifier = dd_eval_fetch_identifier,
    .fetch_index = dd_eval_fetch_index,
    .fetch_nested = dd_eval_fetch_nested,
    .length = dd_eval_length,
    .try_enumerate = dd_eval_try_enumerate,
    .stringify = dd_eval_stringify,
    .get_string = dd_eval_get_string,
    .convert_index = dd_eval_convert_index,
    .instanceof = dd_eval_instanceof,
};

ddog_LiveDebuggerSetup ddtrace_live_debugger_setup = {
    .callbacks = {
        .set_probe = dd_set_probe,
        .remove_probe = dd_remove_live_debugger_probe,
    },
    .evaluator = &dd_evaluator,
};

void ddtrace_live_debugger_minit(void) {
    zend_string *value;
    ZEND_HASH_FOREACH_STR_KEY(get_global_DD_DYNAMIC_INSTRUMENTATION_REDACTED_IDENTIFIERS(), value) {
        ddog_snapshot_add_redacted_name(dd_zend_string_to_CharSlice(value));
    } ZEND_HASH_FOREACH_END();
    ZEND_HASH_FOREACH_STR_KEY(get_global_DD_DYNAMIC_INSTRUMENTATION_REDACTED_TYPES(), value) {
        ddog_snapshot_add_redacted_type(dd_zend_string_to_CharSlice(value));
    } ZEND_HASH_FOREACH_END();
}
