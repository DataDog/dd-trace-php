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
        return $this->boolValue('analytics.enabled', !$this->requiresExplicitTraceAnalyticsEnabling());
    }

    /**
     * @return float
     */
    public function getTraceAnalyticsSampleRate()
    {
        return $this->floatValue('analytics.sample.rate', 1.0);
    }
}
