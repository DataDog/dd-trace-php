<?php

namespace DDTrace\Integrations;

/**
 * An abstract class used as base for *internal officially supported* integrations. It may change with time.
 */
abstract class AbstractIntegration implements \DDTrace\Contracts\Integration
{
    /**
     * @var DefaultIntegrationConfiguration|mixed
     */
    private $configuration;

    /**
     * @var static
     */
    private static $instance;

    protected function __construct()
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
