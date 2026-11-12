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
    private $exposureRecorder;

    private function __construct($mapper = null, bool $recordMetrics = true, $flagEvaluationRecorder = null, $exposureRecorder = null)
    {
        if ($mapper !== null && !$mapper instanceof ResultMapper) {
            throw new \InvalidArgumentException('Expected a ResultMapper instance');
        }

        $this->mapper = $mapper ?: new ResultMapper();
        $this->recordMetrics = $recordMetrics;
        $this->flagEvaluationRecorder = $flagEvaluationRecorder;
        $this->exposureRecorder = $exposureRecorder;
    }

    public static function isAvailable()
    {
        return function_exists('DDTrace\\ffe_evaluate');
    }

    /**
     * @param callable|null $flagEvaluationRecorder Internal adapter for terminal OpenFeature reporting.
     * @param callable|null $exposureRecorder Receives one detached exposure batch for finalization.
     */
    public static function create($recordMetrics = true, $flagEvaluationRecorder = null, $exposureRecorder = null)
    {
        return self::isAvailable() ? new self(null, $recordMetrics, $flagEvaluationRecorder, $exposureRecorder) : new UnavailableEvaluator();
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
            $this->recordMetrics,
            $this->exposureRecorder !== null
        );

        if (is_array($rawResult) || is_object($rawResult)) {
            $rawResult = $this->withProviderState($rawResult);
        }

        if (is_object($rawResult) && isset($rawResult->dependencyEvaluations)) {
            foreach ($rawResult->dependencyEvaluations as $dependency) {
                self::submitFlagEvaluation(array(
                    $dependency['flagKey'],
                    $dependency['variant'],
                    $dependency['allocationKey'],
                    $targetingKeyInvalid ? null : $targetingKey,
                    $dependency['observeFullEvaluationData'] ? $normalizedAttributes : array(),
                    $dependency['errorCode'],
                    $dependency['variant'] === null,
                    $dependency['observeFullEvaluationData'],
                    $evaluationTimestampMs,
                    $targetingKeyInvalid
                ));
            }
        }

        $details = $this->mapper->map($rawResult, $expectedType, $defaultValue);

        if ($this->exposureRecorder !== null && is_object($rawResult)
            && !empty($rawResult->exposureCandidates) && !$details->isError()) {
            try {
                ($this->exposureRecorder)(array($rawResult->exposureCandidates, $targetingKey, $normalizedAttributes));
            } catch (\Throwable $ignored) {
                // Exposure reporting must not change the evaluation result.
            }
        }

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

    /** @internal Shared submission for immediate native and finalized OpenFeature observations. */
    public static function submitFlagEvaluation(array $observation)
    {
        try {
            return \DDTrace\Internal\record_ffe_flag_evaluation(...$observation);
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    /** @internal Commit only after the enclosing public evaluation succeeds. */
    public static function submitExposures(array $batch)
    {
        foreach ($batch[0] as $candidate) {
            try {
                \DDTrace\Internal\record_ffe_exposure(
                    $candidate['flagKey'], $batch[1], $batch[2], $candidate['allocationKey'],
                    $candidate['variant'], $candidate['serialId'], $candidate['hasSerialId']
                );
            } catch (\Throwable $ignored) {
                // One failed observation must not affect another or the flag result.
            }
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
