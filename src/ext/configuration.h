#ifndef DD_CONFIGURATION_H
#define DD_CONFIGURATION_H

#include <stdbool.h>

#include "compatibility.h"
#include "ddtrace_string.h"
#include "env_config.h"
#include "integrations/integrations.h"

/**
 * Returns true if `subject` matches "true" or "1".
 * Returns false if `subject` matches "false" or "0".
 * Returns `default_value` otherwise.
 * @param subject An already lowercased string
 * @param default_value
 * @return
 */
bool ddtrace_config_bool(ddtrace_string subject, bool default_value);

/**
 * Fetch the environment variable represented by `env_name` from the SAPI env
 * or regular environment and test if it is a boolean config value.
 * @see ddtrace_config_bool
 * @param env_name Name of the environment variable to fetch.
 * @param default_value
 * @return If the environment variable does not exist or is not bool-ish, return `default_value` instead.
 */
bool ddtrace_config_env_bool(ddtrace_string env_name, bool default_value TSRMLS_DC);

bool ddtrace_config_distributed_tracing_enabled(TSRMLS_D);
bool ddtrace_config_trace_enabled(TSRMLS_D);

#define DDTRACE_LONGEST_INTEGRATION_ENV_PREFIX_LEN 9  // "DD_TRACE_" FTW!
#define DDTRACE_LONGEST_INTEGRATION_ENV_SUFFIX_LEN 22  // "_ANALYTICS_SAMPLE_RATE" FTW!
#define DDTRACE_LONGEST_INTEGRATION_ENV_LEN                                              \
    (DDTRACE_LONGEST_INTEGRATION_ENV_PREFIX_LEN + DDTRACE_LONGEST_INTEGRATION_NAME_LEN + \
     DDTRACE_LONGEST_INTEGRATION_ENV_SUFFIX_LEN)

// note: only call this if ddtrace_config_trace_enabled() returns true
bool ddtrace_config_integration_enabled(ddtrace_string integration TSRMLS_DC);
bool ddtrace_config_integration_enabled_ex(ddtrace_integration_name integration_name TSRMLS_DC);
bool ddtrace_config_integration_analytics_enabled(ddtrace_string integration TSRMLS_DC);
double ddtrace_config_integration_analytics_sample_rate(ddtrace_string integration TSRMLS_DC);

size_t ddtrace_config_integration_env_name(char *name, const char *prefix, ddtrace_integration *integration,
                                           const char *suffix);

inline ddtrace_string ddtrace_string_getenv(char *str, size_t len TSRMLS_DC) {
    return ddtrace_string_cstring_ctor(ddtrace_getenv(str, len TSRMLS_CC));
}

// Returns an env var value as string. If the env is not defined it uses a fallback env variable name.
// Used when for backward compatibility we need to support a primary and secondary env variable name.
inline ddtrace_string ddtrace_string_getenv_multi(char *primary, size_t primary_len, char *secondary,
                                                  size_t secondary_len TSRMLS_DC) {
    return ddtrace_string_cstring_ctor(ddtrace_getenv_multi(primary, primary_len, secondary, secondary_len TSRMLS_CC));
}

struct ddtrace_memoized_configuration_t;
extern struct ddtrace_memoized_configuration_t ddtrace_memoized_configuration;

void ddtrace_initialize_config(TSRMLS_D);
void ddtrace_reload_config(TSRMLS_D);
void ddtrace_config_shutdown(void);

/* From the curl docs on CONNECT_TIMEOUT_MS:
 *     If libcurl is built to use the standard system name resolver, that
 *     portion of the transfer will still use full-second resolution for
 *     timeouts with a minimum timeout allowed of one second.
 * The default is 0, which means to wait indefinitely. Even in the background
 * we don't want to wait forever, but I'm not sure what to set the connect
 * timeout to.
 * A user hit an issue with the userland time of 100.
 */
#define DD_TRACE_AGENT_CONNECT_TIMEOUT 100L
#define DD_TRACE_BGS_CONNECT_TIMEOUT 2000L

/* Default for the PHP sender; should be kept in sync with DDTrace\Transport\Http::DEFAULT_AGENT_TIMEOUT */
#define DD_TRACE_AGENT_TIMEOUT 500L

/* This should be at least an order of magnitude higher than the userland HTTP Transport default. */
#define DD_TRACE_BGS_TIMEOUT 5000L

/* There is an issue on PHP 5.4 that has been around for a while that was
 * exposed by the background sender getting enabled. We need to fix that, but
 * until then let's not enable BGS by default on < 5.6.
 *
 * We also don't enable the sandbox on PHP 5.4 yet.
 */
#if PHP_VERSION_ID < 50600
#define DD_TRACE_BGS_ENABLED false
#else
#define DD_TRACE_BGS_ENABLED true
#endif

#define DD_CONFIGURATION                                                                                             \
    CHAR(get_dd_trace_agent_url, "DD_TRACE_AGENT_URL", "")                                                           \
    CHAR(get_dd_agent_host, "DD_AGENT_HOST", "localhost")                                                            \
    BOOL(get_dd_distributed_tracing, "DD_DISTRIBUTED_TRACING", true)                                                 \
    CHAR(get_dd_dogstatsd_port, "DD_DOGSTATSD_PORT", "8125")                                                         \
    CHAR(get_dd_env, "DD_ENV", "")                                                                                   \
    CHAR(get_dd_integrations_disabled, "DD_INTEGRATIONS_DISABLED", "")                                               \
    BOOL(get_dd_priority_sampling, "DD_PRIORITY_SAMPLING", true)                                                     \
    CHAR(get_dd_service, "DD_SERVICE", "")                                                                           \
    CHAR(get_dd_service_mapping, "DD_SERVICE_MAPPING", "")                                                           \
    CHAR(get_dd_service_name, "DD_SERVICE_NAME", "")                                                                 \
    CHAR(get_dd_tags, "DD_TAGS", "")                                                                                 \
    INT(get_dd_trace_agent_port, "DD_TRACE_AGENT_PORT", 8126)                                                        \
    BOOL(get_dd_trace_analytics_enabled, "DD_TRACE_ANALYTICS_ENABLED", false)                                        \
    BOOL(get_dd_trace_auto_flush_enabled, "DD_TRACE_AUTO_FLUSH_ENABLED", false)                                      \
    BOOL(get_dd_trace_cli_enabled, "DD_TRACE_CLI_ENABLED", false)                                                    \
    BOOL(get_dd_trace_measure_compile_time, "DD_TRACE_MEASURE_COMPILE_TIME", true)                                   \
    BOOL(get_dd_trace_debug, "DD_TRACE_DEBUG", false)                                                                \
    BOOL(get_dd_trace_enabled, "DD_TRACE_ENABLED", true)                                                             \
    CHAR(get_dd_trace_global_tags, "DD_TRACE_GLOBAL_TAGS", "")                                                       \
    BOOL(get_dd_trace_heath_metrics_enabled, "DD_TRACE_HEALTH_METRICS_ENABLED", false)                               \
    DOUBLE(get_dd_trace_heath_metrics_heartbeat_sample_rate, "DD_TRACE_HEALTH_METRICS_HEARTBEAT_SAMPLE_RATE", 0.001) \
    BOOL(get_dd_trace_http_client_split_by_domain, "DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN", false)                    \
    CHAR(get_dd_trace_memory_limit, "DD_TRACE_MEMORY_LIMIT", NULL)                                                   \
    BOOL(get_dd_trace_report_hostname, "DD_TRACE_REPORT_HOSTNAME", false)                                            \
    CHAR(get_dd_trace_resource_uri_fragment_regex, "DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX", "")                       \
    CHAR(get_dd_trace_resource_uri_mapping_incoming, "DD_TRACE_RESOURCE_URI_MAPPING_INCOMING", "")                   \
    CHAR(get_dd_trace_resource_uri_mapping_outgoing, "DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING", "")                   \
    DOUBLE(get_dd_trace_sample_rate, "DD_TRACE_SAMPLE_RATE", 1.0)                                                    \
    CHAR(get_dd_trace_sampling_rules, "DD_TRACE_SAMPLING_RULES", "")                                                 \
    CHAR(get_dd_trace_traced_internal_functions, "DD_TRACE_TRACED_INTERNAL_FUNCTIONS", "")                           \
    INT(get_dd_trace_agent_timeout, "DD_TRACE_AGENT_TIMEOUT", DD_TRACE_AGENT_TIMEOUT)                                \
    INT(get_dd_trace_agent_connect_timeout, "DD_TRACE_AGENT_CONNECT_TIMEOUT", DD_TRACE_AGENT_CONNECT_TIMEOUT)        \
    INT(get_dd_trace_debug_prng_seed, "DD_TRACE_DEBUG_PRNG_SEED", -1)                                                \
    BOOL(get_dd_trace_ignore_legacy_blacklist, "DD_TRACE_IGNORE_LEGACY_BLACKLIST", false)                            \
    BOOL(get_dd_log_backtrace, "DD_LOG_BACKTRACE", false)                                                            \
    BOOL(get_dd_trace_generate_root_span, "DD_TRACE_GENERATE_ROOT_SPAN", true)                                       \
    BOOL(get_dd_trace_sandbox_enabled, "DD_TRACE_SANDBOX_ENABLED", true)                                             \
    INT(get_dd_trace_spans_limit, "DD_TRACE_SPANS_LIMIT", 1000)                                                      \
    BOOL(get_dd_trace_send_traces_via_thread, "DD_TRACE_BETA_SEND_TRACES_VIA_THREAD", DD_TRACE_BGS_ENABLED,          \
         "use background thread to send traces to the agent")                                                        \
    BOOL(get_dd_trace_bgs_enabled, "DD_TRACE_BGS_ENABLED", DD_TRACE_BGS_ENABLED,                                     \
         "use background sender (BGS) to send traces to the agent")                                                  \
    INT(get_dd_trace_bgs_connect_timeout, "DD_TRACE_BGS_CONNECT_TIMEOUT", DD_TRACE_BGS_CONNECT_TIMEOUT)              \
    INT(get_dd_trace_bgs_timeout, "DD_TRACE_BGS_TIMEOUT", DD_TRACE_BGS_TIMEOUT)                                      \
    INT(get_dd_trace_agent_flush_interval, "DD_TRACE_AGENT_FLUSH_INTERVAL", 5000)                                    \
    INT(get_dd_trace_agent_flush_after_n_requests, "DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS", 10)                      \
    INT(get_dd_trace_shutdown_timeout, "DD_TRACE_SHUTDOWN_TIMEOUT", 5000)                                            \
    BOOL(get_dd_trace_startup_logs, "DD_TRACE_STARTUP_LOGS", true)                                                   \
    BOOL(get_dd_trace_agent_debug_verbose_curl, "DD_TRACE_AGENT_DEBUG_VERBOSE_CURL", false)                          \
    BOOL(get_dd_trace_debug_curl_output, "DD_TRACE_DEBUG_CURL_OUTPUT", false)                                        \
    INT(get_dd_trace_beta_high_memory_pressure_percent, "DD_TRACE_BETA_HIGH_MEMORY_PRESSURE_PERCENT", 80,            \
        "reaching this percent threshold of a span buffer will trigger background thread "                           \
        "to attempt to flush existing data to trace agent")                                                          \
    BOOL(get_dd_trace_warn_legacy_dd_trace, "DD_TRACE_WARN_LEGACY_DD_TRACE", true)                                   \
    CHAR(get_dd_version, "DD_VERSION", "")

// render all configuration getters and define memoization struct
#include "configuration_render.h"

#endif  // DD_CONFIGURATION_H
