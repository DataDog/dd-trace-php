<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use OpenFeature\interfaces\provider\Reason;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\implementation\provider\ResolutionError;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\ResolutionDetails as ResolutionDetailsInterface;

/**
 * Maps raw bridge results from DDTrace\ffe_evaluate() to OpenFeature ResolutionDetails.
 *
 * The bridge returns an associative array with keys:
 *   value_json, variant, allocation_key, reason, error_code, do_log
 *
 * This mapper centralizes:
 *   - Error detection and default-value fallback
 *   - Error code mapping to OpenFeature ErrorCode
 *   - Reason forcing to ERROR when error_code != 0
 *   - JSON decoding of value_json for typed coercion
 *
 * @internal Used by DataDogProvider. Not part of the public API.
 */
final class BridgeResultMapper
{
    /**
     * Bridge error code constants matching components-rs/ffe.rs.
     */
    private const ERROR_NONE = 0;
    private const ERROR_FLAG_NOT_FOUND = 1;
    private const ERROR_PARSE_ERROR = 2;
    private const ERROR_TYPE_MISMATCH = 3;
    private const ERROR_GENERAL = 4;
    private const ERROR_PROVIDER_NOT_READY = 5;

    /**
     * Bridge reason constants matching components-rs/ffe.rs.
     */
    private const REASON_DEFAULT = 0;
    private const REASON_TARGETING_MATCH = 1;
    private const REASON_SPLIT = 2;
    private const REASON_DISABLED = 3;
    private const REASON_ERROR = 4;

    /**
     * Map bridge error codes to OpenFeature ErrorCode enum values.
     *
     * @var array<int, string>
     */
    private const ERROR_CODE_MAP = [
        self::ERROR_FLAG_NOT_FOUND => 'FLAG_NOT_FOUND',
        self::ERROR_PARSE_ERROR => 'PARSE_ERROR',
        self::ERROR_TYPE_MISMATCH => 'TYPE_MISMATCH',
        self::ERROR_GENERAL => 'GENERAL',
        self::ERROR_PROVIDER_NOT_READY => 'PROVIDER_NOT_READY',
    ];

    /**
     * Map bridge reason integers to OpenFeature reason strings.
     *
     * @var array<int, string>
     */
    private const REASON_MAP = [
        self::REASON_DEFAULT => Reason::DEFAULT,
        self::REASON_TARGETING_MATCH => Reason::TARGETING_MATCH,
        self::REASON_SPLIT => Reason::SPLIT,
        self::REASON_DISABLED => Reason::DISABLED,
        self::REASON_ERROR => Reason::ERROR,
    ];

    /**
     * Map a bridge result to a boolean ResolutionDetails.
     *
     * @param array<string, mixed>|null $bridgeResult Raw result from DDTrace\ffe_evaluate()
     * @param bool $defaultValue Caller-provided default
     */
    public function mapBoolean(?array $bridgeResult, bool $defaultValue): ResolutionDetailsInterface
    {
        return $this->mapResult($bridgeResult, $defaultValue, 'boolean');
    }

    /**
     * Map a bridge result to a string ResolutionDetails.
     *
     * @param array<string, mixed>|null $bridgeResult Raw result from DDTrace\ffe_evaluate()
     * @param string $defaultValue Caller-provided default
     */
    public function mapString(?array $bridgeResult, string $defaultValue): ResolutionDetailsInterface
    {
        return $this->mapResult($bridgeResult, $defaultValue, 'string');
    }

    /**
     * Map a bridge result to an integer ResolutionDetails.
     *
     * @param array<string, mixed>|null $bridgeResult Raw result from DDTrace\ffe_evaluate()
     * @param int $defaultValue Caller-provided default
     */
    public function mapInteger(?array $bridgeResult, int $defaultValue): ResolutionDetailsInterface
    {
        return $this->mapResult($bridgeResult, $defaultValue, 'integer');
    }

    /**
     * Map a bridge result to a float ResolutionDetails.
     *
     * @param array<string, mixed>|null $bridgeResult Raw result from DDTrace\ffe_evaluate()
     * @param float $defaultValue Caller-provided default
     */
    public function mapFloat(?array $bridgeResult, float $defaultValue): ResolutionDetailsInterface
    {
        return $this->mapResult($bridgeResult, $defaultValue, 'float');
    }

    /**
     * Map a bridge result to an object (array) ResolutionDetails.
     *
     * @param array<string, mixed>|null $bridgeResult Raw result from DDTrace\ffe_evaluate()
     * @param mixed[] $defaultValue Caller-provided default
     */
    public function mapObject(?array $bridgeResult, array $defaultValue): ResolutionDetailsInterface
    {
        return $this->mapResult($bridgeResult, $defaultValue, 'object');
    }

    /**
     * Core mapping logic shared by all typed resolvers.
     *
     * @param array<string, mixed>|null $bridgeResult Raw result from DDTrace\ffe_evaluate()
     * @param bool|string|int|float|mixed[] $defaultValue Caller-provided default
     * @param string $expectedType One of: boolean, string, integer, float, object
     */
    private function mapResult(?array $bridgeResult, bool|string|int|float|array $defaultValue, string $expectedType): ResolutionDetailsInterface
    {
        $builder = new ResolutionDetailsBuilder();

        // Null result means the evaluation engine was completely unavailable
        if ($bridgeResult === null) {
            return $builder
                ->withValue($defaultValue)
                ->withReason(Reason::ERROR)
                ->withError(new ResolutionError(
                    ErrorCode::PROVIDER_NOT_READY(),
                    'FFE evaluation engine unavailable'
                ))
                ->build();
        }

        $errorCode = $bridgeResult['error_code'] ?? self::ERROR_GENERAL;

        // Any non-zero error_code: return default value with ERROR reason
        if ($errorCode !== self::ERROR_NONE) {
            $openFeatureErrorCode = $this->mapErrorCode($errorCode);

            return $builder
                ->withValue($defaultValue)
                ->withReason(Reason::ERROR)
                ->withError(new ResolutionError($openFeatureErrorCode))
                ->build();
        }

        // Success path: decode the JSON value and coerce to the expected type
        $valueJson = $bridgeResult['value_json'] ?? null;
        $decoded = $this->decodeValue($valueJson, $expectedType);

        if ($decoded === null) {
            // JSON decode failure or type mismatch: return default
            return $builder
                ->withValue($defaultValue)
                ->withReason(Reason::ERROR)
                ->withError(new ResolutionError(
                    ErrorCode::PARSE_ERROR(),
                    'Failed to decode value_json or type mismatch'
                ))
                ->build();
        }

        $reason = $this->mapReason($bridgeResult['reason'] ?? self::REASON_DEFAULT);
        $variant = $bridgeResult['variant'] ?? null;

        $builder->withValue($decoded)->withReason($reason);

        if ($variant !== null) {
            $builder->withVariant($variant);
        }

        return $builder->build();
    }

    /**
     * Map a bridge error code integer to an OpenFeature ErrorCode enum.
     */
    private function mapErrorCode(int $errorCode): ErrorCode
    {
        $name = self::ERROR_CODE_MAP[$errorCode] ?? 'GENERAL';

        return ErrorCode::$name();
    }

    /**
     * Map a bridge reason integer to an OpenFeature reason string.
     */
    private function mapReason(int $reason): string
    {
        return self::REASON_MAP[$reason] ?? 'DEFAULT';
    }

    /**
     * Decode a JSON value and validate it matches the expected type.
     *
     * Returns null on decode failure or type mismatch.
     *
     * @return bool|string|int|float|mixed[]|null
     */
    private function decodeValue(?string $valueJson, string $expectedType): bool|string|int|float|array|null
    {
        if ($valueJson === null || $valueJson === '') {
            return null;
        }

        $decoded = json_decode($valueJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $this->coerceToType($decoded, $expectedType);
    }

    /**
     * Coerce a decoded JSON value to the expected PHP type.
     *
     * Returns null if the value cannot be safely represented as the expected type.
     *
     * @param mixed $value Decoded JSON value
     * @param string $expectedType One of: boolean, string, integer, float, object
     * @return bool|string|int|float|mixed[]|null
     */
    private function coerceToType(mixed $value, string $expectedType): bool|string|int|float|array|null
    {
        return match ($expectedType) {
            'boolean' => is_bool($value) ? $value : null,
            'string' => is_string($value) ? $value : null,
            'integer' => is_int($value) ? $value : null,
            'float' => is_int($value) || is_float($value) ? (float) $value : null,
            'object' => is_array($value) ? $value : null,
            default => null,
        };
    }
}
