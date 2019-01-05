<?php

namespace DDTrace\Log;

/**
 * Defines logging methods as used in DDTrace code.
 */
interface LoggerInterface
{
    /**
     * Detailed debug information.
     *
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public function debug($message, array $context = array());
}
