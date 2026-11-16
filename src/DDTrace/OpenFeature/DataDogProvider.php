<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\EvaluationLog;
use DDTrace\FeatureFlags\Internal\Evaluator;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetric;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricRecorder;
use DDTrace\FeatureFlags\Internal\NativeEvaluator;
use DDTrace\Log\Logger as GlobalLogger;
use DDTrace\Log\LoggerInterface;
use DDTrace\Log\LogLevel;
use DDTrace\Log\NonThrowingLogger;
use OpenFeature\implementation\hooks\AbstractHook;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\implementation\provider\ResolutionError;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\flags\FlagValueType;
use OpenFeature\interfaces\hooks\HookContext;
use OpenFeature\interfaces\hooks\HookHints;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason as OpenFeatureReason;
use OpenFeature\interfaces\provider\ResolutionDetails as ResolutionDetailsInterface;

final class DataDogProvider extends AbstractProvider
{
    private const ALLOCATION_KEY_METADATA_KEY = 'allocationKey';

    protected static string $NAME = 'Datadog';

    private Evaluator $evaluator;
    private LoggerInterface $datadogLogger;
    private bool $warnedAboutRuntimeNotReady = false;
    private EvaluationMetricRecorder $metricRecorder;
    private object $mainObservationScope;
    private \WeakMap $fiberObservationScopes;

    public function __construct(?LoggerInterface $logger = null)
    {
        // Native evaluation metrics are disabled here because OpenFeature owns
        // the final provider outcome, including OF-level type mismatch mapping.
        $this->mainObservationScope = (object) ['pending' => null, 'recorder' => null];
        $this->fiberObservationScopes = new \WeakMap();
        $this->evaluator = NativeEvaluator::create(false, function (array $observation): void {
            $recorder = $this->observationScope()->recorder;
            if ($recorder !== null) {
                $recorder->capture($observation);
            } else {
                NativeEvaluator::submitFlagEvaluation($observation);
            }
        });
        $this->datadogLogger = new NonThrowingLogger($logger ?: GlobalLogger::get());
        $this->metricRecorder = EvaluationMetricRecorder::createDefault();
        // Register only when enabled: debug-off evaluations run no diagnostic hooks.
        if ($this->datadogLogger->isLevelActive(LogLevel::DEBUG)) {
            $this->setHooks([new class ($this->datadogLogger) extends AbstractHook {
                public function __construct(private LoggerInterface $logger)
                {
                }

                public function supportsFlagValueType(string $flagValueType): bool
                {
                    return true;
                }

                public function before(HookContext $context, HookHints $hints): ?EvaluationContext
                {
                    return null;
                }

                public function after(HookContext $context, ResolutionDetailsInterface $details, HookHints $hints): void
                {
                }

                public function finally(HookContext $context, HookHints $hints): void
                {
                }

                public function error(HookContext $context, \Throwable $error, HookHints $hints): void
                {
                    if (!$this->logger->isLevelActive(LogLevel::DEBUG)) {
                        return;
                    }
                    try {
                        $details = json_encode([
                            'flagKey' => $context->getFlagKey(),
                            'valueType' => $context->getType(),
                            'defaultValue' => $context->getDefaultValue(),
                            'targetingKey' => $context->getEvaluationContext()->getTargetingKey(),
                            'attributes' => (object) $context->getEvaluationContext()->getAttributes()->toArray(),
                            'error' => (string) $error,
                            'returnedValueObservable' => false,
                        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
                        if ($details === false) {
                            $details = 'detailsUnavailable: ' . json_last_error_msg();
                        }
                    } catch (\Throwable $formatError) {
                        $details = 'detailsUnavailable: ' . $formatError->getMessage();
                    }
                    $this->logger->debug('Feature Flags: OpenFeature evaluation error {details}', ['details' => $details]);
                }
            }]);
        }
    }

    public function getHooks(): array
    {
        // The SDK asks once per invocation. A fresh hook keeps finalization
        // state separate even when a before hook fails before ours runs.
        return array_merge(parent::getHooks(), [new FlagEvaluationHook($this->observationScope())]);
    }

    private function observationScope(): object
    {
        $fiber = class_exists(\Fiber::class, false) ? \Fiber::getCurrent() : null;
        if ($fiber === null) {
            return $this->mainObservationScope;
        }
        return $this->fiberObservationScopes[$fiber]
            ?? ($this->fiberObservationScopes[$fiber] = (object) ['pending' => null, 'recorder' => null]);
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
        $scope = $this->observationScope();
        $observation = $scope->pending;
        if ($observation !== null) {
            $observation->claim();
        }
        $previous = $scope->recorder;
        $scope->recorder = $observation;
        try {
            return $this->resolveWithObservation($flagKey, $expectedType, $defaultValue, $context);
        } finally {
            $scope->recorder = $previous;
        }
    }

    private function resolveWithObservation(
        string $flagKey,
        string $expectedType,
        mixed $defaultValue,
        ?EvaluationContext $context
    ): ResolutionDetailsInterface {
        $normalizedContext = $this->normalizeContext($context);
        $details = $this->evaluate($flagKey, $expectedType, $defaultValue, $normalizedContext);
        // OpenFeature, not the provider, determines the value the application
        // receives, so this logs the resolution handed to it.
        EvaluationLog::debug(
            $this->datadogLogger,
            'provider resolved',
            $flagKey,
            $defaultValue,
            $normalizedContext['targetingKey'] ?? null,
            $normalizedContext['attributes'] ?? [],
            $details
        );
        $this->warnIfRuntimeNotReady($details);
        // The PHP OpenFeature SDK does not pass ResolutionDetails to finally
        // hooks, so PHP records metrics here after native evaluation has the
        // final provider result.
        $this->recordEvaluationMetric($flagKey, $details);
        // APM span enrichment is recorded inside NativeEvaluator::evaluate()
        // (the shared choke point), so there is nothing to do here.

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

    private function warnIfRuntimeNotReady(EvaluationDetails $details): void
    {
        if ($this->warnedAboutRuntimeNotReady) {
            return;
        }

        if ($details->getErrorCode() !== EvaluationErrorCode::PROVIDER_NOT_READY) {
            return;
        }

        $message = $details->getErrorMessage();
        if (!is_string($message) || $message === '') {
            $message = 'Datadog-backed PHP OpenFeature evaluation is not ready. Returning the default value.';
        }

        $this->warnedAboutRuntimeNotReady = true;
        $this->datadogLogger->warning($message);
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
