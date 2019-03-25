<?php

namespace DDTrace\Integrations;

use DDTrace\Configuration;
use DDTrace\Configuration\EnvVariableRegistry;
use DDTrace\Configuration\Registry;

/**
 * Abstract integration-level configuration providing basic configuration properties to all the integrations.
 */
abstract class AbstractIntegrationConfiguration
{
    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var string
     */
    private $integrationName;

    /**
     * @var Configuration
     */
    protected $globalConfig;

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
        $prefix = strtoupper(str_replace('-', '_', trim($this->integrationName)));
        $this->registry = new EnvVariableRegistry("DD_${prefix}_");
        $this->globalConfig = Configuration::get();
    }

    /**
    * @param string $key
    * @param bool $default
    * @return bool
    */
    protected function boolValue($key, $default)
    {
        return $this->registry->boolValue($key, $default);
    }

    /**
    * @param string $key
    * @param float $default
    * @return float
    */
    protected function floatValue($key, $default)
    {
        return $this->registry->floatValue($key, $default);
    }

    /**
     * @return bool
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return $this->requiresExplicitTraceAnalyticsEnabling;
    }
}
