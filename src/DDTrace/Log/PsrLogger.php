<?php

namespace DDTrace\Log;

use Psr\Log\LoggerInterface as PsrLoggerInterface;

/**
 * An implementation of the DDTrace\LoggerInterface that uses Psr\Log under the hood.
 */
class PsrLogger implements LoggerInterface
{
    /**
     * @var PsrLoggerInterface
     */
    private $psrLogger = null;

    /**
     * @param PsrLoggerInterface $psrLogger
     */
    public function __construct(PsrLoggerInterface $psrLogger)
    {
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
