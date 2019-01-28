<?php

namespace DDTrace\Log;

/**
 * Defines logging methods as used in DDTrace code.
 */
interface LoggerInterface
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
}
