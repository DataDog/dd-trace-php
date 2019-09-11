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
     * Whether or not tracing is enabled.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->boolValue('trace.enabled', true);
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
        return $this->floatValue('sampling.rate', 1.0, 0.0, 1.0);
    }

    /**
     * Whether or not a specific integration is enabled.
     *
     * @param string $name
     * @return bool
     */
    public function isIntegrationEnabled($name)
    {
        return $this->isEnabled() && !$this->inArray('integrations.disabled', $name);
    }

    /**
     * Returns the global tags to be set on all spans.
     */
    public function getGlobalTags()
    {
        return $this->associativeStringArrayValue('trace.global.tags');
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
        return $this->boolValue('trace.url.as.resource.names.enabled', false);
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
        // Sandbox API not available on < PHP 5.6 yet
        if (PHP_VERSION_ID < 50600) {
            return false;
        }
        return $this->boolValue('trace.sandbox.enabled', true);
    }

    /**
     * The name of the application.
     *
     * @param string $default
     * @return string
     */
    public function appName($default = '')
    {
        // Using the env `DD_SERVICE_NAME` for consistency with other tracers.
        $appName = $this->stringValue('service.name');
        if ($appName) {
            return $appName;
        }

        // This is deprecated and will be removed in a future release
        $appName = $this->stringValue('trace.app.name');
        if ($appName) {
            self::logDebug(
                'Env variable \'DD_TRACE_APP_NAME\' is deprecated and will be removed soon. ' .
                'Use \'DD_SERVICE_NAME\' instead'
            );
            return $appName;
        }

        $appName = getenv('ddtrace_app_name');
        if (false !== $appName) {
            self::logDebug(
                'Env variable \'ddtrace_app_name\' is deprecated and will be removed soon. ' .
                'Use \'DD_SERVICE_NAME\' instead'
            );
            return trim($appName);
        }
        return $default;
    }
}
