#include "ddtrace.h"
#include "configuration.h"
#include "integrations/integrations.h"
#include <hook/hook.h>
#include <components-rs/ddtrace.h>
#include "telemetry.h"
#include "sidecar.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

zend_long dd_composer_hook_id;

static bool dd_check_for_composer_autoloader(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    UNUSED(invocation, auxiliary, dynamic);

    ddog_CharSlice composer_path = dd_zend_string_to_CharSlice(execute_data->func->op_array.filename);
    if (!ddtrace_sidecar // if sidecar connection was broken, let's skip immediately
        || ddtrace_detect_composer_installed_json(&ddtrace_sidecar, ddtrace_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id), composer_path)) {
        zai_hook_remove((zai_str)ZAI_STR_EMPTY, (zai_str)ZAI_STR_EMPTY, dd_composer_hook_id);
    }
    return true;
}

void ddtrace_telemetry_first_init(void) {
    dd_composer_hook_id = zai_hook_install((zai_str)ZAI_STR_EMPTY, (zai_str)ZAI_STR_EMPTY, dd_check_for_composer_autoloader, NULL, ZAI_HOOK_AUX_UNUSED, 0);
}

void ddtrace_telemetry_finalize(void) {
    if (!ddtrace_sidecar || !get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        return;
    }

    ddog_TelemetryActionsBuffer *buffer = ddog_sidecar_telemetry_buffer_alloc();

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

    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_memoized_entry *cfg = &zai_config_memoized_entries[i];
        zend_ini_entry *ini = cfg->ini_entries[0];
#if ZTS
        ini = zend_hash_find_ptr(EG(ini_directives), ini->name);
#endif
        if (!zend_string_equals_literal(ini->name, "datadog.trace.enabled")) { // datadog.trace.enabled is meaningless: always off at rshutdown
            ddog_ConfigurationOrigin origin = DDOG_CONFIGURATION_ORIGIN_DEFAULT;
            if (!zend_string_equals_cstr(ini->value, cfg->default_encoded_value.ptr, cfg->default_encoded_value.len)) {
                origin = cfg->name_index >= 0 ? DDOG_CONFIGURATION_ORIGIN_ENV_VAR : DDOG_CONFIGURATION_ORIGIN_CODE;
            }
            ddog_CharSlice name = dd_zend_string_to_CharSlice(ini->name);
            name.len -= strlen("datadog.");
            name.ptr += strlen("datadog.");
            ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, name, dd_zend_string_to_CharSlice(ini->value), origin);
        }
    }

    // Send information about explicitly disabled integrations
    for (size_t i = 0; i < ddtrace_integrations_len; ++i) {
        ddtrace_integration *integration = &ddtrace_integrations[i];
        if (!integration->is_enabled()) {
            ddog_CharSlice integration_name = (ddog_CharSlice) {.len = integration->name_len, .ptr = integration->name_lcase};
            ddog_sidecar_telemetry_addIntegration_buffer(buffer, integration_name, (ddog_CharSlice)DDOG_CHARSLICE_C(""), false);
        }
    }
    ddog_sidecar_telemetry_buffer_flush(&ddtrace_sidecar, ddtrace_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id), buffer);

    ddog_CharSlice service_name = DDOG_CHARSLICE_C("unnamed-php-service");
    if (DDTRACE_G(last_flushed_root_service_name)) {
        service_name = dd_zend_string_to_CharSlice(DDTRACE_G(last_flushed_root_service_name));
    }

    ddog_CharSlice env_name = DDOG_CHARSLICE_C("none");
    if (DDTRACE_G(last_flushed_root_env_name)) {
        env_name = dd_zend_string_to_CharSlice(DDTRACE_G(last_flushed_root_env_name));
    }

    ddog_CharSlice php_version = dd_zend_string_to_CharSlice(Z_STR_P(zend_get_constant_str(ZEND_STRL("PHP_VERSION"))));
    struct ddog_RuntimeMeta *meta = ddog_sidecar_runtimeMeta_build((ddog_CharSlice)DDOG_CHARSLICE_C("php"), php_version, (ddog_CharSlice)DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION));

    ddog_sidecar_telemetry_flushServiceData(&ddtrace_sidecar, ddtrace_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id), meta, service_name, env_name);

    ddog_sidecar_runtimeMeta_drop(meta);

    ddog_sidecar_telemetry_end(&ddtrace_sidecar, ddtrace_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id));
}

void ddtrace_telemetry_notify_integration(const char *name, size_t name_len) {
    if (ddtrace_sidecar && get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        ddog_CharSlice integration = (ddog_CharSlice) {.len = name_len, .ptr = name};
        ddog_sidecar_telemetry_addIntegration(&ddtrace_sidecar, ddtrace_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id), integration,
                                              (ddog_CharSlice)DDOG_CHARSLICE_C(""), true);
    }
}
