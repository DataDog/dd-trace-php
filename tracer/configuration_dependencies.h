#include <string.h>

#include "configuration.h"
#include "tracer_otel_config.h"
#include <ext/otel_config.h>
#include "span.h"
#include "random.h"
#include "ip_extraction.h"
#include "live_debugger.h"

static bool dd_parse_tags(zai_str value, zval *decoded_value, bool persistent) {
    ZVAL_ARR(decoded_value, pemalloc(sizeof(HashTable), persistent));
    zend_hash_init(Z_ARR_P(decoded_value), 8, NULL, persistent ? ZVAL_INTERNAL_PTR_DTOR : ZVAL_PTR_DTOR, persistent);

    if (value.len == 0) {
        return true;
    }

    const char *str = value.ptr;
    const char *end = str + value.len;
    const char *current = str;

    /* Prefer comma-separated tags when the input contains a comma. */
    char separator = memchr(str, ',', value.len) ? ',' : ' ';

    while (current < end) {
        while (current < end && (*current == ' ' || *current == separator)) {
            current++;
        }
        if (current == end) {
            break;
        }

        const char *tag_end = memchr(current, separator, end - current);
        if (!tag_end) {
            tag_end = end;
        }

        const char *key_start = current;
        const char *key_end = tag_end;
        const char *value_start = tag_end;
        const char *value_end = tag_end;
        const char *colon = memchr(current, ':', tag_end - current);
        if (colon) {
            key_end = colon;
            value_start = colon + 1;
        }

        while (key_start < key_end && *key_start == ' ') {
            key_start++;
        }
        while (key_end > key_start && key_end[-1] == ' ') {
            key_end--;
        }

        if (key_start != key_end) {
            while (value_start < value_end && *value_start == ' ') {
                value_start++;
            }
            while (value_end > value_start && value_end[-1] == ' ') {
                value_end--;
            }

            zend_string *key = zend_string_init(key_start, key_end - key_start, persistent);
            zval decoded_tag_value;
            ZVAL_STR(&decoded_tag_value,
                     zend_string_init(value_start, value_end - value_start, persistent));
            /* OpenTelemetry attributes have unique keys. Retain the last value,
             * matching the tracer's historical DD_TAGS behavior. */
            zend_hash_update(Z_ARRVAL_P(decoded_value), key, &decoded_tag_value);
            zend_string_release(key);
        }

        current = tag_end + 1;
    }

    return true;
}

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

static bool dd_parse_propagation_behavior_extract(zai_str value, zval *decoded_value, bool persistent) {
    UNUSED(persistent);
    if (zai_str_eq_ci_cstr(value, "continue")) {
        ZVAL_LONG(decoded_value, DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT_CONTINUE);
    } else if (zai_str_eq_ci_cstr(value, "restart")) {
        ZVAL_LONG(decoded_value, DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT_RESTART);
    } else if (zai_str_eq_ci_cstr(value, "ignore")) {
        ZVAL_LONG(decoded_value, DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT_IGNORE);
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

// Custom parser to ensure security-testing headers are always captured.
static bool dd_parse_header_tags(zai_str value, zval *decoded_value, bool persistent) {
    if (!zai_config_decode_value(value, ZAI_CONFIG_TYPE_SET_OR_MAP_LOWERCASE, NULL, decoded_value, persistent)) {
        return false;
    }

    HashTable *ht = Z_ARRVAL_P(decoded_value);
    zval empty;
    if (persistent) {
        ZVAL_EMPTY_PSTRING(&empty);
    } else {
        ZVAL_EMPTY_STRING(&empty);
    }
    Z_TRY_ADDREF(empty);
    zend_hash_str_update(ht, ZEND_STRL("x-datadog-endpoint-scan"), &empty);
    zend_hash_str_update(ht, ZEND_STRL("x-datadog-security-test"), &empty);

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
