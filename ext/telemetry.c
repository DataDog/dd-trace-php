#include "components-rs/sidecar.h"
#include "datadog.h"
#include "ffi_utils.h"
#include <tracer/tracer_api.h>
#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#endif
#include "configuration.h"
#include <hook/hook.h>
#include <components-rs/datadog.h>
#include "telemetry.h"
#include "sidecar.h"
#include <string.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

// These globals are set by the SSI loader
DATADOG_PUBLIC bool datadog_loaded_by_ssi = false;
DATADOG_PUBLIC bool datadog_ssi_forced_injection_enabled = false;

static void dd_commit_metrics(void);

ddog_SidecarActionsBuffer *datadog_telemetry_buffer(void) {
    if (DATADOG_G(telemetry_buffer)) {
        return DATADOG_G(telemetry_buffer);
    }
    return DATADOG_G(telemetry_buffer) = ddog_sidecar_telemetry_buffer_alloc();
}

ddog_ShmCacheMap *datadog_telemetry_cache(void) {
    if (DATADOG_G(telemetry_cache)) {
        return DATADOG_G(telemetry_cache);
    }
    return DATADOG_G(telemetry_cache) = ddog_sidecar_telemetry_cache_new();
}

void datadog_telemetry_rinit(void) {
    zend_hash_init(&DATADOG_G(otel_config_telemetry), 8, unused, ZVAL_PTR_DTOR, 0);
}

void datadog_telemetry_rshutdown(void) {
    zend_hash_destroy(&DATADOG_G(otel_config_telemetry));
}

// Register in the sidecar services not bound to the request lifetime
void datadog_telemetry_register_services(ddog_SidecarTransport **sidecar) {
#ifdef DDTRACE
    ddtrace_telemetry_register_services(sidecar);
#endif
}

void datadog_telemetry_lifecycle_end() {
    if (!DATADOG_G(sidecar) || !get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        return;
    }

    datadog_ffi_try("Failed ending sidecar lifecycle",
                    ddog_sidecar_lifecycle_end(&DATADOG_G(sidecar), datadog_sidecar_instance_id, &DATADOG_G(sidecar_queue_id)));
}

void datadog_telemetry_finalize() {
    if (!DATADOG_G(last_service_name) || !DATADOG_G(last_env_name)) {
        LOG(WARN, "No telemetry submission can happen without service/env");
        return;
    }

    ddog_CharSlice service_name = dd_zend_string_to_CharSlice(DATADOG_G(last_service_name));
    ddog_CharSlice env_name = dd_zend_string_to_CharSlice(DATADOG_G(last_env_name));

    ddog_SidecarActionsBuffer *buffer = datadog_telemetry_buffer();

#ifdef DDTRACE
    // Must be called before clearing telemetry_buffer so ddtrace_telemetry_finalize
    // uses the same buffer (via datadog_telemetry_buffer()) that we'll flush below.
    ddtrace_telemetry_finalize();
#endif

    DATADOG_G(telemetry_buffer) = NULL;

    zend_module_entry *module;
    char module_name[261] = { 'e', 'x', 't', '-' };
    ZEND_HASH_FOREACH_PTR(&module_registry, module) {
        size_t namelen = strlen(module->name);
        size_t copylen = MIN(256, namelen);
        memcpy(module_name + 4, module->name, copylen);
        const char *version = module->version ? module->version : "";
        ddog_sidecar_telemetry_addDependency_buffer(buffer,
                                                    (ddog_CharSlice) {.len = copylen + 4, .ptr = module_name},
                                                    (ddog_CharSlice) {.len = strlen(version), .ptr = version});
    } ZEND_HASH_FOREACH_END();

    if (!ddog_sidecar_telemetry_config_sent(datadog_telemetry_cache(), service_name, env_name)) {
        for (uint16_t i = 0; i < zai_config_memoized_entries_count; i++) {
            zai_config_memoized_entry *cfg = &zai_config_memoized_entries[i];
            zend_ini_entry *ini = cfg->ini_entries[0];
#if ZTS
            ini = zend_hash_find_ptr(EG(ini_directives), ini->name);
#endif
            if (cfg->names[0].len != sizeof("DD_TRACE_ENABLED") - 1
                || memcmp(cfg->names[0].ptr, "DD_TRACE_ENABLED", sizeof("DD_TRACE_ENABLED") - 1) != 0) { // DD_TRACE_ENABLED is meaningless: always off at rshutdown
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
                ddog_CharSlice name = (ddog_CharSlice){.ptr = cfg->names[0].ptr, .len = cfg->names[0].len};
                ddog_CharSlice config_id = (ddog_CharSlice) {.len = cfg->config_id.len, .ptr = cfg->config_id.ptr};
                ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, name, dd_zend_string_to_CharSlice(ini->value), origin, config_id);
            }
        }

        // Send extra internal configuration
        ddog_CharSlice instrumentation_source = datadog_loaded_by_ssi ? DDOG_CHARSLICE_C("ssi") : DDOG_CHARSLICE_C("manual");
        ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, DDOG_CHARSLICE_C("instrumentation_source"), instrumentation_source, DDOG_CONFIGURATION_ORIGIN_DEFAULT, DDOG_CHARSLICE_C(""));

        ddog_CharSlice ssi_forced = datadog_ssi_forced_injection_enabled ? DDOG_CHARSLICE_C("True") : DDOG_CHARSLICE_C("False");
        ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, DDOG_CHARSLICE_C("ssi_forced_injection_enabled"), ssi_forced, DDOG_CONFIGURATION_ORIGIN_ENV_VAR, DDOG_CHARSLICE_C(""));

        char *injection_enabled = getenv("DD_INJECTION_ENABLED");
        if (injection_enabled) {
            ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, DDOG_CHARSLICE_C("ssi_injection_enabled"), (ddog_CharSlice) {.ptr = injection_enabled, .len = strlen(injection_enabled)}, DDOG_CONFIGURATION_ORIGIN_ENV_VAR, DDOG_CHARSLICE_C(""));
        }

        // Send OTel configuration telemetry
        zend_string *config_name;
        zval *config_value;
        ZEND_HASH_FOREACH_STR_KEY_VAL(&DATADOG_G(otel_config_telemetry), config_name, config_value) {
            if (config_name && Z_TYPE_P(config_value) == IS_STRING) {
                ddog_CharSlice name = dd_zend_string_to_CharSlice(config_name);
                ddog_CharSlice value = dd_zend_string_to_CharSlice(Z_STR_P(config_value));
                // OTel configurations are from environment variables
                ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, name, value, DDOG_CONFIGURATION_ORIGIN_ENV_VAR, DDOG_CHARSLICE_C(""));
            }
        } ZEND_HASH_FOREACH_END();
    }

    ddog_CharSlice metric_name = DDOG_CHARSLICE_C("logs_created");
    ddog_sidecar_telemetry_register_metric(&DATADOG_G(sidecar), metric_name, DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_GENERAL);
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

    datadog_ffi_try("Failed flushing filtered telemetry buffer",
        ddog_sidecar_telemetry_filter_flush(&DATADOG_G(sidecar), datadog_sidecar_instance_id, &DATADOG_G(sidecar_queue_id), buffer, datadog_telemetry_cache(), service_name, env_name));

    ddog_sidecar_telemetry_buffer_drop(buffer);
}


DATADOG_PUBLIC void datadog_metric_register_buffer(zend_string *name, ddog_MetricType type, ddog_MetricNamespace ns) {
    if (!DATADOG_G(sidecar)) {
        return;
    }
    ddog_CharSlice metric_name = dd_zend_string_to_CharSlice(name);
    ddog_sidecar_telemetry_register_metric(&DATADOG_G(sidecar), metric_name, type, ns);
}

DATADOG_PUBLIC bool datadog_metric_add_point(zend_string *name, double value, zend_string *tags) {
    if (!DATADOG_G(metrics_buffer)) {
        DATADOG_G(metrics_buffer) = ddog_sidecar_telemetry_buffer_alloc();
    }
    ddog_CharSlice metric_name = dd_zend_string_to_CharSlice(name);
    ddog_CharSlice tags_slice = dd_zend_string_to_CharSlice(tags);
    ddog_sidecar_telemetry_add_span_metric_point_buffer(DATADOG_G(metrics_buffer), metric_name, value, tags_slice);
    return true;
}

static void dd_commit_metrics() {
    if (!DATADOG_G(metrics_buffer)) {
        return;
    }
    ddog_sidecar_telemetry_buffer_flush(
        &DATADOG_G(sidecar), datadog_sidecar_instance_id, &DATADOG_G(sidecar_queue_id), DATADOG_G(metrics_buffer));
    DATADOG_G(metrics_buffer) = NULL;
}
