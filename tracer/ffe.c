#include "ffe.h"

#include "configuration.h"
#include "span.h"
#include <components-rs/common.h>
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
    zend_string *variant
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

// --- APM feature-flag span enrichment tag staging (PHP-01) ---------------
//
// The OpenFeature provider accumulates serial ids / subjects / runtime
// defaults inline during evaluation (DG-004) and stages the encoded tag set
// here. The values are flushed into the root span meta when the root span
// closes (ddtrace_close_span) and cleared on root close / request shutdown so
// nothing leaks across requests. The whole feature is gated: when the gate is
// off the provider never stages anything, so this store stays empty and the
// close-span path is a cheap early-return (DG-005).

static void dd_ffe_set_span_tag(zend_string **slot, zend_string *value) {
    if (*slot) {
        zend_string_release(*slot);
        *slot = NULL;
    }
    if (value && ZSTR_LEN(value) > 0) {
        *slot = zend_string_copy(value);
    }
}

void ddtrace_ffe_set_span_enrichment_tags(zend_string *flags_enc, zend_string *subjects_enc, zend_string *runtime_defaults) {
    dd_ffe_set_span_tag(&DDTRACE_G(ffe_span_flags_enc), flags_enc);
    dd_ffe_set_span_tag(&DDTRACE_G(ffe_span_subjects_enc), subjects_enc);
    dd_ffe_set_span_tag(&DDTRACE_G(ffe_span_runtime_defaults), runtime_defaults);
}

bool ddtrace_ffe_has_span_enrichment_tags(void) {
    return DDTRACE_G(ffe_span_flags_enc) != NULL
        || DDTRACE_G(ffe_span_subjects_enc) != NULL
        || DDTRACE_G(ffe_span_runtime_defaults) != NULL;
}

void ddtrace_ffe_clear_span_enrichment_tags(void) {
    if (DDTRACE_G(ffe_span_flags_enc)) {
        zend_string_release(DDTRACE_G(ffe_span_flags_enc));
        DDTRACE_G(ffe_span_flags_enc) = NULL;
    }
    if (DDTRACE_G(ffe_span_subjects_enc)) {
        zend_string_release(DDTRACE_G(ffe_span_subjects_enc));
        DDTRACE_G(ffe_span_subjects_enc) = NULL;
    }
    if (DDTRACE_G(ffe_span_runtime_defaults)) {
        zend_string_release(DDTRACE_G(ffe_span_runtime_defaults));
        DDTRACE_G(ffe_span_runtime_defaults) = NULL;
    }
}

static void dd_ffe_add_span_tag_to_meta(zend_array *meta, const char *key, size_t key_len, zend_string *value) {
    if (!value || ZSTR_LEN(value) == 0) {
        return;
    }
    zval tag;
    ZVAL_STR_COPY(&tag, value);
    zend_hash_str_update(meta, key, key_len, &tag);
}

void ddtrace_ffe_flush_span_enrichment_tags(zend_array *meta) {
    // Cheap gate check first: if the feature is off there is nothing staged and
    // we must do no work (DG-005). zai_config may not be initialized yet during
    // early shutdown, so fall back to the global value in that case.
    bool enabled = zai_config_is_initialized()
        ? get_DD_EXPERIMENTAL_FLAGGING_PROVIDER_SPAN_ENRICHMENT_ENABLED()
        : get_global_DD_EXPERIMENTAL_FLAGGING_PROVIDER_SPAN_ENRICHMENT_ENABLED();

    if (!enabled || !meta || !ddtrace_ffe_has_span_enrichment_tags()) {
        ddtrace_ffe_clear_span_enrichment_tags();
        return;
    }

    dd_ffe_add_span_tag_to_meta(meta, ZEND_STRL("ffe_flags_enc"), DDTRACE_G(ffe_span_flags_enc));
    dd_ffe_add_span_tag_to_meta(meta, ZEND_STRL("ffe_subjects_enc"), DDTRACE_G(ffe_span_subjects_enc));
    dd_ffe_add_span_tag_to_meta(meta, ZEND_STRL("ffe_runtime_defaults"), DDTRACE_G(ffe_span_runtime_defaults));

    ddtrace_ffe_clear_span_enrichment_tags();
}
