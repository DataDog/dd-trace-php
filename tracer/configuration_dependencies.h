#include "configuration.h"
#include "tracer_otel_config.h"
#include "span.h"
#include "random.h"
#include "ip_extraction.h"
#include "live_debugger.h"

static bool dd_parse_dbm_mode(zai_str value, zval *decoded_value, bool persistent) {
    UNUSED(persistent);
    if (zai_str_eq_ci_cstr(value, "disabled")) {
        ZVAL_LONG(decoded_value, DD_TRACE_DBM_PROPAGATION_DISABLED);
    } else if (zai_str_eq_ci_cstr(value, "service")) {
        ZVAL_LONG(decoded_value, DD_TRACE_DBM_PROPAGATION_SERVICE);
    } else if (zai_str_eq_ci_cstr(value, "full")) {
        ZVAL_LONG(decoded_value, DD_TRACE_DBM_PROPAGATION_FULL);
    } else if (zai_str_eq_ci_cstr(value, "dynamic_service")) {
        ZVAL_LONG(decoded_value, DD_TRACE_DBM_PROPAGATION_DYNAMIC_SERVICE);
    } else {
        return false;
    }

    return true;
}

static bool dd_parse_sampling_rules_format(zai_str value, zval *decoded_value, bool persistent) {
    UNUSED(persistent);
    if (zai_str_eq_ci_cstr(value, "regex")) {
        ZVAL_LONG(decoded_value, DD_TRACE_SAMPLING_RULES_FORMAT_REGEX);
    } else if (zai_str_eq_ci_cstr(value, "glob")) {
        ZVAL_LONG(decoded_value, DD_TRACE_SAMPLING_RULES_FORMAT_GLOB);
    } else {
        return false;
    }

    return true;
}

static bool dd_parse_sidecar_connection_mode(zai_str value, zval *decoded_value, bool persistent) {
    UNUSED(persistent);
    if (zai_str_eq_ci_cstr(value, "auto")) {
        ZVAL_LONG(decoded_value, DD_TRACE_SIDECAR_CONNECTION_MODE_AUTO);
    } else if (zai_str_eq_ci_cstr(value, "subprocess")) {
        ZVAL_LONG(decoded_value, DD_TRACE_SIDECAR_CONNECTION_MODE_SUBPROCESS);
    } else if (zai_str_eq_ci_cstr(value, "thread")) {
        ZVAL_LONG(decoded_value, DD_TRACE_SIDECAR_CONNECTION_MODE_THREAD);
    } else {
        return false;
    }

    return true;
}

static bool dd_parse_tags(zai_str value, zval *decoded_value, bool persistent) {
    ZVAL_ARR(decoded_value, pemalloc(sizeof(HashTable), persistent));
    zend_hash_init(Z_ARR_P(decoded_value), 8, NULL, persistent ? ZVAL_INTERNAL_PTR_DTOR : ZVAL_PTR_DTOR, persistent);

    if (value.len == 0) {
        return true;
    }

    const char *str = value.ptr;
    const char *end = str + value.len;
    const char *current = str;

    // Determine separator - prefer comma if present, otherwise use space
    char sep = memchr(str, ',', value.len) ? ',': ' ';

    while (current < end) {
        // Skip leading whitespace
        while (current < end && (*current == ' ' || *current == sep)) current++;
        if (current == end) {
            // Abort if only separators are remaining
            break;
        }

        // Find next separator, this will be the end of the tag
        const char *tag_end = memchr(current, sep, end - current);
        if (!tag_end) {
            tag_end = end;
        }
        // Prepare key and value
        // Initialize key to be the entire tag and value to be empty
        const char *key_start = current;
        const char *key_end = tag_end;
        const char *val_start = tag_end;
        const char *val_end = tag_end;
        // If the tag has a colon, use the index of the colon to split the tag into key and value
        const char *colon = memchr(current, ':', tag_end - current);
        if (colon) {
            // Tag has a colon, use the index of the colon to  split into key and value
            key_end = colon;
            val_start = colon + 1;
        }

        // Strip whitespace from key
        while (key_start < key_end && *key_start == ' ') key_start++;
        while (key_end > key_start && key_end[-1] == ' ') key_end--;
        // Only add if key is non-empty
        if (key_start != key_end) {
            // Strip whitespace from value
            while (val_start < val_end && *val_start == ' ') val_start++;
            while (val_end > val_start && val_end[-1] == ' ') val_end--;

            zval val;
            ZVAL_STR(&val, zend_string_init(val_start, val_end - val_start, persistent));
            zend_hash_str_update(Z_ARRVAL_P(decoded_value), key_start, key_end - key_start, &val);
        }
        // Move to the start of the next tag
        current = tag_end + 1;
    }

    return true;
}

#define INI_CHANGE_DYNAMIC_CONFIG(name, config) \
    static bool ddtrace_alter_##name(zval *old_value, zval *new_value, zend_string *new_str) { \
        UNUSED(old_value, new_value); \
        /* When RC writes, bypass the check for ddog_remote_config_alter_dynamic_config */ \
        if (!DATADOG_G(remote_config_state) || DATADOG_G(remote_config_writing)) {  \
            return true; \
        } \
        return ddog_remote_config_alter_dynamic_config(DATADOG_G(remote_config_state), DDOG_CHARSLICE_C(config), zend_string_copy(new_str)); \
    }

INI_CHANGE_DYNAMIC_CONFIG(DD_TRACE_HEADER_TAGS, "datadog.trace.header_tags")
INI_CHANGE_DYNAMIC_CONFIG(DD_TRACE_SAMPLE_RATE, "datadog.trace.sample_rate")
INI_CHANGE_DYNAMIC_CONFIG(DD_TRACE_LOGS_ENABLED, "datadog.logs_injection")
INI_CHANGE_DYNAMIC_CONFIG(DD_CODE_ORIGIN_FOR_SPANS_ENABLED, "datadog.code_origin_for_spans_enabled")
INI_CHANGE_DYNAMIC_CONFIG(DD_EXCEPTION_REPLAY_ENABLED, "datadog.exception_replay_enabled")
