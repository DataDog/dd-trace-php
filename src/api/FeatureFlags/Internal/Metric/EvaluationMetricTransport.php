<?php

namespace DDTrace\FeatureFlags\Internal\Metric;

interface EvaluationMetricTransport
{
    /**
     * @param string $serviceName
     * @param array<int, array{attributes: array<string, string>, count: int}> $points
     * @return bool
     */
    public function send($serviceName, array $points);
}
