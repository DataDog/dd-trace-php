<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\Evaluator;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetric;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricRecorder;
use DDTrace\FeatureFlags\Internal\NativeEvaluator;
use DDTrace\FeatureFlags\SpanEnrichmentBinder;
use DDTrace\Log\LoggerInterface;
use DDTrace\Log\TriggerErrorLogger;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\implementation\provider\ResolutionError;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\flags\FlagValueType;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason as OpenFeatureReason;
use OpenFeature\interfaces\provider\ResolutionDetails as ResolutionDetailsInterface;

final class DataDogProvider extends AbstractProvider
{
    private const ALLOCATION_KEY_METADATA_KEY = 'allocationKey';

    protected static string $NAME = 'Datadog';

    private Evaluator $evaluator;
    private LoggerInterface $warningLogger;
    private bool $warnedAboutNonProductionRuntime = false;
    private EvaluationMetricRecorder $metricRecorder;
    /**
     * Gate-gated adapter to the SHARED request-scoped span-enrichment
     * accumulator (SpanEnrichmentRegistry). Null unless the experimental
     * span-enrichment gate is on (DG-005: gate off => no binder, no per-span
     * overhead). Routing through the shared registry rather than a per-provider
     * accumulator is what lets multiple providers / native clients / mixed
     * evaluations under one root span AGGREGATE rather than OVERWRITE one
     * another's tags (PR review blocker).
     */
    private ?SpanEnrichmentBinder $spanEnrichmentBinder = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        // Native evaluation metrics are disabled here because OpenFeature owns
        // the final provider outcome, including OF-level type mismatch mapping.
        $this->evaluator = NativeEvaluator::create(false);
        $this->warningLogger = $logger ?: new TriggerErrorLogger();
        $this->metricRecorder = EvaluationMetricRecorder::createDefault();

        // DG-005: only construct the binder when the experimental span-enrichment
        // feature is opted in. With the gate off we construct nothing and never
        // accumulate/stage anything, so the close-span write path stays a cheap
        // no-op and there is no idle per-span overhead.
        if (SpanEnrichmentBinder::gateEnabled()) {
            $this->spanEnrichmentBinder = new SpanEnrichmentBinder();
        }
    }

    /**
     * @internal Tests and Datadog-owned bridge adapters only.
     */
    public static function createWithDependencies(
        ?Evaluator $evaluator = null,
        ?LoggerInterface $logger = null,
        $metricRecorder = null
    ): self {
        $provider = new self($logger);
        if ($evaluator !== null) {
            $provider->evaluator = $evaluator;
        }
        if ($metricRecorder !== null) {
            $provider->metricRecorder = new EvaluationMetricRecorder($metricRecorder);
        }

        return $provider;
    }

    public function resolveBooleanValue(
        string $flagKey,
        bool $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        return $this->resolve($flagKey, FlagValueType::BOOLEAN, $defaultValue, $context);
    }

    public function resolveStringValue(
        string $flagKey,
        string $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        return $this->resolve($flagKey, FlagValueType::STRING, $defaultValue, $context);
    }

    public function resolveIntegerValue(
        string $flagKey,
        int $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        return $this->resolve($flagKey, FlagValueType::INTEGER, $defaultValue, $context);
    }

    public function resolveFloatValue(
        string $flagKey,
        float $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        return $this->resolve($flagKey, FlagValueType::FLOAT, $defaultValue, $context);
    }

    /**
     * @param array<string, mixed> $defaultValue
     */
    public function resolveObjectValue(
        string $flagKey,
        array $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        return $this->resolve($flagKey, FlagValueType::OBJECT, $defaultValue, $context);
    }

    private function resolve(
        string $flagKey,
        string $expectedType,
        mixed $defaultValue,
        ?EvaluationContext $context
    ): ResolutionDetailsInterface {
        $normalizedContext = $this->normalizeContext($context);
        $details = $this->evaluate($flagKey, $expectedType, $defaultValue, $normalizedContext);
        $this->warnIfNonProductionRuntime($details);
        // The PHP OpenFeature SDK does not pass ResolutionDetails to finally
        // hooks, so PHP records metrics here after native evaluation has the
        // final provider result.
        $this->recordEvaluationMetric($flagKey, $details);
        // DG-004: PHP has no finally hook with ResolutionDetails, so APM span
        // enrichment is accumulated INLINE here, on the same code path and from
        // the same $details, immediately after the metrics hook. Skipped entirely
        // with the gate off (no binder was constructed); when on, it feeds the
        // SHARED registry so this provider's tags aggregate with any other
        // evaluation path active on the same root span.
        if ($this->spanEnrichmentBinder !== null) {
            $this->spanEnrichmentBinder->accumulate(
                $flagKey,
                $details,
                $normalizedContext['targetingKey'] ?? null
            );
        }

        $builder = (new ResolutionDetailsBuilder())
            ->withValue($details->getValue())
            ->withReason($this->mapReason($details->getReason()));

        $variant = $details->getVariant();
        if ($variant !== null && $variant !== '') {
            $builder->withVariant($variant);
        }

        if ($details->getErrorCode() !== null) {
            $builder->withError(new ResolutionError(
                $this->mapErrorCode($details->getErrorCode()),
                $details->getErrorMessage()
            ));
        }

        return $builder->build();
    }

    private function recordEvaluationMetric(string $flagKey, EvaluationDetails $details): void
    {
        $this->metricRecorder->record(EvaluationMetric::create(
            $flagKey,
            $details->getVariant(),
            $details->getReason(),
            $details->getErrorCode(),
            $this->allocationKey($details)
        ));
    }

    private function allocationKey(EvaluationDetails $details): ?string
    {
        $exposure = $details->getExposureData();
        // This is Datadog-internal evaluator metadata. PHP OpenFeature has no
        // flagMetadata surface here, so keep the key internal to the provider.
        $key = self::ALLOCATION_KEY_METADATA_KEY;
        if (!is_array($exposure) || !isset($exposure[$key]) || !is_string($exposure[$key])) {
            return null;
        }

        return $exposure[$key] !== '' ? $exposure[$key] : null;
    }

    /**
     * @param bool|string|int|float|array<string, mixed> $defaultValue
     * @param array<string, mixed> $context
     */
    private function evaluate(
        string $flagKey,
        string $expectedType,
        mixed $defaultValue,
        array $context
    ): EvaluationDetails {
        $evaluationType = match ($expectedType) {
            FlagValueType::BOOLEAN => EvaluationType::BOOLEAN,
            FlagValueType::STRING => EvaluationType::STRING,
            FlagValueType::INTEGER => EvaluationType::INTEGER,
            FlagValueType::FLOAT => EvaluationType::FLOAT,
            FlagValueType::OBJECT => EvaluationType::OBJECT,
            default => throw new \InvalidArgumentException('Unknown OpenFeature flag value type: ' . $expectedType),
        };

        return $this->evaluator->evaluate(
            $flagKey,
            $evaluationType,
            $defaultValue,
            $context['targetingKey'] ?? null,
            $context['attributes'] ?? []
        );
    }

    /**
     * @return array{targetingKey?: ?string, attributes?: array<string, bool|int|float|string>}
     */
    private function normalizeContext(?EvaluationContext $context): array
    {
        if ($context === null) {
            return [];
        }

        $attributes = [];
        foreach ($context->getAttributes()->toArray() as $key => $value) {
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $attributes[(string) $key] = $value;
            }
        }

        return [
            'targetingKey' => $context->getTargetingKey(),
            'attributes' => $attributes,
        ];
    }

    private function warnIfNonProductionRuntime(EvaluationDetails $details): void
    {
        if ($this->warnedAboutNonProductionRuntime) {
            return;
        }

        $providerState = $details->getProviderState();
        if (!array_key_exists('productionRuntime', $providerState) || $providerState['productionRuntime'] !== false) {
            return;
        }

        $message = $details->getErrorMessage();
        if (!is_string($message) || $message === '') {
            $message = 'Datadog-backed PHP OpenFeature evaluation is not fully enabled yet.';
        }

        $this->warningLogger->warning($message);
        $this->warnedAboutNonProductionRuntime = true;
    }

    private function mapReason(string $reason): string
    {
        return match ($reason) {
            EvaluationReason::STATIC_REASON => EvaluationReason::STATIC_REASON,
            EvaluationReason::DEFAULT_REASON => OpenFeatureReason::DEFAULT,
            EvaluationReason::TARGETING_MATCH => OpenFeatureReason::TARGETING_MATCH,
            EvaluationReason::SPLIT => OpenFeatureReason::SPLIT,
            EvaluationReason::DISABLED => OpenFeatureReason::DISABLED,
            EvaluationReason::ERROR => OpenFeatureReason::ERROR,
            default => OpenFeatureReason::UNKNOWN,
        };
    }

    private function mapErrorCode(string $errorCode): ErrorCode
    {
        return match ($errorCode) {
            EvaluationErrorCode::PROVIDER_NOT_READY => ErrorCode::PROVIDER_NOT_READY(),
            EvaluationErrorCode::FLAG_NOT_FOUND => ErrorCode::FLAG_NOT_FOUND(),
            EvaluationErrorCode::PARSE_ERROR => ErrorCode::PARSE_ERROR(),
            EvaluationErrorCode::TYPE_MISMATCH => ErrorCode::TYPE_MISMATCH(),
            default => ErrorCode::GENERAL(),
        };
    }
}
