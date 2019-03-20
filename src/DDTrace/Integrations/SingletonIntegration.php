<?php

namespace DDTrace\Integrations;

abstract class SingletonIntegration implements \DDTrace\Contracts\Integration
{
    private static $instance;

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
}
