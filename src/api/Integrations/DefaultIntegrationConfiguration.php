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
        // noop
        return true;
    }

    /**
     * @return float
     */
    public function getTraceAnalyticsSampleRate()
    {
        // noop
        return 1.0;
    }
}
