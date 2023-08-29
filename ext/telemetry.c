#include "ddtrace.h"
#include "configuration.h"
#include "coms.h"
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
    if (ddtrace_detect_composer_installed_json(&dd_sidecar, dd_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id), composer_path)) {
        zai_hook_remove(ZAI_STR_EMPTY, ZAI_STR_EMPTY, dd_composer_hook_id);
    }
    return true;
}

void ddtrace_telemetry_first_init(void) {
    dd_composer_hook_id = zai_hook_install(ZAI_STR_EMPTY, ZAI_STR_EMPTY, dd_check_for_composer_autoloader, NULL, ZAI_HOOK_AUX_UNUSED, 0);
}

void ddtrace_telemetry_finalize(void) {
    if (!dd_sidecar) {
        return;
    }

    ddog_TelemetryActionsBuffer *buffer = ddog_sidecar_telemetry_buffer_alloc();

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
            ddog_sidecar_telemetry_addIntegration_buffer(buffer, integration_name, DDOG_CHARSLICE_C("0"), false);
        }
    }
    ddog_sidecar_telemetry_buffer_flush(&dd_sidecar, dd_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id), buffer);

    ddog_CharSlice service_name = DDOG_CHARSLICE_C("unnamed-php-service");
    if (DDTRACE_G(last_flushed_root_service_name)) {
        service_name = dd_zend_string_to_CharSlice(DDTRACE_G(last_flushed_root_service_name));
    }

    ddog_CharSlice php_version = dd_zend_string_to_CharSlice(Z_STR_P(zend_get_constant_str(ZEND_STRL("PHP_VERSION"))));
    struct ddog_RuntimeMeta *meta = ddog_sidecar_runtimeMeta_build(DDOG_CHARSLICE_C("php"), php_version, DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION));

    ddog_sidecar_telemetry_flushServiceData(&dd_sidecar, dd_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id), meta, service_name);

    ddog_sidecar_runtimeMeta_drop(meta);

    ddog_sidecar_telemetry_end(&dd_sidecar, dd_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id));
}

void ddtrace_telemetry_notify_integration(const char *name, size_t name_len) {
    if (dd_sidecar) {
        ddog_CharSlice integration = (ddog_CharSlice) {.len = name_len, .ptr = name};
        ddog_sidecar_telemetry_addIntegration(&dd_sidecar, dd_sidecar_instance_id, &DDTRACE_G(telemetry_queue_id), integration,
                                              DDOG_CHARSLICE_C("0"), true);
    }
}
