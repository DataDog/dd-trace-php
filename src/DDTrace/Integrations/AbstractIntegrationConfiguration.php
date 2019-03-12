<?php

namespace DDTrace\Integrations;

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
     * @return string The integration name this configuration refers to.
     */
    abstract public function getIntegrationName();

    public function __construct()
    {
        $prefix = strtoupper(str_replace('-', '_', trim($this->getIntegrationName())));
        $this->registry = new EnvVariableRegistry("DD_${prefix}_");
    }
}
