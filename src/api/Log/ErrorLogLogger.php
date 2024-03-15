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
     * Logs a error at the info level.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function info($message, array $context = [])
    {
        // As a first draft, we do not implement logging levels. This logger is simply enabled when property
        // trace.debug = true and all messages are shown.
        $this->emit(LogLevel::INFO, $message, $context);
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

        if ($this->isJSON()) {
            $message = json_encode([
                'message' => $message,
                'level' => $level,
                'timestamp' => date(\DateTime::ATOM),
                'dd' => [
                    'trace_id' => \DDTrace\logs_correlation_trace_id(),
                    'span_id' => dd_trace_peek_span_id(),
                ],
                ...$context,
            ]) . PHP_EOL;

            $logFile = \dd_trace_env_config('DD_TRACE_LOG_FILE');
            if ($logFile) {
                file_put_contents($logFile, $message, FILE_APPEND);
            }
            error_log($message);
        } else {
            $interpolatedMessage = $this->interpolate($message, $context);
            $date = date(\DateTime::ATOM);
            $logFile = \dd_trace_env_config('DD_TRACE_LOG_FILE');
            $logsCorrelationEnabled = \dd_trace_env_config('DD_LOGS_INJECTION');
            if ($logsCorrelationEnabled) {
                $logsCorrelationTraceID = \DDTrace\logs_correlation_trace_id();
                $logsCorrelationSpanID = dd_trace_peek_span_id();
                $interpolatedMessage = "$interpolatedMessage [dd.trace_id=$logsCorrelationTraceID dd.span_id=$logsCorrelationSpanID]";
            }

            $interpolatedMessage = $interpolatedMessage . ' ' . json_encode($context);

            if ($logFile) {
                file_put_contents($logFile, "[$date] [ddtrace] [$level] - $interpolatedMessage\n", FILE_APPEND);
                return;
            }

            error_log("[$date] [ddtrace] [$level] - $interpolatedMessage");
        }
    }
}
