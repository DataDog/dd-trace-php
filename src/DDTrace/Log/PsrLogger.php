<?php

namespace DDTrace\Log;

/**
 * An implementation of the DDTrace\LoggerInterface that uses Psr\Log under the hood.
 */
class PsrLogger implements LoggerInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $psrLogger = null;

    /**
     * @param \Psr\Log\LoggerInterface $psrLogger
     */
    public function __construct($psrLogger)
    {
        if (!is_a($psrLogger, '\Psr\Log\LoggerInterface')) {
            throw new \InvalidArgumentException(
                '\DDTrace\Log\PsrLogger constructor arg must implement \Psr\Log\LoggerInterface'
            );
        }
        $this->psrLogger = $psrLogger;
    }

    /**
     * Emits a debug level message.
     *
     * @param string $message
     * @param array $context
     */
    public function debug($message, array $context = array())
    {
        $this->psrLogger->debug($message, $context);
    }
}
