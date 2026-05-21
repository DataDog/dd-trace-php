<?php

namespace DDTrace\FeatureFlags;

final class CallableMetricsRecorder implements MetricsRecorder
{
    const METRIC_NAME = 'feature_flag.evaluations';

    private $sink;
    private $enabled;

    public function __construct($sink, $enabled)
    {
        if (!is_callable($sink)) {
            throw new \InvalidArgumentException('Expected a metrics sink callable');
        }

        $this->sink = $sink;
        $this->enabled = (bool) $enabled;
    }

    public static function createFromEnvironment($sink)
    {
        return new self($sink, self::isTruthy(getenv('DD_METRICS_OTEL_ENABLED')));
    }

    public function recordEvaluation($flagKey, $valueType, $reason, $errorCode = null)
    {
        if (!$this->enabled) {
            return;
        }

        call_user_func($this->sink, array(
            'name' => self::METRIC_NAME,
            'attributes' => array(
                'feature_flag.key' => $flagKey,
                'feature_flag.result.reason' => $reason,
                'feature_flag.result.value_type' => $valueType,
                'feature_flag.error.code' => $errorCode === null ? 'none' : $errorCode,
            ),
        ));
    }

    private static function isTruthy($value)
    {
        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
    }
}
