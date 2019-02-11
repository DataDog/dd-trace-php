<?php

namespace DDTrace\Log;

/**
 * Known log levels.
 */
final class LogLevel
{
    /**
     * Const list from https://www.php-fig.org/psr/psr-3/
     */
    const EMERGENCY = 'emergency';
    const ALERT     = 'alert';
    const CRITICAL  = 'critical';
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const NOTICE    = 'notice';
    const INFO      = 'info';
    const DEBUG     = 'debug';

    /**
     * All the log levels.
     *
     * @return string[]
     */
    public static function all()
    {
        return [
            self::EMERGENCY,
            self::ALERT,
            self::CRITICAL,
            self::ERROR,
            self::WARNING,
            self::NOTICE,
            self::INFO,
            self::DEBUG,
        ];
    }
}
