#include "ffe.h"

#include "configuration.h"
#include "span.h"
#include <components-rs/common.h>
#include <components-rs/datadog.h>
#include <components-rs/sidecar.h>
#include <ext/configuration.h>
#include <ext/datadog.h>
#include <ext/ffi_utils.h>
#include <ext/sidecar.h>
#include <php.h>
#include <string.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#define DD_FFE_METRIC_BUFFER_LIMIT 1000
#define DD_FFE_EXPOSURE_BUFFER_LIMIT 1000
#define DD_FFE_CONTEXT_FIELD_LIMIT 256

ddog_FfeRuntimeConfig ddtrace_ffe_configure(void) {
    const ddog_FfeSettingsInput settings = {
        .enabled = get_global_DD_FEATURE_FLAGS_ENABLED(),
        .enabled_set = zai_config_memoized_entries[DATADOG_CONFIG_DD_FEATURE_FLAGS_ENABLED].name_index != ZAI_CONFIG_ORIGIN_DEFAULT,
        .source = dd_zend_string_to_CharSlice(get_global_DD_FEATURE_FLAGS_CONFIGURATION_SOURCE()),
        .source_set = zai_config_memoized_entries[DATADOG_CONFIG_DD_FEATURE_FLAGS_CONFIGURATION_SOURCE].name_index != ZAI_CONFIG_ORIGIN_DEFAULT,
        .legacy_enabled = get_global_DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED(),
        .legacy_enabled_set = zai_config_memoized_entries[DATADOG_CONFIG_DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED].name_index != ZAI_CONFIG_ORIGIN_DEFAULT,
        .agentless_base_url = dd_zend_string_to_CharSlice(get_global_DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL()),
        .poll_interval_seconds = get_global_DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS(),
        .request_timeout_seconds = get_global_DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS(),
        .initialization_timeout_ms = get_global_DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS(),
        .site = dd_zend_string_to_CharSlice(get_global_DD_SITE()),
        .api_key = dd_zend_string_to_CharSlice(get_global_DD_API_KEY()),
        .environment = dd_zend_string_to_CharSlice(get_global_DD_ENV()),
    };
    return ddog_ffe_configure(&settings);
}

bool ddtrace_ffe_record_flag_evaluation(zend_string *flag_key, zend_string *variant,
    zend_string *allocation_key, zend_string *targeting_key, HashTable *attributes,
    zend_string *error_type, bool runtime_default_used, bool observe_full_evaluation_data) {
    if (!get_DD_FLAGGING_EVALUATION_COUNTS_ENABLED() || !flag_key || !ZSTR_LEN(flag_key)
        || !DATADOG_G(sidecar) || !datadog_sidecar_instance_id || !DATADOG_G(sidecar_queue_id)) {
        return false;
    }

    // Admission must precede telemetry snapshot work. Neither this check nor
    // submission waits for capacity or reconnects the transport on evaluation.
    if (ddog_sidecar_check_ffe_submission(DATADOG_G(sidecar)) != DDOG_FFE_SUBMISSION_STATUS_READY) {
        return false;
    }

    ddog_FfeScalarAttribute scalars[DD_FFE_CONTEXT_FIELD_LIMIT] = {0};
    zend_string *owned_keys[DD_FFE_CONTEXT_FIELD_LIMIT] = {0};
    size_t count = 0;
    ddog_FfeSnapshotState snapshot = {0};
    // Protected observations never walk or serialize their context.
    if (observe_full_evaluation_data) {
        snapshot.context_truncated = zend_hash_num_elements(attributes) > DD_FFE_CONTEXT_FIELD_LIMIT;
        zend_ulong index;
        zend_string *key;
        zval *value;
        ZEND_HASH_FOREACH_KEY_VAL(attributes, index, key, value) {
            if (count == DD_FFE_CONTEXT_FIELD_LIMIT) {
                break;
            }
            if (!key) {
                key = owned_keys[count] = zend_long_to_str((zend_long) index);
            }
            ddog_FfeScalarAttribute *attribute = &scalars[count++];
            attribute->key = dd_zend_string_to_CharSlice(key);
            ZVAL_DEREF(value);
            switch (Z_TYPE_P(value)) {
                case IS_STRING:
                    attribute->kind = 0;
                    attribute->string_value = dd_zend_string_to_CharSlice(Z_STR_P(value));
                    break;
                case IS_TRUE:
                case IS_FALSE:
                    attribute->kind = 1;
                    attribute->bool_value = Z_TYPE_P(value) == IS_TRUE;
                    break;
                case IS_LONG:
                    attribute->kind = 2;
                    attribute->integer_value = Z_LVAL_P(value);
                    break;
                case IS_DOUBLE:
                    attribute->kind = 3;
                    attribute->double_value = Z_DVAL_P(value);
                    break;
                default:
                    // The evaluator accepts scalars only. Retain an omission
                    // marker if an internal caller supplies anything else.
                    attribute->kind = 4;
                    break;
            }
        } ZEND_HASH_FOREACH_END();
    }

    int64_t timestamp = (int64_t) (ddtrace_nanoseconds_realtime() / 1000000);
    ddog_FfeFlagEvaluation event = {
        .timestamp_ms = timestamp,
        .first_evaluation_ms = timestamp,
        .last_evaluation_ms = timestamp,
        .evaluation_count = 1,
        .flag_key = dd_zend_string_to_CharSlice(flag_key),
        .variant = dd_zend_string_to_CharSlice(variant),
        .allocation_key = dd_zend_string_to_CharSlice(allocation_key),
        .targeting_key = dd_zend_string_to_CharSlice(targeting_key),
        .error_message = dd_zend_string_to_CharSlice(error_type),
        .runtime_default_used = runtime_default_used,
        .observe_full_evaluation_data = observe_full_evaluation_data,
    };
    ddog_FfeTelemetryContext context = {
        .service = dd_zend_string_to_CharSlice(get_DD_SERVICE()),
        .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
        .version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
    };
    ddog_FfeSubmissionStatus status = ddog_sidecar_try_submit_ffe_flag_evaluation(
        DATADOG_G(sidecar), datadog_sidecar_instance_id, &DATADOG_G(sidecar_queue_id),
        &context, &event, (ddog_Slice_FfeScalarAttribute) {.ptr = scalars, .len = count}, &snapshot);
    for (size_t i = 0; i < count; ++i) {
        if (owned_keys[i]) {
            zend_string_release(owned_keys[i]);
        }
    }
    return status == DDOG_FFE_SUBMISSION_STATUS_ACCEPTED;
}

typedef struct {
    zend_string *flag_key;
    zend_string *variant;
    zend_string *reason;
    zend_string *error_type;
    zend_string *allocation_key;
} dd_ffe_metric;

typedef struct {
    uint64_t timestamp_ms;
    zend_string *flag_key;
    zend_string *subject_id;
    zend_string *subject_attributes_json;
    zend_string *allocation_key;
    zend_string *variant;
    int32_t serial_id;
    bool has_serial_id;
} dd_ffe_exposure;

static void dd_ffe_release_metric(dd_ffe_metric *metric) {
    zend_string_release(metric->flag_key);
    zend_string_release(metric->variant);
    zend_string_release(metric->reason);
    zend_string_release(metric->error_type);
    zend_string_release(metric->allocation_key);
}

static void dd_ffe_clear_evaluation_metrics(void) {
    dd_ffe_metric *buffer = (dd_ffe_metric *) DDTRACE_G(ffe_metric_buffer);
    for (size_t i = 0; i < DDTRACE_G(ffe_metric_buffer_len); i++) {
        dd_ffe_release_metric(&buffer[i]);
    }
    if (buffer) {
        efree(buffer);
    }
    DDTRACE_G(ffe_metric_buffer) = NULL;
    DDTRACE_G(ffe_metric_buffer_len) = 0;
    DDTRACE_G(ffe_metric_buffer_cap) = 0;
}

bool ddtrace_ffe_record_evaluation_metric(
    zend_string *flag_key,
    zend_string *variant,
    const char *reason,
    const char *error_type,
    zend_string *allocation_key
) {
    if (!get_DD_METRICS_OTEL_ENABLED() || !flag_key || ZSTR_LEN(flag_key) == 0) {
        return false;
    }

    if (DDTRACE_G(ffe_metric_buffer_len) >= DD_FFE_METRIC_BUFFER_LIMIT) {
        return false;
    }

    if (DDTRACE_G(ffe_metric_buffer_len) == DDTRACE_G(ffe_metric_buffer_cap)) {
        size_t new_cap = DDTRACE_G(ffe_metric_buffer_cap) == 0 ? 8 : DDTRACE_G(ffe_metric_buffer_cap) * 2;
        if (new_cap > DD_FFE_METRIC_BUFFER_LIMIT) {
            new_cap = DD_FFE_METRIC_BUFFER_LIMIT;
        }
        DDTRACE_G(ffe_metric_buffer) = safe_erealloc(
            DDTRACE_G(ffe_metric_buffer),
            new_cap,
            sizeof(dd_ffe_metric),
            0
        );
        DDTRACE_G(ffe_metric_buffer_cap) = new_cap;
    }

    dd_ffe_metric *buffer = (dd_ffe_metric *) DDTRACE_G(ffe_metric_buffer);
    dd_ffe_metric *metric = &buffer[DDTRACE_G(ffe_metric_buffer_len)++];
    metric->flag_key = zend_string_copy(flag_key);
    metric->variant = variant ? zend_string_copy(variant) : ZSTR_EMPTY_ALLOC();
    metric->reason = reason ? zend_string_init(reason, strlen(reason), 0) : ZSTR_EMPTY_ALLOC();
    metric->error_type = error_type ? zend_string_init(error_type, strlen(error_type), 0) : ZSTR_EMPTY_ALLOC();
    metric->allocation_key = allocation_key ? zend_string_copy(allocation_key) : ZSTR_EMPTY_ALLOC();

    return true;
}

bool ddtrace_ffe_flush_evaluation_metrics(void) {
    size_t metric_count = DDTRACE_G(ffe_metric_buffer_len);
    dd_ffe_metric *buffer = (dd_ffe_metric *) DDTRACE_G(ffe_metric_buffer);

    if (metric_count == 0 || !buffer) {
        return false;
    }

    if (!DATADOG_G(sidecar) || !datadog_sidecar_instance_id || !DATADOG_G(sidecar_queue_id)) {
        dd_ffe_clear_evaluation_metrics();
        return false;
    }

    ddog_FfeEvaluationMetric *ffi_metrics = safe_emalloc(metric_count, sizeof(ddog_FfeEvaluationMetric), 0);
    for (size_t i = 0; i < metric_count; i++) {
        ffi_metrics[i] = (ddog_FfeEvaluationMetric) {
            .flag_key = dd_zend_string_to_CharSlice(buffer[i].flag_key),
            .variant = dd_zend_string_to_CharSlice(buffer[i].variant),
            .reason = dd_zend_string_to_CharSlice(buffer[i].reason),
            .error_type = dd_zend_string_to_CharSlice(buffer[i].error_type),
            .allocation_key = dd_zend_string_to_CharSlice(buffer[i].allocation_key),
        };
    }

    ddog_FfeTelemetryContext context = {
        .service = dd_zend_string_to_CharSlice(get_DD_SERVICE()),
        .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
        .version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
    };
    ddog_Slice_FfeEvaluationMetric metric_slice = {
        .ptr = ffi_metrics,
        .len = metric_count,
    };

    bool flushed = datadog_ffi_try(
        "Failed sending FFE metrics batch to sidecar",
        ddog_sidecar_send_ffe_evaluation_metrics(
            &DATADOG_G(sidecar),
            datadog_sidecar_instance_id,
            &DATADOG_G(sidecar_queue_id),
            &context,
            metric_slice));

    efree(ffi_metrics);
    dd_ffe_clear_evaluation_metrics();
    return flushed;
}

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
    zend_string *variant,
    int32_t serial_id,
    bool has_serial_id
) {
    if (ZSTR_LEN(flag_key) == 0 || ZSTR_LEN(variant) == 0) {
        return;
    }

    if (DDTRACE_G(ffe_exposure_buffer_len) >= DD_FFE_EXPOSURE_BUFFER_LIMIT) {
        return;
    }

    if (DDTRACE_G(ffe_exposure_buffer_len) == DDTRACE_G(ffe_exposure_buffer_cap)) {
        size_t new_cap = DDTRACE_G(ffe_exposure_buffer_cap) == 0 ? 8 : DDTRACE_G(ffe_exposure_buffer_cap) * 2;
        if (new_cap > DD_FFE_EXPOSURE_BUFFER_LIMIT) {
            new_cap = DD_FFE_EXPOSURE_BUFFER_LIMIT;
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
    exposure->subject_id = targeting_key ? zend_string_copy(targeting_key) : ZSTR_EMPTY_ALLOC();
    exposure->subject_attributes_json = zend_string_copy(subject_attributes_json);
    exposure->allocation_key = zend_string_copy(allocation_key);
    exposure->variant = zend_string_copy(variant);
    exposure->serial_id = serial_id;
    exposure->has_serial_id = has_serial_id;
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
            .serial_id = buffer[i].serial_id,
            .has_serial_id = buffer[i].has_serial_id,
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
