<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\EvaluationType;

final class NativeEvaluator implements Evaluator
{
    const WARNING_MESSAGE = 'Datadog-backed PHP feature flag evaluation has no Remote Configuration data loaded for this request. Returning default values.';

    private $mapper;

    private function __construct($mapper = null)
    {
        if ($mapper !== null && !$mapper instanceof ResultMapper) {
            throw new \InvalidArgumentException('Expected a ResultMapper instance');
        }

        $this->mapper = $mapper ?: new ResultMapper();
    }

    public static function isAvailable()
    {
        return function_exists('DDTrace\\ffe_evaluate');
    }

    public static function create()
    {
        return self::isAvailable() ? new self() : new UnavailableEvaluator();
    }

    public function evaluate(
        $flagKey,
        $expectedType,
        $defaultValue,
        $targetingKey = null,
        array $attributes = array()
    ) {
        $rawResult = \DDTrace\ffe_evaluate(
            $flagKey,
            $this->typeId($expectedType),
            $targetingKey,
            $this->normalizeAttributes($attributes)
        );

        if (is_array($rawResult)) {
            $rawResult = $this->withProviderState($rawResult);
        }

        return $this->mapper->map($rawResult, $expectedType, $defaultValue);
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

    private function withProviderState(array $rawResult)
    {
        $hasConfig = \DDTrace\ffe_has_config();
        $configVersion = \DDTrace\ffe_config_version();

        $providerState = array(
            'ready' => $hasConfig,
            'hasConfig' => $hasConfig,
            'configVersion' => $configVersion,
            'productionRuntime' => false,
            'mode' => 'native_remote_config',
            'reason' => $hasConfig ? 'metrics_delivery_pending' : 'configuration_missing',
        );

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
}
