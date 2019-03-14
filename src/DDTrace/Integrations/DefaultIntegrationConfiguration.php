<?php

namespace DDTrace\Integrations;

/**
 * Default integration configuration object for integrations not having any specific param. Put here
 * config options that are common to ALL integrations.
 */
class DefaultIntegrationConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @param bool $default
     * @return bool
     */
    public function isTraceAnalyticsEnabled($default = false)
    {
        return $this->boolValue('analytics.enabled', $default);
    }

    /**
     * @return float
     */
    public function getTraceAnalyticsSampleRate()
    {
        return $this->floatValue('analytics.sample.rate', 1.0);
    }
}
