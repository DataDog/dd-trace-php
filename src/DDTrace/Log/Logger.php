<?php

namespace DDTrace\Log;

use DDTrace\Configuration;

/**
 * A global logger holder. Can be configured to use a specific logger. If not configured, it returns a NullLogger.
 */
final class Logger
{
    /**
     * @var LoggerInterface
     */
    private static $logger;

    /**
     * Sets the global logger instance.
     *
     * @param LoggerInterface $logger
     */
    public static function set(LoggerInterface $logger)
    {
        self::$logger = $logger;
    }

    /**
     * Retrieves the global logger instance. If not set, it falls back to a NullLogger.
     *
     * @return LoggerInterface
     */
    public static function get()
    {
        if (self::$logger === null) {
            $config = Configuration::get();
            self::$logger = $config->isDebugModeEnabled()
                ? new ErrorLogLogger(LogLevel::DEBUG)
                : new NullLogger(LogLevel::EMERGENCY);
        }
        return self::$logger;
    }

    /**
     * Reset the logger.
     */
    public static function reset()
    {
        self::$logger = null;
    }
}
