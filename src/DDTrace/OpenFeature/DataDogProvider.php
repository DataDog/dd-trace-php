<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use DDTrace\FeatureFlags\Client as FeatureFlagsClient;
use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\Internal\DefaultEvaluationCompletedHook;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricHook;
use DDTrace\FeatureFlags\Internal\NoopWarningEmitter;
use DDTrace\FeatureFlags\Internal\TriggerErrorWarningEmitter;
use DDTrace\FeatureFlags\Internal\WarningEmitter;
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
    protected static string $NAME = 'Datadog';

    private FeatureFlagsClient $client;
    private WarningEmitter $warningEmitter;
    private bool $warnedAboutNonProductionRuntime = false;
    private ?EvaluationDetails $lastEvaluationDetails = null;

    /** @var EvalMetricsHook */
    private $metricsHook;

    public function __construct()
    {
        // PHP 8 OpenFeature path records `feature_flag.evaluations` via an
        // OpenFeature `after`/`error` hook (mirroring dd-trace-go/js/java/dotnet
        // architecture). Construct the DD client with an exposure-only hook
        // composite so the metric is not also recorded inside Client::evaluate()
        // and double-counted.
        $this->client = FeatureFlagsClient::createWithDependencies(
            null,
            new NoopWarningEmitter(),
            DefaultEvaluationCompletedHook::createWithoutMetric()
        );
        $this->warningEmitter = new TriggerErrorWarningEmitter();

        $provider = $this;
        $this->metricsHook = new EvalMetricsHook(
            EvaluationMetricHook::sharedWriter(),
            function () use ($provider) {
                return $provider->consumeLastEvaluationDetails();
            }
        );
    }

    /**
     * Always include the Datadog metric hook ahead of any user-supplied
     * provider hooks. `AbstractProvider::setHooks()` REPLACES the hook list,
     * so registering the metric hook via `setHooks([$metricsHook])` in the
     * constructor would let a later `$provider->setHooks($userHooks)` silently
     * drop our metric emission. Overriding `getHooks()` keeps both: the user
     * can register their own provider-level hooks freely, and we always
     * record `feature_flag.evaluations` on the OpenFeature path.
     *
     * @return array<int, \OpenFeature\interfaces\hooks\Hook>
     */
    public function getHooks(): array
    {
        return array_merge([$this->metricsHook], parent::getHooks());
    }

    /**
     * @internal Datadog-owned bridge adapters only.
     *
     * Returns the most recent DD-side `EvaluationDetails` produced by this
     * provider during the current OpenFeature evaluation, then clears the
     * stash. Returns `null` if the provider was not invoked for this
     * evaluation (e.g. a `before` hook threw before `resolve*Value`).
     */
    public function consumeLastEvaluationDetails(): ?EvaluationDetails
    {
        $details = $this->lastEvaluationDetails;
        $this->lastEvaluationDetails = null;
        return $details;
    }

    /**
     * @internal Tests and Datadog-owned bridge adapters only.
     */
    public static function createWithDependencies(
        ?FeatureFlagsClient $client = null,
        ?WarningEmitter $warningEmitter = null,
        $metricWriter = null
    ): self {
        $provider = new self();
        if ($client !== null) {
            $provider->client = $client;
        }
        if ($warningEmitter !== null) {
            $provider->warningEmitter = $warningEmitter;
        }
        if ($metricWriter !== null) {
            $provider->setHooks([
                new EvalMetricsHook(
                    $metricWriter,
                    function () use ($provider) {
                        return $provider->consumeLastEvaluationDetails();
                    }
                ),
            ]);
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
        // Clear any stale stash so a throw from inside evaluate() leaves it null
        // and the OpenFeature `error` hook sees no leftover details from a prior call.
        $this->lastEvaluationDetails = null;
        $details = $this->evaluate($flagKey, $expectedType, $defaultValue, $this->normalizeContext($context));
        $this->lastEvaluationDetails = $details;
        $this->warnIfNonProductionRuntime($details);

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
        return match ($expectedType) {
            FlagValueType::BOOLEAN => $this->client->getBooleanDetails($flagKey, $defaultValue, $context),
            FlagValueType::STRING => $this->client->getStringDetails($flagKey, $defaultValue, $context),
            FlagValueType::INTEGER => $this->client->getIntegerDetails($flagKey, $defaultValue, $context),
            FlagValueType::FLOAT => $this->client->getFloatDetails($flagKey, $defaultValue, $context),
            FlagValueType::OBJECT => $this->client->getObjectDetails($flagKey, $defaultValue, $context),
            default => throw new \InvalidArgumentException('Unknown OpenFeature flag value type: ' . $expectedType),
        };
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

        $this->warningEmitter->warning($message);
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
