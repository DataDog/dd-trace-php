<?php

namespace DDTrace\Log;

/**
 * Defines logging methods as used in DDTrace code.
 */
interface LoggerInterface
{
    /**
     * Logs a message at the debug level.
     *
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public function debug($message, array $context = array());

    /**
     * Logs a warning at the debug level.
     *
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public function warning($message, array $context = []);

    /**
     * Logs a error at the debug level.
     *
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public function error($message, array $context = array());

    /**
     * @param string $level
     * @return bool
     */
    public function isLevelActive($level);
}
