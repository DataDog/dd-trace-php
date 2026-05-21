<?php

namespace DDTrace\FeatureFlags\Testing;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\Evaluator;
use DDTrace\FeatureFlags\ResultMapper;

/**
 * @internal Test and local-development evaluator for code that should not wait
 * for the native ddtrace FFE bridge to exist.
 */
final class FakeEvaluator implements Evaluator
{
    private $results;
    private $calls = array();
    private $mapper;

    /**
     * @param array<string, array<string, mixed>|EvaluationDetails|null> $results
     */
    public function __construct(array $results = array(), $mapper = null)
    {
        if ($mapper !== null && !$mapper instanceof ResultMapper) {
            throw new \InvalidArgumentException('Expected a ResultMapper instance');
        }

        $this->results = $results;
        $this->mapper = $mapper ?: new ResultMapper();
    }

    public function setResult($flagKey, EvaluationDetails $details)
    {
        $this->results[$flagKey] = $details;

        return $this;
    }

    public function setRawResult($flagKey, $rawResult)
    {
        $this->results[$flagKey] = $rawResult;

        return $this;
    }

    public function setSuccess(
        $flagKey,
        $value,
        $reason = EvaluationReason::TARGETING_MATCH,
        $variant = null,
        array $flagMetadata = array(),
        array $exposureData = array(),
        array $providerState = array()
    ) {
        return $this->setRawResult($flagKey, array(
            'value' => $value,
            'reason' => $reason,
            'error_code' => ResultMapper::BRIDGE_ERROR_NONE,
            'variant' => $variant,
            'flag_metadata' => $flagMetadata,
            'exposure_data' => $exposureData,
            'provider_state' => $this->fakeProviderState($providerState),
        ));
    }

    public function setDefault($flagKey, $value, $variant = null)
    {
        return $this->setSuccess($flagKey, $value, EvaluationReason::DEFAULT_REASON, $variant);
    }

    public function setDisabled($flagKey, $value, $variant = null)
    {
        return $this->setSuccess($flagKey, $value, EvaluationReason::DISABLED, $variant);
    }

    public function setFlagNotFound($flagKey, $message = 'Flag was not found')
    {
        return $this->setBridgeError($flagKey, ResultMapper::BRIDGE_ERROR_FLAG_UNRECOGNIZED, $message);
    }

    public function setTypeMismatch($flagKey, $message = 'Flag value had the wrong type')
    {
        return $this->setBridgeError($flagKey, ResultMapper::BRIDGE_ERROR_TYPE_MISMATCH, $message);
    }

    public function setParseError($flagKey, $message = 'Flag configuration could not be parsed')
    {
        return $this->setBridgeError($flagKey, ResultMapper::BRIDGE_ERROR_CONFIG_PARSE, $message);
    }

    public function setProviderNotReady($flagKey, $message = 'FFE evaluator is not ready')
    {
        return $this->setBridgeError($flagKey, ResultMapper::BRIDGE_ERROR_CONFIG_MISSING, $message);
    }

    public function setGeneralError($flagKey, $message = 'FFE evaluator failed')
    {
        return $this->setBridgeError($flagKey, ResultMapper::BRIDGE_ERROR_GENERAL, $message);
    }

    public function evaluate($flagKey, $expectedType, $defaultValue, $targetingKey = null, array $attributes = array())
    {
        $this->calls[] = array(
            'flagKey' => $flagKey,
            'expectedType' => $expectedType,
            'defaultValue' => $defaultValue,
            'targetingKey' => $targetingKey,
            'attributes' => $attributes,
        );

        $rawResult = array_key_exists($flagKey, $this->results) ? $this->results[$flagKey] : null;

        return $this->mapper->map($rawResult, $expectedType, $defaultValue);
    }

    public function getCalls()
    {
        return $this->calls;
    }

    private function setBridgeError($flagKey, $bridgeErrorCode, $message)
    {
        return $this->setRawResult($flagKey, array(
            'value_json' => 'null',
            'reason' => ResultMapper::BRIDGE_REASON_ERROR,
            'error_code' => $bridgeErrorCode,
            'error_message' => $message,
            'provider_state' => $this->fakeProviderState(),
        ));
    }

    private function fakeProviderState(array $providerState = array())
    {
        return array_merge(array(
            'evaluator' => 'fake',
            'productionRuntime' => false,
        ), $providerState);
    }
}
