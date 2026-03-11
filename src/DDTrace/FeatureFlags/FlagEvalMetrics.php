<?php

namespace DDTrace\FeatureFlags;

use Throwable;

/**
 * Records OTel metrics for each feature flag evaluation.
 *
 * Emits counter: feature_flag.evaluations
 * Dimensions:
 *   - feature_flag.key
 *   - feature_flag.result.variant
 *   - feature_flag.result.reason       (lowercase)
 *   - feature_flag.result.allocation_key (if present)
 *   - error.type                        (only when errorCode is set)
 *
 * Requires DD_METRICS_OTEL_ENABLED=true and open-telemetry/sdk in composer.
 * Is a complete noop otherwise.
 */
class FlagEvalMetrics
{
    const METER_NAME = 'datadog/ffe';
    const METRIC_NAME = 'feature_flag.evaluations';
    const METRIC_UNIT = '{evaluation}';
    const METRIC_DESC = 'Number of feature flag evaluations';

    /** @var object|null \OpenTelemetry\API\Metrics\CounterInterface */
    private static $counter = null;

    /** @var bool */
    private static $initialized = false;

    /**
     * Record a metric for a completed flag evaluation.
     *
     * @param string $flagKey
     * @param array  $result  Result from Provider::evaluate():
     *                        ['value', 'reason', 'variant', 'allocation_key', 'error_code'?]
     */
    public static function record($flagKey, array $result)
    {
        $counter = self::getCounter();
        if ($counter === null) {
            return;
        }

        $attributes = [
            'feature_flag.key'            => $flagKey,
            'feature_flag.result.variant' => (string)($result['variant'] ?? ''),
            'feature_flag.result.reason'  => strtolower((string)($result['reason'] ?? 'default')),
        ];

        if (!empty($result['allocation_key'])) {
            $attributes['feature_flag.result.allocation_key'] = (string)$result['allocation_key'];
        }

        $errorCode = isset($result['error_code']) ? (int)$result['error_code'] : 0;
        if ($errorCode !== 0) {
            $attributes['error.type'] = self::errorCodeToTag($errorCode);
        }

        try {
            $counter->add(1, $attributes);
        } catch (Throwable $e) {
            // noop
        }
    }

    /**
     * @return object|null \OpenTelemetry\API\Metrics\CounterInterface
     */
    private static function getCounter()
    {
        if (self::$initialized) {
            return self::$counter;
        }
        self::$initialized = true;

        if (!function_exists('dd_trace_env_config') || !\dd_trace_env_config('DD_METRICS_OTEL_ENABLED')) {
            return null;
        }

        if (!class_exists('\OpenTelemetry\API\Globals')) {
            return null;
        }

        try {
            $meter = \OpenTelemetry\API\Globals::meterProvider()->getMeter(self::METER_NAME);
            self::$counter = $meter->createCounter(
                self::METRIC_NAME,
                self::METRIC_UNIT,
                self::METRIC_DESC
            );
        } catch (Throwable $e) {
            // noop — OTel metrics not available
        }

        return self::$counter;
    }

    private static function errorCodeToTag($code)
    {
        switch ((int)$code) {
            case 1:
                return 'type_mismatch';    // ERROR_TYPE_MISMATCH
            case 2:
                return 'parse_error';      // ERROR_CONFIG_PARSE
            case 3:
                return 'flag_not_found';   // ERROR_FLAG_UNRECOGNIZED
            default:
                return 'general';
        }
    }

    /**
     * Reset static state (useful for testing).
     */
    public static function reset()
    {
        self::$counter = null;
        self::$initialized = false;
    }
}
