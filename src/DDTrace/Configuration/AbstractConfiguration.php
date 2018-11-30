<?php

namespace DDTrace\Configuration;

/**
 * DDTrace abstract configuration class.
 */
abstract class AbstractConfiguration
{
    /**
     * @var static
     */
    private static $instance;

    /**
     * @var EnvVariableRegistry
     */
    protected $registry;

    protected function __construct()
    {
        $this->registry = new EnvVariableRegistry();
    }

    /**
     * Returns the singleton configuration instance.
     *
     * @return static
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }

        return self::$instance;
    }

    /**
     * Replaces the singleton instance.
     *
     * @param AbstractConfiguration $configuration
     */
    public static function replace(AbstractConfiguration $configuration)
    {
        self::$instance = $configuration;
    }

    /**
     * Clear the singleton instance.
     */
    public static function clear()
    {
        self::$instance = null;
    }
}
