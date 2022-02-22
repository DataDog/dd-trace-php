<?php

namespace DDTrace\Integrations;

/**
 * Abstract integration-level configuration providing basic configuration properties to all the integrations.
 */
abstract class AbstractIntegrationConfiguration
{
    /**
     * @var string
     */
    protected $integrationName;

    /**
     * @var bool Whether or not this integration requires explicit trace analytics enabling.
     */
    private $requiresExplicitTraceAnalyticsEnabling = true;

    /**
     * @param string $integrationName
     * @param bool $requiresExplicitTraceAnalyticsEnabling
     */
    public function __construct($integrationName, $requiresExplicitTraceAnalyticsEnabling = true)
    {
        $this->integrationName = $integrationName;
        $this->requiresExplicitTraceAnalyticsEnabling = $requiresExplicitTraceAnalyticsEnabling;
    }

    /**
     * @return bool
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return $this->requiresExplicitTraceAnalyticsEnabling;
    }
}
