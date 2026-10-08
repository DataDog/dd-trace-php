<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\SpanEnrichmentRegistry;

final class NativeEvaluator implements Evaluator
{
    const WARNING_MESSAGE = 'Datadog-backed PHP feature flag evaluation has no Remote Configuration data loaded for this request. Returning default values.';
    const AGENTLESS_WARNING_MESSAGE = 'Datadog-backed PHP feature flag evaluation has no agentless configuration loaded. Returning default values.';

    private $mapper;
    private $recordMetrics;

    private function __construct($mapper = null, bool $recordMetrics = true)
    {
        if ($mapper !== null && !$mapper instanceof ResultMapper) {
            throw new \InvalidArgumentException('Expected a ResultMapper instance');
        }

        $this->mapper = $mapper ?: new ResultMapper();
        $this->recordMetrics = $recordMetrics;
    }

    public static function isAvailable()
    {
        return function_exists('DDTrace\\ffe_evaluate');
    }

    public static function create($recordMetrics = true)
    {
        return self::isAvailable() ? new self(null, $recordMetrics) : new UnavailableEvaluator();
    }

    public function evaluate(
        $flagKey,
        $expectedType,
        $defaultValue,
        $targetingKey = null,
        array $attributes = array()
    ) {
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

        // Both public APIs use this final mapped outcome. EVP counts are
        // independent of OpenFeature's metric hook and the OTLP kill switch.
        $this->recordFlagEvaluation($flagKey, $targetingKey, $normalizedAttributes, $rawResult, $details);

        // APM feature-flag span enrichment. This is the single choke point both
        // the native Client and the OpenFeature DataDogProvider evaluate through,
        // and it is only reachable with the extension loaded (UnavailableEvaluator
        // is returned otherwise), so enrichment is recorded here once rather than
        // duplicated in each caller. No-op when the gate is off or there is no
        // active root span; never throws into evaluation.
        SpanEnrichmentRegistry::record($flagKey, $details, $targetingKey);

        return $details;
    }

    private function recordFlagEvaluation($flagKey, $targetingKey, array $attributes, $rawResult, $details)
    {
        if (!function_exists('DDTrace\\Internal\\record_ffe_flag_evaluation')) {
            return;
        }
        try {
            $exposure = $details->getExposureData();
            $consent = is_object($rawResult)
                && isset($rawResult->observeFullEvaluationData)
                && $rawResult->observeFullEvaluationData === true;
            \DDTrace\Internal\record_ffe_flag_evaluation(
                $flagKey,
                $details->getVariant(),
                isset($exposure['allocationKey']) ? $exposure['allocationKey'] : null,
                $targetingKey,
                $attributes,
                $details->getErrorCode(),
                $details->getVariant() === null && !isset($exposure['serialId']),
                $consent
            );
        } catch (\Throwable $ignored) {
            // Best-effort telemetry must not change a flag's evaluation result.
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
        if (function_exists('DDTrace\\Internal\\ffe_provider_state')) {
            $providerState = array_merge($providerState, \DDTrace\Internal\ffe_provider_state());
        }
        $warningMessage = $providerState['mode'] === 'native_agentless'
            ? self::AGENTLESS_WARNING_MESSAGE : self::WARNING_MESSAGE;

        if (is_array($rawResult)) {
            if (isset($rawResult['provider_state']) && is_array($rawResult['provider_state'])) {
                $providerState = array_merge($providerState, $rawResult['provider_state']);
            }

            if (!$hasConfig) {
                $rawResult['error_message'] = $warningMessage;
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
            $rawResult->errorMessage = $warningMessage;
        }

        $rawResult->providerState = $providerState;
        $rawResult->hasConfig = $hasConfig;
        $rawResult->configVersion = $configVersion;

        return $rawResult;
    }
}
