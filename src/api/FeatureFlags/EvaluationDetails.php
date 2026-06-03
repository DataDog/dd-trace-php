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

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @return string One of EvaluationType::*.
     */
    public function getValueType()
    {
        return $this->valueType;
    }

    /**
     * @return string One of EvaluationReason::*.
     */
    public function getReason()
    {
        return $this->reason;
    }

    /**
     * @return string|null
     */
    public function getVariant()
    {
        return $this->variant;
    }

    /**
     * @return string|null One of EvaluationErrorCode::* or null on success.
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /**
     * @return string|null
     */
    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFlagMetadata()
    {
        return $this->flagMetadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExposureData()
    {
        return $this->exposureData;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProviderState()
    {
        return $this->providerState;
    }

    /**
     * @return bool
     */
    public function isError()
    {
        return $this->errorCode !== null;
    }
}
