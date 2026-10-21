<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\Log\LoggerInterface;
use DDTrace\Log\LogLevel;

/**
 * Debug-logs an evaluation's raw inputs and the SDK's outcome, so customers
 * can explain why a flag returned its value.
 *
 * @internal
 */
final class EvaluationLog
{
    /**
     * @param LoggerInterface $logger Must not throw.
     * @param string $event What the outcome is, e.g. "evaluated" for the value
     *     returned to the application.
     * @param string $flagKey
     * @param mixed $defaultValue
     * @param string|null $targetingKey
     * @param array<string, mixed> $attributes
     * @param EvaluationDetails $details
     */
    public static function debug(
        LoggerInterface $logger,
        $event,
        $flagKey,
        $defaultValue,
        $targetingKey,
        array $attributes,
        EvaluationDetails $details
    ) {
        if (!$logger->isLevelActive(LogLevel::DEBUG)) {
            return;
        }

        // Formatting must never change the evaluation; a default value can
        // hold a JsonSerializable object that throws.
        try {
            $providerState = $details->getProviderState();
            $json = json_encode(array(
                'flagKey' => $flagKey,
                'valueType' => $details->getValueType(),
                'targetingKey' => $targetingKey,
                'attributes' => (object) $attributes,
                'defaultValue' => $defaultValue,
                'value' => $details->getValue(),
                'variant' => $details->getVariant(),
                'reason' => $details->getReason(),
                'errorCode' => $details->getErrorCode(),
                'errorMessage' => $details->getErrorMessage(),
                'flagMetadata' => (object) $details->getFlagMetadata(),
                'configVersion' => isset($providerState['configVersion']) ? $providerState['configVersion'] : null,
            ), self::jsonOptions());
            // Partial output can succeed while replacing raw values with null or 0.
            $formatError = json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : null;
        } catch (\Throwable $e) {
            $json = false;
            $formatError = get_class($e) . ': ' . $e->getMessage();
        }

        if ($json === false || $formatError !== null) {
            $json = json_encode(array(
                'flagKey' => $flagKey,
                'reason' => $details->getReason(),
                'errorCode' => $details->getErrorCode(),
                'detailsUnavailable' => $formatError,
            ), self::jsonOptions());
        }

        $logger->debug('Feature Flags: ' . $event . ' {details}', array('details' => $json));
    }

    private static function jsonOptions()
    {
        $options = JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $options |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        return $options;
    }
}
