<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext as ImmutableEvaluationContext;
use OpenFeature\interfaces\flags\EvaluationContext;

/** A completed flag evaluation and its exposure decision, as given to an ExposureHook callback. */
final class ExposureEvaluation
{
    public const DECISION_ADMITTED = 'admitted';
    public const DECISION_REPEAT = 'repeat';
    public const DECISION_UNAVAILABLE = 'unavailable';
    public const DECISION_UNKNOWN = 'unknown';
    public const DECISION_NOT_EXPOSURE = 'not_exposure';

    /**
     * @param self::DECISION_* $decisionStatus Evaluation-time admission decision.
     * @param array<string, string|float|bool> $features
     * @internal Built by the Datadog provider and ExposureHook.
     */
    public function __construct(
        private string $flagKey,
        private EvaluationContext $evaluationContext,
        private bool $exposure = false,
        private string $decisionStatus = self::DECISION_NOT_EXPOSURE,
        private ?string $variant = null,
        private ?string $allocationKey = null,
        private ?int $splitSerialId = null,
        private array $features = [],
    ) {
    }

    /** @internal */
    public static function copyContext(EvaluationContext $context): EvaluationContext
    {
        return new ImmutableEvaluationContext(
            $context->getTargetingKey(),
            new Attributes($context->getAttributes()->toArray())
        );
    }

    public function getFlagKey(): string
    {
        return $this->flagKey;
    }

    /** Null when the evaluation failed or returned the default value. */
    public function getVariant(): ?string
    {
        return $this->variant;
    }

    public function getAllocationKey(): ?string
    {
        return $this->allocationKey;
    }

    public function getSplitSerialId(): ?int
    {
        return $this->splitSerialId;
    }

    public function getTargetingKey(): ?string
    {
        return $this->evaluationContext->getTargetingKey();
    }

    /** A copy of the context the flag was evaluated with. */
    public function getEvaluationContext(): EvaluationContext
    {
        return $this->evaluationContext;
    }

    /**
     * The features of the selected split, such as holdout.key and holdout.assignment_group.
     *
     * @return array<string, string|float|bool> Empty when the split has none.
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /** True for a successful evaluation of an allocation that logs exposures, repeats included. */
    public function isExposure(): bool
    {
        return $this->exposure;
    }

    /** @return self::DECISION_* The sidecar verdict, or DECISION_NOT_EXPOSURE for an ineligible evaluation. */
    public function getDecisionStatus(): string
    {
        return $this->decisionStatus;
    }

    /** True when the assignment matches the latest admission in the current decision domain. */
    public function isCacheHit(): bool
    {
        return $this->decisionStatus === self::DECISION_REPEAT;
    }

    /** True when the sidecar admitted this observation during evaluation. */
    public function shouldSend(): bool
    {
        return $this->decisionStatus === self::DECISION_ADMITTED;
    }
}
