<?php

namespace DDTrace\Integrations;

/**
 * Default integration configuration object for integrations not having any specific param. Put here
 * config options that are common to ALL integrations.
 */
class DefaultIntegrationConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return bool
     */
    public function isTraceAnalyticsEnabled()
    {
        if (\DDTrace\Config\integration_analytics_enabled($this->integrationName)) {
            return true;
        }

        return \DDTrace\Util\Runtime::getBoolIni("datadog.trace.analytics_enabled")
            && !$this->requiresExplicitTraceAnalyticsEnabling();
    }

    /**
     * @return float
     */
    public function getTraceAnalyticsSampleRate()
    {
        return \DDTrace\Config\integration_analytics_sample_rate($this->integrationName);
    }
}
