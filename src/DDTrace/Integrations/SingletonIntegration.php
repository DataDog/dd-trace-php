<?php

namespace DDTrace\Integrations;

/**
 * A class implementing the singleton pattern for integration instances.
 */
abstract class SingletonIntegration implements \DDTrace\Contracts\Integration
{
    /**
     * @var static
     */
    private static $instance;

    /**
     * @var DefaultIntegrationConfiguration|mixed
     */
    private $configuration;

    private function __construct()
    {
        $this->configuration = $this->buildConfiguration();
    }

    /**
     * @return static
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * Build the integration's configuration object. Override to provide your own implementation.
     *
     * @return DefaultIntegrationConfiguration|mixed
     */
    protected function buildConfiguration()
    {
        return new DefaultIntegrationConfiguration($this->getName());
    }

    /**
     * @return DefaultIntegrationConfiguration|mixed
     */
    protected function getConfiguration()
    {
        return $this->configuration;
    }
}
