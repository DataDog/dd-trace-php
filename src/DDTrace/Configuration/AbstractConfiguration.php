<?php

namespace DDTrace\Configuration;

/**
 * DDTrace abstract configuration class.
 */
abstract class AbstractConfiguration implements Registry
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
    public static function get()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }

        return self::$instance;
    }

    /**
     * {@inheritdoc}
     *
     * Allows users to access configuration properties by name instead of calling explicit methods.
     */
    public function boolValue($key, $default)
    {
        return $this->registry->boolValue($key, $default);
    }

    /**
     * {@inheritdoc}
     *
     * Allows users to access configuration properties by name instead of calling explicit methods.
     */
    public function inArray($key, $name)
    {
        return $this->registry->inArray($key, $name);
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
