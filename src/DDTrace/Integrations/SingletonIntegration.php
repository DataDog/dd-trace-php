<?php

namespace DDTrace\Integrations;

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

    public function __construct()
    {
        $this->configuration = $this->buildConfiguration();
    }

    /**
     * @return \DDTrace\Contracts\Integration
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
