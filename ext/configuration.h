#ifndef DATADOG_CONFIGURATION_H
#define DATADOG_CONFIGURATION_H

#include <stdbool.h>

#include "compatibility.h"
#include <config/config.h>

bool datadog_config_minit(int module_number);
void datadog_config_first_rinit();

extern bool runtime_config_first_init;
extern zai_config_entry datadog_config_entries[];

enum datadog_sidecar_connection_mode {
    DD_TRACE_SIDECAR_CONNECTION_MODE_AUTO = 0,       // Default: try subprocess, fallback to thread
    DD_TRACE_SIDECAR_CONNECTION_MODE_SUBPROCESS = 1, // Force subprocess only
    DD_TRACE_SIDECAR_CONNECTION_MODE_THREAD = 2,     // Force thread only
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
#define DD_TRACE_AGENT_TIMEOUT_VAL 500
#define DD_TRACE_AGENT_FLUSH_INTERVAL_VAL 1001

#define DD_CFG_STR(str) #str
#define DD_CFG_EXPSTR(str) DD_CFG_STR(str)

#ifdef __SANITIZE_ADDRESS__
#define DD_CRASHTRACKING_ENABLED_DEFAULT "false"
#else
#define DD_CRASHTRACKING_ENABLED_DEFAULT "true"
#endif

#define DATADOG_CONFIGURATION                                                                                  \
    CONFIG(STRING, DD_TRACE_AGENT_URL, "", .ini_change = zai_config_system_ini_change)                         \
    CONFIG(STRING, DD_AGENT_HOST, "localhost", .ini_change = zai_config_system_ini_change)                     \
    CONFIG(STRING, DD_DOGSTATSD_URL, "http://localhost:8125")                                                  \
    CONFIG(STRING, DD_DOGSTATSD_HOST, "localhost")                                                             \
    CONFIG(STRING, DD_API_KEY, "", .ini_change = zai_config_system_ini_change)                                 \
    CONFIG(INT, DD_DOGSTATSD_PORT, "8125")                                                                     \
    CONFIG(STRING, DD_ENV, "", .ini_change = datadog_alter_dd_env,                                             \
           .env_config_fallback = ddtrace_conf_otel_resource_attributes_env)                                   \
    CONFIG(STRING, DD_SERVICE, "", .ini_change = datadog_alter_dd_service,                                     \
           .env_config_fallback = ddtrace_conf_otel_service_name)                                              \
    CONFIG(MAP, DD_SERVICE_MAPPING, "")                                                                        \
    CONFIG(CUSTOM(MAP), DD_TAGS, "",                                                                           \
           .env_config_fallback = ddtrace_conf_otel_resource_attributes_tags,                                  \
           .parser = dd_parse_tags)                                                                            \
    CONFIG(INT, DD_TRACE_AGENT_PORT, "8126", .ini_change = zai_config_system_ini_change)                       \
    CONFIG(BOOL, DD_TRACE_CLI_ENABLED, "true")                                                                 \
    CONFIG(BOOL, DD_TRACE_DEBUG, "false", .ini_change = datadog_alter_dd_trace_debug)                          \
    CONFIG(BOOL, DD_TRACE_ENABLED, "true", .ini_change = datadog_alter_dd_trace_disabled_config,               \
           .env_config_fallback = ddtrace_conf_otel_traces_exporter)                                           \
    CONFIG(BOOL, DD_INSTRUMENTATION_TELEMETRY_ENABLED, "true", .ini_change = zai_config_system_ini_change)     \
    CONFIG(BOOL, DD_TRACE_HEALTH_METRICS_ENABLED, "false", .ini_change = zai_config_system_ini_change)         \
    CONFIG(DOUBLE, DD_TRACE_HEALTH_METRICS_HEARTBEAT_SAMPLE_RATE, "0.001")                                     \
    CONFIG(BOOL, DD_TRACE_REPORT_HOSTNAME, "false")                                                            \
    CONFIG(STRING, DD_HOSTNAME, "")                                                                            \
    CONFIG(BOOL, DD_TRACE_FORCE_FLUSH_ON_SHUTDOWN, "false") /* true if pid == 1 || ppid == 1 */                \
    CONFIG(BOOL, DD_TRACE_FORCE_FLUSH_ON_SIGTERM, "false") /* true if pid == 1 || ppid == 1 */                 \
    CONFIG(BOOL, DD_TRACE_FORCE_FLUSH_ON_SIGINT, "false") /* true if pid == 1 || ppid == 1 */                  \
    CONFIG(BOOL, DD_APPSEC_ENABLED, "false", .ini_change = zai_config_system_ini_change)                       \
    CONFIG(BOOL, DD_APPSEC_RASP_ENABLED , "true")                                                              \
    CONFIG(INT, DD_TRACE_AGENT_TIMEOUT, DD_CFG_EXPSTR(DD_TRACE_AGENT_TIMEOUT_VAL),                             \
           .ini_change = zai_config_system_ini_change)                                                         \
    CONFIG(INT, DD_TRACE_AGENT_CONNECT_TIMEOUT, DD_CFG_EXPSTR(DD_TRACE_AGENT_CONNECT_TIMEOUT_VAL),             \
           .ini_change = zai_config_system_ini_change)                                                         \
    CONFIG(BOOL, DD_LOG_BACKTRACE, "false")                                                                    \
    CONFIG(BOOL, DD_CRASHTRACKING_ENABLED, DD_CRASHTRACKING_ENABLED_DEFAULT)                                   \
    CONFIG(INT, DD_TRACE_AGENT_FLUSH_INTERVAL, DD_CFG_EXPSTR(DD_TRACE_AGENT_FLUSH_INTERVAL_VAL),               \
           .ini_change = zai_config_system_ini_change)                                                         \
    CONFIG(INT, DD_TELEMETRY_HEARTBEAT_INTERVAL, "60", .ini_change = zai_config_system_ini_change)             \
    CONFIG(INT, DD_TELEMETRY_EXTENDED_HEARTBEAT_INTERVAL, "86400",                                             \
           .ini_change = zai_config_system_ini_change)                                                         \
    CONFIG(INT, DD_TRACE_SHUTDOWN_TIMEOUT, "5000", .ini_change = zai_config_system_ini_change)                 \
    CONFIG(BOOL, DD_TRACE_STARTUP_LOGS, "true")                                                                \
    CONFIG(BOOL, DD_TRACE_ONCE_LOGS, "true")                                                                   \
    CONFIG(BOOL, DD_TRACE_AGENTLESS, "false", .ini_change = zai_config_system_ini_change)                      \
    CONFIG(STRING, DD_VERSION, "", .ini_change = datadog_alter_dd_version,                                     \
           .env_config_fallback = ddtrace_conf_otel_resource_attributes_version)                               \
    CONFIG(INT, DD_TRACE_BUFFER_SIZE, "2097152", .ini_change = zai_config_system_ini_change)                   \
    CONFIG(INT, DD_TRACE_AGENT_MAX_PAYLOAD_SIZE, "52428800", .ini_change = zai_config_system_ini_change)       \
    CONFIG(INT, DD_TRACE_AGENT_STACK_BACKLOG, "12", .ini_change = zai_config_system_ini_change)                \
    CONFIG(INT, DD_TRACE_SIDECAR_BACKPRESSURE_BYTES, "4194304", .ini_change = zai_config_system_ini_change)    \
    CONFIG(INT, DD_TRACE_SIDECAR_BACKPRESSURE_QUEUE, "100", .ini_change = zai_config_system_ini_change)        \
    CONFIG(STRING, DD_TRACE_AGENT_TEST_SESSION_TOKEN, "", .ini_change = datadog_alter_test_session_token)      \
    CONFIG(CUSTOM(INT), DD_TRACE_SIDECAR_CONNECTION_MODE, "auto", .parser = dd_parse_sidecar_connection_mode)  \
    CONFIG(STRING, DD_TRACE_LOG_FILE, "", .ini_change = zai_config_system_ini_change)                          \
    CONFIG(STRING, DD_TRACE_LOG_LEVEL, "error", .ini_change = datadog_alter_dd_trace_log_level,                \
           .env_config_fallback = ddtrace_conf_otel_log_level)                                                 \
    CONFIG(BOOL, DD_APPSEC_SCA_ENABLED, "false", .ini_change = zai_config_system_ini_change)                   \
    CONFIG(BOOL, DD_TRACE_GIT_METADATA_ENABLED, "true")                                                        \
    CONFIG(STRING, DD_GIT_COMMIT_SHA, "")                                                                      \
    CONFIG(STRING, DD_GIT_REPOSITORY_URL, "")                                                                  \
    CONFIG(BOOL, DD_INJECT_FORCE, "false", .ini_change = zai_config_system_ini_change)                         \
    CONFIG(DOUBLE, DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS, "5.0", .ini_change = zai_config_system_ini_change)  \
    CONFIG(BOOL, DD_REMOTE_CONFIG_ENABLED, "true", .ini_change = zai_config_system_ini_change)                 \
    CONFIG(BOOL, DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED, "true")

#define DD_CONFIGURATIONS_ONLY
#ifdef DDTRACE
#include <tracer/configuration.h>
#else
#define DDTRACE_CONFIGURATION
#endif
#undef DD_CONFIGURATIONS_ONLY

#define DD_ALL_CONFIGURATIONS \
    DATADOG_CONFIGURATION \
    DDTRACE_CONFIGURATION \

#define CALIAS CONFIG

#define CONFIG(type, name, ...) DATADOG_CONFIG_##name,
typedef enum {
    DD_ALL_CONFIGURATIONS
} datadog_config_id;
#undef CONFIG

#define DD_CONFIGURATION DATADOG_CONFIGURATION
#include "configuration_helpers.h"

#ifdef _WIN32
static inline bool get_global_DD_TRACE_SIDECAR_TRACE_SENDER(void) { return true; }
#endif

#endif  // DATADOG_CONFIGURATION_H
