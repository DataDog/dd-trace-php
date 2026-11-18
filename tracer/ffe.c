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
#include <ext/json/php_json.h>
#include <json/json.h>
#include <limits.h>
#include <php.h>
#include <string.h>

#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#define DD_FFE_METRIC_BUFFER_LIMIT 1000
#define DD_FFE_EXPOSURE_BUFFER_LIMIT 1000
#define DD_FFE_CONTEXT_FIELD_LIMIT 256

#if PHP_VERSION_ID >= 70300
// JSON flag values are decoded once per configuration and thread (the Rust
// configuration is thread-local too), then shared by every later evaluation,
// in this request or a later one. The arrays are persistent and immutable,
// like the literal arrays of opcache: a copy shares them, and a write first
// separates a request-local copy. A new configuration starts with an empty
// cache; the arrays of the previous one are freed after the request ends.

static zend_string *dd_ffe_json_persist_string(zend_string *str) {
    zend_string *persistent = zend_string_init(ZSTR_VAL(str), ZSTR_LEN(str), 1);
    zend_string_hash_val(persistent);
    // Flagged as interned, so that copies never change its refcount. It is in
    // no interned string table: dd_ffe_json_free_array() frees it.
#ifdef IS_STR_PERMANENT
    GC_ADD_FLAGS(persistent, IS_STR_INTERNED | IS_STR_PERMANENT);
#else
    GC_ADD_FLAGS(persistent, IS_STR_INTERNED);
#endif
    return persistent;
}

static zend_array *dd_ffe_json_persist_array(zend_array *src);

static void dd_ffe_json_persist_zval(zval *dst, zval *src) {
    switch (Z_TYPE_P(src)) {
        case IS_STRING:
            ZVAL_INTERNED_STR(dst, dd_ffe_json_persist_string(Z_STR_P(src)));
            break;
        case IS_ARRAY:
            if (zend_hash_num_elements(Z_ARR_P(src)) == 0) {
                ZVAL_EMPTY_ARRAY(dst);
                break;
            }
            ZVAL_ARR(dst, dd_ffe_json_persist_array(Z_ARR_P(src)));
            Z_TYPE_FLAGS_P(dst) = 0;
            break;
        default:
            ZVAL_COPY_VALUE(dst, src);
            break;
    }
}

// The same immutable form as zai_config_intern_zval(), in the key order and
// with the key types that json_decode() produced.
static zend_array *dd_ffe_json_persist_array(zend_array *src) {
    zend_array *ht = pemalloc(sizeof(zend_array), 1);
    zend_hash_init(ht, zend_hash_num_elements(src), NULL, NULL, 1);
    if (HT_IS_PACKED(src)) {
        zend_hash_real_init_packed(ht);
    } else {
        zend_hash_real_init_mixed(ht);
    }

    zend_ulong index;
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_KEY_VAL(src, index, key, val) {
        zval copy;
        dd_ffe_json_persist_zval(&copy, val);
        if (key) {
            zend_hash_add_new(ht, dd_ffe_json_persist_string(key), &copy);
        } else {
            zend_hash_index_add_new(ht, index, &copy);
        }
    } ZEND_HASH_FOREACH_END();

    GC_ADDREF(ht);
    GC_ADD_FLAGS(ht, IS_ARRAY_IMMUTABLE);
    return ht;
}

static void dd_ffe_json_free_array(zend_array *ht) {
    if (ht == (zend_array *) &zend_empty_array) {
        return;
    }

    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(ht, key, val) {
        if (key) {
            pefree(key, 1);
        }
        if (Z_TYPE_P(val) == IS_STRING) {
            pefree(Z_STR_P(val), 1);
        } else if (Z_TYPE_P(val) == IS_ARRAY) {
            dd_ffe_json_free_array(Z_ARR_P(val));
        }
    } ZEND_HASH_FOREACH_END();

    GC_DEL_FLAGS(ht, IS_ARRAY_IMMUTABLE);
    GC_DELREF(ht);
    zend_hash_destroy(ht);
    pefree(ht, 1);
}

static HashTable *dd_ffe_json_new_table(void) {
    HashTable *table = pemalloc(sizeof(HashTable), 1);
    zend_hash_init(table, 8, NULL, NULL, 1);
    return table;
}

static void dd_ffe_json_retire_all(void) {
    HashTable *cache = DDTRACE_G(ffe_json_cache);
    if (!cache || zend_hash_num_elements(cache) == 0) {
        return;
    }
    if (!DDTRACE_G(ffe_json_retired)) {
        DDTRACE_G(ffe_json_retired) = dd_ffe_json_new_table();
    }

    zend_array *array;
    ZEND_HASH_FOREACH_PTR(cache, array) {
        zend_hash_next_index_insert_ptr(DDTRACE_G(ffe_json_retired), array);
    } ZEND_HASH_FOREACH_END();
    zend_hash_clean(cache);
}

void ddtrace_ffe_json_value(zval *dst, const ddog_FfeResult *result) {
    ZVAL_UNDEF(dst);

    if (!DDTRACE_G(ffe_json_cache)) {
        DDTRACE_G(ffe_json_cache) = dd_ffe_json_new_table();
    }
    uint64_t version = ddog_ffe_config_version();
    if (DDTRACE_G(ffe_json_cache_version) != version) {
        // The ids belong to the previous configuration: they may be reused.
        dd_ffe_json_retire_all();
        DDTRACE_G(ffe_json_cache_version) = version;
    }

    zend_array *array = zend_hash_index_find_ptr(DDTRACE_G(ffe_json_cache), (zend_ulong) result->json_id);
    if (!array) {
        if (result->json_text.len > INT_MAX) {
            return;
        }

        // The JSON scanner reads up to a terminating NUL, which the borrowed
        // text does not have. This copy happens once per value.
        zend_string *text = zend_string_init(result->json_text.ptr, result->json_text.len, 0);
        zval decoded;
        int status = zai_json_decode_assoc_safe(&decoded, ZSTR_VAL(text), (int) ZSTR_LEN(text), PHP_JSON_PARSER_DEFAULT_DEPTH, false);
        zend_string_release(text);
        if (status != SUCCESS) {
            return;
        }
        if (Z_TYPE(decoded) != IS_ARRAY) {
            zval_ptr_dtor(&decoded);
            return;
        }

        zval persistent;
        dd_ffe_json_persist_zval(&persistent, &decoded);
        zval_ptr_dtor(&decoded);
        array = Z_ARR(persistent);
        zend_hash_index_add_new_ptr(DDTRACE_G(ffe_json_cache), (zend_ulong) result->json_id, array);
    }

    ZVAL_ARR(dst, array);
    Z_TYPE_FLAGS_P(dst) = 0;
}

static void dd_ffe_json_free_table(HashTable **table) {
    if (!*table) {
        return;
    }

    zend_array *array;
    ZEND_HASH_FOREACH_PTR(*table, array) {
        dd_ffe_json_free_array(array);
    } ZEND_HASH_FOREACH_END();
    zend_hash_destroy(*table);
    pefree(*table, 1);
    *table = NULL;
}

void ddtrace_ffe_json_cache_post_deactivate(void) {
    // No request value refers to a retired array any more.
    dd_ffe_json_free_table(&DDTRACE_G(ffe_json_retired));
}

void ddtrace_ffe_json_cache_gshutdown(HashTable **cache, HashTable **retired) {
    dd_ffe_json_free_table(retired);
    dd_ffe_json_free_table(cache);
}
#else
// Before PHP 7.3, ResultMapper decodes the JSON text.
void ddtrace_ffe_json_value(zval *dst, const ddog_FfeResult *result) {
    (void) result;
    ZVAL_UNDEF(dst);
}

void ddtrace_ffe_json_cache_post_deactivate(void) {}
void ddtrace_ffe_json_cache_gshutdown(HashTable **cache, HashTable **retired) {
    (void) cache;
    (void) retired;
}
#endif

bool ddtrace_ffe_record_flag_evaluation(zend_string *flag_key, zend_string *variant,
    zend_string *allocation_key, zend_string *targeting_key, HashTable *attributes,
    zend_string *error_type, bool runtime_default_used, bool observe_full_evaluation_data,
    int64_t evaluation_timestamp_ms, bool targeting_key_invalid) {
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
    DDTRACE_G(ffe_exposures_dropped) = 0;
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
        LOG(DEBUG, "Feature Flags: exposure suppressed; missing flag key or variant");
        return;
    }

    if (DDTRACE_G(ffe_exposure_buffer_len) >= DD_FFE_EXPOSURE_BUFFER_LIMIT) {
        // Reported once per flush rather than per dropped exposure.
        DDTRACE_G(ffe_exposures_dropped)++;
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
    LOG(DEBUG, "Feature Flags: exposure queued (pending=%zu); delivery awaits sidecar handoff", DDTRACE_G(ffe_exposure_buffer_len));
}

bool ddtrace_ffe_flush_exposures(void) {
    size_t exposure_count = DDTRACE_G(ffe_exposure_buffer_len);
    dd_ffe_exposure *buffer = (dd_ffe_exposure *) DDTRACE_G(ffe_exposure_buffer);

    if (exposure_count == 0 || !buffer) {
        if (get_global_DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED()) {
            LOG(DEBUG, "Feature Flags: exposure flush complete; empty queue (0 exposures)");
        }
        return false;
    }

    LOG(DEBUG, "Feature Flags: exposure flush started (%zu exposure(s)); attempting sidecar handoff", exposure_count);

    if (DDTRACE_G(ffe_exposures_dropped)) {
        LOG(DEBUG, "Feature Flags: dropped %zu exposure(s); buffer limit reached (%d)",
            DDTRACE_G(ffe_exposures_dropped), DD_FFE_EXPOSURE_BUFFER_LIMIT);
    }

    if (!DATADOG_G(sidecar) || !DATADOG_G(request_initialized)) {
        LOG(DEBUG, "Feature Flags: dropping %zu exposure(s); sidecar or request is not initialized", exposure_count);
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
            &context,
            exposure_slice));

    // A successful IPC handoff is not confirmation of delivery to intake.
    // datadog_ffi_try already logs the failure reason.
    if (flushed) {
        LOG(DEBUG, "Feature Flags: handed off %zu exposure(s) to sidecar; the sidecar logs delivery as ffe_exposures_flusher", exposure_count);
    } else {
        LOG(DEBUG, "Feature Flags: dropped %zu exposure(s); sidecar handoff failed and is not retried", exposure_count);
    }

    efree(ffi_exposures);
    dd_ffe_clear_exposures();
    return flushed;
}
