<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use DDTrace\FeatureFlags\Internal\NativeEvaluator;
use DDTrace\FeatureFlags\Internal\SplitFeatures;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\hooks\Hook;
use OpenFeature\interfaces\hooks\HookContext;
use OpenFeature\interfaces\hooks\HookHints;
use OpenFeature\interfaces\provider\ResolutionDetails;
use OpenFeature\interfaces\provider\ThrowableWithResolutionError;

/** @internal One instance per OpenFeature evaluation, not a provider-wide last result. */
final class FlagEvaluationHook implements Hook
{
    private object $scope;
    /** @var callable|null */
    private $exposureRecorder;
    private ?self $previous = null;
    private ?array $observation = null;
    private ?array $exposure = null;
    private ?ResolutionDetails $details = null;
    private int $startedAtMs;
    private static ?\WeakMap $exposureEvaluations = null;

    /** @param callable|null $exposureRecorder Returns false for a repeat, null when nothing was recorded. */
    public function __construct(object $scope, ?callable $exposureRecorder = null)
    {
        $this->scope = $scope;
        $this->exposureRecorder = $exposureRecorder;
        $this->startedAtMs = (int) (microtime(true) * 1000);
    }

    public function supportsFlagValueType(string $flagValueType): bool
    {
        return true;
    }

    public function before(HookContext $context, HookHints $hints): ?EvaluationContext
    {
        $this->previous = $this->scope->pending;
        $this->scope->pending = $this;
        return null;
    }

    // The provider claims this invocation before resolving. Direct/nested
    // provider calls must not overwrite its observation.
    public function claim(): void
    {
        if ($this->scope->pending === $this) {
            $this->scope->pending = $this->previous;
        }
        $this->previous = null;
    }

    public function capture(array $observation): void
    {
        $this->observation = $observation;
    }

    public function captureExposure(array $exposure): void
    {
        $this->exposure = $exposure;
    }

    /** @internal The decision made for this evaluation, for ExposureHook. */
    public static function exposureEvaluation(HookContext $context): ?ExposureEvaluation
    {
        return self::$exposureEvaluations[$context] ?? null;
    }

    public function after(HookContext $context, ResolutionDetails $details, HookHints $hints): void
    {
        if ($this->observation === null) {
            // No native snapshot (for example, an unavailable evaluator).
            // Never infer consent from the current configuration.
            $this->observation = $this->protectedObservation($context);
            $this->observation[1] = $details->getVariant();
            $this->observation[5] = $details->getError()
                ? $details->getError()->getResolutionErrorCode()->getValue() : null;
            $this->observation[6] = $details->getVariant() === null;
        }
        // Later after hooks can still fail the evaluation, so the exposure
        // is decided in finally.
        $this->details = $details;
    }

    public function error(HookContext $context, \Throwable $error, HookHints $hints): void
    {
        $this->observation = $this->observation ?? $this->protectedObservation($context);
        // Before/after failures replace the SDK result with the caller default.
        // Keep evaluated consent/time, but not the successful variant/allocation.
        $this->observation[1] = null;
        $this->observation[2] = null;
        $this->observation[5] = $error instanceof ThrowableWithResolutionError
            ? $error->getResolutionError()->getResolutionErrorCode()->getValue() : 'GENERAL';
        $this->observation[6] = true;
        $this->observation[10] = [];
        $this->details = null;
    }

    public function finally(HookContext $context, HookHints $hints): void
    {
        try {
            $this->decideExposure($context);
            if ($this->observation !== null) {
                NativeEvaluator::submitFlagEvaluation($this->observation);
            }
        } finally {
            $this->claim();
            $this->observation = null;
            $this->exposure = null;
            $this->details = null;
        }
    }

    private function decideExposure(HookContext $context): void
    {
        try {
            $exposure = $this->details !== null ? $this->exposure : null;
            $cacheHit = null;
            if ($exposure !== null && $exposure['isExposure'] && $this->exposureRecorder !== null) {
                try {
                    $recorded = ($this->exposureRecorder)(
                        $exposure['flagKey'],
                        $exposure['targetingKey'],
                        $exposure['attributes'],
                        $exposure['allocationKey'],
                        $exposure['variant'],
                        $exposure['serialId'],
                        $exposure['exposureFeatures']
                    );
                    $cacheHit = is_bool($recorded) ? !$recorded : null;
                } catch (\Throwable $ignored) {
                }
            }
            if ($cacheHit !== null && method_exists($this->details, 'setMetadata')) {
                $this->details->setMetadata(
                    [SplitFeatures::EXPOSURE_CACHE_HIT_KEY => $cacheHit] + ($this->details->getMetadata() ?? [])
                );
            }
            self::$exposureEvaluations ??= new \WeakMap();
            self::$exposureEvaluations[$context] = new ExposureEvaluation(
                $context->getFlagKey(),
                $this->exposure['context'] ?? ExposureEvaluation::copyContext($context->getEvaluationContext()),
                $exposure !== null && $exposure['isExposure'],
                $cacheHit,
                $exposure['variant'] ?? null,
                $exposure['allocationKey'] ?? null,
                $exposure['serialId'] ?? null,
                $exposure['features'] ?? []
            );
        } catch (\Throwable $ignored) {
            // Exposure reporting must not change the evaluation result.
        }
    }

    private function protectedObservation(HookContext $context): array
    {
        return [$context->getFlagKey(), null, null,
            $context->getEvaluationContext()->getTargetingKey(), [], 'GENERAL', true,
            false, $this->startedAtMs, false];
    }
}
