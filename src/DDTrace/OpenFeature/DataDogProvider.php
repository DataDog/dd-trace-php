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
use DDTrace\FeatureFlags\SpanEnrichmentAccumulator;
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
    private const SERIAL_ID_METADATA_KEY = 'serialId';
    private const DO_LOG_METADATA_KEY = 'doLog';
    private const SPAN_ENRICHMENT_CONFIG_KEY = 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_SPAN_ENRICHMENT_ENABLED';

    protected static string $NAME = 'Datadog';

    private Evaluator $evaluator;
    private LoggerInterface $warningLogger;
    private bool $warnedAboutNonProductionRuntime = false;
    private EvaluationMetricRecorder $metricRecorder;
    private bool $spanEnrichmentEnabled = false;
    private ?SpanEnrichmentAccumulator $spanEnrichmentAccumulator = null;
    /**
     * Identity (spl_object_id) of the root span the accumulator is currently
     * bound to. Lets the provider detect a root-span boundary and reset the
     * accumulator per root span (CR-01: otherwise it grows for the provider's
     * whole lifetime, contaminating later spans and leaking across requests in
     * persistent SAPIs).
     */
    private ?int $spanEnrichmentRootId = null;
    /**
     * Test seam: resolve the current root-span id. Null in production, where
     * currentRootSpanId() reads \DDTrace\root_span() directly.
     *
     * @var (callable(): ?int)|null
     */
    private $rootIdResolver = null;
    /**
     * Test seam: register a one-shot accumulator-reset bound to a root-span
     * close. Null in production, where the reset is appended to the root span's
     * $onClose. Signature: function(int $rootId, callable $reset): void.
     *
     * @var (callable(int, callable): void)|null
     */
    private $rootCloseScheduler = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        // Native evaluation metrics are disabled here because OpenFeature owns
        // the final provider outcome, including OF-level type mismatch mapping.
        $this->evaluator = NativeEvaluator::create(false);
        $this->warningLogger = $logger ?: new TriggerErrorLogger();
        $this->metricRecorder = EvaluationMetricRecorder::createDefault();

        // DG-005: only read the gate / allocate the accumulator when the
        // experimental span-enrichment feature is opted in. With the gate off we
        // construct nothing and never stage anything, so the close-span write
        // path stays a cheap no-op and there is no idle per-span overhead.
        $this->spanEnrichmentEnabled = self::spanEnrichmentGateEnabled();
        if ($this->spanEnrichmentEnabled) {
            $this->spanEnrichmentAccumulator = new SpanEnrichmentAccumulator();
        }
    }

    private static function spanEnrichmentGateEnabled(): bool
    {
        // Mirrors EvaluationMetricRecorder's gate read. dd_trace_env_config
        // resolves the extension-managed config; absent (extension not loaded)
        // means the feature is off.
        return \function_exists('dd_trace_env_config')
            && \dd_trace_env_config(self::SPAN_ENRICHMENT_CONFIG_KEY) === true;
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
        // the same $details, immediately after the metrics hook. No-op when the
        // gate is off.
        $this->accumulateSpanEnrichment($flagKey, $details, $normalizedContext['targetingKey'] ?? null);

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
     * Accumulate APM span enrichment data from a single evaluation (DG-004
     * inline path). Mirrors the frozen Node reference branch: a present serial
     * id is recorded (and, when do_log authorizes and a targeting key exists, a
     * subject); an evaluation with no variant is treated as a runtime default.
     * Errors are swallowed (Pattern D) — enrichment must never break the eval.
     */
    private function accumulateSpanEnrichment(
        string $flagKey,
        EvaluationDetails $details,
        ?string $targetingKey
    ): void {
        if (!$this->spanEnrichmentEnabled || $this->spanEnrichmentAccumulator === null) {
            return;
        }

        try {
            // CR-01: reset the accumulator on a root-span boundary so it carries
            // ONLY the current root span's evaluations. Without this the
            // per-provider accumulator grows for the provider's whole lifetime,
            // re-staging stale serial ids / hashed subjects / runtime defaults
            // onto later root spans (within-request multi-root contamination)
            // and, since OpenFeature providers are process-level singletons,
            // across requests in persistent SAPIs (a privacy leak of SHA256
            // subject keys). The native staging store is flushed + cleared on the
            // same ddtrace_close_span root branch; here we keep the PHP-side
            // accumulator in lockstep.
            $this->resetSpanEnrichmentForRootBoundary();

            $exposure = $details->getExposureData();
            $serialId = is_array($exposure) && array_key_exists(self::SERIAL_ID_METADATA_KEY, $exposure)
                ? $exposure[self::SERIAL_ID_METADATA_KEY]
                : null;
            $doLog = is_array($exposure) && !empty($exposure[self::DO_LOG_METADATA_KEY]);

            if ($serialId !== null) {
                $this->spanEnrichmentAccumulator->addSerialId((int) $serialId);
                if ($doLog && $targetingKey !== null && $targetingKey !== '') {
                    $this->spanEnrichmentAccumulator->addSubject($targetingKey, (int) $serialId);
                }
            } else {
                // Runtime-default detection = MISSING variant (Pattern C), never
                // a reason enum.
                $variant = $details->getVariant();
                if ($variant === null || $variant === '') {
                    $this->spanEnrichmentAccumulator->addDefault($flagKey, $details->getValue());
                }
            }

            $this->stageSpanEnrichmentTags();
        } catch (\Throwable $e) {
            // Never let span enrichment break flag evaluation.
            $this->warningLogger->debug('FFE span enrichment accumulation failed: ' . $e->getMessage());
        }
    }

    /**
     * Detect a root-span boundary and, on a change, reset the accumulator so it
     * only ever carries the active root span's evaluations (CR-01). The reset
     * fires on ANY transition (a new root, or losing the active root) so a
     * dropped/abandoned root — which never runs its $onClose handler — cannot
     * leak into the next root span or the next request. On entering a new root
     * span a one-shot reset is also bound to that root's close, keeping the
     * PHP accumulator in lockstep with the native close-span flush.
     */
    private function resetSpanEnrichmentForRootBoundary(): void
    {
        if ($this->spanEnrichmentAccumulator === null) {
            return;
        }

        $rootId = $this->currentRootSpanId();
        if ($rootId === $this->spanEnrichmentRootId) {
            return;
        }

        // Boundary crossed: start clean for the new root (or no-root) context.
        $this->spanEnrichmentAccumulator->clear();
        $this->resetSpanEnrichmentStaging();
        $this->spanEnrichmentRootId = $rootId;

        // Bind a one-shot clear to the new root's close (mirrors the Node
        // #onSpanFinish cleanup). No root => nothing to bind to.
        if ($rootId !== null) {
            $this->scheduleAccumulatorResetOnRootClose($rootId);
        }
    }

    /**
     * Push the currently-accumulated, encoded tag set into native request-local
     * memory so the root-span close path can write it onto the root span. Only
     * stages when there is data; the native side stays empty (and the close-span
     * write is a no-op) otherwise.
     */
    private function stageSpanEnrichmentTags(): void
    {
        if ($this->spanEnrichmentAccumulator === null
            || !$this->spanEnrichmentAccumulator->hasData()
            || !\function_exists('DDTrace\\Internal\\set_ffe_span_enrichment_tags')) {
            return;
        }

        $tags = $this->spanEnrichmentAccumulator->toSpanTags();
        \DDTrace\Internal\set_ffe_span_enrichment_tags(
            $tags[SpanEnrichmentAccumulator::TAG_FLAGS] ?? null,
            $tags[SpanEnrichmentAccumulator::TAG_SUBJECTS] ?? null,
            $tags[SpanEnrichmentAccumulator::TAG_RUNTIME_DEFAULTS] ?? null
        );
    }

    /**
     * Identity of the currently active root span (or null if there is none /
     * tracing is off). Used to detect a root-span boundary so the accumulator is
     * reset per root span rather than growing for the provider's lifetime.
     *
     * The resolver is injectable (`$rootIdResolver`) so the lifecycle can be
     * driven in tests without the native extension; in production it reads
     * `\DDTrace\root_span()`.
     */
    private function currentRootSpanId(): ?int
    {
        if ($this->rootIdResolver !== null) {
            $id = ($this->rootIdResolver)();
            return $id === null ? null : (int) $id;
        }

        if (!\function_exists('DDTrace\\root_span')) {
            return null;
        }

        $root = \DDTrace\root_span();

        return $root !== null ? \spl_object_id($root) : null;
    }

    /**
     * Bind a one-shot reset to the active root span's close so the PHP
     * accumulator is cleared in lockstep with the native flush (which runs on the
     * same `ddtrace_close_span` root branch, AFTER the engine invokes the
     * `$onClose` handlers). Mirrors the frozen Node reference's `#onSpanFinish`
     * cleanup. The engine empties `$onClose` after firing it, so the closure is
     * inherently one-shot per root span.
     *
     * The scheduler is injectable (`$rootCloseScheduler`) for tests; in
     * production it appends to the root span's `$onClose`.
     */
    private function scheduleAccumulatorResetOnRootClose(int $rootId): void
    {
        $reset = function () use ($rootId): void {
            if ($this->spanEnrichmentRootId === $rootId && $this->spanEnrichmentAccumulator !== null) {
                $this->spanEnrichmentAccumulator->clear();
            }
            // The native staging store is cleared by the close-span flush
            // itself; nothing to do here for it.
            if ($this->spanEnrichmentRootId === $rootId) {
                $this->spanEnrichmentRootId = null;
            }
        };

        if ($this->rootCloseScheduler !== null) {
            ($this->rootCloseScheduler)($rootId, $reset);
            return;
        }

        if (!\function_exists('DDTrace\\root_span')) {
            return;
        }

        $root = \DDTrace\root_span();
        if ($root === null) {
            return;
        }

        $root->onClose[] = static function () use ($reset): void {
            $reset();
        };
    }

    /**
     * Clear the native request-local staging slots. Used on a root-span boundary
     * so a previous (possibly dropped, never-flushed) root's staged tags never
     * bleed onto a later root span. No-op without the extension or in tests.
     */
    private function resetSpanEnrichmentStaging(): void
    {
        if (!\function_exists('DDTrace\\Internal\\set_ffe_span_enrichment_tags')) {
            return;
        }

        \DDTrace\Internal\set_ffe_span_enrichment_tags(null, null, null);
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
