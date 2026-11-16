<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use DDTrace\FeatureFlags\Internal\NativeEvaluator;
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
    private ?self $previous = null;
    private ?array $observation = null;
    private int $startedAtMs;

    public function __construct(object $scope)
    {
        $this->scope = $scope;
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
    }

    public function finally(HookContext $context, HookHints $hints): void
    {
        try {
            if ($this->observation !== null) {
                NativeEvaluator::submitFlagEvaluation($this->observation);
            }
        } finally {
            $this->claim();
            $this->observation = null;
        }
    }

    private function protectedObservation(HookContext $context): array
    {
        return [$context->getFlagKey(), null, null,
            $context->getEvaluationContext()->getTargetingKey(), [], 'GENERAL', true,
            false, $this->startedAtMs, false];
    }
}
