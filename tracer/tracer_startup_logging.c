#include "tracer_startup_logging.h"
#include <ext/startup_logging.h>

#include <SAPI.h>
#include <Zend/zend_API.h>
#include <php.h>
#include <stdbool.h>

#include <ext/standard/info.h>

#include "configuration.h"
#include "integrations/integrations.h"
#include <components/log/log.h>

#include <ext/startup_logging_helpers.h>

void ddtrace_populate_startup_config(HashTable *ht) {
    // Cross-language tracer values

    dd_add_assoc_bool(ht, ZEND_STRL("analytics_enabled"), get_DD_TRACE_ANALYTICS_ENABLED());
    dd_add_assoc_double(ht, ZEND_STRL("sample_rate"), get_DD_TRACE_SAMPLE_RATE());
    dd_add_assoc_array(ht, ZEND_STRL("sampling_rules"), dd_array_copy(get_DD_TRACE_SAMPLING_RULES()));
    // TODO Add integration-specific config: integration_<integration>_analytics_enabled,
    // integration_<integration>_sample_rate, integrations_loaded
    dd_add_assoc_array(ht, ZEND_STRL("tags"), dd_array_copy(get_DD_TAGS()));
    dd_add_assoc_array(ht, ZEND_STRL("service_mapping"), dd_array_copy(get_DD_SERVICE_MAPPING()));
    // "log_injection_enabled" N/A for PHP
    // "runtime_metrics_enabled" N/A for PHP
    // "configuration_file" N/A for PHP
    // "vm" N/A for PHP
    // "partial_flushing_enabled" N/A for PHP
    dd_add_assoc_bool(ht, ZEND_STRL("distributed_tracing_enabled"), get_DD_DISTRIBUTED_TRACING());
    // "logs_correlation_enabled" N/A for PHP
    // "profiling_enabled" N/A for PHP
    dd_add_assoc_zstring(ht, ZEND_STRL("dd_version"), zend_string_copy(get_DD_VERSION()));
    // "health_metrics_enabled" N/A for PHP
    dd_add_assoc_zstring(ht, ZEND_STRL("architecture"), php_get_uname('m'));
    dd_add_assoc_bool(ht, ZEND_STRL("instrumentation_telemetry_enabled"), get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED());

    // PHP-specific values
    dd_add_assoc_string(ht, ZEND_STRL("sapi"), sapi_module.name);
    dd_add_assoc_zstring(ht, ZEND_STRL("datadog.trace.sources_path"),
                          zend_string_copy(get_DD_TRACE_SOURCES_PATH()));
    dd_add_assoc_bool(ht, ZEND_STRL("open_basedir_configured"), dd_ini_is_set(ZEND_STRL("open_basedir")));
    dd_add_assoc_zstring(ht, ZEND_STRL("uri_fragment_regex"),
                          dd_implode_keys(get_DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX()));
    dd_add_assoc_zstring(ht, ZEND_STRL("uri_mapping_incoming"),
                          dd_implode_keys(get_DD_TRACE_RESOURCE_URI_MAPPING_INCOMING()));
    dd_add_assoc_zstring(ht, ZEND_STRL("uri_mapping_outgoing"),
                          dd_implode_keys(get_DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING()));
    dd_add_assoc_bool(ht, ZEND_STRL("auto_flush_enabled"), get_DD_TRACE_AUTO_FLUSH_ENABLED());
    dd_add_assoc_bool(ht, ZEND_STRL("generate_root_span"), get_DD_TRACE_GENERATE_ROOT_SPAN());
    dd_add_assoc_bool(ht, ZEND_STRL("http_client_split_by_domain"), get_DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN());
    dd_add_assoc_bool(ht, ZEND_STRL("measure_compile_time"), get_DD_TRACE_MEASURE_COMPILE_TIME());
    dd_add_assoc_bool(ht, ZEND_STRL("report_hostname_on_root_span"), get_DD_TRACE_REPORT_HOSTNAME());
    dd_add_assoc_zstring(ht, ZEND_STRL("traced_internal_functions"),
                          dd_implode_keys(get_DD_TRACE_TRACED_INTERNAL_FUNCTIONS()));
    dd_add_assoc_bool(ht, ZEND_STRL("enabled_from_env"), get_DD_TRACE_ENABLED());
    dd_add_assoc_string(ht, ZEND_STRL("opcache.file_cache"), dd_get_ini(ZEND_STRL("opcache.file_cache")));
    dd_add_assoc_bool(ht, ZEND_STRL("sidecar_trace_sender"), get_global_DD_TRACE_SIDECAR_TRACE_SENDER());
    dd_add_assoc_bool(ht, ZEND_STRL("dynamic_instrumentation_enabled"), get_global_DD_DYNAMIC_INSTRUMENTATION_ENABLED());
    dd_add_assoc_bool(ht, ZEND_STRL("exception_replay_enabled"), get_global_DD_EXCEPTION_REPLAY_ENABLED());

    // OTLP telemetry export status (cross-language schema). PHP exports traces natively via the
    // Datadog Agent (never over OTLP), so otlp_traces_export_enabled is always false; the metrics
    // and logs flags mirror DD_METRICS_OTEL_ENABLED / DD_LOGS_OTEL_ENABLED (request-scoped).
    dd_add_assoc_bool(ht, ZEND_STRL("otlp_traces_export_enabled"), false);
    dd_add_assoc_bool(ht, ZEND_STRL("otlp_metrics_export_enabled"), get_DD_METRICS_OTEL_ENABLED());
    dd_add_assoc_bool(ht, ZEND_STRL("otlp_logs_export_enabled"), get_DD_LOGS_OTEL_ENABLED());
}

static bool dd_file_exists(const char *file) {
    if (!strlen(file)) {
        return false;
    }
    return (VCWD_ACCESS(file, R_OK) == 0);
}

static bool dd_open_basedir_allowed(const char *file) { return (php_check_open_basedir_ex(file, 0) != -1); }

void ddtrace_startup_diagnostics(HashTable *ht, bool quick) {
    //dd_add_assoc_string(ht, ZEND_STRL("service_mapping_error"), ""); // TODO Parse at C level

    const char *sources = ZSTR_VAL(get_DD_TRACE_SOURCES_PATH());
    bool sources_exist = dd_file_exists(sources);
    if (!sources_exist) {
        dd_add_assoc_bool(ht, ZEND_STRL("datadog.trace.sources_path_reachable"), sources_exist);
    } else {
        bool sources_allowed = dd_open_basedir_allowed(sources);
        if (!sources_allowed) {
            dd_add_assoc_bool(ht, ZEND_STRL("open_basedir_sources_allowed"), sources_allowed);
        }
    }

    //dd_add_assoc_string(ht, ZEND_STRL("uri_fragment_regex_error"), ""); // TODO Parse at C level
    //dd_add_assoc_string(ht, ZEND_STRL("uri_mapping_incoming_error"), ""); // TODO Parse at C level
    //dd_add_assoc_string(ht, ZEND_STRL("uri_mapping_outgoing_error"), ""); // TODO Parse at C level
}


// Only show startup logs on the first request
void ddtrace_startup_logging_extra(void (*log)(const char *format, ...)) {
    if (get_DD_OPENAI_LOGS_ENABLED()) {
        log("Note that DD_OPENAI_LOGS_ENABLED=1 may be changed or removed in any release.");
    }
}
