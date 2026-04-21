<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use Closure;

/**
 * Assembles exposure events and sends them to the sidecar via injectable bridge.
 *
 * This is the Phase 3 transport entry point. After each successful evaluation
 * with do_log=true, the DataDogProvider calls ExposureWriter::send() with the
 * ExposureContext from Phase 2.
 *
 * The writer:
 *   1. Captures the timestamp at evaluation time (not flush time -- see Pitfall 3)
 *   2. Builds a single exposure event JSON matching the Ruby/Python cross-tracer format
 *   3. Flattens evaluation context attributes to dot-notation via ContextFlattener
 *   4. Calls the sidecar bridge callable (fire-and-forget per D-02)
 *
 * The sidecar bridge callable signature matches DDTrace\ffe_send_exposure():
 *   fn(string $eventJson, string $flagKey, string $allocationKey,
 *      ?string $targetingKey, string $variantKey): bool
 *
 * The callable is injectable for testing without a compiled C extension,
 * following the same pattern as DataDogProvider's bridge callable (Phase 2).
 *
 * @internal Used by DataDogProvider. Not part of the public API.
 */
final class ExposureWriter
{
    /**
     * Bridge callable for sending exposure events to the sidecar.
     *
     * @var Closure(string, string, string, ?string, string): bool
     */
    private Closure $sidecarCallable;

    /**
     * Callable that returns current time in milliseconds (injectable for testing).
     *
     * @var Closure(): int
     */
    private Closure $timestampProvider;

    /**
     * @param Closure|null $sidecarCallable Custom sidecar bridge callable for testing.
     *        Signature: fn(string $eventJson, string $flagKey, string $allocationKey,
     *                      ?string $targetingKey, string $variantKey): bool
     *        Null = uses DDTrace\ffe_send_exposure() via function_exists guard.
     * @param Closure|null $timestampProvider Custom timestamp provider for testing.
     *        Signature: fn(): int (returns milliseconds since epoch).
     *        Null = uses (int)(microtime(true) * 1000).
     */
    public function __construct(
        ?Closure $sidecarCallable = null,
        ?Closure $timestampProvider = null,
    ) {
        $this->sidecarCallable = $sidecarCallable ?? self::defaultSidecarCallable();
        $this->timestampProvider = $timestampProvider ?? static function (): int {
            return (int)(microtime(true) * 1000);
        };
    }

    /**
     * Send an exposure event to the sidecar for dedup and batched delivery.
     *
     * Builds the event JSON from ExposureContext, flattens optional evaluation
     * attributes to dot-notation, captures timestamp, and fires to sidecar bridge.
     *
     * This is fire-and-forget: the return value indicates whether the event was
     * enqueued (true) or deduplicated/dropped (false), but the caller should not
     * branch on it. Exposure tracking must never affect the evaluation result.
     *
     * @param ExposureContext $context Exposure metadata from evaluation (Phase 2 DTO).
     * @param array<string, mixed>|null $evaluationAttributes Raw evaluation context
     *        attributes to flatten for subject.attributes. Null = no attributes.
     * @return bool True if enqueued, false if deduplicated or buffer full.
     */
    public function send(ExposureContext $context, ?array $evaluationAttributes = null): bool
    {
        $eventJson = $this->buildEventJson($context, $evaluationAttributes);

        return ($this->sidecarCallable)(
            $eventJson,
            $context->flagKey,
            $context->allocationKey ?? '',
            $context->targetingKey,
            $context->variant ?? '',
        );
    }

    /**
     * Build a single exposure event as a JSON string.
     *
     * Format matches Ruby Event.build() and Python build_exposure_event():
     *   {
     *     "timestamp": 1713382853716,
     *     "flag": {"key": "test-flag"},
     *     "allocation": {"key": "default-allocation"},
     *     "variant": {"key": "on"},
     *     "subject": {"id": "user-123", "attributes": {"plan": "enterprise"}}
     *   }
     *
     * @param ExposureContext $context Exposure metadata from evaluation.
     * @param array<string, mixed>|null $evaluationAttributes Raw evaluation context attributes.
     * @return string JSON-encoded single exposure event.
     */
    private function buildEventJson(ExposureContext $context, ?array $evaluationAttributes): string
    {
        $flatAttributes = ($evaluationAttributes !== null)
            ? ContextFlattener::flatten($evaluationAttributes)
            : [];

        $event = [
            'timestamp' => ($this->timestampProvider)(),
            'flag' => ['key' => $context->flagKey],
            'allocation' => ['key' => $context->allocationKey ?? ''],
            'variant' => ['key' => $context->variant ?? ''],
            'subject' => [
                'id' => $context->targetingKey ?? '',
                'attributes' => (object)$flatAttributes,
            ],
        ];

        return json_encode($event, JSON_THROW_ON_ERROR);
    }

    /**
     * Create the default sidecar bridge callable that calls DDTrace\ffe_send_exposure().
     *
     * Returns false when the extension function is not available (graceful degradation).
     *
     * @return Closure(string, string, string, ?string, string): bool
     */
    private static function defaultSidecarCallable(): Closure
    {
        return static function (
            string $eventJson,
            string $flagKey,
            string $allocationKey,
            ?string $targetingKey,
            string $variantKey,
        ): bool {
            if (!function_exists('DDTrace\ffe_send_exposure')) {
                return false;
            }

            return \DDTrace\ffe_send_exposure(
                $eventJson,
                $flagKey,
                $allocationKey,
                $targetingKey,
                $variantKey,
            );
        };
    }
}
