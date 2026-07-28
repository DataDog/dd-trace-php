<?php

namespace DDTrace\Log;

/**
 * Prevents logger failures from escaping into application code.
 *
 * @internal
 */
final class NonThrowingLogger implements LoggerInterface
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function debug($message, array $context = array())
    {
        try {
            $this->logger->debug($message, $context);
        } catch (\Throwable $ignored) {
        }
    }

    public function warning($message, array $context = array())
    {
        try {
            $this->logger->warning($message, $context);
        } catch (\Throwable $ignored) {
        }
    }

    public function error($message, array $context = array())
    {
        try {
            $this->logger->error($message, $context);
        } catch (\Throwable $ignored) {
        }
    }

    public function isLevelActive($level)
    {
        try {
            return (bool) $this->logger->isLevelActive($level);
        } catch (\Throwable $ignored) {
            return false;
        }
    }
}
