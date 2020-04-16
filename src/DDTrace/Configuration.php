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
        return $this->boolValue('trace.debug', false);
    }

    /**
     * Whether or not distributed tracing is enabled globally.
     *
     * @return bool
     */
    public function isDistributedTracingEnabled()
    {
        return $this->boolValue('distributed.tracing', true);
    }

    /**
     * Whether or not automatic trace analytics configuration is enabled.
     *
     * @return bool
     */
    public function isAnalyticsEnabled()
    {
        return $this->boolValue('trace.analytics.enabled', false);
    }

    /**
     * Whether or not priority sampling is enabled globally.
     *
     * @return bool
     */
    public function isPrioritySamplingEnabled()
    {
        return $this->isDistributedTracingEnabled()
            && $this->boolValue('priority.sampling', true);
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
        return $this->boolValue('autofinish.spans', false);
    }

    /**
     * Returns the sampling rate provided by the user. Default: 1.0 (keep all).
     *
     * @return float
     */
    public function getSamplingRate()
    {
        // DD_SAMPLING_RATE is deprecated and will be removed in 0.40.0
        $deprecatedValue = $this->floatValue('sampling.rate', 1.0, 0.0, 1.0);
        return $this->floatValue('trace.sample.rate', $deprecatedValue, 0.0, 1.0);
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
        if (null !== $this->samplingRulesCache) {
            return $this->samplingRulesCache;
        }

        $this->samplingRulesCache = [];

        $parsed = \json_decode($this->stringValue('trace.sampling.rules'), true);
        if (false === $parsed) {
            $parsed = [];
        }

        // We do a proper parsing here to make sure that once the sampling rules leave this method
        // they are always properly defined.
        if (is_array($parsed)) {
            foreach ($parsed as $rule) {
                if (!is_array($rule) || !isset($rule['sample_rate'])) {
                    continue;
                }
                $service = isset($rule['service']) ? strval($rule['service']) : '.*';
                $name = isset($rule['name']) ? strval($rule['name']) : '.*';
                $rate = isset($rule['sample_rate']) ? floatval($rule['sample_rate']) : 1.0;
                $this->samplingRulesCache[] = [
                    'service' => $service,
                    'name' => $name,
                    'sample_rate' => $rate,
                ];
            }
        }

        return $this->samplingRulesCache;
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
        return $this->associativeStringArrayValue('trace.global.tags');
    }

    /**
     * Returns the service mapping.
     */
    public function getServiceMapping()
    {
        // We use the format 'service.mapping' instead of 'trace.service.mapping' for consistency
        // with java naming pattern for this very same config: DD_SERVICE_MAPPING
        return $this->associativeStringArrayValue('service.mapping');
    }

    /**
     * Append hostname as a root span tag
     *
     * @return bool
     */
    public function isHostnameReportingEnabled()
    {
        return $this->boolValue('trace.report.hostname', false);
    }

    /**
     * Use normalized URL as resource name
     *
     * @return bool
     */
    public function isURLAsResourceNameEnabled()
    {
        return $this->boolValue('trace.url.as.resource.names.enabled', true);
    }

    /**
     * Set URL hostname as service name
     *
     * @return bool
     */
    public function isHttpClientSplitByDomain()
    {
        return $this->boolValue('trace.http.client.split.by.domain', false);
    }

    /**
     * Whether or not sandboxed tracing closures are enabled.
     *
     * @return bool
     */
    public function isSandboxEnabled()
    {
        return (bool) \dd_trace_env_config("DD_TRACE_SANDBOX_ENABLED");
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
