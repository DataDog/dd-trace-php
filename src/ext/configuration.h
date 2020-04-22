#ifndef DD_CONFIGURATION_H
#define DD_CONFIGURATION_H

#include <stdbool.h>

#include "compatibility.h"

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
#define DD_TRACE_SANDBOX_ENABLED false
#else
#define DD_TRACE_BGS_ENABLED true
#define DD_TRACE_SANDBOX_ENABLED true
#endif

#define DD_CONFIGURATION                                                                                             \
    CHAR(get_dd_agent_host, "DD_AGENT_HOST", "localhost")                                                            \
    CHAR(get_dd_dogstatsd_port, "DD_DOGSTATSD_PORT", "8125")                                                         \
    INT(get_dd_trace_agent_port, "DD_TRACE_AGENT_PORT", 8126)                                                        \
    BOOL(get_dd_trace_auto_flush_enabled, "DD_TRACE_AUTO_FLUSH_ENABLED", false)                                      \
    BOOL(get_dd_trace_measure_compile_time, "DD_TRACE_MEASURE_COMPILE_TIME", true)                                   \
    BOOL(get_dd_trace_debug, "DD_TRACE_DEBUG", false)                                                                \
    BOOL(get_dd_trace_heath_metrics_enabled, "DD_TRACE_HEALTH_METRICS_ENABLED", false)                               \
    DOUBLE(get_dd_trace_heath_metrics_heartbeat_sample_rate, "DD_TRACE_HEALTH_METRICS_HEARTBEAT_SAMPLE_RATE", 0.001) \
    CHAR(get_dd_trace_memory_limit, "DD_TRACE_MEMORY_LIMIT", NULL)                                                   \
    INT(get_dd_trace_agent_timeout, "DD_TRACE_AGENT_TIMEOUT", DD_TRACE_AGENT_TIMEOUT)                                \
    INT(get_dd_trace_agent_connect_timeout, "DD_TRACE_AGENT_CONNECT_TIMEOUT", DD_TRACE_AGENT_CONNECT_TIMEOUT)        \
    INT(get_dd_trace_debug_prng_seed, "DD_TRACE_DEBUG_PRNG_SEED", -1)                                                \
    BOOL(get_dd_log_backtrace, "DD_LOG_BACKTRACE", false)                                                            \
    BOOL(get_dd_trace_generate_root_span, "DD_TRACE_GENERATE_ROOT_SPAN", true)                                       \
    BOOL(get_dd_trace_sandbox_enabled, "DD_TRACE_SANDBOX_ENABLED", DD_TRACE_SANDBOX_ENABLED)                         \
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
    BOOL(get_dd_trace_agent_debug_verbose_curl, "DD_TRACE_AGENT_DEBUG_VERBOSE_CURL", false)                          \
    BOOL(get_dd_trace_debug_curl_output, "DD_TRACE_DEBUG_CURL_OUTPUT", false)                                        \
    INT(get_dd_trace_beta_high_memory_pressure_percent, "DD_TRACE_BETA_HIGH_MEMORY_PRESSURE_PERCENT", 80,            \
        "reaching this percent threshold of a span buffer will trigger background thread "                           \
        "to attempt to flush existing data to trace agent")

// render all configuration getters and define memoization struct
#include "configuration_render.h"

#endif  // DD_CONFIGURATION_H
