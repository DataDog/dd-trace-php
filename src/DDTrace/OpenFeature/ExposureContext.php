<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

/**
 * Exposure metadata assembled during flag evaluation for later sidecar handoff.
 *
 * This is the Phase 2 handoff surface: it carries all the data that Phase 3
 * will need to construct exposure payloads without re-evaluating flags or
 * re-reading environment variables.
 *
 * Fields:
 *   - service, env, version: from DD_SERVICE, DD_ENV, DD_VERSION
 *   - flagKey: the flag that was evaluated
 *   - allocationKey: which allocation matched (from bridge result)
 *   - variant: which variant was selected (from bridge result)
 *   - targetingKey: the evaluation context targeting key
 *   - doLog: the bridge's exposure gating flag
 *
 * The `doLog` field is the hard gate: when false, no ExposureContext is
 * produced by the provider. Phase 3 transport code can treat the absence
 * of an ExposureContext as "nothing to report" without additional checks.
 *
 * No transport, batching, dedup, or sidecar IPC logic exists in this class.
 * It is purely a data transfer object for the Phase 2 -> Phase 3 boundary.
 *
 * @internal Used by DataDogProvider. Not part of the public API.
 */
final class ExposureContext
{
    /**
     * @param string|null $service DD_SERVICE value (null if unset)
     * @param string|null $env DD_ENV value (null if unset)
     * @param string|null $version DD_VERSION value (null if unset)
     * @param string $flagKey The flag key that was evaluated
     * @param string|null $allocationKey The matched allocation key (from bridge result)
     * @param string|null $variant The selected variant key (from bridge result)
     * @param string|null $targetingKey The evaluation context targeting key
     */
    public function __construct(
        public readonly ?string $service,
        public readonly ?string $env,
        public readonly ?string $version,
        public readonly string $flagKey,
        public readonly ?string $allocationKey,
        public readonly ?string $variant,
        public readonly ?string $targetingKey,
    ) {
    }

    /**
     * Build an ExposureContext from bridge result fields and environment variables.
     *
     * Returns null when do_log is false -- this is the hard gate that prevents
     * downstream exposure reporting for evaluations the evaluator says not to log.
     *
     * @param array<string, mixed> $bridgeResult The raw result from DDTrace\ffe_evaluate()
     * @param string $flagKey The flag key that was evaluated
     * @param string|null $targetingKey The evaluation context targeting key
     * @param Closure|null $envReader Override for reading environment variables (testing)
     * @return self|null ExposureContext or null when do_log is false
     */
    public static function fromBridgeResult(
        array $bridgeResult,
        string $flagKey,
        ?string $targetingKey,
        ?\Closure $envReader = null,
    ): ?self {
        $doLog = $bridgeResult['do_log'] ?? false;

        // Hard gate: do_log=false means no exposure context is produced
        if (!$doLog) {
            return null;
        }

        $readEnv = $envReader ?? static function (string $name): ?string {
            $value = getenv($name);
            return $value !== false ? $value : null;
        };

        return new self(
            service: $readEnv('DD_SERVICE'),
            env: $readEnv('DD_ENV'),
            version: $readEnv('DD_VERSION'),
            flagKey: $flagKey,
            allocationKey: $bridgeResult['allocation_key'] ?? null,
            variant: $bridgeResult['variant'] ?? null,
            targetingKey: $targetingKey,
        );
    }

    /**
     * Convert to an associative array for serialization or debugging.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'service' => $this->service,
            'env' => $this->env,
            'version' => $this->version,
            'flag_key' => $this->flagKey,
            'allocation_key' => $this->allocationKey,
            'variant' => $this->variant,
            'targeting_key' => $this->targetingKey,
        ];
    }
}
