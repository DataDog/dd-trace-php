<?php

namespace DDTrace\Log;

/**
 * JSON logger that writes to a stream, with simple logs correlation support.
 * Heavily inspired from Monolog's StreamHandler.
 * @internal This logger is internal and can be removed without prior notice
 */
final class DatadogLogger
{
    use InterpolateTrait;

    const DEFAULT_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** @var resource|null */
    protected $stream;
    /** @var ?string */
    private $errorMessage = null;
    /** @var true|null */
    private $dirCreated = null;

    public function __construct($stream = null, $mode = 'a')
    {
        if (is_null($stream)) {
            $stream = \dd_trace_env_config('DD_TRACE_LOG_FILE') ?: ini_get('error_log') ?: 'php://stderr';

            // The current fork may not have the necessary permissions to write to /dev/stderr or /dev/stdout
            if ($stream === '/dev/stderr') {
                $stream = 'php://stderr';
            } elseif ($stream === '/dev/stdout') {
                $stream = 'php://stdout';
            }
        }

       if (is_resource($stream)) {
            $this->stream = $stream;
       } elseif (is_string($stream)) {
           $url = self::canonicalizePath($stream);
           $this->createDir($url);
           $this->createStream($url, $mode);
       }
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function emergency($message, array $context = [])
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function alert($message, array $context = [])
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function critical($message, array $context = [])
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function error($message, array $context = [])
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function warning($message, array $context = [])
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function notice($message, array $context = [])
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function info($message, array $context = [])
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function debug($message, array $context = [])
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @param string $level
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function log(string $level, $message, array $context = [])
    {
        if (self::isLogLevelValid($level)) {
            $this->emit(self::format($level, $message, $context));
        }
    }

    // ---

    private static function isLogLevelValid(string $level): bool
    {
        return \in_array($level, LogLevel::all());
    }

    private static function format(string $level, $message, array $context = []): string {
        $message = self::interpolate($message, $context);

        $date = \DateTime::createFromFormat('U.u', microtime(true));


        $record = [
            'message' => $message,
            'status' => $level,
            'timestamp' => $date->format('Y-m-d\TH:i:s.uP'),
        ] + $context;

        return json_encode(array_merge($record, self::handleLogInjection()), self::DEFAULT_JSON_FLAGS) . PHP_EOL;
    }

    private static function handleLogInjection(): array
    {
        $logInjection = \dd_trace_env_config('DD_LOGS_INJECTION');
        if ($logInjection) {
            $traceId = \DDTrace\logs_correlation_trace_id();
            $spanId = \dd_trace_peek_span_id();
            if ($traceId && $spanId) {
                return [
                    'dd.trace_id' => $traceId,
                    'dd.span_id' => $spanId,
                ];
            }
        }

        return [];
    }

    private function emit(string $message)
    {
        if ($this->stream) {
            fwrite($this->stream, $message);
        }
    }

    private static function canonicalizePath(string $streamUrl): string
    {
        $prefix = '';
        if ('file://' === substr($streamUrl, 0, 7)) {
            $streamUrl = substr($streamUrl, 7);
            $prefix = 'file://';
        }

        // other type of stream, not supported
        if (false !== strpos($streamUrl, '://')) {
            return $streamUrl;
        }

        // already absolute
        if (substr($streamUrl, 0, 1) === '/' || substr($streamUrl, 1, 1) === ':' || substr($streamUrl, 0, 2) === '\\\\') {
            return $prefix.$streamUrl;
        }

        $streamUrl = getcwd() . '/' . $streamUrl;

        return $prefix.$streamUrl;
    }

    private function createDir(string $url)
    {
        if ($this->dirCreated) {
            return;
        }

        $dir = self::getDirFromStream($url);
        if (null !== $dir && !is_dir($dir)) {
            $this->errorMessage = null;
            set_error_handler([$this, 'customErrorHandler']);
            $status = mkdir($dir, 0666, true);
            restore_error_handler();
            if (false === $status && !is_dir($dir) && strpos((string) $this->errorMessage, 'File exists') === false) {
                return;
            }
        }
        $this->dirCreated = true;
    }

    private function createStream(string $url, string $mode)
    {
        $this->errorMessage = null;
        set_error_handler([$this, 'customErrorHandler']);
        $this->stream = fopen($url, $mode);
        restore_error_handler();
    }

    public function customErrorHandler(int $code, string $msg): bool
    {
        $this->errorMessage = preg_replace('{^(fopen|mkdir)\(.*?\): }', '', $msg);

        return true;
    }
    private static function getDirFromStream(string $stream)
    {
        $pos = strpos($stream, '://');
        if ($pos === false) {
            return dirname($stream);
        }

        if ('file://' === substr($stream, 0, 7)) {
            return dirname(substr($stream, 7));
        }

        return null;
    }
}
