<?php

namespace DDTrace;

use DDTrace\Configuration\AbstractConfiguration;

/**
 * DDTrace global configuration object.
 */
class Configuration extends AbstractConfiguration
{
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
     * The name of the application.
     *
     * @param string $default
     * @return string
     */
    public function appName($default = '')
    {
        $appName = $this->stringValue('trace.app.name');
        if ($appName) {
            return $appName;
        }
        $appName = getenv('ddtrace_app_name');
        if (false !== $appName) {
            return trim($appName);
        }
        return $default;
    }
}
