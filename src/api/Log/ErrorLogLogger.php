<?php

namespace DDTrace\Log;

/**
 * An implementation of the DDTrace\LoggerInterface that logs to the error_log.
 */
class ErrorLogLogger extends AbstractLogger
{
    use InterpolateTrait;

    /**
     * Logs a debug message. Substitution is provided as specified in:
     * https://www.php-fig.org/psr/psr-3/
     *
     * @param string $message
     * @param array $context
     */
    public function debug($message, array $context = [])
    {
        // As a first draft, we do not implement logging levels. This logger is simply enabled when property
        // trace.debug = true and all messages are shown.
        $this->emit(LogLevel::DEBUG, $message, $context);
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
        // As a first draft, we do not implement logging levels. This logger is simply enabled when property
        // trace.debug = true and all messages are shown.
        $this->emit(LogLevel::WARNING, $message, $context);
    }

    /**
     * Logs a error at the debug level.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function error($message, array $context = [])
    {
        // As a first draft, we do not implement logging levels. This logger is simply enabled when property
        // trace.debug = true and all messages are shown.
        $this->emit(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param string $message
     * @param array $context
     * @param string $level
     */
    private function emit($level, $message, array $context = [])
    {
        if (!$this->isLevelActive($level)) {
            return;
        }

        $interpolatedMessage = $this->interpolate($message, $context);
        $date = date(\DateTime::ATOM);
        error_log("[$date] [ddtrace] [$level] - $interpolatedMessage");
    }
}
