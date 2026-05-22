<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationType;

final class EvaluationCompleted
{
    private $flagKey;
    private $valueType;
    private $defaultValue;
    private $targetingKey;
    private $attributes;
    private $details;

    /**
     * @param string $flagKey
     * @param string $valueType One of EvaluationType::*.
     * @param mixed $defaultValue
     * @param string|null $targetingKey
     * @param array<string, bool|int|float|string> $attributes
     */
    public function __construct(
        $flagKey,
        $valueType,
        $defaultValue,
        $targetingKey,
        array $attributes,
        EvaluationDetails $details
    ) {
        if (!is_string($flagKey) || $flagKey === '') {
            throw new \InvalidArgumentException('Feature flag key must be a non-empty string');
        }

        if (!EvaluationType::isValid($valueType)) {
            throw new \InvalidArgumentException('Unknown feature flag value type: ' . (string) $valueType);
        }

        if ($targetingKey !== null && !is_string($targetingKey)) {
            throw new \InvalidArgumentException('Feature flag targeting key must be a string or null');
        }

        $this->flagKey = $flagKey;
        $this->valueType = $valueType;
        $this->defaultValue = $defaultValue;
        $this->targetingKey = $targetingKey;
        $this->attributes = $attributes;
        $this->details = $details;
    }

    public function getFlagKey()
    {
        return $this->flagKey;
    }

    public function getValueType()
    {
        return $this->valueType;
    }

    public function getDefaultValue()
    {
        return $this->defaultValue;
    }

    public function getTargetingKey()
    {
        return $this->targetingKey;
    }

    public function getAttributes()
    {
        return $this->attributes;
    }

    public function getValue()
    {
        return $this->details->getValue();
    }

    public function getReason()
    {
        return $this->details->getReason();
    }

    public function getVariant()
    {
        return $this->details->getVariant();
    }

    public function getErrorCode()
    {
        return $this->details->getErrorCode();
    }

    public function getErrorMessage()
    {
        return $this->details->getErrorMessage();
    }

    public function getAllocationKey()
    {
        $exposureData = $this->details->getExposureData();
        if (!isset($exposureData['allocationKey']) || !is_string($exposureData['allocationKey'])) {
            return null;
        }

        return $exposureData['allocationKey'] === '' ? null : $exposureData['allocationKey'];
    }

    public function shouldLogExposure()
    {
        $exposureData = $this->details->getExposureData();

        return isset($exposureData['doLog']) && $exposureData['doLog'] === true;
    }
}
