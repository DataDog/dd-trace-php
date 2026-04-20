#include "tracer_telemetry.h"
#include "integrations/integrations.h"
#include "ddtrace.h"
#include <components-rs/datadog.h>
#include <ext/ffi_utils.h>
#include <ext/sidecar.h>
#include <ext/telemetry.h>
#include "autoload_php_files.h"
#include "configuration.h"
#include <hook/hook.h>
#include "span.h"

#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

zend_long dd_composer_hook_id;
ddog_QueueId dd_bgs_queued_id;

const char *ddtrace_telemetry_redact_file(const char *file) {
#ifdef _WIN32
#define SEPARATOR_CHAR "\\"
#else
#define SEPARATOR_CHAR "/"
#endif
    const char *redacted_substring = strstr(file, SEPARATOR_CHAR "DDTrace");
    if (redacted_substring != NULL) {
        return redacted_substring;
    } else {
        // Should not happen but will serve as a gate keepers
        const char *php_file_name = strrchr(file, SEPARATOR_CHAR[0]);
        if (php_file_name) {
            return php_file_name;
        }
        return "";
    }
}

void ddtrace_integration_error_telemetryf(ddog_Log source, const char *format, ...) {
    va_list va, va2;
    va_start(va, format);
    char buf[0x100];
    ddog_SidecarActionsBuffer *buffer = datadog_telemetry_buffer();
    va_copy(va2, va);
    int len = vsnprintf(buf, sizeof(buf), format, va2);
    va_end(va2);
    if (len > (int)sizeof(buf)) {
        char *msg = malloc(len + 1);
        len = vsnprintf(msg, len + 1, format, va);
        ddog_sidecar_telemetry_add_integration_log_buffer(source, buffer, (ddog_CharSlice){ .ptr = msg, .len = (uintptr_t)len });
        free(msg);
    } else {
        ddog_sidecar_telemetry_add_integration_log_buffer(source, buffer, (ddog_CharSlice){ .ptr = buf, .len = (uintptr_t)len });
    }
    va_end(va);
}

static bool dd_check_for_composer_autoloader(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    UNUSED(invocation, auxiliary, dynamic);

    ddog_CharSlice composer_path = dd_zend_string_to_CharSlice(execute_data->func->op_array.filename);
    if (!DATADOG_G(sidecar) // if sidecar connection was broken, let's skip immediately
        || ddtrace_detect_composer_installed_json(&DATADOG_G(sidecar), datadog_sidecar_instance_id, &DATADOG_G(sidecar_queue_id), composer_path)) {
        zai_hook_remove((zai_str)ZAI_STR_EMPTY, (zai_str)ZAI_STR_EMPTY, dd_composer_hook_id);
    }
    return true;
}

void ddtrace_telemetry_first_init(void) {
    dd_composer_hook_id = zai_hook_install((zai_str)ZAI_STR_EMPTY, (zai_str)ZAI_STR_EMPTY, dd_check_for_composer_autoloader, NULL, ZAI_HOOK_AUX_UNUSED, 0);
}

void ddtrace_telemetry_finalize(void) {
    ddog_SidecarActionsBuffer *buffer = datadog_telemetry_buffer();
    // Send information about explicitly disabled integrations
    for (size_t i = 0; i < ddtrace_integrations_len; ++i) {
        ddtrace_integration *integration = &ddtrace_integrations[i];
        if (!integration->is_enabled()) {
            ddog_CharSlice integration_name = (ddog_CharSlice) {.len = integration->name_len, .ptr = integration->name_lcase};
            ddog_sidecar_telemetry_addIntegration_buffer(buffer, integration_name, DDOG_CHARSLICE_C(""), false);
        }
    }

    // Telemetry metrics
    ddog_CharSlice metric_name = DDOG_CHARSLICE_C("spans_created");
    ddog_sidecar_telemetry_register_metric(&DATADOG_G(sidecar), metric_name, DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    zend_string *integration_name;
    zval *metric_value;
    ZEND_HASH_FOREACH_STR_KEY_VAL(&DDTRACE_G(telemetry_spans_created_per_integration), integration_name, metric_value) {
        zai_string tags = zai_string_concat3((zai_str)ZAI_STRL("integration_name:"), (zai_str)ZAI_STR_FROM_ZSTR(integration_name), (zai_str)ZAI_STRING_EMPTY);
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, metric_name, Z_DVAL_P(metric_value), dd_zai_string_to_CharSlice(tags));
        zai_string_destroy(&tags);
    } ZEND_HASH_FOREACH_END();

    ddog_sidecar_telemetry_register_metric(&DATADOG_G(sidecar), DDOG_CHARSLICE_C("context_header_style.extracted"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header_style.extracted"), DDTRACE_G(baggage_extract_count), DDOG_CHARSLICE_C("header_style:baggage"));
    ddog_sidecar_telemetry_register_metric(&DATADOG_G(sidecar), DDOG_CHARSLICE_C("context_header_style.injected"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header_style.injected"), DDTRACE_G(baggage_inject_count), DDOG_CHARSLICE_C("header_style:baggage"));
    ddog_sidecar_telemetry_register_metric(&DATADOG_G(sidecar), DDOG_CHARSLICE_C("context_header.truncated"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header.truncated"), DDTRACE_G(baggage_max_item_count), DDOG_CHARSLICE_C("truncation_reason:baggage_byte_item_exceeded"));
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header.truncated"), DDTRACE_G(baggage_max_byte_count), DDOG_CHARSLICE_C("truncation_reason:baggage_byte_count_exceeded"));
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header.truncated"), DDTRACE_G(baggage_extract_max_item_count), DDOG_CHARSLICE_C("truncation_reason:baggage_extract_item_exceeded"));
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header.truncated"), DDTRACE_G(baggage_extract_max_byte_count), DDOG_CHARSLICE_C("truncation_reason:baggage_extract_byte_exceeded"));
    ddog_sidecar_telemetry_register_metric(&DATADOG_G(sidecar), DDOG_CHARSLICE_C("context_header_style.malformed"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header_style.malformed"), DDTRACE_G(baggage_malformed_count), DDOG_CHARSLICE_C("header_style:baggage"));

    // Flush any accumulated BGS (background sender) metrics if enough time has passed.
    ddtrace_telemetry_flush_bgs_metrics_if_due(DATADOG_GLOBALS_PTR());
}

void ddtrace_telemetry_rinit(void) {
    zend_hash_init(&DDTRACE_G(telemetry_spans_created_per_integration), 8, unused, NULL, 0);
    DDTRACE_G(baggage_extract_count) = 0;
    DDTRACE_G(baggage_inject_count) = 0;
    DDTRACE_G(baggage_malformed_count) = 0;
    DDTRACE_G(baggage_max_item_count) = 0;
    DDTRACE_G(baggage_max_byte_count) = 0;
    DDTRACE_G(baggage_extract_max_item_count) = 0;
    DDTRACE_G(baggage_extract_max_byte_count) = 0;
}

void ddtrace_telemetry_rshutdown(void) {
    zend_hash_destroy(&DDTRACE_G(telemetry_spans_created_per_integration));
}

void ddtrace_telemetry_register_services(ddog_SidecarTransport **sidecar) {
    if (!dd_bgs_queued_id) {
        dd_bgs_queued_id = ddog_sidecar_queueId_generate();
    }

    ddog_sidecar_telemetry_register_metric(sidecar, DDOG_CHARSLICE_C("trace_api.requests"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_register_metric(sidecar, DDOG_CHARSLICE_C("trace_api.responses"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_register_metric(sidecar, DDOG_CHARSLICE_C("trace_api.errors"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);

    // FIXME: it seems we must call "enqueue_actions" (even with an empty list of actions) for things to work properly
    ddog_SidecarActionsBuffer *buffer = ddog_sidecar_telemetry_buffer_alloc();
    datadog_ffi_try("Failed flushing background sender telemetry buffer",
                    ddog_sidecar_telemetry_buffer_flush(sidecar, datadog_sidecar_instance_id, &dd_bgs_queued_id, buffer));
}

void ddtrace_telemetry_notify_integration(const char *name, size_t name_len) {
    ddtrace_telemetry_notify_integration_version(name, name_len, "", 0);
}

void ddtrace_telemetry_notify_integration_version(const char *name, size_t name_len, const char *version, size_t version_len) {
    if (DATADOG_G(sidecar) && get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        ddog_CharSlice integration = (ddog_CharSlice) {.len = name_len, .ptr = name};
        ddog_CharSlice ver = (ddog_CharSlice) {.len = version_len, .ptr = version};
        ddog_sidecar_telemetry_addIntegration_buffer(datadog_telemetry_buffer(), integration, ver, true);
    }
}

void ddtrace_telemetry_inc_spans_created(ddtrace_span_data *span) {
    zval *component = NULL;
    if (Z_TYPE(span->property_meta) == IS_ARRAY) {
        component = zend_hash_str_find(Z_ARRVAL(span->property_meta), ZEND_STRL("component"));
    }

    zend_string *integration = NULL;
    if (component && Z_TYPE_P(component) == IS_STRING) {
        integration = zend_string_copy(Z_STR_P(component));
    } else if (span->flags & DDTRACE_SPAN_FLAG_OPENTELEMETRY) {
        integration = zend_string_init(ZEND_STRL("otel"), 0);
    } else if (span->flags & DDTRACE_SPAN_FLAG_OPENTRACING) {
        integration = zend_string_init(ZEND_STRL("opentracing"), 0);
    } else {
        // Fallback value when the span has not been created by an integration, nor OpenTelemetry/OpenTracing (i.e. \DDTrace\span_start())
        integration = zend_string_init(ZEND_STRL("datadog"), 0);
    }

    zval *current = zend_hash_find(&DDTRACE_G(telemetry_spans_created_per_integration), integration);
    if (current) {
        ++Z_DVAL_P(current);
    } else {
        zval counter;
        ZVAL_DOUBLE(&counter, 1.0);
        zend_hash_add(&DDTRACE_G(telemetry_spans_created_per_integration), integration, &counter);
    }

    zend_string_release(integration);
}

// Process-global atomic accumulators for background-sender metrics.
// Written by the BGS thread (coms.c) without any lock; drained by a PHP request
// thread in ddtrace_telemetry_flush_bgs_metrics_if_due().
static _Atomic(int) bgs_metric_requests = 0;
static _Atomic(int) bgs_metric_responses_1xx = 0;
static _Atomic(int) bgs_metric_responses_2xx = 0;
static _Atomic(int) bgs_metric_responses_3xx = 0;
static _Atomic(int) bgs_metric_responses_4xx = 0;
static _Atomic(int) bgs_metric_responses_5xx = 0;
static _Atomic(int) bgs_metric_errors_timeout = 0;
static _Atomic(int) bgs_metric_errors_network = 0;
static _Atomic(int) bgs_metric_errors_status_code = 0;
// Timestamp (nanoseconds) of the last flush; used to rate-limit to one flush per interval.
static _Atomic(uint64_t) bgs_metrics_last_flush_ns = 0;

void ddtrace_telemetry_send_trace_api_metrics(trace_api_metrics metrics) {
    // Pure atomic accumulation — never touches the sidecar.
    if (!metrics.requests) {
        return;
    }
    atomic_fetch_add(&bgs_metric_requests, metrics.requests);
    atomic_fetch_add(&bgs_metric_responses_1xx, metrics.responses_1xx);
    atomic_fetch_add(&bgs_metric_responses_2xx, metrics.responses_2xx);
    atomic_fetch_add(&bgs_metric_responses_3xx, metrics.responses_3xx);
    atomic_fetch_add(&bgs_metric_responses_4xx, metrics.responses_4xx);
    atomic_fetch_add(&bgs_metric_responses_5xx, metrics.responses_5xx);
    atomic_fetch_add(&bgs_metric_errors_timeout, metrics.errors_timeout);
    atomic_fetch_add(&bgs_metric_errors_network, metrics.errors_network);
    atomic_fetch_add(&bgs_metric_errors_status_code, metrics.errors_status_code);
}

void ddtrace_telemetry_flush_bgs_metrics_if_due(zend_datadog_globals *datadog_globals) {
    if (!datadog_globals->sidecar || !get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        return;
    }

    // Rate-limit: flush at most once per agent flush interval.
    uint64_t now_ns = ddtrace_nanoseconds_realtime();
    uint64_t last = atomic_load(&bgs_metrics_last_flush_ns);
    uint64_t interval_ns = (uint64_t)get_global_DD_TRACE_AGENT_FLUSH_INTERVAL() * 1000000ULL;
    if (now_ns - last < interval_ns) {
        return;
    }
    // CAS ensures only one thread flushes per interval.
    if (!atomic_compare_exchange_strong(&bgs_metrics_last_flush_ns, &last, now_ns)) {
        return;
    }

    int requests = atomic_exchange(&bgs_metric_requests, 0);
    if (!requests) {
        return;
    }

    ddog_SidecarActionsBuffer *buffer = ddog_sidecar_telemetry_buffer_alloc();
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.requests"), requests, DDOG_CHARSLICE_C(""));

    int v;
    if ((v = atomic_exchange(&bgs_metric_responses_1xx, 0))) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), v, DDOG_CHARSLICE_C("status_code:1xx"));
    }
    if ((v = atomic_exchange(&bgs_metric_responses_2xx, 0))) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), v, DDOG_CHARSLICE_C("status_code:2xx"));
    }
    if ((v = atomic_exchange(&bgs_metric_responses_3xx, 0))) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), v, DDOG_CHARSLICE_C("status_code:3xx"));
    }
    if ((v = atomic_exchange(&bgs_metric_responses_4xx, 0))) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), v, DDOG_CHARSLICE_C("status_code:4xx"));
    }
    if ((v = atomic_exchange(&bgs_metric_responses_5xx, 0))) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), v, DDOG_CHARSLICE_C("status_code:5xx"));
    }
    if ((v = atomic_exchange(&bgs_metric_errors_timeout, 0))) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.errors"), v, DDOG_CHARSLICE_C("type:timeout"));
    }
    if ((v = atomic_exchange(&bgs_metric_errors_network, 0))) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.errors"), v, DDOG_CHARSLICE_C("type:network"));
    }
    if ((v = atomic_exchange(&bgs_metric_errors_status_code, 0))) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.errors"), v, DDOG_CHARSLICE_C("type:status_code"));
    }

    datadog_ffi_try("Failed flushing background sender metrics",
                    ddog_sidecar_telemetry_buffer_flush(&datadog_globals->sidecar, datadog_sidecar_instance_id, &dd_bgs_queued_id, buffer));
}

void ddtrace_telemetry_flush_bgs_metrics_final(zend_datadog_globals *datadog_globals) {
    // Bypass the time gate so any remaining metrics are sent before the transport
    // is dropped in GSHUTDOWN.  Setting last_flush_ns to 0 makes the time check in
    // _if_due always pass; the CAS inside still prevents a concurrent double-flush.
    atomic_store(&bgs_metrics_last_flush_ns, 0);
    ddtrace_telemetry_flush_bgs_metrics_if_due(datadog_globals);
}
