#include "ffe.h"

#include "configuration.h"
#include "span.h"
#include <components-rs/common.h>
#include <components-rs/sidecar.h>
#include <ext/datadog.h>
#include <ext/ffi_utils.h>
#include <ext/sidecar.h>
#include <php.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#define DATADOG_FFE_EXPOSURE_BUFFER_LIMIT 1000

typedef struct {
    uint64_t timestamp_ms;
    zend_string *flag_key;
    zend_string *subject_id;
    zend_string *subject_attributes_json;
    zend_string *allocation_key;
    zend_string *variant;
} dd_ffe_exposure;

static void dd_ffe_release_exposure(dd_ffe_exposure *exposure) {
    zend_string_release(exposure->flag_key);
    zend_string_release(exposure->subject_id);
    zend_string_release(exposure->subject_attributes_json);
    zend_string_release(exposure->allocation_key);
    zend_string_release(exposure->variant);
}

static void dd_ffe_clear_exposures(void) {
    dd_ffe_exposure *buffer = (dd_ffe_exposure *) DDTRACE_G(ffe_exposure_buffer);
    for (size_t i = 0; i < DDTRACE_G(ffe_exposure_buffer_len); i++) {
        dd_ffe_release_exposure(&buffer[i]);
    }
    if (buffer) {
        efree(buffer);
    }
    DDTRACE_G(ffe_exposure_buffer) = NULL;
    DDTRACE_G(ffe_exposure_buffer_len) = 0;
    DDTRACE_G(ffe_exposure_buffer_cap) = 0;
}

void ddtrace_ffe_record_exposure(
    zend_string *flag_key,
    zend_string *targeting_key,
    zend_string *subject_attributes_json,
    zend_string *allocation_key,
    zend_string *variant
) {
    if (ZSTR_LEN(flag_key) == 0 || ZSTR_LEN(variant) == 0) {
        return;
    }

    if (DDTRACE_G(ffe_exposure_buffer_len) >= DATADOG_FFE_EXPOSURE_BUFFER_LIMIT) {
        return;
    }

    if (DDTRACE_G(ffe_exposure_buffer_len) == DDTRACE_G(ffe_exposure_buffer_cap)) {
        size_t new_cap = DDTRACE_G(ffe_exposure_buffer_cap) == 0 ? 8 : DDTRACE_G(ffe_exposure_buffer_cap) * 2;
        if (new_cap > DATADOG_FFE_EXPOSURE_BUFFER_LIMIT) {
            new_cap = DATADOG_FFE_EXPOSURE_BUFFER_LIMIT;
        }
        DDTRACE_G(ffe_exposure_buffer) = safe_erealloc(
            DDTRACE_G(ffe_exposure_buffer),
            new_cap,
            sizeof(dd_ffe_exposure),
            0
        );
        DDTRACE_G(ffe_exposure_buffer_cap) = new_cap;
    }

    dd_ffe_exposure *buffer = (dd_ffe_exposure *) DDTRACE_G(ffe_exposure_buffer);
    dd_ffe_exposure *exposure = &buffer[DDTRACE_G(ffe_exposure_buffer_len)++];
    exposure->timestamp_ms = ddtrace_nanoseconds_realtime() / 1000000;
    exposure->flag_key = zend_string_copy(flag_key);
    exposure->subject_id = targeting_key ? zend_string_copy(targeting_key) : zend_string_init("", 0, 0);
    exposure->subject_attributes_json = zend_string_copy(subject_attributes_json);
    exposure->allocation_key = zend_string_copy(allocation_key);
    exposure->variant = zend_string_copy(variant);
}

bool ddtrace_ffe_flush_exposures(void) {
    size_t exposure_count = DDTRACE_G(ffe_exposure_buffer_len);
    dd_ffe_exposure *buffer = (dd_ffe_exposure *) DDTRACE_G(ffe_exposure_buffer);

    if (exposure_count == 0 || !buffer) {
        return false;
    }

    if (!DATADOG_G(sidecar) || !datadog_sidecar_instance_id || !DATADOG_G(sidecar_queue_id)) {
        dd_ffe_clear_exposures();
        return false;
    }

    ddog_FfeExposure *ffi_exposures = safe_emalloc(exposure_count, sizeof(ddog_FfeExposure), 0);
    for (size_t i = 0; i < exposure_count; i++) {
        ffi_exposures[i] = (ddog_FfeExposure) {
            .timestamp_ms = buffer[i].timestamp_ms,
            .flag_key = dd_zend_string_to_CharSlice(buffer[i].flag_key),
            .subject_id = dd_zend_string_to_CharSlice(buffer[i].subject_id),
            .subject_attributes_json = dd_zend_string_to_CharSlice(buffer[i].subject_attributes_json),
            .allocation_key = dd_zend_string_to_CharSlice(buffer[i].allocation_key),
            .variant = dd_zend_string_to_CharSlice(buffer[i].variant),
        };
    }

    ddog_FfeTelemetryContext context = {
        .service = dd_zend_string_to_CharSlice(get_DD_SERVICE()),
        .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
        .version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
    };
    ddog_Slice_FfeExposure exposure_slice = {
        .ptr = ffi_exposures,
        .len = exposure_count,
    };

    bool flushed = datadog_ffi_try(
        "Failed sending FFE exposure batch to sidecar",
        ddog_sidecar_send_ffe_exposure_batch(
            &DATADOG_G(sidecar),
            datadog_sidecar_instance_id,
            &DATADOG_G(sidecar_queue_id),
            &context,
            exposure_slice));

    efree(ffi_exposures);
    dd_ffe_clear_exposures();
    return flushed;
}
