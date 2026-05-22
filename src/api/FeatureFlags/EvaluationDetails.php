<?php

namespace DDTrace\FeatureFlags;

final class EvaluationDetails
{
    private $value;
    private $valueType;
    private $reason;
    private $variant;
    private $errorCode;
    private $errorMessage;
    private $flagMetadata;
    private $exposureData;
    private $providerState;

    /**
     * @param mixed $value
     * @param string $valueType One of EvaluationType::*.
     * @param string $reason One of EvaluationReason::*.
     * @param string|null $variant
     * @param string|null $errorCode One of EvaluationErrorCode::* or null on success.
     * @param string|null $errorMessage
     * @param array<string, mixed> $flagMetadata
     * @param array<string, mixed> $exposureData
     * @param array<string, mixed> $providerState
     */
    public function __construct(
        $value,
        $valueType,
        $reason,
        $variant = null,
        $errorCode = null,
        $errorMessage = null,
        array $flagMetadata = array(),
        array $exposureData = array(),
        array $providerState = array()
    ) {
        if (!EvaluationType::isValid($valueType)) {
            throw new \InvalidArgumentException('Unknown feature flag value type: ' . (string) $valueType);
        }

        if (!EvaluationReason::isValid($reason)) {
            throw new \InvalidArgumentException('Unknown feature flag evaluation reason: ' . (string) $reason);
        }

        if (!EvaluationErrorCode::isValid($errorCode)) {
            throw new \InvalidArgumentException('Unknown feature flag evaluation error code: ' . (string) $errorCode);
        }

        $this->value = $value;
        $this->valueType = $valueType;
        $this->reason = $reason;
        $this->variant = $variant;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->flagMetadata = $flagMetadata;
        $this->exposureData = $exposureData;
        $this->providerState = $providerState;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getValueType()
    {
        return $this->valueType;
    }

    public function getReason()
    {
        return $this->reason;
    }

    public function getVariant()
    {
        return $this->variant;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    public function getFlagMetadata()
    {
        return $this->flagMetadata;
    }

    public function getExposureData()
    {
        return $this->exposureData;
    }

    public function getProviderState()
    {
        return $this->providerState;
    }

    public function isError()
    {
        return $this->errorCode !== null;
    }
}
