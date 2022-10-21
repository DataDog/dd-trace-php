#include "telemetry.h"
#include "ddtrace.h"
#include "configuration.h"
#include <hook/hook.h>
#include <components/rust/ddtrace.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static inline ddog_CharSlice dd_zend_string_to_CharSlice(zend_string *str) {
    return (ddog_CharSlice){ .len = str->len, .ptr = str->val };
}

zend_long dd_composer_hook_id;

static bool dd_check_for_composer_autoloader(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    UNUSED(invocation, auxiliary, dynamic);

    if (ddtrace_detect_composer_installed_json(DDTRACE_G(telemetry_handle), dd_zend_string_to_CharSlice(execute_data->func->op_array.filename))) {
        zai_hook_remove(ZAI_STRING_EMPTY, ZAI_STRING_EMPTY, dd_composer_hook_id);
    }
    return true;
}

void ddtrace_setup_composer_telemetry_hook(void) {
    dd_composer_hook_id = zai_hook_install(ZAI_STRING_EMPTY, ZAI_STRING_EMPTY, dd_check_for_composer_autoloader, NULL, ZAI_HOOK_AUX_UNUSED, 0);
}


ddog_TelemetryWorkerHandle *ddtrace_build_telemetry_handle(void) {
    ddog_TelemetryWorkerBuilder *builder;
    ddog_MaybeError error = ddog_builder_instantiate(&builder, dd_zend_string_to_CharSlice(get_DD_SERVICE()), DDOG_CHARSLICE_C("php"), dd_zend_string_to_CharSlice(Z_STR_P(zend_get_constant_str(ZEND_STRL("PHP_VERSION")))), DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION));
    if (!error.tag) {
        free((char*)error.some.ptr);
        return NULL;
    }

    ddog_builder_with_application_env(builder, dd_zend_string_to_CharSlice(get_DD_ENV()));
    ddog_builder_with_application_service_version(builder, dd_zend_string_to_CharSlice(get_DD_VERSION()));
    ddog_builder_with_runtime_id(builder, (ddog_CharSlice){ .len = sizeof(ddtrace_runtime_id), .ptr = (char *) ddtrace_runtime_id });

    ddog_TelemetryWorkerHandle *handle;
    error = ddog_builder_run(builder, &handle);
    if (!error.tag) {
        free((char*)error.some.ptr);
        return NULL;
    }
    return handle;
}
