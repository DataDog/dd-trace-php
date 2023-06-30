#ifndef DD_CONFIGURATION_H
#define DD_CONFIGURATION_H

#include <stdbool.h>

#include "compatibility.h"
#include "config/config.h"
#include "ddtrace_string.h"
#include "integrations/integrations.h"
#include "span.h"

// note: only call this if ddtrace_config_trace_enabled() returns true
bool ddtrace_config_integration_enabled(ddtrace_integration_name integration_name);

bool ddtrace_config_minit(int module_number);
void ddtrace_config_first_rinit();

extern bool runtime_config_first_init;

enum ddtrace_dbm_propagation_mode {
    DD_TRACE_DBM_PROPAGATION_DISABLED,
    DD_TRACE_DBM_PROPAGATION_SERVICE,
    DD_TRACE_DBM_PROPAGATION_FULL,
};

/* From the curl docs on CONNECT_TIMEOUT_MS:
 *     If libcurl is built to use the standard system name resolver, that
 *     portion of the transfer will still use full-second resolution for
 *     timeouts with a minimum timeout allowed of one second.
 * The default is 0, which means to wait indefinitely. Even in the background
 * we don't want to wait forever, but I'm not sure what to set the connect
 * timeout to.
 * A user hit an issue with the userland time of 100.
 */
#define DD_TRACE_AGENT_CONNECT_TIMEOUT_VAL 100
#define DD_TRACE_BGS_CONNECT_TIMEOUT_VAL 2000

/* Default for the PHP sender; should be kept in sync with DDTrace\Transport\Http::DEFAULT_AGENT_TIMEOUT */
#define DD_TRACE_AGENT_TIMEOUT_VAL 500

/* This should be at least an order of magnitude higher than the userland HTTP Transport default. */
#define DD_TRACE_BGS_TIMEOUT_VAL 5000

#define DD_INTEGRATION_ANALYTICS_ENABLED_DEFAULT false
#define DD_INTEGRATION_ANALYTICS_SAMPLE_RATE_DEFAULT 1

#if _BUILD_FROM_PECL_
#define DD_DEFAULT_REQUEST_INIT_HOOK_PATH "@php_dir@/datadog_trace/bridge/dd_wrap_autoloader.php"
#else
#define DD_DEFAULT_REQUEST_INIT_HOOK_PATH ""
#endif

#define DD_CFG_STR(str) #str
#define DD_CFG_EXPSTR(str) DD_CFG_STR(str)
#define INTEGRATION(id, ...)                                                                                           \
    CONFIG(BOOL, DD_TRACE_##id##_ENABLED, "true")                                                                      \
    CALIAS(BOOL, DD_TRACE_##id##_ANALYTICS_ENABLED, DD_CFG_EXPSTR(DD_INTEGRATION_ANALYTICS_ENABLED_DEFAULT),           \
           CALIASES(DD_CFG_STR(DD_##id##_ANALYTICS_ENABLED), DD_CFG_STR(DD_TRACE_##id##_ANALYTICS_ENABLED)))           \
    CALIAS(DOUBLE, DD_TRACE_##id##_ANALYTICS_SAMPLE_RATE, DD_CFG_EXPSTR(DD_INTEGRATION_ANALYTICS_SAMPLE_RATE_DEFAULT), \
           CALIASES(DD_CFG_STR(DD_##id##_ANALYTICS_SAMPLE_RATE), DD_CFG_STR(DD_TRACE_##id##_ANALYTICS_SAMPLE_RATE)))

#define DD_TRACE_OBFUSCATION_QUERY_STRING_REGEXP_DEFAULT \
    "(?i)(?:p(?:ass)?w(?:or)?d|pass(?:_?phrase)?|secret|(?:api_?|private_?|public_?|access_?|secret_?)key(?:_?id)?|token|consumer_?(?:id|key|secret)|sign(?:ed|ature)?|auth(?:entication|orization)?)(?:(?:\\s|%20)*(?:=|%3D)[^&]+|(?:\"|%22)(?:\\s|%20)*(?::|%3A)(?:\\s|%20)*(?:\"|%22)(?:%2[^2]|%[^2]|[^\"%])+(?:\"|%22))|bearer(?:\\s|%20)+[a-z0-9\\._\\-]|token(?::|%3A)[a-z0-9]{13}|gh[opsu]_[0-9a-zA-Z]{36}|ey[I-L](?:[\\w=-]|%3D)+\\.ey[I-L](?:[\\w=-]|%3D)+(?:\\.(?:[\\w.+\\/=-]|%3D|%2F|%2B)+)?|[\\-]{5}BEGIN(?:[a-z\\s]|%20)+PRIVATE(?:\\s|%20)KEY[\\-]{5}[^\\-]+[\\-]{5}END(?:[a-z\\s]|%20)+PRIVATE(?:\\s|%20)KEY|ssh-rsa(?:\\s|%20)*(?:[a-z0-9\\/\\.+]|%2F|%5C|%2B){100,}"

#define DD_CONFIGURATION                                                                                       \
    CALIAS(STRING, DD_TRACE_REQUEST_INIT_HOOK, DD_DEFAULT_REQUEST_INIT_HOOK_PATH,                              \
           CALIASES("DDTRACE_REQUEST_INIT_HOOK"), .ini_change = zai_config_system_ini_change)                  \
    CONFIG(STRING, DD_TRACE_AGENT_URL, "", .ini_change = zai_config_system_ini_change)                         \
    CONFIG(STRING, DD_AGENT_HOST, "", .ini_change = zai_config_system_ini_change)                              \
    CONFIG(STRING, DD_DOGSTATSD_URL, "")                                                                       \
    CONFIG(BOOL, DD_DISTRIBUTED_TRACING, "true")                                                               \
    CONFIG(STRING, DD_DOGSTATSD_PORT, "8125")                                                                  \
    CONFIG(STRING, DD_ENV, "")                                                                                 \
    CONFIG(BOOL, DD_AUTOFINISH_SPANS, "false")                                                                 \
    CONFIG(BOOL, DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED, "true")                                               \
    CONFIG(BOOL, DD_HTTP_SERVER_ROUTE_BASED_NAMING, "true")                                                    \
    CONFIG(SET, DD_INTEGRATIONS_DISABLED, "default")                                                           \
    CONFIG(BOOL, DD_PRIORITY_SAMPLING, "true")                                                                 \
    CALIAS(STRING, DD_SERVICE, "", CALIASES("DD_SERVICE_NAME"))                                                \
    CONFIG(MAP, DD_SERVICE_MAPPING, "")                                                                        \
    CALIAS(MAP, DD_TAGS, "", CALIASES("DD_TRACE_GLOBAL_TAGS"))                                                 \
    CONFIG(INT, DD_TRACE_AGENT_PORT, "0", .ini_change = zai_config_system_ini_change)                          \
    CONFIG(BOOL, DD_TRACE_ANALYTICS_ENABLED, "false")                                                          \
    CONFIG(BOOL, DD_TRACE_AUTO_FLUSH_ENABLED, "false")                                                         \
    CONFIG(BOOL, DD_TRACE_CLI_ENABLED, "false")                                                                \
    CONFIG(BOOL, DD_TRACE_MEASURE_COMPILE_TIME, "true")                                                        \
    CONFIG(BOOL, DD_TRACE_DEBUG, "false")                                                                      \
    CONFIG(BOOL, DD_TRACE_ENABLED, "true", .ini_change = ddtrace_alter_dd_trace_disabled_config)               \
    CONFIG(BOOL, DD_TRACE_TELEMETRY_ENABLED, "false", .ini_change = zai_config_system_ini_change)              \
    CONFIG(BOOL, DD_TRACE_HEALTH_METRICS_ENABLED, "false", .ini_change = zai_config_system_ini_change)         \
    CONFIG(DOUBLE, DD_TRACE_HEALTH_METRICS_HEARTBEAT_SAMPLE_RATE, "0.001")                                     \
    CONFIG(BOOL, DD_TRACE_DB_CLIENT_SPLIT_BY_INSTANCE, "false")                                                \
    CONFIG(BOOL, DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN, "false")                                                \
    CONFIG(BOOL, DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST, "false")                                                 \
    CONFIG(STRING, DD_TRACE_MEMORY_LIMIT, "")                                                                  \
    CONFIG(BOOL, DD_TRACE_REPORT_HOSTNAME, "false")                                                            \
    CONFIG(BOOL, DD_TRACE_FLUSH_COLLECT_CYCLES, "false")                                                       \
    CONFIG(BOOL, DD_TRACE_REMOVE_ROOT_SPAN_LARAVEL_QUEUE, "true")                                              \
    CONFIG(BOOL, DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS, "false")                                         \
    CONFIG(SET, DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX, "")                                                      \
    CONFIG(SET, DD_TRACE_RESOURCE_URI_MAPPING_INCOMING, "")                                                    \
    CONFIG(SET, DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING, "")                                                    \
    CONFIG(SET, DD_TRACE_RESOURCE_URI_QUERY_PARAM_ALLOWED, "")                                                 \
    CONFIG(SET, DD_TRACE_HTTP_URL_QUERY_PARAM_ALLOWED, "*")                                                    \
    CONFIG(SET, DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED, "")                                                     \
    CONFIG(INT, DD_TRACE_RATE_LIMIT, "0", .ini_change = zai_config_system_ini_change)                          \
    CALIAS(DOUBLE, DD_TRACE_SAMPLE_RATE, "1", CALIASES("DD_SAMPLING_RATE"))                                    \
    CONFIG(JSON, DD_TRACE_SAMPLING_RULES, "[]")                                                                \
    CONFIG(JSON, DD_SPAN_SAMPLING_RULES, "[]")                                                                 \
    CONFIG(STRING, DD_SPAN_SAMPLING_RULES_FILE, "", .ini_change = ddtrace_alter_sampling_rules_file_config)    \
    CONFIG(SET_LOWERCASE, DD_TRACE_HEADER_TAGS, "")                                                            \
    CONFIG(INT, DD_TRACE_X_DATADOG_TAGS_MAX_LENGTH, "512")                                                     \
    CONFIG(BOOL, DD_TRACE_PROPAGATE_SERVICE, "false")                                                          \
    CALIAS(SET_LOWERCASE, DD_TRACE_PROPAGATION_STYLE_EXTRACT, "tracecontext,Datadog,B3,B3 single header",      \
           CALIASES("DD_PROPAGATION_STYLE_EXTRACT"))                                                           \
    CALIAS(SET_LOWERCASE, DD_TRACE_PROPAGATION_STYLE_INJECT, "tracecontext,Datadog",                           \
           CALIASES("DD_PROPAGATION_STYLE_INJECT"))                                                            \
    CONFIG(SET_LOWERCASE, DD_TRACE_PROPAGATION_STYLE, "tracecontext,Datadog")                                  \
    CONFIG(SET, DD_TRACE_TRACED_INTERNAL_FUNCTIONS, "")                                                        \
    CONFIG(INT, DD_TRACE_AGENT_TIMEOUT, DD_CFG_EXPSTR(DD_TRACE_AGENT_TIMEOUT_VAL),                             \
           .ini_change = zai_config_system_ini_change)                                                         \
    CONFIG(INT, DD_TRACE_AGENT_CONNECT_TIMEOUT, DD_CFG_EXPSTR(DD_TRACE_AGENT_CONNECT_TIMEOUT_VAL),             \
           .ini_change = zai_config_system_ini_change)                                                         \
    CONFIG(INT, DD_TRACE_DEBUG_PRNG_SEED, "-1", .ini_change = ddtrace_reseed_seed_change)                      \
    CONFIG(BOOL, DD_LOG_BACKTRACE, "false")                                                                    \
    CONFIG(BOOL, DD_TRACE_GENERATE_ROOT_SPAN, "true", .ini_change = ddtrace_span_alter_root_span_config)       \
    CONFIG(INT, DD_TRACE_SPANS_LIMIT, "1000")                                                                  \
    CONFIG(BOOL, DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED, "false")                                         \
    CONFIG(INT, DD_TRACE_AGENT_MAX_CONSECUTIVE_FAILURES,                                                       \
           DD_CFG_EXPSTR(DD_TRACE_CIRCUIT_BREAKER_DEFAULT_MAX_CONSECUTIVE_FAILURES))                           \
    CONFIG(INT, DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC,                                                        \
           DD_CFG_EXPSTR(DD_TRACE_CIRCUIT_BREAKER_DEFAULT_RETRY_TIME_MSEC))                                    \
    CONFIG(INT, DD_TRACE_BGS_CONNECT_TIMEOUT, DD_CFG_EXPSTR(DD_TRACE_BGS_CONNECT_TIMEOUT_VAL),                 \
           .ini_change = zai_config_system_ini_change)                                                         \
    CONFIG(INT, DD_TRACE_BGS_TIMEOUT, DD_CFG_EXPSTR(DD_TRACE_BGS_TIMEOUT_VAL),                                 \
           .ini_change = zai_config_system_ini_change)                                                         \
    CONFIG(INT, DD_TRACE_AGENT_FLUSH_INTERVAL, "5000", .ini_change = zai_config_system_ini_change)             \
    CONFIG(INT, DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS, "10")                                                   \
    CONFIG(INT, DD_TRACE_SHUTDOWN_TIMEOUT, "5000", .ini_change = zai_config_system_ini_change)                 \
    CONFIG(BOOL, DD_TRACE_STARTUP_LOGS, "true")                                                                \
    CONFIG(BOOL, DD_TRACE_AGENT_DEBUG_VERBOSE_CURL, "false", .ini_change = zai_config_system_ini_change)       \
    CONFIG(BOOL, DD_TRACE_DEBUG_CURL_OUTPUT, "false", .ini_change = zai_config_system_ini_change)              \
    CONFIG(INT, DD_TRACE_BETA_HIGH_MEMORY_PRESSURE_PERCENT, "80", .ini_change = zai_config_system_ini_change)  \
    CONFIG(BOOL, DD_TRACE_WARN_LEGACY_DD_TRACE, "true")                                                        \
    CONFIG(BOOL, DD_TRACE_RETAIN_THREAD_CAPABILITIES, "false", .ini_change = zai_config_system_ini_change)     \
    CONFIG(STRING, DD_VERSION, "")                                                                             \
    CONFIG(STRING, DD_TRACE_OBFUSCATION_QUERY_STRING_REGEXP, DD_TRACE_OBFUSCATION_QUERY_STRING_REGEXP_DEFAULT) \
    CONFIG(BOOL, DD_TRACE_CLIENT_IP_ENABLED, "false")                                                          \
    CONFIG(STRING, DD_TRACE_CLIENT_IP_HEADER, "")                                                              \
    CONFIG(BOOL, DD_TRACE_FORKED_PROCESS, "true")                                                              \
    CONFIG(INT, DD_TRACE_HOOK_LIMIT, "100")                                                                    \
    CONFIG(INT, DD_TRACE_AGENT_MAX_PAYLOAD_SIZE, "52428800", .ini_change = zai_config_system_ini_change)       \
    CONFIG(INT, DD_TRACE_AGENT_STACK_INITIAL_SIZE, "131072", .ini_change = zai_config_system_ini_change)       \
    CONFIG(INT, DD_TRACE_AGENT_STACK_BACKLOG, "12", .ini_change = zai_config_system_ini_change)                \
    CONFIG(BOOL, DD_TRACE_PROPAGATE_USER_ID_DEFAULT, "false")                                                  \
    CONFIG(CUSTOM(INT), DD_DBM_PROPAGATION_MODE, "disabled", .parser = dd_parse_dbm_mode)                      \
    DD_INTEGRATIONS

#define CALIAS CONFIG

#define CONFIG(type, name, ...) DDTRACE_CONFIG_##name,
typedef enum { DD_CONFIGURATION } ddtrace_config_id;
#undef CONFIG

#define BOOL(id)                                                                                                 \
    static inline bool get_##id(void) { return IS_TRUE == Z_TYPE_P(zai_config_get_value(DDTRACE_CONFIG_##id)); } \
    static inline bool get_global_##id(void) {                                                                   \
        return IS_TRUE == Z_TYPE(zai_config_memoized_entries[DDTRACE_CONFIG_##id].decoded_value);                \
    }
#define INT(id)                                                                                            \
    static inline zend_long get_##id(void) { return Z_LVAL_P(zai_config_get_value(DDTRACE_CONFIG_##id)); } \
    static inline zend_long get_global_##id(void) {                                                        \
        return Z_LVAL(zai_config_memoized_entries[DDTRACE_CONFIG_##id].decoded_value);                     \
    }
#define DOUBLE(id)                                                                                      \
    static inline double get_##id(void) { return Z_DVAL_P(zai_config_get_value(DDTRACE_CONFIG_##id)); } \
    static inline double get_global_##id(void) {                                                        \
        return Z_DVAL(zai_config_memoized_entries[DDTRACE_CONFIG_##id].decoded_value);                  \
    }
#define STRING(id)                                                                                           \
    static inline zend_string *get_##id(void) { return Z_STR_P(zai_config_get_value(DDTRACE_CONFIG_##id)); } \
    static inline zend_string *get_global_##id(void) {                                                       \
        return Z_STR(zai_config_memoized_entries[DDTRACE_CONFIG_##id].decoded_value);                        \
    }
#define SET MAP
#define SET_LOWERCASE MAP
#define JSON MAP
#define MAP(id)                                                                                             \
    static inline zend_array *get_##id(void) { return Z_ARR_P(zai_config_get_value(DDTRACE_CONFIG_##id)); } \
    static inline zend_array *get_global_##id(void) {                                                       \
        return Z_ARR(zai_config_memoized_entries[DDTRACE_CONFIG_##id].decoded_value);                       \
    }
#define CUSTOM(type) type

#define CONFIG(type, name, ...) type(name)
DD_CONFIGURATION
#undef CONFIG

#undef STRING
#undef MAP
#undef SET
#undef SET_LOWERCASE
#undef JSON
#undef BOOL
#undef INT
#undef DOUBLE

#undef CUSTOM
#undef CALIAS

#endif  // DD_CONFIGURATION_H
