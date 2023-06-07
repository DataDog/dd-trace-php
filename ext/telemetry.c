#include "telemetry.h"
#include "ddtrace.h"
#include "configuration.h"
#include "coms.h"
#include "logging.h"
#include "integrations/integrations.h"
#include <hook/hook.h>
#include <components-rs/ddtrace.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static inline ddog_CharSlice dd_zend_string_to_CharSlice(zend_string *str) {
    return (ddog_CharSlice){ .len = str->len, .ptr = str->val };
}

static ddog_TelemetryTransport *dd_sidecar;
static struct ddog_InstanceId *dd_telemetry_instance_id;
static uint8_t dd_telemetry_formatted_session_id[36];
zend_long dd_composer_hook_id;

static bool dd_check_for_composer_autoloader(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    UNUSED(invocation, auxiliary, dynamic);

    ddog_CharSlice composer_path = dd_zend_string_to_CharSlice(execute_data->func->op_array.filename);
    if (ddtrace_detect_composer_installed_json(&dd_sidecar, dd_telemetry_instance_id, &DDTRACE_G(telemetry_queue_id), composer_path)) {
        zai_hook_remove(ZAI_STRING_EMPTY, ZAI_STRING_EMPTY, dd_composer_hook_id);
    }
    return true;
}

static void ddtrace_set_telemetry_globals(void) {
    uint8_t formatted_run_time_id[36];
    ddtrace_format_runtime_id(&formatted_run_time_id);
    ddog_CharSlice runtime_id = (ddog_CharSlice) {.ptr = (char *) formatted_run_time_id, .len = sizeof(formatted_run_time_id)};
    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) dd_telemetry_formatted_session_id, .len = sizeof(dd_telemetry_formatted_session_id)};
    dd_telemetry_instance_id = ddog_sidecar_instanceId_build(session_id, runtime_id);
}

void ddtrace_telemetry_setup(void) {
    ddog_Option_VecU8 sidecar_error = ddog_sidecar_connect_php(&dd_sidecar);
    if (sidecar_error.tag == DDOG_OPTION_VEC_U8_SOME_VEC_U8) {
        ddtrace_log_errf("%.*s", (int)sidecar_error.some.len, sidecar_error.some.ptr);
        ddog_MaybeError_drop(sidecar_error);
        return;
    }

    ddtrace_format_runtime_id(&dd_telemetry_formatted_session_id);
    ddtrace_set_telemetry_globals();

    char *agent_url = ddtrace_agent_url();
    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) dd_telemetry_formatted_session_id, .len = sizeof(dd_telemetry_formatted_session_id)};
    ddog_sidecar_session_config_setAgentUrl(&dd_sidecar, session_id, (ddog_CharSlice){ .ptr = agent_url, .len = strlen(agent_url) });
    free(agent_url);

    dd_composer_hook_id = zai_hook_install(ZAI_STRING_EMPTY, ZAI_STRING_EMPTY, dd_check_for_composer_autoloader, NULL, ZAI_HOOK_AUX_UNUSED, 0);
}

void ddtrace_telemetry_shutdown(void) {
    if (dd_telemetry_instance_id) {
        ddog_sidecar_instanceId_drop(dd_telemetry_instance_id);
        ddog_sidecar_transport_drop(dd_sidecar);
    }
}

void ddtrace_telemetry_finalize(void) {
    if (!dd_telemetry_instance_id) {
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
            ddog_sidecar_telemetry_enqueueConfig_buffer(buffer, dd_zend_string_to_CharSlice(ini->name), dd_zend_string_to_CharSlice(ini->value), origin);
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
    ddog_sidecar_telemetry_buffer_flush(&dd_sidecar, dd_telemetry_instance_id, &DDTRACE_G(telemetry_queue_id), buffer);

    ddog_CharSlice service_name = DDOG_CHARSLICE_C("unnamed-php-service");
    if (DDTRACE_G(last_flushed_root_service_name)) {
        service_name = dd_zend_string_to_CharSlice(DDTRACE_G(last_flushed_root_service_name));
    }

    ddog_CharSlice php_version = dd_zend_string_to_CharSlice(Z_STR_P(zend_get_constant_str(ZEND_STRL("PHP_VERSION"))));
    struct ddog_RuntimeMeta *meta = ddog_sidecar_runtimeMeta_build(DDOG_CHARSLICE_C("php"), php_version, DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION));

    ddog_sidecar_telemetry_flushServiceData(&dd_sidecar, dd_telemetry_instance_id, &DDTRACE_G(telemetry_queue_id), meta, service_name);

    ddog_sidecar_runtimeMeta_drop(meta);

    ddog_sidecar_telemetry_end(&dd_sidecar, dd_telemetry_instance_id, &DDTRACE_G(telemetry_queue_id));
}

void ddtrace_telemetry_notify_integration(const char *name, size_t name_len) {
    if (dd_telemetry_instance_id) {
        ddog_CharSlice integration = (ddog_CharSlice) {.len = name_len, .ptr = name};
        ddog_sidecar_telemetry_addIntegration(&dd_sidecar, dd_telemetry_instance_id, &DDTRACE_G(telemetry_queue_id), integration,
                                              DDOG_CHARSLICE_C("0"), true);
    }
}

void ddtrace_reset_telemetry_globals(void) {
    if (dd_telemetry_instance_id) {
        ddog_sidecar_instanceId_drop(dd_telemetry_instance_id);
        ddtrace_set_telemetry_globals();
    }
}
