<?php

namespace DDTrace\FeatureFlags\Internal\Metric;

use DDTrace\FeatureFlags\Internal\EvaluationCompleted;
use DDTrace\FeatureFlags\Internal\EvaluationCompletedHook;

final class EvaluationMetricHook implements EvaluationCompletedHook
{
    private static $defaultWriter;
    private static $shutdownRegistered = false;

    private $writer;

    public function __construct($writer = null)
    {
        if ($writer !== null && !$writer instanceof EvaluationMetricWriter) {
            throw new \InvalidArgumentException('Expected an EvaluationMetricWriter instance');
        }

        $this->writer = $writer;
    }

    public static function createDefault()
    {
        $writer = self::sharedWriter();
        return new self($writer);
    }

    /**
     * @internal Datadog-owned bridge adapters only.
     *
     * Shared writer used by both this hook (PHP 7 / DD-Client path) and the
     * PHP 8 OpenFeature `EvalMetricsHook`, so metrics emitted through either
     * path aggregate into one series buffer and flush together.
     *
     * @return ?EvaluationMetricWriter
     */
    public static function sharedWriter()
    {
        if (!self::isEnabled()) {
            return null;
        }

        if (self::$defaultWriter === null) {
            self::$defaultWriter = EvaluationMetricWriter::createDefault();
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function(array(self::$defaultWriter, 'flush'));
            self::$shutdownRegistered = true;
        }

        return self::$defaultWriter;
    }

    public function evaluationCompleted(EvaluationCompleted $evaluation)
    {
        if ($this->writer !== null) {
            $this->writer->record($evaluation);
        }
    }

    private static function isEnabled()
    {
        if (function_exists('dd_trace_env_config')) {
            return \dd_trace_env_config('DD_METRICS_OTEL_ENABLED') === true;
        }

        $value = getenv('DD_METRICS_OTEL_ENABLED');
        if ($value === false) {
            return false;
        }

        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
    }
}
