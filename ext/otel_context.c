#include "otel_context.h"

#ifdef __linux__

#include <limits.h>
#include <unistd.h>

#include "configuration.h"
#include "datadog.h"
#include "ffi_utils.h"
#include "process_tags.h"
#include "target_metadata.h"
#include <components-rs/datadog.h>

void datadog_otel_process_context_publish_config(
    zend_string *configured_service,
    zend_string *env,
    zend_string *version) {
    if (!zai_config_is_initialized()) {
        return;
    }

    uint8_t runtime_id[36];
    datadog_format_runtime_id(&runtime_id);

    zend_string *service = configured_service;
    bool release_service = false;
    if (!ZSTR_LEN(service)) {
        service = datadog_default_service_name();
        release_service = true;
    }

    char detected_hostname[HOST_NAME_MAX + 1] = {0};
    zend_string *configured_hostname = get_DD_HOSTNAME();
    ddog_CharSlice hostname = dd_zend_string_to_CharSlice(configured_hostname);
    if (!hostname.len && gethostname(detected_hostname, HOST_NAME_MAX) == 0) {
        hostname = (ddog_CharSlice){
            .ptr = detected_hostname,
            .len = strnlen(detected_hostname, HOST_NAME_MAX),
        };
    }

    zend_string *process_tags = datadog_process_tags_get_serialized();
    datadog_publish_otel_process_context(
        (ddog_CharSlice){.ptr = (const char *)runtime_id, .len = sizeof(runtime_id)},
        DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION),
        dd_zend_string_to_CharSlice(service),
        dd_zend_string_to_CharSlice(env),
        dd_zend_string_to_CharSlice(version),
        hostname,
        ddtrace_get_container_id(),
        dd_zend_string_to_CharSlice(process_tags));

    if (release_service) {
        zend_string_release(service);
    }
}

void datadog_otel_process_context_publish(void) {
    datadog_otel_process_context_publish_config(
        get_DD_SERVICE(),
        get_DD_ENV(),
        get_DD_VERSION());
}

#else

void datadog_otel_process_context_publish(void) {}

void datadog_otel_process_context_publish_config(
    zend_string *service,
    zend_string *env,
    zend_string *version) {
    (void)service;
    (void)env;
    (void)version;
}

#endif
