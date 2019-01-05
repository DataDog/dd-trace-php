<?php

namespace DDTrace\Log;

/**
 * An implementation of the DDTrace\LoggerInterface that logs nothing.
 */
class NullLogger implements LoggerInterface
{
    /**
     * Does not emit any debug message.
     *
     * @param string $message
     * @param array $context
     */
    public function debug($message, array $context = array())
    {
    }
}
