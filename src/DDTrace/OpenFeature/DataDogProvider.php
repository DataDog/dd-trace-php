<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use Closure;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ResolutionDetails as ResolutionDetailsInterface;

/**
 * OpenFeature provider for Datadog feature flag evaluation.
 *
 * Delegates all evaluation to the Rust-backed DDTrace\ffe_evaluate() bridge.
 * No flag evaluation logic is implemented in PHP -- the provider is purely an
 * adapter between the OpenFeature SDK and the Phase 1 FFI bridge.
 *
 * All five typed resolution methods share a single internal evaluation pipeline
 * to ensure consistent bridge invocation, context normalization, and error mapping.
 *
 * Lifecycle integration:
 *   - Per-call readiness checks via ProviderLifecycle
 *   - Non-ready state returns defaults with PROVIDER_NOT_READY error
 *   - Blocking init available via OpenFeatureLifecycleCompatibility
 *
 * Exposure tracking:
 *   - On successful evaluation with do_log=true, assembles an ExposureContext
 *   - do_log=false is a hard gate: no exposure context is produced
 *   - ExposureWriter fires exposure events to the sidecar (fire-and-forget)
 *   - Exposure sending never blocks the evaluation return path
 *
 * @internal This provider is registered via OpenFeatureAPI::setProvider().
 */
final class DataDogProvider extends AbstractProvider
{
    protected static string $NAME = 'Datadog';

    /**
     * Bridge expected-type constants matching ext/ddtrace.stub.php:
     *   0=string, 1=int, 2=float, 3=bool, 4=object
     */
    private const TYPE_STRING = 0;
    private const TYPE_INT = 1;
    private const TYPE_FLOAT = 2;
    private const TYPE_BOOL = 3;
    private const TYPE_OBJECT = 4;

    private BridgeResultMapper $resultMapper;
    private EvaluationContextNormalizer $contextNormalizer;
    private ProviderLifecycle $lifecycle;
    private ExposureWriter $exposureWriter;
    private MetricsCounter $metricsCounter;

    /**
     * Last exposure context produced by a successful evaluation.
     * Null when the last evaluation had do_log=false, was an error, or provider was not ready.
     *
     * Phase 3 transport will consume this via getLastExposureContext().
     */
    private ?ExposureContext $lastExposureContext = null;

    /**
     * Optional override for reading environment variables (testing).
     *
     * @var \Closure|null
     */
    private ?\Closure $envReader;

    /**
     * Bridge callable: fn(string $flagKey, int $expectedType, ?string $targetingKey, array $attributes): ?array
     *
     * @var Closure(string, int, ?string, array<string, bool|string|int|float>): (?array<string, mixed>)
     */
    private Closure $bridgeCallable;

    /**
     * @param BridgeResultMapper|null $resultMapper Custom result mapper (null = default)
     * @param EvaluationContextNormalizer|null $contextNormalizer Custom normalizer (null = default)
     * @param Closure|null $bridgeCallable Custom bridge callable for testing (null = uses DDTrace\ffe_evaluate)
     * @param ProviderLifecycle|null $lifecycle Custom lifecycle helper for testing (null = default)
     * @param ExposureWriter|null $exposureWriter Custom exposure writer for testing (null = default)
     * @param Closure|null $envReader Override for reading environment variables (testing)
     * @param MetricsCounter|null $metricsCounter Custom metrics counter for testing (null = default no-op)
     */
    public function __construct(
        ?BridgeResultMapper $resultMapper = null,
        ?EvaluationContextNormalizer $contextNormalizer = null,
        ?Closure $bridgeCallable = null,
        ?ProviderLifecycle $lifecycle = null,
        ?ExposureWriter $exposureWriter = null,
        ?\Closure $envReader = null,
        ?MetricsCounter $metricsCounter = null,
    ) {
        $this->resultMapper = $resultMapper ?? new BridgeResultMapper();
        $this->contextNormalizer = $contextNormalizer ?? new EvaluationContextNormalizer();
        $this->bridgeCallable = $bridgeCallable ?? self::defaultBridgeCallable();
        $this->lifecycle = $lifecycle ?? new ProviderLifecycle();
        $this->exposureWriter = $exposureWriter ?? new ExposureWriter();
        $this->envReader = $envReader;
        $this->metricsCounter = $metricsCounter ?? new MetricsCounter();
    }

    /**
     * Access the provider's lifecycle helper.
     *
     * Used by OpenFeatureLifecycleCompatibility for blocking init.
     *
     * @internal Not part of the public Datadog API.
     */
    public function getLifecycle(): ProviderLifecycle
    {
        return $this->lifecycle;
    }

    /**
     * Get the exposure context from the last successful evaluation.
     *
     * Returns null when:
     *   - No evaluation has occurred yet
     *   - The last evaluation had do_log=false
     *   - The last evaluation was an error or provider was not ready
     *
     * Phase 3 transport will consume this to build exposure payloads.
     *
     * @internal Not part of the public Datadog API.
     */
    public function getLastExposureContext(): ?ExposureContext
    {
        return $this->lastExposureContext;
    }

    public function resolveBooleanValue(
        string $flagKey,
        bool $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetailsInterface {
        return $this->resolveViaFfe($flagKey, $defaultValue, self::TYPE_BOOL, 'boolean', $context);
    }

    public function resolveStringValue(
        string $flagKey,
        string $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetailsInterface {
        return $this->resolveViaFfe($flagKey, $defaultValue, self::TYPE_STRING, 'string', $context);
    }

    public function resolveIntegerValue(
        string $flagKey,
        int $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetailsInterface {
        return $this->resolveViaFfe($flagKey, $defaultValue, self::TYPE_INT, 'integer', $context);
    }

    public function resolveFloatValue(
        string $flagKey,
        float $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetailsInterface {
        return $this->resolveViaFfe($flagKey, $defaultValue, self::TYPE_FLOAT, 'float', $context);
    }

    /**
     * @param mixed[] $defaultValue
     */
    public function resolveObjectValue(
        string $flagKey,
        array $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetailsInterface {
        return $this->resolveViaFfe($flagKey, $defaultValue, self::TYPE_OBJECT, 'object', $context);
    }

    /**
     * Shared evaluation pipeline used by all five typed resolver methods.
     *
     * 1. Check provider readiness via lifecycle helper
     * 2. Normalize the evaluation context to (targetingKey, flatPrimitiveAttrs)
     * 3. Call DDTrace\ffe_evaluate() via the bridge callable
     * 4. Map the bridge result to an OpenFeature ResolutionDetails
     *
     * @param string $flagKey The flag key to evaluate
     * @param bool|string|int|float|mixed[] $defaultValue Caller-provided default
     * @param int $expectedType Bridge type constant (0-4)
     * @param string $mapperType Mapper type name: boolean, string, integer, float, object
     * @param EvaluationContext|null $context OpenFeature evaluation context
     */
    private function resolveViaFfe(
        string $flagKey,
        bool|string|int|float|array $defaultValue,
        int $expectedType,
        string $mapperType,
        ?EvaluationContext $context,
    ): ResolutionDetailsInterface {
        // Per-call readiness check via lifecycle helper.
        // When not ready, delegate to the mapper with null bridge result,
        // which returns default value with PROVIDER_NOT_READY error.
        if (!$this->lifecycle->isReady()) {
            // Counter fires on not-ready path too (D-03) with error.type=PROVIDER_NOT_READY.
            $this->metricsCounter->record($flagKey, null);

            return match ($mapperType) {
                'boolean' => $this->resultMapper->mapBoolean(null, $defaultValue),
                'string' => $this->resultMapper->mapString(null, $defaultValue),
                'integer' => $this->resultMapper->mapInteger(null, $defaultValue),
                'float' => $this->resultMapper->mapFloat(null, $defaultValue),
                'object' => $this->resultMapper->mapObject(null, $defaultValue),
                default => throw new \LogicException("Unsupported mapper type: {$mapperType}"),
            };
        }

        [$targetingKey, $attributes] = $this->contextNormalizer->normalize($context);

        $bridgeResult = ($this->bridgeCallable)($flagKey, $expectedType, $targetingKey, $attributes);

        // Assemble exposure context on the success path.
        // do_log=false is a hard gate: no exposure context is produced.
        // Null bridge result or error paths also produce no exposure context.
        if ($bridgeResult !== null && ($bridgeResult['error_code'] ?? -1) === 0) {
            $this->lastExposureContext = ExposureContext::fromBridgeResult(
                $bridgeResult,
                $flagKey,
                $targetingKey,
                $this->envReader,
            );
        } else {
            $this->lastExposureContext = null;
        }

        // Fire-and-forget exposure event to sidecar (per D-12, D-13).
        // Only send when ExposureContext is non-null (do_log=true, no error).
        if ($this->lastExposureContext !== null) {
            $this->exposureWriter->send(
                $this->lastExposureContext,
                $attributes,
            );
        }

        // Record OTel feature_flag.evaluations counter (D-02, D-03).
        // Single call site per evaluation; fires on success and bridge-error paths.
        // The not-ready early-return path records the counter separately above.
        $this->metricsCounter->record($flagKey, $bridgeResult);

        return match ($mapperType) {
            'boolean' => $this->resultMapper->mapBoolean($bridgeResult, $defaultValue),
            'string' => $this->resultMapper->mapString($bridgeResult, $defaultValue),
            'integer' => $this->resultMapper->mapInteger($bridgeResult, $defaultValue),
            'float' => $this->resultMapper->mapFloat($bridgeResult, $defaultValue),
            'object' => $this->resultMapper->mapObject($bridgeResult, $defaultValue),
            default => throw new \LogicException("Unsupported mapper type: {$mapperType}"),
        };
    }

    /**
     * Set the service context on the exposure writer's sidecar bridge.
     *
     * Called once during provider initialization to pass DD_SERVICE, DD_ENV, DD_VERSION
     * to the Rust exposure state for inclusion in batch payloads.
     *
     * @internal Not part of the public Datadog API.
     */
    public function initializeServiceContext(): void
    {
        $readEnv = $this->envReader ?? static function (string $name): ?string {
            $value = getenv($name);
            return $value !== false ? $value : null;
        };

        $service = $readEnv('DD_SERVICE') ?? '';
        $env = $readEnv('DD_ENV') ?? '';
        $version = $readEnv('DD_VERSION') ?? '';

        if (function_exists('DDTrace\ffe_set_service_context')) {
            \DDTrace\ffe_set_service_context($service, $env, $version);
        }
    }

    /**
     * Create the default bridge callable that calls DDTrace\ffe_evaluate().
     *
     * Returns null when the extension function is not available, which
     * triggers PROVIDER_NOT_READY error handling in BridgeResultMapper.
     *
     * @return Closure(string, int, ?string, array<string, bool|string|int|float>): (?array<string, mixed>)
     */
    private static function defaultBridgeCallable(): Closure
    {
        return static function (string $flagKey, int $expectedType, ?string $targetingKey, array $attributes): ?array {
            if (!function_exists('DDTrace\ffe_evaluate')) {
                return null;
            }

            /** @var array<string, mixed>|null $result */
            $result = \DDTrace\ffe_evaluate($flagKey, $expectedType, $targetingKey, $attributes);

            return $result;
        };
    }
}
