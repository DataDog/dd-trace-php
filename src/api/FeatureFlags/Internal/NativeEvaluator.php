<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\SpanEnrichmentRegistry;

final class NativeEvaluator implements Evaluator
{
    const WARNING_MESSAGE = 'Datadog-backed PHP feature flag evaluation has no Remote Configuration data loaded for this request. Returning default values.';

    private $mapper;
    private $recordMetrics;
    private $flagEvaluationRecorder;

    private function __construct($mapper = null, bool $recordMetrics = true, $flagEvaluationRecorder = null)
    {
        if ($mapper !== null && !$mapper instanceof ResultMapper) {
            throw new \InvalidArgumentException('Expected a ResultMapper instance');
        }

        $this->mapper = $mapper ?: new ResultMapper();
        $this->recordMetrics = $recordMetrics;
        $this->flagEvaluationRecorder = $flagEvaluationRecorder;
    }

    public static function isAvailable()
    {
        return function_exists('DDTrace\\ffe_evaluate');
    }

    /** @param callable|null $flagEvaluationRecorder Internal adapter for terminal OpenFeature reporting. */
    public static function create($recordMetrics = true, $flagEvaluationRecorder = null)
    {
        return self::isAvailable() ? new self(null, $recordMetrics, $flagEvaluationRecorder) : new UnavailableEvaluator();
    }

    public function evaluate(
        $flagKey,
        $expectedType,
        $defaultValue,
        $targetingKey = null,
        array $attributes = array(),
        $targetingKeyInvalid = false
    ) {
        $evaluationTimestampMs = (int) (microtime(true) * 1000);
        $normalizedAttributes = $this->normalizeAttributes($attributes);
        $rawResult = \DDTrace\ffe_evaluate(
            $flagKey,
            $this->typeId($expectedType),
            $targetingKey,
            $normalizedAttributes,
            $this->recordMetrics
        );

        if (is_array($rawResult) || is_object($rawResult)) {
            $rawResult = $this->withProviderState($rawResult);
        }

        $details = $this->mapper->map($rawResult, $expectedType, $defaultValue);

        $this->recordDependencyEvaluations(
            $rawResult,
            $targetingKey,
            $normalizedAttributes,
            $evaluationTimestampMs,
            $targetingKeyInvalid
        );

        // Native clients submit now. OpenFeature can retain this snapshot until
        // its hooks finish, independently of native/OTLP metric recording.
        $this->recordFlagEvaluation($flagKey, $targetingKey, $normalizedAttributes, $rawResult, $details, $evaluationTimestampMs, $targetingKeyInvalid);

        // APM feature-flag span enrichment. This is the single choke point both
        // the native Client and the OpenFeature DataDogProvider evaluate through,
        // and it is only reachable with the extension loaded (UnavailableEvaluator
        // is returned otherwise), so enrichment is recorded here once rather than
        // duplicated in each caller. No-op when the gate is off or there is no
        // active root span; never throws into evaluation.
        SpanEnrichmentRegistry::record($flagKey, $details, $targetingKey);

        return $details;
    }

    private function recordFlagEvaluation($flagKey, $targetingKey, array $attributes, $rawResult, $details, $evaluationTimestampMs, $targetingKeyInvalid)
    {
        try {
            $exposure = $details->getExposureData();
            $consent = is_object($rawResult)
                && isset($rawResult->observeFullEvaluationData)
                && $rawResult->observeFullEvaluationData === true;
            $observation = array(
                $flagKey,
                $details->getVariant(),
                isset($exposure['allocationKey']) ? $exposure['allocationKey'] : null,
                $targetingKeyInvalid ? null : $targetingKey,
                $attributes,
                $details->getErrorCode(),
                $details->getVariant() === null && !isset($exposure['serialId']),
                $consent,
                $evaluationTimestampMs,
                $targetingKeyInvalid
            );
            if ($this->flagEvaluationRecorder !== null) {
                // Keep only the native inspection budget plus one overflow
                // marker while OpenFeature finishes its hooks. Scalars are
                // already copied by normalizeAttributes; do not retain the
                // customer's full context or any protected attributes.
                $observation[4] = $consent ? array_slice($attributes, 0, 257, true) : array();
                ($this->flagEvaluationRecorder)($observation);
            } else {
                self::submitFlagEvaluation($observation);
            }
        } catch (\Throwable $ignored) {
            // Best-effort telemetry must not change a flag's evaluation result.
        }
    }

    private function recordDependencyEvaluations(
        $rawResult,
        $targetingKey,
        array $attributes,
        $evaluationTimestampMs,
        $targetingKeyInvalid
    ) {
        $dependencies = is_object($rawResult) && isset($rawResult->dependencyEvaluations)
            && is_array($rawResult->dependencyEvaluations)
            ? $rawResult->dependencyEvaluations
            : array();
        foreach ($dependencies as $dependency) {
            $telemetry = $this->mapper->telemetryData($dependency);
            if (!is_string($telemetry['flagKey']) || $telemetry['flagKey'] === '') {
                continue;
            }
            $observation = array(
                $telemetry['flagKey'],
                $telemetry['variant'],
                $telemetry['allocationKey'],
                $targetingKeyInvalid ? null : $targetingKey,
                $telemetry['observeFullEvaluationData'] ? $attributes : array(),
                $telemetry['errorCode'],
                $telemetry['runtimeDefault'],
                $telemetry['observeFullEvaluationData'],
                $evaluationTimestampMs,
                $targetingKeyInvalid
            );
            self::submitFlagEvaluation($observation);
        }
    }

    /** @internal Shared submission for immediate native and finalized OpenFeature observations. */
    public static function submitFlagEvaluation(array $observation)
    {
        try {
            return \DDTrace\Internal\record_ffe_flag_evaluation(...$observation);
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    private function typeId($expectedType)
    {
        switch ($expectedType) {
            case EvaluationType::STRING:
                return \DDTrace\FFE_STRING;
            case EvaluationType::INTEGER:
                return \DDTrace\FFE_INT;
            case EvaluationType::FLOAT:
                return \DDTrace\FFE_FLOAT;
            case EvaluationType::BOOLEAN:
                return \DDTrace\FFE_BOOL;
            case EvaluationType::OBJECT:
                return \DDTrace\FFE_OBJECT;
        }

        throw new \InvalidArgumentException('Unknown feature flag value type: ' . (string) $expectedType);
    }

    private function normalizeAttributes(array $attributes)
    {
        $normalized = array();
        foreach ($attributes as $key => $value) {
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $normalized[(string) $key] = $value;
            }
        }

        return $normalized;
    }

    private function withProviderState($rawResult)
    {
        $hasConfig = \DDTrace\ffe_has_config();
        $configVersion = \DDTrace\ffe_config_version();

        $providerState = array(
            'ready' => $hasConfig,
            'hasConfig' => $hasConfig,
            'configVersion' => $configVersion,
            'productionRuntime' => true,
            'mode' => 'native_remote_config',
            'reason' => $hasConfig ? 'ready' : 'configuration_missing',
        );

        if (is_array($rawResult)) {
            if (isset($rawResult['provider_state']) && is_array($rawResult['provider_state'])) {
                $providerState = array_merge($providerState, $rawResult['provider_state']);
            }

            if (!$hasConfig) {
                $rawResult['error_message'] = self::WARNING_MESSAGE;
            }

            $rawResult['provider_state'] = $providerState;
            $rawResult['has_config'] = $hasConfig;
            $rawResult['config_version'] = $configVersion;

            return $rawResult;
        }

        if (isset($rawResult->providerState) && is_array($rawResult->providerState)) {
            $providerState = array_merge($providerState, $rawResult->providerState);
        }

        if (!$hasConfig) {
            $rawResult->errorMessage = self::WARNING_MESSAGE;
        }

        $rawResult->providerState = $providerState;
        $rawResult->hasConfig = $hasConfig;
        $rawResult->configVersion = $configVersion;

        return $rawResult;
    }
}
