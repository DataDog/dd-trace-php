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
        $global = Configuration::get();
        $globalAnalyticsEnabled = $global->isAnalyticsEnabled();
        $integrationAnalyticsEnabled = $this->boolValue('analytics.enabled', false);
        return $integrationAnalyticsEnabled
                || ($globalAnalyticsEnabled && !$this->requiresExplicitTraceAnalyticsEnabling());
    }

    /**
     * @return float
     */
    public function getTraceAnalyticsSampleRate()
    {
        return $this->floatValue('analytics.sample.rate', 1.0);
    }
}
