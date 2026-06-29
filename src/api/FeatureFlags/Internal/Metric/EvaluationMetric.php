<?php

namespace DDTrace\FeatureFlags\Internal\Metric;

final class EvaluationMetric
{
    private $flagKey;
    private $variant;
    private $reason;
    private $errorCode;
    private $allocationKey;

    private function __construct($flagKey, $variant, $reason, $errorCode, $allocationKey)
    {
        if (!is_string($flagKey) || $flagKey === '') {
            throw new \InvalidArgumentException('Expected a non-empty feature flag key');
        }

        $this->flagKey = $flagKey;
        $this->variant = self::nullableString($variant);
        $this->reason = self::nullableString($reason);
        $this->errorCode = self::nullableString($errorCode);
        $this->allocationKey = self::nullableString($allocationKey);
    }

    public static function create($flagKey, $variant = null, $reason = null, $errorCode = null, $allocationKey = null)
    {
        return new self($flagKey, $variant, $reason, $errorCode, $allocationKey);
    }

    public function getFlagKey()
    {
        return $this->flagKey;
    }

    public function getVariant()
    {
        return $this->variant;
    }

    public function getReason()
    {
        return $this->reason;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getAllocationKey()
    {
        return $this->allocationKey;
    }

    private static function nullableString($value)
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
