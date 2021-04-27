<?php

namespace DDTrace\Log;

/**
 * An implementation of the DDTrace\LoggerInterface that logs nothing.
 */
final class NullLogger extends AbstractLogger
{
    /**
     * Logs a debug at the debug level.
     *
     * @param string $message
     * @param array $context
     */
    public function debug($message, array $context = array())
    {
    }

    /**
     * Logs a warning at the debug level.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function warning($message, array $context = [])
    {
    }

    /**
     * Logs a error at the debug level.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function error($message, array $context = array())
    {
    }

    /**
     * @param string $level
     * @return bool
     */
    public function isLevelActive($level)
    {
        return false;
    }
}
