<?php

namespace DDTrace;

use DDTrace\Configuration\AbstractConfiguration;
use DDTrace\Log\LoggingTrait;

/**
 * DDTrace global configuration object.
 */
class Configuration extends AbstractConfiguration
{
    use LoggingTrait;

    /**
     * Parsing sampling rules might be expensive so we cache values the first time we parse them.
     *
     * @var array
     */
    private $samplingRulesCache;

    /**
     * Whether or not tracing is enabled.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return \ddtrace_config_trace_enabled();
    }

    /**
     * Whether or not debug mode is enabled.
     *
     * @return bool
     */
    public function isDebugModeEnabled()
    {
        return \ddtrace_config_debug_enabled();
    }

    /**
     * Whether or not distributed tracing is enabled globally.
     *
     * @return bool
     */
    public function isDistributedTracingEnabled()
    {
        return \ddtrace_config_distributed_tracing_enabled();
    }

    /**
     * Whether or not automatic trace analytics configuration is enabled.
     *
     * @return bool
     */
    public function isAnalyticsEnabled()
    {
        return \ddtrace_config_analytics_enabled();
    }

    /**
     * Whether or not priority sampling is enabled globally.
     *
     * @return bool
     */
    public function isPrioritySamplingEnabled()
    {
        return $this->isDistributedTracingEnabled()
            && \ddtrace_config_priority_sampling_enabled();
    }

    /**
     * Whether or not also unfinished spans should be finished (and thus sent) when tracer is flushed.
     * Motivation: We had users reporting that in some cases they have manual end-points that `echo` some content and
     * then just `exit(0)` at the end of action's method. While the shutdown hook that flushes traces would still be
     * called, many spans would be unfinished and thus discarded. With this option enabled spans are automatically
     * finished (if not finished yet) when the tracer is flushed.
     *
     * @return bool
     */
    public function isAutofinishSpansEnabled()
    {
        return \ddtrace_config_autofinish_span_enabled();
    }

    /**
     * Returns the sampling rate provided by the user. Default: 1.0 (keep all).
     *
     * @return float
     */
    public function getSamplingRate()
    {
        return \ddtrace_config_sampling_rate();
    }

    /**
     * Returns the sampling rules defined for the current service.
     * Results are cached so it is perfectly fine to call this method multiple times.
     * The expected format for sampling rule env variable is:
     * - example: DD_TRACE_SAMPLING_RULES=[]
     *        --> sample rate is 100%
     * - example: DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.2}]
     *        --> sample rate is 20%
     * - example: DD_TRACE_SAMPLING_RULES=[{"service": "a.*", "name": "b", "sample_rate": 0.1}, {"sample_rate": 0.2}]
     *        --> sample rate is 20% except for spans of service starting with 'a' and with name 'b' where rate is 10%
     *
     * Note that 'service' and 'name' is optional when when omitted the '*' pattern is assumed.
     *
     * @return array
     */
    public function getSamplingRules()
    {
        return \ddtrace_config_sampling_rules();
    }

    /**
     * Whether or not a specific integration is enabled.
     *
     * @param string $name
     * @return bool
     */
    public function isIntegrationEnabled($name)
    {
        return \ddtrace_config_integration_enabled($name);
    }

    /**
     * Returns the global tags to be set on all spans.
     */
    public function getGlobalTags()
    {
        return \ddtrace_config_global_tags();
    }

    /**
     * Returns the service mapping.
     */
    public function getServiceMapping()
    {
        // We use the format 'service.mapping' instead of 'trace.service.mapping' for consistency
        // with java naming pattern for this very same config: DD_SERVICE_MAPPING
        return \ddtrace_config_service_mapping();
    }

    /**
     * Append hostname as a root span tag
     *
     * @return bool
     */
    public function isHostnameReportingEnabled()
    {
        return \ddtrace_config_hostname_reporting_enabled();
    }

    /**
     * Use normalized URL as resource name
     *
     * @return bool
     */
    public function isURLAsResourceNameEnabled()
    {
        return \ddtrace_config_url_resource_name_enabled();
    }

    /**
     * Set URL hostname as service name
     *
     * @return bool
     */
    public function isHttpClientSplitByDomain()
    {
        return \ddtrace_config_http_client_split_by_domain_enabled();
    }

    /**
     * Whether or not sandboxed tracing closures are enabled.
     *
     * @return bool
     */
    public function isSandboxEnabled()
    {
        return \ddtrace_config_sandbox_enabled();
    }

    /**
     * The name of the application.
     *
     * @param string $default
     * @return string
     */
    public function appName($default = '')
    {
        return \ddtrace_config_app_name($default);
    }
}
