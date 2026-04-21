<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use Closure;

/**
 * Injectable wrapper for the OTel `feature_flag.evaluations` Int64Counter.
 *
 * This class is the single call site for recording feature flag evaluation
 * metrics. It is designed to be testable without pulling in the upstream OTel
 * PHP API or SDK composer packages (see PROJECT.md and Phase 4 D-01):
 *
 *   - The counter itself is a user-supplied Closure (`counterCallable`) with
 *     signature `fn(array<string, string> $attributes): void`.
 *   - When dd-trace-php is loaded at runtime, a real callable wiring the
 *     built-in OTel Meter binding is injected at construction time.
 *   - When dd-trace-php is absent (staging repo, CI without the extension),
 *     the default callable is a no-op so `MetricsCounter::record()` is safe
 *     to call unconditionally.
 *
 * Counter behavior matches the cross-tracer convention:
 *   - Fires once per evaluation on both success and error paths (D-03).
 *   - Attributes match OBSV-02 exactly (D-05):
 *       feature_flag.key, feature_flag.result.variant,
 *       feature_flag.result.reason, feature_flag.result.allocation_key,
 *       error.type.
 *   - error.type is an empty string on success and the OpenFeature error-code
 *     name on failure (FLAG_NOT_FOUND, PARSE_ERROR, TYPE_MISMATCH, GENERAL,
 *     PROVIDER_NOT_READY). A null bridge result is treated as
 *     PROVIDER_NOT_READY.
 *   - The counter is gated on the `DD_METRICS_OTEL_ENABLED` env var (D-04).
 *     When unset, empty, or falsy ("0", "false", "no", "off"), `record()` is
 *     a no-op regardless of the injected counterCallable.
 *
 * Reason and error-code mappings mirror {@see BridgeResultMapper} so the
 * counter and the OpenFeature ResolutionDetails always report the same
 * outcome for the same evaluation.
 *
 * @internal Not part of the public Datadog API.
 */
final class MetricsCounter
{
    /**
     * Bridge reason integer -> OpenFeature reason name.
     *
     * Mirrors {@see BridgeResultMapper::REASON_MAP}. Kept as a local copy so
     * this class has no coupling to BridgeResultMapper's private constants.
     *
     * @var array<int, string>
     */
    private const REASON_MAP = [
        0 => 'DEFAULT',
        1 => 'TARGETING_MATCH',
        2 => 'SPLIT',
        3 => 'DISABLED',
        4 => 'ERROR',
    ];

    /**
     * Bridge error_code integer -> OpenFeature error-code name.
     *
     * Mirrors {@see BridgeResultMapper::ERROR_CODE_MAP}. Unknown error codes
     * fall back to 'GENERAL' to match BridgeResultMapper::mapErrorCode().
     *
     * @var array<int, string>
     */
    private const ERROR_CODE_MAP = [
        1 => 'FLAG_NOT_FOUND',
        2 => 'PARSE_ERROR',
        3 => 'TYPE_MISMATCH',
        4 => 'GENERAL',
        5 => 'PROVIDER_NOT_READY',
    ];

    /**
     * The injected counter callable.
     *
     * Signature: fn(array<string, string> $attributes): void.
     *
     * @var Closure(array<string, string>): void
     */
    private Closure $counterCallable;

    /**
     * Optional override for reading environment variables (testing).
     *
     * When null, DD_METRICS_OTEL_ENABLED is read via getenv().
     *
     * @var Closure(string): ?string|null
     */
    private ?Closure $envReader;

    /**
     * @param Closure|null $counterCallable Custom counter callable for testing.
     *        Signature: fn(array<string, string> $attributes): void.
     *        Null = no-op closure (safe when compiled extension is absent).
     * @param Closure|null $envReader Override for reading environment variables (testing).
     *        Signature: fn(string $name): ?string.
     */
    public function __construct(
        ?Closure $counterCallable = null,
        ?Closure $envReader = null,
    ) {
        $this->counterCallable = $counterCallable ?? self::defaultCounterCallable();
        $this->envReader = $envReader;
    }

    /**
     * Record a single evaluation in the feature_flag.evaluations counter.
     *
     * Called exactly once per DataDogProvider evaluation (D-02). Fires on
     * success and all error paths (D-03). No-op when DD_METRICS_OTEL_ENABLED
     * is disabled (D-04).
     *
     * @param string $flagKey The flag key that was evaluated.
     * @param array<string, mixed>|null $bridgeResult Raw result from
     *        DDTrace\ffe_evaluate(), or null when the bridge is unavailable
     *        (treated as PROVIDER_NOT_READY).
     */
    public function record(string $flagKey, ?array $bridgeResult): void
    {
        if (!$this->isMetricsEnabled()) {
            return;
        }

        $errorType = $this->resolveErrorType($bridgeResult);
        $reason = $this->resolveReason($bridgeResult, $errorType);

        $attributes = [
            'feature_flag.key' => $flagKey,
            'feature_flag.result.variant' => self::stringOrEmpty($bridgeResult['variant'] ?? null),
            'feature_flag.result.reason' => $reason,
            'feature_flag.result.allocation_key' => self::stringOrEmpty($bridgeResult['allocation_key'] ?? null),
            'error.type' => $errorType,
        ];

        ($this->counterCallable)($attributes);
    }

    /**
     * Resolve the error.type attribute value.
     *
     * Matches BridgeResultMapper's error-handling semantics:
     *   - null bridge result -> PROVIDER_NOT_READY (bridge engine unavailable)
     *   - non-zero error_code -> mapped error name, fallback GENERAL
     *   - error_code == 0 -> empty string (success)
     *
     * @param array<string, mixed>|null $bridgeResult
     */
    private function resolveErrorType(?array $bridgeResult): string
    {
        if ($bridgeResult === null) {
            return 'PROVIDER_NOT_READY';
        }

        $errorCode = $bridgeResult['error_code'] ?? 0;
        if ($errorCode === 0) {
            return '';
        }

        return self::ERROR_CODE_MAP[$errorCode] ?? 'GENERAL';
    }

    /**
     * Resolve the feature_flag.result.reason attribute value.
     *
     * On any error path the reason is forced to 'ERROR' (matches BridgeResultMapper).
     * On success the reason is looked up from the bridge's `reason` integer.
     *
     * @param array<string, mixed>|null $bridgeResult
     */
    private function resolveReason(?array $bridgeResult, string $errorType): string
    {
        if ($errorType !== '') {
            return 'ERROR';
        }

        // Success path: $bridgeResult is guaranteed non-null here because a
        // null bridge result maps to error.type=PROVIDER_NOT_READY above.
        /** @var array<string, mixed> $bridgeResult */
        $reason = $bridgeResult['reason'] ?? 0;

        return self::REASON_MAP[$reason] ?? 'DEFAULT';
    }

    /**
     * Check whether DD_METRICS_OTEL_ENABLED is set to a truthy value.
     *
     * Accepted truthy values (case-insensitive, trimmed): "true", "1", "yes",
     * "on". Anything else (null, empty, "false", "0", "no", "off", ...) means
     * the counter is disabled.
     */
    private function isMetricsEnabled(): bool
    {
        $reader = $this->envReader ?? static function (string $name): ?string {
            $value = getenv($name);
            return $value !== false ? $value : null;
        };

        $raw = $reader('DD_METRICS_OTEL_ENABLED');
        if ($raw === null) {
            return false;
        }

        $normalized = strtolower(trim($raw));

        return match ($normalized) {
            'true', '1', 'yes', 'on' => true,
            default => false,
        };
    }

    /**
     * Coerce a possibly-null/missing bridge field to a non-null string.
     *
     * OTel attribute values must be typed; OBSV-02 specifies string attributes,
     * and downstream pipelines may reject null. Empty string is the sentinel
     * for "missing" to match the cross-tracer convention.
     *
     * @param mixed $value
     */
    private static function stringOrEmpty(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }

    /**
     * Default counter callable used when no explicit callable is injected.
     *
     * In the staging repo (no compiled dd-trace-php extension) this is a
     * complete no-op: `record()` is safe to call but no metric is emitted.
     * When the compiled extension is present, callers inject a real callable
     * that wires the OTel Meter binding (e.g. via
     * `function_exists('DDTrace\\otel_counter_add')`).
     *
     * @return Closure(array<string, string>): void
     */
    private static function defaultCounterCallable(): Closure
    {
        return static function (array $attributes): void {
            // no-op -- dd-trace-php integration injects the real Meter binding.
        };
    }
}
