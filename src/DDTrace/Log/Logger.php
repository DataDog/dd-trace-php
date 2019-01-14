<?php

namespace DDTrace\Log;

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
            self::$logger = new NullLogger();
        }
        return self::$logger;
    }
}
