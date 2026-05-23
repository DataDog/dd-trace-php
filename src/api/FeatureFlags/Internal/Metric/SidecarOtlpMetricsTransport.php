<?php

namespace DDTrace\FeatureFlags\Internal\Metric;

/**
 * @internal Datadog-owned bridge adapters only.
 *
 * Delivers FFE evaluation-metric batches (OTLP/protobuf, encoded by
 * `OtlpMetricEncoder`) to the libdatadog sidecar via the
 * `\DDTrace\send_ffe_metrics()` native function. The sidecar
 * asynchronously POSTs the encoded bytes as `application/x-protobuf`
 * to the user-configured OTLP HTTP metrics intake (typically the value
 * of `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT`).
 *
 * Fire-and-forget: the sidecar handles retries/backoff and the PHP
 * request flow does not wait on OTLP intake availability. The first
 * call after fresh request startup may drop if the sidecar process is
 * not yet ready — matches the documented fire-and-forget semantics
 * shared with DogStatsD, trace stats, and telemetry self-metrics.
 *
 * This transport intentionally does NOT perform PHP-side socket I/O.
 * Per the dd-trace-php architectural rule (Bob's review on PR #3910,
 * 2026-05-22) all tracer-extension I/O must route through the sidecar.
 * dd-trace-php had no pre-existing OTLP-from-PHP path; the
 * `ddog_sidecar_send_ffe_metrics` FFI added in
 * DataDog/libdatadog#2026 is the first sidecar OTLP forwarder.
 */
final class SidecarOtlpMetricsTransport implements EvaluationMetricTransport
{
    /** @var string */
    private $endpoint;

    public function __construct($endpoint = null)
    {
        $this->endpoint = is_string($endpoint) && $endpoint !== ''
            ? $endpoint
            : self::resolveEndpoint();
    }

    /**
     * @param string $serviceName
     * @param array<int, array{attributes: array<string,string>, count: int}> $points
     * @return bool
     */
    public function send($serviceName, array $points)
    {
        if ($this->endpoint === '' || !function_exists('DDTrace\\send_ffe_metrics')) {
            return false;
        }

        $payload = OtlpMetricEncoder::encode(
            (string) $serviceName,
            $points,
            self::nowNanos(),
            self::nowNanos()
        );
        if (!is_string($payload) || $payload === '') {
            return false;
        }

        return \DDTrace\send_ffe_metrics($this->endpoint, $payload);
    }

    private static function resolveEndpoint()
    {
        foreach (array('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT', 'OTEL_EXPORTER_OTLP_ENDPOINT') as $envKey) {
            $value = self::env($envKey);
            if (is_string($value) && $value !== '') {
                // The base OTLP endpoint convention appends /v1/metrics if not specified.
                if ($envKey === 'OTEL_EXPORTER_OTLP_ENDPOINT'
                    && strpos($value, '/v1/metrics') === false) {
                    return rtrim($value, '/') . '/v1/metrics';
                }
                return $value;
            }
        }
        return 'http://localhost:4318/v1/metrics';
    }

    private static function env($name)
    {
        if (function_exists('dd_trace_env_config')) {
            $value = \dd_trace_env_config($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        $value = getenv($name);
        return $value === false ? '' : $value;
    }

    private static function nowNanos()
    {
        $micro = microtime(true);
        return (int) ($micro * 1000000000);
    }
}
