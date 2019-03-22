<?php

namespace DDTrace\Integrations;

use DDTrace\Configuration;

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
        if ($this->boolValue('analytics.enabled', false)) {
            return true;
        }

        return Configuration::get()->isAnalyticsEnabled() && !$this->requiresExplicitTraceAnalyticsEnabling();
    }

    /**
     * @return float
     */
    public function getTraceAnalyticsSampleRate()
    {
        return $this->floatValue('analytics.sample.rate', 1.0);
    }
}
