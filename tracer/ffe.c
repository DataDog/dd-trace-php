#include "ffe.h"

#include "configuration.h"
#include "span.h"
#include "tracer_telemetry.h"
#include <components-rs/common.h>
#include <components-rs/datadog.h>
#include <components-rs/sidecar.h>
#include <components/log/log.h>
#include <ext/configuration.h>
#include <ext/datadog.h>
#include <ext/ffi_utils.h>
#include <ext/sidecar.h>
#include <ext/zend_hrtime.h>
#include <ext/standard/php_string.h>
#include <php.h>
#include <string.h>

#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#define DD_FFE_METRIC_BUFFER_LIMIT 1000
#define DD_FFE_ADMISSION_BUDGET_US 10000
#define DD_FFE_CONTEXT_FIELD_LIMIT 256

bool ddtrace_ffe_record_flag_evaluation(zend_string *flag_key, zend_string *variant,
    zend_string *allocation_key, zend_string *targeting_key, HashTable *attributes,
    zend_string *error_type, bool runtime_default_used, bool observe_full_evaluation_data,
    int64_t evaluation_timestamp_ms, bool targeting_key_invalid, zend_string *features_json) {
    if (!get_DD_FLAGGING_EVALUATION_COUNTS_ENABLED()) {
        return false;
    }
    if (!flag_key || !ZSTR_LEN(flag_key)) {
        ddtrace_telemetry_count_ffe_rejection(DDOG_FFE_SUBMISSION_STATUS_INVALID_INPUT);
        return false;
    }
    if (!DATADOG_G(sidecar) || !DATADOG_G(request_initialized)) {
        ddtrace_telemetry_count_ffe_rejection(DDOG_FFE_SUBMISSION_STATUS_UNAVAILABLE);
        return false;
    }

    ddog_FfeScalarAttribute scalars[DD_FFE_CONTEXT_FIELD_LIMIT] = {0};
    zend_string *owned_keys[DD_FFE_CONTEXT_FIELD_LIMIT] = {0};
    size_t count = 0;
    ddog_FfeSnapshotState snapshot = {.targeting_key_invalid = targeting_key_invalid};
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

    ddog_FfeFlagEvaluation event = {
        .timestamp_ms = evaluation_timestamp_ms,
        .first_evaluation_ms = evaluation_timestamp_ms,
        .last_evaluation_ms = evaluation_timestamp_ms,
        .evaluation_count = 1,
        .flag_key = dd_zend_string_to_CharSlice(flag_key),
        .variant = dd_zend_string_to_CharSlice(variant),
        .allocation_key = dd_zend_string_to_CharSlice(allocation_key),
        .targeting_key = dd_zend_string_to_CharSlice(targeting_key),
        .error_message = dd_zend_string_to_CharSlice(error_type),
        .runtime_default_used = runtime_default_used,
        .observe_full_evaluation_data = observe_full_evaluation_data,
        .features_json = dd_zend_string_to_CharSlice(features_json),
    };
    // An empty context makes the sidecar attribute the batch to the application
    // set on this transport via ddog_sidecar_set_application.
    ddog_FfeTelemetryContext context = {0};
    ddog_FfeSubmissionStatus status = ddog_sidecar_try_submit_ffe_flag_evaluation(
        DATADOG_G(sidecar), &context, &event,
        (ddog_Slice_FfeScalarAttribute) {.ptr = scalars, .len = count}, &snapshot);
    for (size_t i = 0; i < count; ++i) {
        if (owned_keys[i]) {
            zend_string_release(owned_keys[i]);
        }
    }
    if (status == DDOG_FFE_SUBMISSION_STATUS_ACCEPTED) {
        return true;
    }
    ddtrace_telemetry_count_ffe_rejection(status);
    return false;
}

typedef enum {
    DD_FFE_FAILURE_NONE,
    DD_FFE_FAILURE_INVALID_SOURCE,
    DD_FFE_FAILURE_MISSING_API_KEY,
    DD_FFE_FAILURE_INVALID_API_KEY,
    DD_FFE_FAILURE_INVALID_SITE,
    DD_FFE_FAILURE_INVALID_ENDPOINT,
    DD_FFE_FAILURE_UNSUPPORTED_SIDECAR_MODE,
} dd_ffe_failure;

static const struct {
    const char *reason;
    const char *message;
} dd_ffe_failures[] = {
    [DD_FFE_FAILURE_INVALID_SOURCE] = {"invalid_source", "Feature Flags configuration source is invalid; use agentless, remote_config or offline"},
    [DD_FFE_FAILURE_MISSING_API_KEY] = {"missing_api_key", "Feature Flags CDN configuration requires DD_API_KEY for the managed endpoint"},
    [DD_FFE_FAILURE_INVALID_API_KEY] = {"invalid_api_key", "Feature Flags CDN configuration requires DD_API_KEY to be a valid HTTP header value"},
    [DD_FFE_FAILURE_INVALID_SITE] = {"invalid_site", "Feature Flags CDN configuration requires a valid DD_SITE hostname"},
    [DD_FFE_FAILURE_INVALID_ENDPOINT] = {"invalid_endpoint", "Feature Flags CDN base URL must be an absolute HTTP or HTTPS URL without whitespace or a fragment"},
    [DD_FFE_FAILURE_UNSUPPORTED_SIDECAR_MODE] = {"unsupported_sidecar_mode", "Feature Flags CDN configuration requires sidecar subprocess mode"},
};

// Resolved once per process from the startup configuration, before any other thread runs.
static struct {
    bool resolved;
    bool enabled;
    ddtrace_ffe_source source;
    bool legacy_key_decided;
    bool invalid_poll_interval;
    bool invalid_request_timeout;
    bool invalid_initialization_timeout;
    uint32_t initialization_timeout_ms;
    dd_ffe_failure failure;
    ddog_FfeCdnConfig *cdn_config;
} dd_ffe;

static _Atomic(int64_t) dd_ffe_diagnostics_reported;
// Shared by all threads: only the first evaluation of the process waits for a configuration.
static _Atomic(int64_t) dd_ffe_initialization_deadline_ns;
static _Atomic(int64_t) dd_ffe_initialization_pid;

static bool dd_ffe_config_is_set(zai_config_id id) {
    return zai_config_memoized_entries[id].name_index != ZAI_CONFIG_ORIGIN_DEFAULT;
}

static zend_long dd_ffe_validated(zend_long value, zend_long def, zend_long max, bool *invalid) {
    *invalid = value < 1 || value > max;
    return *invalid ? def : value;
}

static void dd_ffe_resolve_source(void) {
    // The shared configuration defaults do not opt this PHP process into delivery.
    // The canonical source selects a transport only when explicitly configured.
    zend_string *source = php_trim(get_global_DD_FEATURE_FLAGS_CONFIGURATION_SOURCE(), NULL, 0, 3);
    if (dd_ffe_config_is_set(DATADOG_CONFIG_DD_FEATURE_FLAGS_CONFIGURATION_SOURCE) && ZSTR_LEN(source)) {
        if (zend_string_equals_literal_ci(source, "agentless")) {
            dd_ffe.source = DDTRACE_FFE_SOURCE_AGENTLESS;
            dd_ffe.enabled = true;
        } else if (zend_string_equals_literal_ci(source, "remote_config")) {
            dd_ffe.source = DDTRACE_FFE_SOURCE_REMOTE_CONFIG;
            dd_ffe.enabled = true;
        } else if (!zend_string_equals_literal_ci(source, "offline")) {
            dd_ffe.failure = DD_FFE_FAILURE_INVALID_SOURCE;
        }
    } else if (!dd_ffe_config_is_set(DATADOG_CONFIG_DD_FEATURE_FLAGS_ENABLED)
               && dd_ffe_config_is_set(DATADOG_CONFIG_DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED)) {
        dd_ffe.legacy_key_decided = true;
        dd_ffe.source = DDTRACE_FFE_SOURCE_REMOTE_CONFIG;
        dd_ffe.enabled = get_global_DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED();
    }
    // No implicit source: canonical enablement alone must not start delivery or
    // inherit the legacy RC opt-in. Only an explicit source or legacy-only
    // enablement above can activate configuration delivery.
    zend_string_release(source);

    if (dd_ffe_config_is_set(DATADOG_CONFIG_DD_FEATURE_FLAGS_ENABLED) && !get_global_DD_FEATURE_FLAGS_ENABLED()) {
        dd_ffe.enabled = false;
    }
}

void ddtrace_ffe_startup(void) {
    if (dd_ffe.resolved) {
        return;
    }
    dd_ffe.resolved = true;
    atomic_store(&dd_ffe_initialization_pid, (int64_t)getpid());
    dd_ffe_resolve_source();

    zend_long poll_interval = dd_ffe_validated(get_global_DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS(), 30, 3600, &dd_ffe.invalid_poll_interval);
    zend_long request_timeout = dd_ffe_validated(get_global_DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS(), 5, 300, &dd_ffe.invalid_request_timeout);
    dd_ffe.initialization_timeout_ms = (uint32_t)dd_ffe_validated(get_global_DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS(), 10000, INT32_MAX, &dd_ffe.invalid_initialization_timeout);

    if (!dd_ffe.enabled || dd_ffe.source != DDTRACE_FFE_SOURCE_AGENTLESS) {
        return;
    }
    ddog_FfeCdnConfigError error;
    dd_ffe.cdn_config = ddog_ffe_cdn_config_new(
        dd_zend_string_to_CharSlice(get_global_DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL()),
        dd_zend_string_to_CharSlice(get_global_DD_SITE()),
        dd_zend_string_to_CharSlice(get_global_DD_ENV()),
        dd_zend_string_to_CharSlice(get_global_DD_API_KEY()),
        (uint32_t)poll_interval, (uint32_t)request_timeout, &error);
    if (!dd_ffe.cdn_config) {
        switch (error) {
            case DDOG_FFE_CDN_CONFIG_ERROR_MISSING_API_KEY: dd_ffe.failure = DD_FFE_FAILURE_MISSING_API_KEY; break;
            case DDOG_FFE_CDN_CONFIG_ERROR_INVALID_API_KEY: dd_ffe.failure = DD_FFE_FAILURE_INVALID_API_KEY; break;
            case DDOG_FFE_CDN_CONFIG_ERROR_INVALID_SITE: dd_ffe.failure = DD_FFE_FAILURE_INVALID_SITE; break;
            case DDOG_FFE_CDN_CONFIG_ERROR_INVALID_URL: dd_ffe.failure = DD_FFE_FAILURE_INVALID_ENDPOINT; break;
        }
    }
}

void ddtrace_ffe_shutdown(void) {
    if (dd_ffe.cdn_config) {
        ddog_ffe_cdn_config_drop(dd_ffe.cdn_config);
        dd_ffe.cdn_config = NULL;
    }
}

void ddtrace_ffe_handle_fork(void) {
    int64_t pid = (int64_t)getpid();
    int64_t owner = atomic_load(&dd_ffe_initialization_pid);
    while (owner != pid) {
        // A negative PID owns the reset. Other child threads wait until it is
        // complete; a reset inherited from a different process can be reclaimed.
        if (owner != -pid && atomic_compare_exchange_strong(&dd_ffe_initialization_pid, &owner, -pid)) {
            if (!ddog_ffe_has_config()) {
                atomic_store(&dd_ffe_initialization_deadline_ns, 0);
            }
            atomic_store(&dd_ffe_initialization_pid, pid);
            return;
        }
        owner = atomic_load(&dd_ffe_initialization_pid);
    }
}

bool ddtrace_ffe_enabled(void) {
    return dd_ffe.enabled;
}

bool ddtrace_ffe_remote_config_enabled(void) {
    return dd_ffe.enabled && dd_ffe.source == DDTRACE_FFE_SOURCE_REMOTE_CONFIG;
}

bool ddtrace_ffe_agentless_enabled(void) {
    return dd_ffe.enabled && dd_ffe.source == DDTRACE_FFE_SOURCE_AGENTLESS;
}

// The in-process thread listener does not fetch from the CDN. A reader of the agentless directory would then not receive any product.
const ddog_FfeCdnConfig *ddtrace_ffe_cdn_config(void) {
    return datadog_sidecar_active_mode == DD_SIDECAR_CONNECTION_SUBPROCESS ? dd_ffe.cdn_config : NULL;
}

static dd_ffe_failure dd_ffe_current_failure(void) {
    if (dd_ffe.cdn_config && datadog_sidecar_active_mode == DD_SIDECAR_CONNECTION_THREAD) {
        return DD_FFE_FAILURE_UNSUPPORTED_SIDECAR_MODE;
    }
    return dd_ffe.failure;
}

static void dd_ffe_report_diagnostics(void) {
    if (dd_ffe.legacy_key_decided) {
        LOG(WARN, "DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED is deprecated; use DD_FEATURE_FLAGS_ENABLED and DD_FEATURE_FLAGS_CONFIGURATION_SOURCE");
    }
    if (dd_ffe.invalid_poll_interval) {
        LOG(WARN, "Invalid DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS; using 30 seconds (valid range: 1-3600)");
    }
    if (dd_ffe.invalid_request_timeout) {
        LOG(WARN, "Invalid DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS; using 5 seconds (valid range: 1-300)");
    }
    if (dd_ffe.invalid_initialization_timeout) {
        LOG(WARN, "Invalid DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS; using 10000 milliseconds (valid range: 1-2147483647)");
    }
    dd_ffe_failure failure = dd_ffe_current_failure();
    if (failure != DD_FFE_FAILURE_NONE) {
        LOG(WARN, "%s", dd_ffe_failures[failure].message);
    }
}

void ddtrace_ffe_before_evaluate(void) {
    // Applications that never evaluate a flag do not get feature flag diagnostics.
    if (!atomic_exchange(&dd_ffe_diagnostics_reported, 1)) {
        dd_ffe_report_diagnostics();
    }

    if (!ddtrace_ffe_cdn_config() || !DATADOG_G(remote_config_state) || !DATADOG_G(request_initialized)) {
        return;
    }

    // Swoole can fork without the optional tracing hook. Detect that boundary
    // before using the process-wide initialization deadline, once per child.
    ddtrace_ffe_handle_fork();
    int64_t now = (int64_t)zend_hrtime();
    int64_t deadline = atomic_load(&dd_ffe_initialization_deadline_ns);
    if (!deadline) {
        int64_t unset = 0;
        int64_t ours = now + (int64_t)dd_ffe.initialization_timeout_ms * 1000000;
        deadline = atomic_compare_exchange_strong(&dd_ffe_initialization_deadline_ns, &unset, ours) ? ours : atomic_load(&dd_ffe_initialization_deadline_ns);
    }
    // Past the deadline, this still reads updates delivered without a Remote Config notification.
    uint32_t remaining_ms = deadline > now ? (uint32_t)((deadline - now) / 1000000) : 0;
    ddog_remote_config_await_ffe(DATADOG_G(remote_config_state), remaining_ms);
}

void ddtrace_ffe_provider_state(zval *state) {
    bool has_config = ddog_ffe_has_config();
    dd_ffe_failure failure = dd_ffe_current_failure();

    array_init(state);
    add_assoc_bool(state, "ready", dd_ffe.enabled && has_config);
    add_assoc_bool(state, "hasConfig", has_config);
    add_assoc_long(state, "configVersion", (zend_long)ddog_ffe_config_version());
    add_assoc_bool(state, "productionRuntime", true);
    add_assoc_bool(state, "enabled", dd_ffe.enabled);
    add_assoc_string(state, "mode",
        dd_ffe.source == DDTRACE_FFE_SOURCE_AGENTLESS ? "native_agentless"
        : dd_ffe.source == DDTRACE_FFE_SOURCE_REMOTE_CONFIG ? "native_remote_config" : "native_disabled");
    if (!has_config && failure != DD_FFE_FAILURE_NONE) {
        add_assoc_string(state, "reason", (char *)dd_ffe_failures[failure].reason);
        add_assoc_string(state, "errorMessage", (char *)dd_ffe_failures[failure].message);
    } else {
        add_assoc_string(state, "reason", !dd_ffe.enabled ? "disabled" : has_config ? "ready" : "configuration_missing");
    }
}

typedef struct {
    zend_string *flag_key;
    zend_string *variant;
    zend_string *reason;
    zend_string *error_type;
    zend_string *allocation_key;
} dd_ffe_metric;



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
    // Not as conditional expressions: MSVC computes their strlen() before checking for NULL.
    metric->reason = ZSTR_EMPTY_ALLOC();
    if (reason) {
        metric->reason = zend_string_init(reason, strlen(reason), 0);
    }
    metric->error_type = ZSTR_EMPTY_ALLOC();
    if (error_type) {
        metric->error_type = zend_string_init(error_type, strlen(error_type), 0);
    }
    metric->allocation_key = allocation_key ? zend_string_copy(allocation_key) : ZSTR_EMPTY_ALLOC();

    return true;
}

bool ddtrace_ffe_flush_evaluation_metrics(void) {
    size_t metric_count = DDTRACE_G(ffe_metric_buffer_len);
    dd_ffe_metric *buffer = (dd_ffe_metric *) DDTRACE_G(ffe_metric_buffer);

    if (metric_count == 0 || !buffer) {
        return false;
    }

    if (!DATADOG_G(sidecar) || !DATADOG_G(request_initialized)) {
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
            &context,
            metric_slice));

    efree(ffi_metrics);
    dd_ffe_clear_evaluation_metrics();
    return flushed;
}

#define DD_FFE_DEFERRED_LIMIT 1000

typedef struct {
    ddog_FfeExposure event;
    zend_string *fields[6];
} dd_ffe_deferred_row;

typedef struct {
    zend_string *service, *env, *version;
    size_t len, cap, dropped;
    dd_ffe_deferred_row *rows;
} dd_ffe_deferred_batch;

static zend_string *dd_ffe_retain(zend_string *s) {
    return s ? zend_string_copy(s) : ZSTR_EMPTY_ALLOC();
}

static bool dd_ffe_same_text(zend_string *saved, zend_string *current) {
    return current ? zend_string_equals(saved, current) : ZSTR_LEN(saved) == 0;
}

static bool dd_ffe_can_defer(ddog_FfeTelemetryContext context, ddog_FfeExposure event) {
    size_t available = 64 * 1024;
    const size_t lengths[] = {context.service.len, context.env.len, context.version.len, event.flag_key.len,
                              event.subject_id.len, event.subject_attributes_json.len, event.allocation_key.len,
                              event.variant.len, event.features_json.len};
    for (size_t i = 0; i < sizeof(lengths) / sizeof(lengths[0]); i++) {
        if (lengths[i] > available) return false;
        available -= lengths[i];
    }
    return event.allocation_key.len > 0;
}

void ddtrace_ffe_discard_deferred(void) {
    dd_ffe_deferred_batch *batch = DDTRACE_G(ffe_deferred);
    if (!batch) return;
    for (size_t i = 0; i < batch->len; i++) {
        for (size_t j = 0; j < 6; j++) zend_string_release(batch->rows[i].fields[j]);
    }
    zend_string_release(batch->service);
    zend_string_release(batch->env);
    zend_string_release(batch->version);
    efree(batch->rows);
    efree(batch);
    DDTRACE_G(ffe_deferred) = NULL;
}

void ddtrace_ffe_flush_deferred(void) {
    dd_ffe_deferred_batch *batch = DDTRACE_G(ffe_deferred);
    if (!batch) return;
    if (DATADOG_G(sidecar) && DATADOG_G(request_initialized)) {
        ddog_FfeTelemetryContext context = {
            .service = dd_zend_string_to_CharSlice(batch->service),
            .env = dd_zend_string_to_CharSlice(batch->env),
            .version = dd_zend_string_to_CharSlice(batch->version),
        };
        ddog_FfeExposure *events = safe_emalloc(batch->len, sizeof(*events), 0);
        for (size_t i = 0; i < batch->len; i++) events[i] = batch->rows[i].event;
        ddog_Slice_FfeExposure slice = {.ptr = events, .len = batch->len};
        if (datadog_ffi_try("Failed sending deferred FFE exposures",
                ddog_sidecar_send_ffe_exposure_batch(&DATADOG_G(sidecar), &context, slice))) {
            DDTRACE_G(ffe_exposure_handed_off) = true;
        }
        efree(events);
    }
    if (batch->dropped) LOG(DEBUG, "Feature Flags: deferred exposure buffer dropped %zu observations", batch->dropped);
    ddtrace_ffe_discard_deferred();
}

static void dd_ffe_defer(ddog_FfeExposure event, zend_string *flag, zend_string *subject, zend_string *attributes,
                         zend_string *allocation, zend_string *variant, zend_string *features) {
    dd_ffe_deferred_batch *batch = DDTRACE_G(ffe_deferred);
    if (!batch) {
        DDTRACE_G(ffe_deferred) = batch = ecalloc(1, sizeof(*batch));
        batch->service = dd_ffe_retain(get_DD_SERVICE());
        batch->env = dd_ffe_retain(get_DD_ENV());
        batch->version = dd_ffe_retain(get_DD_VERSION());
    }
    if (batch->len == DD_FFE_DEFERRED_LIMIT) {
        batch->dropped++;
        return;
    }
    if (batch->len == batch->cap) {
        batch->cap = MIN(batch->cap ? 2 * batch->cap : 8, DD_FFE_DEFERRED_LIMIT);
        batch->rows = safe_erealloc(batch->rows, batch->cap, sizeof(*batch->rows), 0);
    }
    // The slices in event borrow the strings retained in this row until the batch is handed off.
    batch->rows[batch->len++] = (dd_ffe_deferred_row){
        .event = event, .fields = {dd_ffe_retain(flag), dd_ffe_retain(subject), dd_ffe_retain(attributes),
                                   dd_ffe_retain(allocation), dd_ffe_retain(variant), dd_ffe_retain(features)},
    };
}

bool ddtrace_ffe_test_admission_ready(void) {
    if (!DATADOG_G(sidecar) || !DATADOG_G(request_initialized)) return false;

    // Verdict fixtures exercise real IPC under instrumentation at the FFI's maximum budget.
    DDTRACE_G(ffe_test_admission_budget_us) = 50000;
    ddog_FfeTelemetryContext context = {.service = DDOG_CHARSLICE_C("ddtrace-phpt-readiness")};
    ddog_FfeExposure probe = {.flag_key = DDOG_CHARSLICE_C("probe"), .allocation_key = DDOG_CHARSLICE_C("probe"),
                              .variant = DDOG_CHARSLICE_C("ready")};
    ddog_FfeAdmission status = ddog_FFE_ADMISSION_UNAVAILABLE;
    return datadog_ffi_try("FFE readiness probe failed", ddog_sidecar_ffe_admit_v1(
        &DATADOG_G(sidecar), &context, &probe, false, DDTRACE_G(ffe_test_admission_budget_us), &status)) &&
        (status == ddog_FFE_ADMISSION_ADMITTED || status == ddog_FFE_ADMISSION_REPEAT);
}

const char *ddtrace_ffe_admission_name(int status) {
    switch (status) {
        case ddog_FFE_ADMISSION_ADMITTED: return "admitted";
        case ddog_FFE_ADMISSION_REPEAT: return "repeat";
        case ddog_FFE_ADMISSION_UNKNOWN: return "unknown";
        default: return "unavailable";
    }
}

int ddtrace_ffe_record_exposure(
    zend_string *flag_key,
    zend_string *targeting_key,
    zend_string *subject_attributes_json,
    zend_string *allocation_key,
    zend_string *variant,
    int32_t serial_id,
    bool has_serial_id,
    zend_string *features_json
) {
    if (ZSTR_LEN(flag_key) == 0 || ZSTR_LEN(variant) == 0) {
        LOG(DEBUG, "Feature Flags: exposure suppressed; missing flag key or variant");
        return -1;
    }

    if (!DATADOG_G(sidecar) || !DATADOG_G(request_initialized)) {
        LOG(DEBUG, "Feature Flags: exposure admission unavailable");
        return ddog_FFE_ADMISSION_UNAVAILABLE;
    }
    ddog_FfeTelemetryContext context = {
        .service = dd_zend_string_to_CharSlice(get_DD_SERVICE()),
        .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
        .version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
    };
    ddog_FfeExposure event = {
        .timestamp_ms = ddtrace_nanoseconds_realtime() / 1000000,
        .flag_key = dd_zend_string_to_CharSlice(flag_key),
        .subject_id = dd_zend_string_to_CharSlice(targeting_key),
        .subject_attributes_json = dd_zend_string_to_CharSlice(subject_attributes_json),
        .allocation_key = dd_zend_string_to_CharSlice(allocation_key),
        .variant = dd_zend_string_to_CharSlice(variant),
        .serial_id = serial_id,
        .has_serial_id = has_serial_id,
        .features_json = dd_zend_string_to_CharSlice(features_json),
    };
    bool deliver = get_DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED();
    dd_ffe_deferred_batch *batch = DDTRACE_G(ffe_deferred);
    if (batch && (!dd_ffe_same_text(batch->service, get_DD_SERVICE()) ||
                  !dd_ffe_same_text(batch->env, get_DD_ENV()) || !dd_ffe_same_text(batch->version, get_DD_VERSION()))) {
        ddtrace_ffe_flush_deferred();
    }
    ddog_FfeAdmission status = ddog_FFE_ADMISSION_UNAVAILABLE;
    if (DDTRACE_G(ffe_deferred)) {
        if (deliver && dd_ffe_can_defer(context, event)) {
            status = ddog_FFE_ADMISSION_QUEUE_FULL;
        }
    } else {
        uint32_t budget = DDTRACE_G(ffe_test_admission_budget_us);
        datadog_ffi_try("FFE exposure admission failed", ddog_sidecar_ffe_admit_v1(
            &DATADOG_G(sidecar), &context, &event, deliver, budget ? budget : DD_FFE_ADMISSION_BUDGET_US, &status));
    }
    if (deliver && status == ddog_FFE_ADMISSION_QUEUE_FULL) {
        // Keep subsequent observations in request order for the legacy batch admission.
        dd_ffe_defer(event, flag_key, targeting_key, subject_attributes_json, allocation_key, variant, features_json);
        status = ddog_FFE_ADMISSION_UNAVAILABLE;
    }
    if (deliver && status == ddog_FFE_ADMISSION_ADMITTED) {
        DDTRACE_G(ffe_exposure_handed_off) = true;
    }
    LOG(DEBUG, "Feature Flags: exposure admission %s", ddtrace_ffe_admission_name((int) status));
    return (int) status;
}
