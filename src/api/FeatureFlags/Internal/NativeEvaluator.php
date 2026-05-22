<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\EvaluationType;

final class NativeEvaluator implements Evaluator
{
    const WARNING_MESSAGE = 'Datadog-backed PHP feature flag evaluation is not fully production-ready yet.';

    private $mapper;
    private $unavailableEvaluator;
    private $remoteConfig;

    public function __construct($mapper = null, $unavailableEvaluator = null, $remoteConfig = null)
    {
        if ($mapper !== null && !$mapper instanceof ResultMapper) {
            throw new \InvalidArgumentException('Expected a ResultMapper instance');
        }

        if ($unavailableEvaluator !== null && !$unavailableEvaluator instanceof Evaluator) {
            throw new \InvalidArgumentException('Expected an Evaluator instance');
        }

        if ($remoteConfig !== null && !$remoteConfig instanceof RemoteConfigClient) {
            throw new \InvalidArgumentException('Expected a RemoteConfigClient instance');
        }

        $this->mapper = $mapper ?: new ResultMapper();
        $this->unavailableEvaluator = $unavailableEvaluator ?: new UnavailableEvaluator();
        $this->remoteConfig = $remoteConfig ?: new RemoteConfigClient();
    }

    public static function isAvailable()
    {
        return function_exists('DDTrace\\ffe_evaluate')
            && RemoteConfigClient::isAvailable();
    }

    public static function createOrUnavailable()
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
        if (!self::isAvailable()) {
            return $this->unavailableEvaluator->evaluate(
                $flagKey,
                $expectedType,
                $defaultValue,
                $targetingKey,
                $attributes
            );
        }

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
                return 0;
            case EvaluationType::INTEGER:
                return 1;
            case EvaluationType::FLOAT:
                return 2;
            case EvaluationType::BOOLEAN:
                return 3;
            case EvaluationType::OBJECT:
                return 4;
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
        $hasConfig = $this->remoteConfig->hasConfig();
        $configVersion = $this->remoteConfig->configVersion();

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
