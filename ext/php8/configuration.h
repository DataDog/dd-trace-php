#ifndef DD_CONFIGURATION_H
#define DD_CONFIGURATION_H

#include <stdbool.h>

#include "compatibility.h"
#include "ddtrace_config.h"
#include "ddtrace_string.h"
#include "env_config.h"
#include "integrations/integrations.h"

// TODO Tie these into the X Macros below
static inline bool get_dd_trace_debug(void) {
    return IS_TRUE == Z_TYPE_P(zai_config_get_value(DDTRACE_CONFIG_DD_TRACE_DEBUG));
}

static inline zend_string *get_dd_service(void) {
    return Z_STR_P(zai_config_get_value(DDTRACE_CONFIG_DD_SERVICE));
}

static inline zend_array *get_dd_tags(void) {
    return Z_ARRVAL_P(zai_config_get_value(DDTRACE_CONFIG_DD_TAGS));
}

static inline zend_long get_dd_trace_agent_port(void) {
    return Z_LVAL_P(zai_config_get_value(DDTRACE_CONFIG_DD_TRACE_AGENT_PORT));
}

static inline double get_dd_trace_sample_rate(void) {
    return Z_DVAL_P(zai_config_get_value(DDTRACE_CONFIG_DD_TRACE_SAMPLE_RATE));
}

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
bool ddtrace_config_env_bool(ddtrace_string env_name, bool default_value);

bool ddtrace_config_distributed_tracing_enabled(void);
bool ddtrace_config_trace_enabled(void);

#define DDTRACE_LONGEST_INTEGRATION_ENV_PREFIX_LEN 9   // DD_TRACE_ FTW!
#define DDTRACE_LONGEST_INTEGRATION_ENV_SUFFIX_LEN 22  // "_ANALYTICS_SAMPLE_RATE" FTW!
#define DDTRACE_LONGEST_INTEGRATION_ENV_LEN                                              \
    (DDTRACE_LONGEST_INTEGRATION_ENV_PREFIX_LEN + DDTRACE_LONGEST_INTEGRATION_NAME_LEN + \
     DDTRACE_LONGEST_INTEGRATION_ENV_SUFFIX_LEN)

// note: only call this if ddtrace_config_trace_enabled() returns true
bool ddtrace_config_integration_enabled(ddtrace_string integration);
bool ddtrace_config_integration_enabled_ex(ddtrace_integration_name integration_name);
bool ddtrace_config_integration_analytics_enabled(ddtrace_string integration);
double ddtrace_config_integration_analytics_sample_rate(ddtrace_string integration);

size_t ddtrace_config_integration_env_name(char *name, const char *prefix, ddtrace_integration *integration,
                                           const char *suffix);

inline ddtrace_string ddtrace_string_getenv(char *str, size_t len) {
    return ddtrace_string_cstring_ctor(ddtrace_getenv(str, len));
}

// Returns an env var value as string. If the env is not defined it uses a fallback env variable name.
// Used when for backward compatibility we need to support a primary and secondary env variable name.
inline ddtrace_string ddtrace_string_getenv_multi(char *primary, size_t primary_len, char *secondary,
                                                  size_t secondary_len) {
    return ddtrace_string_cstring_ctor(ddtrace_getenv_multi(primary, primary_len, secondary, secondary_len));
}

struct ddtrace_memoized_configuration_t;
extern struct ddtrace_memoized_configuration_t ddtrace_memoized_configuration;

void ddtrace_initialize_config(void);
void ddtrace_reload_config(void);
void ddtrace_config_shutdown(void);

#include "integrations/integrations.h"

/* From the curl docs on CONNECT_TIMEOUT_MS:
 *     If libcurl is built to use the standard system name resolver, that
 *     portion of the transfer will still use full-second resolution for
 *     timeouts with a minimum timeout allowed of one second.
 * The default is 0, which means to wait indefinitely. Even in the background
 * we don't want to wait forever, but I'm not sure what to set the connect
 * timeout to.
 * A user hit an issue with the userland time of 100.
 */
#define DD_TRACE_AGENT_CONNECT_TIMEOUT_VAL "100"
#define DD_TRACE_BGS_CONNECT_TIMEOUT_VAL "2000"

/* Default for the PHP sender; should be kept in sync with DDTrace\Transport\Http::DEFAULT_AGENT_TIMEOUT */
#define DD_TRACE_AGENT_TIMEOUT_VAL "500"

/* This should be at least an order of magnitude higher than the userland HTTP Transport default. */
#define DD_TRACE_BGS_TIMEOUT_VAL "5000"

#define DD_INTEGRATION_ANALYTICS_ENABLED_DEFAULT "false"

#define DD_CONFIGURATION_STRINGIZE(str) #str
#define INTEGRATION(id, ...) \
    CFG(BOOL, DD_TRACE_##id##_ENABLED, "true") \
    CFG(BOOL, DD_TRACE_##id##_ANALYTICS_ENABLED, DD_INTEGRATION_ANALYTICS_ENABLED_DEFAULT, ((zai_string_view[]){ \
        ZAI_STRL_VIEW(DD_CONFIGURATION_STRINGIZE(DD_##id##_ANALYTICS_ENABLED)), \
    })) \
    CFG(DOUBLE, DD_TRACE_##id##_ANALYTICS_SAMPLE_RATE, DD_INTEGRATION_ANALYTICS_ENABLED_DEFAULT, ((zai_string_view[]){ \
        ZAI_STRL_VIEW(DD_CONFIGURATION_STRINGIZE(DD_##id##_ANALYTICS_SAMPLE_RATE)), \
    }))

#define DD_CONFIGURATION                                                                                             \
    CFG(CHAR, DD_TRACE_AGENT_URL, "")                                                           \
    CFG(CHAR, DD_AGENT_HOST, "localhost")                                                            \
    CFG(BOOL, DD_DISTRIBUTED_TRACING, "true")                                                 \
    CFG(CHAR, DD_DOGSTATSD_PORT, "8125")                                                         \
    CFG(CHAR, DD_ENV, "")                                                                                   \
    CFG(BOOL, DD_AUTOFINISH_SPANS, "false")                                                      \
    CFG(BOOL, DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED, "true")                         \
    CFG(CHAR, DD_INTEGRATIONS_DISABLED, "")                                               \
    CFG(BOOL, DD_PRIORITY_SAMPLING, "true")                                                     \
    CFG(MAP, DD_SERVICE_MAPPING, "")                                                               \
    CFG(CHAR, DD_SERVICE, "", ((zai_string_view[]){ \
        ZAI_STRL_VIEW("DD_SERVICE_NAME"), \
        ZAI_STRL_VIEW("DD_TRACE_APP_NAME"), \
    }))                                                              \
    CFG(BOOL, DD_TRACE_ANALYTICS_ENABLED, "false")                                        \
    CFG(BOOL, DD_TRACE_AUTO_FLUSH_ENABLED, "false")                                      \
    CFG(BOOL, DD_TRACE_CLI_ENABLED, "false")                                                    \
    CFG(BOOL, DD_TRACE_MEASURE_COMPILE_TIME, "true")                                   \
    CFG(BOOL, DD_TRACE_ENABLED, "true")                                                             \
    CFG(BOOL, DD_TRACE_HEALTH_METRICS_ENABLED, "false")                               \
    CFG(DOUBLE, DD_TRACE_HEALTH_METRICS_HEARTBEAT_SAMPLE_RATE, "0.001") \
    CFG(BOOL, DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN, "false")                    \
    CFG(CHAR, DD_TRACE_MEMORY_LIMIT, "NULL")                                                   \
    CFG(BOOL, DD_TRACE_REPORT_HOSTNAME, "false")                                            \
    CFG(CHAR, DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX, "")                       \
    CFG(CHAR, DD_TRACE_RESOURCE_URI_MAPPING_INCOMING, "")                   \
    CFG(CHAR, DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING, "")                   \
    CFG(CHAR, DD_TRACE_SAMPLING_RULES, "")                                                 \
    CFG(CHAR, DD_TRACE_TRACED_INTERNAL_FUNCTIONS, "")                           \
    CFG(INT, DD_TRACE_AGENT_TIMEOUT, DD_TRACE_AGENT_TIMEOUT_VAL)                                \
    CFG(INT, DD_TRACE_AGENT_CONNECT_TIMEOUT, DD_TRACE_AGENT_CONNECT_TIMEOUT_VAL)        \
    CFG(INT, DD_TRACE_DEBUG_PRNG_SEED, "-1")                                                \
    CFG(BOOL, DD_LOG_BACKTRACE, "false")                                                            \
    CFG(BOOL, DD_TRACE_GENERATE_ROOT_SPAN, "true")                                       \
    CFG(BOOL, DD_TRACE_SANDBOX_ENABLED, "true")                                             \
    CFG(INT, DD_TRACE_SPANS_LIMIT, "1000")                                                      \
    CFG(INT, DD_TRACE_BGS_CONNECT_TIMEOUT, DD_TRACE_BGS_CONNECT_TIMEOUT_VAL)              \
    CFG(INT, DD_TRACE_BGS_TIMEOUT, DD_TRACE_BGS_TIMEOUT_VAL)                                      \
    CFG(INT, DD_TRACE_AGENT_FLUSH_INTERVAL, "5000")                                    \
    CFG(INT, DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS, "10")                      \
    CFG(INT, DD_TRACE_SHUTDOWN_TIMEOUT, "5000")                                            \
    CFG(BOOL, DD_TRACE_STARTUP_LOGS, "true")                                                   \
    CFG(BOOL, DD_TRACE_AGENT_DEBUG_VERBOSE_CURL, "false")                          \
    CFG(BOOL, DD_TRACE_DEBUG_CURL_OUTPUT, "false")                                        \
    CFG(INT, DD_TRACE_BETA_HIGH_MEMORY_PRESSURE_PERCENT, "80")            \
    CFG(BOOL, DD_TRACE_WARN_LEGACY_DD_TRACE, "true")                                   \
    CFG(BOOL, DD_TRACE_RETAIN_THREAD_CAPABILITIES, "false")                      \
    CFG(CHAR, DD_VERSION, "")                                                                                        \
    DD_INTEGRATIONS

// render all configuration getters and define memoization struct
#include "configuration_render.h"

#endif  // DD_CONFIGURATION_H
