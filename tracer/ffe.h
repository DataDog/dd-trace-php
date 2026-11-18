#ifndef DDTRACE_FFE_H
#define DDTRACE_FFE_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <zend.h>
#include <components-rs/common.h>

typedef enum {
    DDTRACE_FFE_SOURCE_DISABLED,
    DDTRACE_FFE_SOURCE_REMOTE_CONFIG,
    DDTRACE_FFE_SOURCE_AGENTLESS,
} ddtrace_ffe_source;

// Resolves the startup configuration once per process.
void ddtrace_ffe_startup(void);
void ddtrace_ffe_shutdown(void);
void ddtrace_ffe_handle_fork(void);
bool ddtrace_ffe_enabled(void);
bool ddtrace_ffe_remote_config_enabled(void);
bool ddtrace_ffe_agentless_enabled(void);
// The agentless source for new Remote Config readers, NULL if they cannot use it.
const ddog_FfeCdnConfig *ddtrace_ffe_cdn_config(void);
// Reports diagnostics on first use and waits for the initial agentless configuration.
void ddtrace_ffe_before_evaluate(void);
void ddtrace_ffe_provider_state(zval *state);

bool ddtrace_ffe_record_evaluation_metric(zend_string *flag_key, zend_string *variant, const char *reason, const char *error_type, zend_string *allocation_key);
bool ddtrace_ffe_flush_evaluation_metrics(void);

bool ddtrace_ffe_record_flag_evaluation(zend_string *flag_key, zend_string *variant,
    zend_string *allocation_key, zend_string *targeting_key, HashTable *attributes,
    zend_string *error_type, bool runtime_default_used, bool observe_full_evaluation_data,
    int64_t evaluation_timestamp_ms, bool targeting_key_invalid);

// Sets dst to the decoded array of a JSON flag value: an immutable array that
// lives as long as the configuration, shared by every evaluation of that
// value on this thread. Leaves dst undefined if it cannot provide one.
void ddtrace_ffe_json_value(zval *dst, const ddog_FfeResult *result);
// Frees the arrays of replaced configurations, once no request uses them.
void ddtrace_ffe_json_cache_post_deactivate(void);
// Takes the fields of the globals being destroyed, which may be another thread's.
void ddtrace_ffe_json_cache_gshutdown(HashTable **cache, HashTable **retired);

void ddtrace_ffe_record_exposure(zend_string *flag_key, zend_string *targeting_key, zend_string *subject_attributes_json, zend_string *allocation_key, zend_string *variant, int32_t serial_id, bool has_serial_id);
bool ddtrace_ffe_flush_exposures(void);

#endif // DDTRACE_FFE_H
