<?php

namespace DDTrace\FeatureFlags\Internal\Exposure;

/**
 * @internal Datadog-owned bridge adapters only.
 *
 * Delivers FFE exposure batches to the libdatadog sidecar via the
 * `\DDTrace\send_ffe_exposures()` native function. The sidecar
 * asynchronously POSTs the JSON envelope to the agent EVP proxy at
 * `/evp_proxy/v2/api/v2/exposures` with `X-Datadog-EVP-Subdomain:
 * event-platform-intake`.
 *
 * Fire-and-forget: the sidecar handles retries/backoff and the PHP
 * request flow does not wait on Agent availability. The first call
 * after fresh request startup may drop if the sidecar process is not
 * yet ready — this matches the documented fire-and-forget semantics
 * shared with DogStatsD, trace stats, and telemetry self-metrics.
 *
 * This transport intentionally does NOT perform PHP-side socket I/O.
 * Per the dd-trace-php architectural rule (Bob's review on PR #3910,
 * 2026-05-22) all tracer-extension I/O must route through the sidecar.
 */
final class SidecarExposureTransport implements ExposureTransport
{
    /**
     * @param array<string, mixed> $payload
     * @return bool
     */
    public function send(array $payload)
    {
        $encoded = json_encode($payload);
        if (!is_string($encoded)) {
            return false;
        }

        if (!function_exists('DDTrace\\send_ffe_exposures')) {
            // The native FFI is not loaded (e.g. ddtrace extension absent
            // or pre-FFE build). Drop the batch silently — no PHP-side
            // fallback transport per architectural rule.
            return false;
        }

        return \DDTrace\send_ffe_exposures($encoded);
    }
}
