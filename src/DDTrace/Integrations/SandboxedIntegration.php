<?php

namespace DDTrace\Integrations;

abstract class SandboxedIntegration extends Integration
{
    /**
     * @var self
     */
    private static $instance;

    /**
     * @return self
     */
    public static function get()
    {
        if (null === self::$instance) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * Load the integration
     *
     * @return int
     */
    abstract public function init();
}
