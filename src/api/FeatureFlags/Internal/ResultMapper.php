<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;

final class ResultMapper
{
    const BRIDGE_REASON_STATIC = 0;
    const BRIDGE_REASON_DEFAULT = 1;
    const BRIDGE_REASON_TARGETING_MATCH = 2;
    const BRIDGE_REASON_SPLIT = 3;
    const BRIDGE_REASON_DISABLED = 4;
    const BRIDGE_REASON_ERROR = 5;

    const BRIDGE_ERROR_NONE = 0;
    const BRIDGE_ERROR_TYPE_MISMATCH = 1;
    const BRIDGE_ERROR_CONFIG_PARSE = 2;
    const BRIDGE_ERROR_FLAG_UNRECOGNIZED = 3;
    const BRIDGE_ERROR_CONFIG_MISSING = 6;
    const BRIDGE_ERROR_GENERAL = 7;

    /**
     * @param array<string, mixed>|EvaluationDetails|null $rawResult
     * @param string $expectedType One of EvaluationType::*.
     * @param mixed $defaultValue
     * @return EvaluationDetails
     */
    public function map($rawResult, $expectedType, $defaultValue)
    {
        if (!EvaluationType::isValid($expectedType)) {
            throw new \InvalidArgumentException('Unknown feature flag value type: ' . (string) $expectedType);
        }

        if ($rawResult instanceof EvaluationDetails) {
            return $rawResult;
        }

        if ($rawResult === null) {
            return $this->errorDetails(
                $defaultValue,
                $expectedType,
                EvaluationErrorCode::PROVIDER_NOT_READY,
                'FFE evaluator is not ready',
                array('ready' => false)
            );
        }

        if (!is_array($rawResult)) {
            return $this->errorDetails(
                $defaultValue,
                $expectedType,
                EvaluationErrorCode::GENERAL,
                'FFE evaluator returned an invalid result'
            );
        }

        $errorCode = $this->mapErrorCode(
            $this->read($rawResult, array('error_code', 'errorCode'), self::BRIDGE_ERROR_GENERAL)
        );
        if ($errorCode !== null) {
            return $this->errorDetails(
                $defaultValue,
                $expectedType,
                $errorCode,
                $this->read($rawResult, array('error_message', 'errorMessage'), null),
                $this->readArray($rawResult, array('provider_state', 'providerState'))
            );
        }

        $reason = $this->mapReason($this->read($rawResult, array('reason'), self::BRIDGE_REASON_DEFAULT));
        if ($this->isDefaultReturn($rawResult, $reason)) {
            return $this->defaultDetails($defaultValue, $expectedType, $reason, $rawResult);
        }

        $decoded = null;
        $decodeError = $this->decodeValue($rawResult, $expectedType, $decoded);
        if ($decodeError !== null) {
            return $this->errorDetails(
                $defaultValue,
                $expectedType,
                $decodeError,
                $decodeError === EvaluationErrorCode::PARSE_ERROR
                    ? 'FFE evaluator returned invalid JSON'
                    : 'FFE evaluator returned a value with the wrong type',
                $this->readArray($rawResult, array('provider_state', 'providerState'))
            );
        }

        return new EvaluationDetails(
            $decoded,
            $expectedType,
            $reason,
            $this->read($rawResult, array('variant'), null),
            null,
            null,
            $this->readArray($rawResult, array('flag_metadata', 'flagMetadata', 'metadata')),
            $this->exposureData($rawResult),
            $this->providerState($rawResult)
        );
    }

    private function defaultDetails($defaultValue, $expectedType, $reason, array $rawResult)
    {
        return new EvaluationDetails(
            $defaultValue,
            $expectedType,
            $reason,
            null,
            null,
            null,
            $this->readArray($rawResult, array('flag_metadata', 'flagMetadata', 'metadata')),
            array(),
            $this->providerState($rawResult)
        );
    }

    private function errorDetails(
        $defaultValue,
        $expectedType,
        $errorCode,
        $errorMessage = null,
        array $providerState = array()
    ) {
        return new EvaluationDetails(
            $defaultValue,
            $expectedType,
            EvaluationReason::ERROR,
            null,
            $errorCode,
            $errorMessage,
            array(),
            array(),
            $providerState
        );
    }

    private function decodeValue(array $rawResult, $expectedType, &$decoded)
    {
        if (array_key_exists('value', $rawResult)) {
            $value = $rawResult['value'];
        } else {
            $valueJson = $this->read($rawResult, array('value_json', 'valueJson'), null);
            if (!is_string($valueJson) || $valueJson === '') {
                return EvaluationErrorCode::PARSE_ERROR;
            }

            $value = json_decode($valueJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return EvaluationErrorCode::PARSE_ERROR;
            }
        }

        if (!$this->coerceValue($value, $expectedType, $decoded)) {
            return EvaluationErrorCode::TYPE_MISMATCH;
        }

        return null;
    }

    private function isDefaultReturn(array $rawResult, $reason)
    {
        if ($reason !== EvaluationReason::DEFAULT_REASON && $reason !== EvaluationReason::DISABLED) {
            return false;
        }

        if (array_key_exists('value', $rawResult)) {
            return $rawResult['value'] === null;
        }

        $valueJson = $this->read($rawResult, array('value_json', 'valueJson'), null);

        return is_string($valueJson) && trim($valueJson) === 'null';
    }

    private function coerceValue($value, $expectedType, &$coerced)
    {
        switch ($expectedType) {
            case EvaluationType::BOOLEAN:
                if (is_bool($value)) {
                    $coerced = $value;
                    return true;
                }
                return false;

            case EvaluationType::STRING:
                if (is_string($value)) {
                    $coerced = $value;
                    return true;
                }
                return false;

            case EvaluationType::INTEGER:
                if (is_int($value)) {
                    $coerced = $value;
                    return true;
                }
                return false;

            case EvaluationType::FLOAT:
                if (is_int($value) || is_float($value)) {
                    $coerced = (float) $value;
                    return true;
                }
                return false;

            case EvaluationType::OBJECT:
                if (is_array($value)) {
                    $coerced = $value;
                    return true;
                }
                return false;
        }

        return false;
    }

    private function mapErrorCode($errorCode)
    {
        if ($errorCode === null || $errorCode === self::BRIDGE_ERROR_NONE || $errorCode === '0') {
            return null;
        }

        if (is_string($errorCode) && EvaluationErrorCode::isValid($errorCode)) {
            return $errorCode;
        }

        switch ((int) $errorCode) {
            case self::BRIDGE_ERROR_TYPE_MISMATCH:
                return EvaluationErrorCode::TYPE_MISMATCH;
            case self::BRIDGE_ERROR_CONFIG_PARSE:
                return EvaluationErrorCode::PARSE_ERROR;
            case self::BRIDGE_ERROR_FLAG_UNRECOGNIZED:
                return EvaluationErrorCode::FLAG_NOT_FOUND;
            case self::BRIDGE_ERROR_CONFIG_MISSING:
                return EvaluationErrorCode::PROVIDER_NOT_READY;
            case self::BRIDGE_ERROR_GENERAL:
            default:
                return EvaluationErrorCode::GENERAL;
        }
    }

    private function mapReason($reason)
    {
        if (is_string($reason) && EvaluationReason::isValid($reason)) {
            return $reason;
        }

        switch ((int) $reason) {
            case self::BRIDGE_REASON_STATIC:
                return EvaluationReason::STATIC_REASON;
            case self::BRIDGE_REASON_TARGETING_MATCH:
                return EvaluationReason::TARGETING_MATCH;
            case self::BRIDGE_REASON_SPLIT:
                return EvaluationReason::SPLIT;
            case self::BRIDGE_REASON_DISABLED:
                return EvaluationReason::DISABLED;
            case self::BRIDGE_REASON_ERROR:
                return EvaluationReason::ERROR;
            case self::BRIDGE_REASON_DEFAULT:
            default:
                return EvaluationReason::DEFAULT_REASON;
        }
    }

    private function exposureData(array $rawResult)
    {
        $exposureData = $this->readArray($rawResult, array('exposure_data', 'exposureData'));

        if (array_key_exists('allocation_key', $rawResult)) {
            $exposureData['allocationKey'] = $rawResult['allocation_key'];
        }

        if (array_key_exists('do_log', $rawResult)) {
            $exposureData['doLog'] = (bool) $rawResult['do_log'];
        }

        return $exposureData;
    }

    private function providerState(array $rawResult)
    {
        $providerState = $this->readArray($rawResult, array('provider_state', 'providerState'));

        if (array_key_exists('has_config', $rawResult)) {
            $providerState['hasConfig'] = (bool) $rawResult['has_config'];
        }

        if (array_key_exists('config_version', $rawResult)) {
            $providerState['configVersion'] = $rawResult['config_version'];
        }

        return $providerState;
    }

    private function readArray(array $rawResult, array $keys)
    {
        $value = $this->read($rawResult, $keys, array());

        return is_array($value) ? $value : array();
    }

    private function read(array $rawResult, array $keys, $default)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $rawResult)) {
                return $rawResult[$key];
            }
        }

        return $default;
    }
}
