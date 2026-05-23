<?php

namespace DDTrace\FeatureFlags\Internal\Metric;

use DDTrace\FeatureFlags\Internal\EvaluationCompleted;

final class EvaluationMetricWriter
{
    const DEFAULT_SERIES_LIMIT = 1000;

    private $transport;
    private $serviceName;
    private $seriesLimit;
    private $series = array();
    private $dropped = 0;

    public function __construct(
        EvaluationMetricTransport $transport,
        $serviceName,
        $seriesLimit = self::DEFAULT_SERIES_LIMIT
    ) {
        $this->transport = $transport;
        $this->serviceName = $serviceName === '' ? 'unknown_service:php' : (string) $serviceName;
        $this->seriesLimit = max(0, (int) $seriesLimit);
    }

    public static function createDefault()
    {
        return new self(new SidecarOtlpMetricsTransport(), self::defaultServiceName());
    }

    public function record(EvaluationCompleted $evaluation)
    {
        return $this->recordAttributes(
            $evaluation->getFlagKey(),
            $evaluation->getVariant(),
            $evaluation->getReason(),
            $evaluation->getErrorCode(),
            $evaluation->getAllocationKey()
        );
    }

    /**
     * @internal Tests and Datadog-owned bridge adapters only.
     *
     * @param string $flagKey
     * @param string|null $variant
     * @param string|null $reason
     * @param string|null $errorCode
     * @param string|null $allocationKey
     * @return bool
     */
    public function recordAttributes($flagKey, $variant, $reason, $errorCode, $allocationKey)
    {
        $attributes = $this->buildAttributes($flagKey, $variant, $reason, $errorCode, $allocationKey);
        $key = json_encode($attributes);
        if (!is_string($key)) {
            $this->dropped++;
            return false;
        }

        if (isset($this->series[$key])) {
            $this->series[$key]['count']++;
            return true;
        }

        if (count($this->series) >= $this->seriesLimit) {
            $this->dropped++;
            return false;
        }

        $this->series[$key] = array(
            'attributes' => $attributes,
            'count' => 1,
        );

        return true;
    }

    public function flush()
    {
        if (!$this->series) {
            return true;
        }

        $points = array_values($this->series);
        $this->series = array();

        try {
            $sent = $this->transport->send($this->serviceName, $points);
        } catch (\Throwable $throwable) {
            $sent = false;
        }

        if (!$sent) {
            $this->dropped += self::sumCounts($points);
        }

        return $sent;
    }

    public function bufferedSeriesCount()
    {
        return count($this->series);
    }

    public function droppedCount()
    {
        return $this->dropped;
    }

    private function buildAttributes($flagKey, $variant, $reason, $errorCode, $allocationKey)
    {
        if (!is_string($reason) || $reason === '') {
            $reason = 'unknown';
        } else {
            $reason = strtolower($reason);
        }

        if (!is_string($variant)) {
            $variant = '';
        }

        $attributes = array(
            'feature_flag.key' => (string) $flagKey,
            'feature_flag.result.variant' => $variant,
            'feature_flag.result.reason' => $reason,
        );

        if (is_string($errorCode) && $errorCode !== '') {
            $attributes['error.type'] = strtolower($errorCode);
        }

        if (is_string($allocationKey) && $allocationKey !== '') {
            $attributes['feature_flag.result.allocation_key'] = $allocationKey;
        }

        return $attributes;
    }

    private static function defaultServiceName()
    {
        $service = self::env('OTEL_SERVICE_NAME');
        if ($service !== '') {
            return $service;
        }

        $resourceAttributes = self::env('OTEL_RESOURCE_ATTRIBUTES');
        foreach (explode(',', $resourceAttributes) as $attribute) {
            $parts = explode('=', $attribute, 2);
            if (count($parts) === 2 && trim($parts[0]) === 'service.name') {
                $service = trim(rawurldecode($parts[1]));
                if ($service !== '') {
                    return $service;
                }
            }
        }

        $service = self::env('DD_SERVICE');
        if ($service !== '') {
            return $service;
        }

        return 'unknown_service:php';
    }

    private static function env($name)
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        if (strncmp($name, 'DD_', 3) === 0 && function_exists('dd_trace_env_config')) {
            $configured = \dd_trace_env_config($name);
            if (is_string($configured)) {
                return $configured;
            }
        }

        return '';
    }

    private static function sumCounts(array $points)
    {
        $count = 0;
        foreach ($points as $point) {
            $count += (int) $point['count'];
        }

        return $count;
    }
}
