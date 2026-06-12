<?php

namespace DDTrace\FeatureFlags\Internal\Metric;

final class EvaluationMetricRecorder
{
    private $recorder;

    public function __construct($recorder = null)
    {
        if ($recorder !== null && !is_callable($recorder)) {
            throw new \InvalidArgumentException('Expected a metric recorder callable');
        }

        $this->recorder = $recorder;
    }

    public static function createDefault()
    {
        return new self(self::nativeRecorder());
    }

    /**
     * @internal Datadog-owned bridge adapters only.
     *
     * @return ?callable(EvaluationMetric): bool
     */
    public static function nativeRecorder()
    {
        if (!self::isEnabled() || !function_exists('DDTrace\\Internal\\record_ffe_evaluation_metric')) {
            return null;
        }

        return static function (EvaluationMetric $metric) {
            return \DDTrace\Internal\record_ffe_evaluation_metric(
                $metric->getFlagKey(),
                $metric->getVariant(),
                $metric->getReason(),
                $metric->getErrorCode(),
                $metric->getAllocationKey()
            );
        };
    }

    /**
     * @internal Tests and Datadog-owned bridge adapters only.
     */
    public function record(EvaluationMetric $metric)
    {
        if ($this->recorder === null) {
            return false;
        }

        try {
            $recorder = $this->recorder;
            return (bool) $recorder($metric);
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    /**
     * Returns true when the EVP flagevaluation emission killswitch
     * DD_FLAGGING_EVALUATION_COUNTS_ENABLED is not explicitly disabled.
     * Default: enabled.
     *
     * @internal Killswitch only — does not control the OTel metric path.
     */
    public static function isEvpEnabled()
    {
        $val = getenv('DD_FLAGGING_EVALUATION_COUNTS_ENABLED');
        if ($val === false || $val === '') {
            return true; // absent → on
        }
        $lower = strtolower($val);
        return !in_array($lower, ['false', '0', 'no'], true);
    }

    private static function isEnabled()
    {
        return \dd_trace_env_config('DD_METRICS_OTEL_ENABLED') === true;
    }
}
