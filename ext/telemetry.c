#include "components-rs/sidecar.h"
#include "ddtrace.h"
#include "configuration.h"
#include "integrations/integrations.h"
#include <hook/hook.h>
#include <components-rs/ddtrace.h>
#include "telemetry.h"
#include "serializer.h"
#include "sidecar.h"
#include <string.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

// These globals are set by the SSI loader
DDTRACE_PUBLIC bool ddtrace_loaded_by_ssi = false;
DDTRACE_PUBLIC bool ddtrace_ssi_forced_injection_enabled = false;

zend_long dd_composer_hook_id;
ddog_QueueId dd_bgs_queued_id;

static void dd_commit_metrics(void);

ddog_SidecarActionsBuffer *ddtrace_telemetry_buffer(void) {
    if (DDTRACE_G(telemetry_buffer)) {
        return DDTRACE_G(telemetry_buffer);
    }
    return DDTRACE_G(telemetry_buffer) = ddog_sidecar_telemetry_buffer_alloc();
}

ddog_ShmCacheMap *ddtrace_telemetry_cache(void) {
    if (DDTRACE_G(telemetry_cache)) {
        return DDTRACE_G(telemetry_cache);
    }
    return DDTRACE_G(telemetry_cache) = ddog_sidecar_telemetry_cache_new();
}

void ddtrace_integration_error_telemetryf(ddog_Log source, const char *format, ...) {
    va_list va, va2;
    va_start(va, format);
    char buf[0x100];
    ddog_SidecarActionsBuffer *buffer = ddtrace_telemetry_buffer();
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

static bool dd_check_for_composer_autoloader(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    UNUSED(invocation, auxiliary, dynamic);

    ddog_CharSlice composer_path = dd_zend_string_to_CharSlice(execute_data->func->op_array.filename);
    if (!ddtrace_sidecar // if sidecar connection was broken, let's skip immediately
        || ddtrace_detect_composer_installed_json(&ddtrace_sidecar, ddtrace_sidecar_instance_id, &DDTRACE_G(sidecar_queue_id), composer_path)) {
        zai_hook_remove((zai_str)ZAI_STR_EMPTY, (zai_str)ZAI_STR_EMPTY, dd_composer_hook_id);
    }
    return true;
}

void ddtrace_telemetry_first_init(void) {
    dd_composer_hook_id = zai_hook_install((zai_str)ZAI_STR_EMPTY, (zai_str)ZAI_STR_EMPTY, dd_check_for_composer_autoloader, NULL, ZAI_HOOK_AUX_UNUSED, 0);
}

void ddtrace_telemetry_rinit(void) {
    zend_hash_init(&DDTRACE_G(telemetry_spans_created_per_integration), 8, unused, NULL, 0);
    zend_hash_init(&DDTRACE_G(otel_config_telemetry), 8, unused, ZVAL_PTR_DTOR, 0);
    DDTRACE_G(baggage_extract_count) = 0;
    DDTRACE_G(baggage_inject_count) = 0;
    DDTRACE_G(baggage_malformed_count) = 0;
    DDTRACE_G(baggage_max_item_count) = 0;
    DDTRACE_G(baggage_max_byte_count) = 0;
}

void ddtrace_telemetry_rshutdown(void) {
    zend_hash_destroy(&DDTRACE_G(telemetry_spans_created_per_integration));
    zend_hash_destroy(&DDTRACE_G(otel_config_telemetry));
}

// Register in the sidecar services not bound to the request lifetime
void ddtrace_telemetry_register_services(ddog_SidecarTransport **sidecar) {
    if (!dd_bgs_queued_id) {
        dd_bgs_queued_id = ddog_sidecar_queueId_generate();
    }

    ddog_SidecarActionsBuffer *buffer = ddog_sidecar_telemetry_buffer_alloc();
    ddog_sidecar_telemetry_register_metric_buffer(buffer, DDOG_CHARSLICE_C("trace_api.requests"), DDOG_METRIC_TYPE_COUNT,
                                                  DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_register_metric_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), DDOG_METRIC_TYPE_COUNT,
                                                  DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_register_metric_buffer(buffer, DDOG_CHARSLICE_C("trace_api.errors"), DDOG_METRIC_TYPE_COUNT,
                                                  DDOG_METRIC_NAMESPACE_TRACERS);

    // FIXME: it seems we must call "enqueue_actions" (even with an empty list of actions) for things to work properly
    ddtrace_ffi_try("Failed flushing background sender telemetry buffer",
                    ddog_sidecar_telemetry_buffer_flush(sidecar, ddtrace_sidecar_instance_id, &dd_bgs_queued_id, buffer));
}

void ddtrace_telemetry_lifecycle_end() {
    if (!ddtrace_sidecar || !get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        return;
    }

    ddtrace_ffi_try("Failed ending sidecar lifecycle",
                    ddog_sidecar_lifecycle_end(&ddtrace_sidecar, ddtrace_sidecar_instance_id, &DDTRACE_G(sidecar_queue_id)));
}

void ddtrace_telemetry_finalize() {
    if (!DDTRACE_G(last_service_name) || !DDTRACE_G(last_env_name)) {
        LOG(WARN, "No telemetry submission can happen without service/env");
        return;
    }

    ddog_CharSlice service_name = dd_zend_string_to_CharSlice(DDTRACE_G(last_service_name));
    ddog_CharSlice env_name = dd_zend_string_to_CharSlice(DDTRACE_G(last_env_name));

    ddog_SidecarActionsBuffer *buffer = ddtrace_telemetry_buffer();
    DDTRACE_G(telemetry_buffer) = NULL;

    zend_module_entry *module;
    char module_name[261] = { 'e', 'x', 't', '-' };
    ZEND_HASH_FOREACH_PTR(&module_registry, module) {
        size_t namelen = strlen(module->name);
        memcpy(module_name + 4, module->name, MIN(256, strlen(module->name)));
        const char *version = module->version ? module->version : "";
        ddog_sidecar_telemetry_addDependency_buffer(buffer,
                                                    (ddog_CharSlice) {.len = namelen + 4, .ptr = module_name},
                                                    (ddog_CharSlice) {.len = strlen(version), .ptr = version});
    } ZEND_HASH_FOREACH_END();

    if (!ddog_sidecar_telemetry_config_sent(ddtrace_telemetry_cache(), service_name, env_name)) {
        for (uint16_t i = 0; i < zai_config_memoized_entries_count; i++) {
            zai_config_memoized_entry *cfg = &zai_config_memoized_entries[i];
            zend_ini_entry *ini = cfg->ini_entries[0];
#if ZTS
            ini = zend_hash_find_ptr(EG(ini_directives), ini->name);
#endif
            if (!zend_string_equals_literal(ini->name, "datadog.trace.enabled")) { // datadog.trace.enabled is meaningless: always off at rshutdown
                ddog_ConfigurationOrigin origin = DDOG_CONFIGURATION_ORIGIN_ENV_VAR;
                switch (cfg->name_index) {
                    case ZAI_CONFIG_ORIGIN_DEFAULT:
                        origin = DDOG_CONFIGURATION_ORIGIN_DEFAULT;
                        break;
                    case ZAI_CONFIG_ORIGIN_LOCAL_STABLE:
                        origin = DDOG_CONFIGURATION_ORIGIN_LOCAL_STABLE_CONFIG;
                        break;
                    case ZAI_CONFIG_ORIGIN_FLEET_STABLE:
                        origin = DDOG_CONFIGURATION_ORIGIN_FLEET_STABLE_CONFIG;
                        break;
                }
                if (cfg->name_index != ZAI_CONFIG_ORIGIN_LOCAL_STABLE
                    && cfg->name_index != ZAI_CONFIG_ORIGIN_FLEET_STABLE
                    && !zend_string_equals_cstr(ini->value, cfg->default_encoded_value.ptr, cfg->default_encoded_value.len)) {
                    origin = cfg->name_index >= ZAI_CONFIG_ORIGIN_MODIFIED ? DDOG_CONFIGURATION_ORIGIN_ENV_VAR : DDOG_CONFIGURATION_ORIGIN_CODE;
                }
                ddog_CharSlice name = dd_zend_string_to_CharSlice(ini->name);
                name.len -= strlen("datadog.");
                name.ptr += strlen("datadog.");
                ddog_CharSlice config_id = (ddog_CharSlice) {.len = cfg->config_id.len, .ptr = cfg->config_id.ptr};
                ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, name, dd_zend_string_to_CharSlice(ini->value), origin, config_id);
            }
        }

        // Send extra internal configuration
        ddog_CharSlice instrumentation_source = ddtrace_loaded_by_ssi ? DDOG_CHARSLICE_C("ssi") : DDOG_CHARSLICE_C("manual");
        ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, DDOG_CHARSLICE_C("instrumentation_source"), instrumentation_source, DDOG_CONFIGURATION_ORIGIN_DEFAULT, DDOG_CHARSLICE_C(""));

        ddog_CharSlice ssi_forced = ddtrace_ssi_forced_injection_enabled ? DDOG_CHARSLICE_C("True") : DDOG_CHARSLICE_C("False");
        ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, DDOG_CHARSLICE_C("ssi_forced_injection_enabled"), ssi_forced, DDOG_CONFIGURATION_ORIGIN_ENV_VAR, DDOG_CHARSLICE_C(""));

        char *injection_enabled = getenv("DD_INJECTION_ENABLED");
        if (injection_enabled) {
            ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, DDOG_CHARSLICE_C("ssi_injection_enabled"), (ddog_CharSlice) {.ptr = injection_enabled, .len = strlen(injection_enabled)}, DDOG_CONFIGURATION_ORIGIN_ENV_VAR, DDOG_CHARSLICE_C(""));
        }

        // Send OTel configuration telemetry
        zend_string *config_name;
        zval *config_value;
        ZEND_HASH_FOREACH_STR_KEY_VAL(&DDTRACE_G(otel_config_telemetry), config_name, config_value) {
            if (config_name && Z_TYPE_P(config_value) == IS_STRING) {
                ddog_CharSlice name = dd_zend_string_to_CharSlice(config_name);
                ddog_CharSlice value = dd_zend_string_to_CharSlice(Z_STR_P(config_value));
                // OTel configurations are from environment variables
                ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, name, value, DDOG_CONFIGURATION_ORIGIN_ENV_VAR, DDOG_CHARSLICE_C(""));
            }
        } ZEND_HASH_FOREACH_END();
    }

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
    ddog_sidecar_telemetry_register_metric_buffer(buffer, metric_name, DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    zend_string *integration_name;
    zval *metric_value;
    ZEND_HASH_FOREACH_STR_KEY_VAL(&DDTRACE_G(telemetry_spans_created_per_integration), integration_name, metric_value) {
        zai_string tags = zai_string_concat3((zai_str)ZAI_STRL("integration_name:"), (zai_str)ZAI_STR_FROM_ZSTR(integration_name), (zai_str)ZAI_STRING_EMPTY);
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, metric_name, Z_DVAL_P(metric_value), dd_zai_string_to_CharSlice(tags));
        zai_string_destroy(&tags);
    } ZEND_HASH_FOREACH_END();

    ddog_sidecar_telemetry_register_metric_buffer(buffer, DDOG_CHARSLICE_C("context_header_style.extracted"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header_style.extracted"), DDTRACE_G(baggage_extract_count), DDOG_CHARSLICE_C("header_style:baggage"));
    ddog_sidecar_telemetry_register_metric_buffer(buffer, DDOG_CHARSLICE_C("context_header_style.injected"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header_style.injected"), DDTRACE_G(baggage_inject_count), DDOG_CHARSLICE_C("header_style:baggage"));
    ddog_sidecar_telemetry_register_metric_buffer(buffer, DDOG_CHARSLICE_C("context_header.truncated"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header.truncated"), DDTRACE_G(baggage_max_item_count), DDOG_CHARSLICE_C("truncation_reason:baggage_byte_item_exceeded"));
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header.truncated"), DDTRACE_G(baggage_max_byte_count), DDOG_CHARSLICE_C("truncation_reason:baggage_byte_count_exceeded"));
    ddog_sidecar_telemetry_register_metric_buffer(buffer, DDOG_CHARSLICE_C("context_header_style.malformed"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("context_header_style.malformed"), DDTRACE_G(baggage_malformed_count), DDOG_CHARSLICE_C("header_style:baggage"));

    metric_name = DDOG_CHARSLICE_C("logs_created");
    ddog_sidecar_telemetry_register_metric_buffer(buffer, metric_name, DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_GENERAL);
    static struct {
        ddog_CharSlice level;
        ddog_CharSlice tags;
    } log_levels[] = {
        {DDOG_CHARSLICE_C_BARE("trace"), DDOG_CHARSLICE_C_BARE("level:trace")},
        {DDOG_CHARSLICE_C_BARE("debug"), DDOG_CHARSLICE_C_BARE("level:debug")},
        {DDOG_CHARSLICE_C_BARE("info"), DDOG_CHARSLICE_C_BARE("level:info")},
        {DDOG_CHARSLICE_C_BARE("warn"), DDOG_CHARSLICE_C_BARE("level:warn")},
        {DDOG_CHARSLICE_C_BARE("error"), DDOG_CHARSLICE_C_BARE("level:error")},
    };
    uint32_t count;
    for (size_t i = 0; i < sizeof(log_levels) / sizeof(log_levels[0]); ++i) {
        if ((count = ddog_get_logs_count(log_levels[i].level)) > 0) {
            ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, metric_name, (double)count, log_levels[i].tags);
        }
    }

    dd_commit_metrics();

    ddtrace_ffi_try("Failed flushing filtered telemetry buffer",
        ddog_sidecar_telemetry_filter_flush(&ddtrace_sidecar, ddtrace_sidecar_instance_id, &DDTRACE_G(sidecar_queue_id), buffer, ddtrace_telemetry_cache(), service_name, env_name));

    ddog_sidecar_telemetry_buffer_drop(buffer);

}

void ddtrace_telemetry_notify_integration(const char *name, size_t name_len) {
    ddtrace_telemetry_notify_integration_version(name, name_len, "", 0);
}

void ddtrace_telemetry_notify_integration_version(const char *name, size_t name_len, const char *version, size_t version_len) {
    if (ddtrace_sidecar && get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        ddog_CharSlice integration = (ddog_CharSlice) {.len = name_len, .ptr = name};
        ddog_CharSlice ver = (ddog_CharSlice) {.len = version_len, .ptr = version};
        ddog_sidecar_telemetry_addIntegration_buffer(ddtrace_telemetry_buffer(), integration, ver, true);
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

void ddtrace_telemetry_send_trace_api_metrics(trace_api_metrics metrics) {
    if (!ddtrace_sidecar || !get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        return;
    }

    if (!metrics.requests) {
        return;
    }

    ddog_SidecarActionsBuffer *buffer = ddog_sidecar_telemetry_buffer_alloc();
    ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.requests"), (double)metrics.requests, DDOG_CHARSLICE_C(""));

    if (metrics.responses_1xx) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), (double)metrics.responses_1xx, DDOG_CHARSLICE_C("status_code:1xx"));
    }
    if (metrics.responses_2xx) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), (double)metrics.responses_2xx, DDOG_CHARSLICE_C("status_code:2xx"));
    }
    if (metrics.responses_3xx) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), (double)metrics.responses_3xx, DDOG_CHARSLICE_C("status_code:3xx"));
    }
    if (metrics.responses_4xx) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), (double)metrics.responses_4xx, DDOG_CHARSLICE_C("status_code:4xx"));
    }
    if (metrics.responses_5xx) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.responses"), (double)metrics.responses_5xx, DDOG_CHARSLICE_C("status_code:5xx"));
    }

    if (metrics.errors_timeout) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.errors"), (double)metrics.errors_timeout, DDOG_CHARSLICE_C("type:timeout"));
    }
    if (metrics.errors_network) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.errors"), (double)metrics.errors_network, DDOG_CHARSLICE_C("type:network"));
    }
    if (metrics.errors_status_code) {
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("trace_api.errors"), (double)metrics.errors_status_code, DDOG_CHARSLICE_C("type:status_code"));
    }

    ddtrace_ffi_try("Failed flushing background sender metrics",
                    ddog_sidecar_telemetry_buffer_flush(&ddtrace_sidecar, ddtrace_sidecar_instance_id, &dd_bgs_queued_id, buffer));
}

ZEND_TLS ddog_SidecarActionsBuffer *metrics_buffer;

DDTRACE_PUBLIC void ddtrace_metric_register_buffer(zend_string *name, ddog_MetricType type, ddog_MetricNamespace ns)
{
    if (!metrics_buffer) {
        metrics_buffer = ddog_sidecar_telemetry_buffer_alloc();
    }

    ddog_CharSlice metric_name = dd_zend_string_to_CharSlice(name);
    ddog_sidecar_telemetry_register_metric_buffer(metrics_buffer, metric_name, type, ns);
}

DDTRACE_PUBLIC bool ddtrace_metric_add_point(zend_string *name, double value, zend_string *tags) {
    if (!metrics_buffer) {
        metrics_buffer = ddog_sidecar_telemetry_buffer_alloc();
    }
    ddog_CharSlice metric_name = dd_zend_string_to_CharSlice(name);
    ddog_CharSlice tags_slice = dd_zend_string_to_CharSlice(tags);
    ddog_sidecar_telemetry_add_span_metric_point_buffer(metrics_buffer, metric_name, value, tags_slice);
    return true;
}

static void dd_commit_metrics() {
    if (!metrics_buffer) {
        return;
    }
    ddog_sidecar_telemetry_buffer_flush(
        &ddtrace_sidecar, ddtrace_sidecar_instance_id, &DDTRACE_G(sidecar_queue_id), metrics_buffer);
    metrics_buffer = NULL;
}
