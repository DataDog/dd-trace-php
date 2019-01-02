<?php

namespace DDTrace\Log;

final class Logger
{
    /**
     * @var LoggerInterface
     */
    private static $logger;

    public static function set(LoggerInterface $logger)
    {
        self::$logger = $logger;
    }

    public static function get()
    {
        if (self::$logger === null) {
            self::$logger = new NullLogger();
        }
        return self::$logger;
    }
}
