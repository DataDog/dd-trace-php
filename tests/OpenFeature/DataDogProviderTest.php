<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use Closure;
use DateTime;
use DDTrace\OpenFeature\BridgeResultMapper;
use DDTrace\OpenFeature\DataDogProvider;
use DDTrace\OpenFeature\EvaluationContextNormalizer;
use DDTrace\OpenFeature\ExposureWriter;
use DDTrace\OpenFeature\MetricsCounter;
use DDTrace\OpenFeature\ProviderLifecycle;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\interfaces\provider\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DataDogProvider, BridgeResultMapper, and EvaluationContextNormalizer.
 *
 * Tests use an injectable bridge callable to avoid requiring the compiled C extension.
 * The bridge callable simulates DDTrace\ffe_evaluate() return values.
 */
class DataDogProviderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Provider metadata
    // -------------------------------------------------------------------------

    public function testProviderMetadataReportsDatadog(): void
    {
        $provider = $this->createProvider(fn () => null);
        $this->assertSame('Datadog', $provider->getMetadata()->getName());
    }

    // -------------------------------------------------------------------------
    // Boolean resolution
    // -------------------------------------------------------------------------

    public function testResolveBooleanValueReturnsEvaluatedTrue(): void
    {
        $provider = $this->createProviderWithSuccessResult('"true"', json_decode('"true"') === true ? 'true' : null);
        // value_json for bool is 'true' (JSON-encoded boolean)
        $provider = $this->createProvider($this->successBridge('true', 'variant-a', 'alloc-1'));

        $result = $provider->resolveBooleanValue('flag.bool', false);

        $this->assertTrue($result->getValue());
        $this->assertSame('variant-a', $result->getVariant());
        $this->assertNull($result->getError());
    }

    public function testResolveBooleanValueReturnsFalse(): void
    {
        $provider = $this->createProvider($this->successBridge('false', 'variant-b', 'alloc-2'));

        $result = $provider->resolveBooleanValue('flag.bool', true);

        $this->assertFalse($result->getValue());
        $this->assertSame('variant-b', $result->getVariant());
    }

    // -------------------------------------------------------------------------
    // String resolution
    // -------------------------------------------------------------------------

    public function testResolveStringValueReturnsEvaluatedString(): void
    {
        $provider = $this->createProvider($this->successBridge('"blue"', 'color-variant', 'alloc-3'));

        $result = $provider->resolveStringValue('flag.color', 'red');

        $this->assertSame('blue', $result->getValue());
        $this->assertSame('color-variant', $result->getVariant());
        $this->assertNull($result->getError());
    }

    // -------------------------------------------------------------------------
    // Integer resolution
    // -------------------------------------------------------------------------

    public function testResolveIntegerValueReturnsEvaluatedInt(): void
    {
        $provider = $this->createProvider($this->successBridge('42', 'num-variant', 'alloc-4'));

        $result = $provider->resolveIntegerValue('flag.count', 0);

        $this->assertSame(42, $result->getValue());
        $this->assertSame('num-variant', $result->getVariant());
    }

    // -------------------------------------------------------------------------
    // Float resolution
    // -------------------------------------------------------------------------

    public function testResolveFloatValueReturnsEvaluatedFloat(): void
    {
        $provider = $this->createProvider($this->successBridge('3.14', 'float-variant', 'alloc-5'));

        $result = $provider->resolveFloatValue('flag.rate', 0.0);

        $this->assertSame(3.14, $result->getValue());
        $this->assertSame('float-variant', $result->getVariant());
    }

    public function testResolveFloatValueAcceptsIntegerJsonAsFloat(): void
    {
        // JSON integer should be coerced to float
        $provider = $this->createProvider($this->successBridge('10', 'int-as-float', 'alloc-6'));

        $result = $provider->resolveFloatValue('flag.rate', 0.0);

        $this->assertSame(10.0, $result->getValue());
    }

    // -------------------------------------------------------------------------
    // Object resolution
    // -------------------------------------------------------------------------

    public function testResolveObjectValueReturnsEvaluatedArray(): void
    {
        $jsonValue = '{"key":"value","nested":{"a":1}}';
        $provider = $this->createProvider($this->successBridge($jsonValue, 'obj-variant', 'alloc-7'));

        $result = $provider->resolveObjectValue('flag.config', []);

        $this->assertSame(['key' => 'value', 'nested' => ['a' => 1]], $result->getValue());
        $this->assertSame('obj-variant', $result->getVariant());
    }

    // -------------------------------------------------------------------------
    // Default value returned on bridge errors
    // -------------------------------------------------------------------------

    public function testDefaultReturnedWhenBridgeErrorCodeFlagNotFound(): void
    {
        $provider = $this->createProvider($this->errorBridge(1)); // ERROR_FLAG_NOT_FOUND

        $result = $provider->resolveBooleanValue('missing.flag', true);

        $this->assertTrue($result->getValue());
        $this->assertSame(Reason::ERROR, $result->getReason());
        $this->assertNotNull($result->getError());
        $this->assertEquals(
            ErrorCode::FLAG_NOT_FOUND(),
            $result->getError()->getResolutionErrorCode()
        );
    }

    public function testDefaultReturnedWhenBridgeErrorCodeProviderNotReady(): void
    {
        $provider = $this->createProvider($this->errorBridge(5)); // ERROR_PROVIDER_NOT_READY

        $result = $provider->resolveStringValue('flag.str', 'fallback');

        $this->assertSame('fallback', $result->getValue());
        $this->assertSame(Reason::ERROR, $result->getReason());
        $this->assertEquals(
            ErrorCode::PROVIDER_NOT_READY(),
            $result->getError()->getResolutionErrorCode()
        );
    }

    public function testDefaultReturnedWhenBridgeErrorCodeTypeMismatch(): void
    {
        $provider = $this->createProvider($this->errorBridge(3)); // ERROR_TYPE_MISMATCH

        $result = $provider->resolveIntegerValue('flag.int', 99);

        $this->assertSame(99, $result->getValue());
        $this->assertEquals(
            ErrorCode::TYPE_MISMATCH(),
            $result->getError()->getResolutionErrorCode()
        );
    }

    public function testDefaultReturnedWhenBridgeErrorCodeParseError(): void
    {
        $provider = $this->createProvider($this->errorBridge(2)); // ERROR_PARSE_ERROR

        $result = $provider->resolveFloatValue('flag.float', 1.5);

        $this->assertSame(1.5, $result->getValue());
        $this->assertEquals(
            ErrorCode::PARSE_ERROR(),
            $result->getError()->getResolutionErrorCode()
        );
    }

    public function testDefaultReturnedWhenBridgeErrorCodeGeneral(): void
    {
        $provider = $this->createProvider($this->errorBridge(4)); // ERROR_GENERAL

        $result = $provider->resolveObjectValue('flag.obj', ['default' => true]);

        $this->assertSame(['default' => true], $result->getValue());
        $this->assertEquals(
            ErrorCode::GENERAL(),
            $result->getError()->getResolutionErrorCode()
        );
    }

    public function testDefaultReturnedWhenBridgeReturnsNull(): void
    {
        $provider = $this->createProvider(fn () => null);

        $result = $provider->resolveBooleanValue('flag.bool', true);

        $this->assertTrue($result->getValue());
        $this->assertSame(Reason::ERROR, $result->getReason());
        $this->assertEquals(
            ErrorCode::PROVIDER_NOT_READY(),
            $result->getError()->getResolutionErrorCode()
        );
    }

    // -------------------------------------------------------------------------
    // Reason coercion to ERROR when error_code != 0
    // -------------------------------------------------------------------------

    public function testReasonForcedToErrorWhenErrorCodeNonZero(): void
    {
        // Even if bridge returns a reason other than ERROR, it should be forced to ERROR
        $provider = $this->createProvider(fn () => [
            'value_json' => '"something"',
            'variant' => 'v1',
            'allocation_key' => 'a1',
            'reason' => 1, // REASON_TARGETING_MATCH
            'error_code' => 1, // ERROR_FLAG_NOT_FOUND
            'do_log' => false,
        ]);

        $result = $provider->resolveStringValue('flag.str', 'default');

        // Reason MUST be ERROR because error_code is non-zero
        $this->assertSame(Reason::ERROR, $result->getReason());
        // Value MUST be the default, not the bridge value
        $this->assertSame('default', $result->getValue());
    }

    public function testReasonPreservedWhenErrorCodeIsZero(): void
    {
        $provider = $this->createProvider(fn () => [
            'value_json' => '"hello"',
            'variant' => 'v1',
            'allocation_key' => 'a1',
            'reason' => 1, // REASON_TARGETING_MATCH
            'error_code' => 0,
            'do_log' => true,
        ]);

        $result = $provider->resolveStringValue('flag.str', 'default');

        $this->assertSame('TARGETING_MATCH', $result->getReason());
        $this->assertSame('hello', $result->getValue());
    }

    // -------------------------------------------------------------------------
    // Reason mapping (success cases)
    // -------------------------------------------------------------------------

    public function testReasonMappingSplit(): void
    {
        $provider = $this->createProvider(fn () => [
            'value_json' => 'true',
            'variant' => 'v1',
            'allocation_key' => 'a1',
            'reason' => 2, // REASON_SPLIT
            'error_code' => 0,
            'do_log' => true,
        ]);

        $result = $provider->resolveBooleanValue('flag.bool', false);
        $this->assertSame('SPLIT', $result->getReason());
    }

    public function testReasonMappingDisabled(): void
    {
        $provider = $this->createProvider(fn () => [
            'value_json' => '"off"',
            'variant' => 'disabled-variant',
            'allocation_key' => 'a1',
            'reason' => 3, // REASON_DISABLED
            'error_code' => 0,
            'do_log' => false,
        ]);

        $result = $provider->resolveStringValue('flag.str', 'default');
        $this->assertSame('DISABLED', $result->getReason());
    }

    public function testReasonMappingDefault(): void
    {
        $provider = $this->createProvider(fn () => [
            'value_json' => '100',
            'variant' => 'default-variant',
            'allocation_key' => 'a1',
            'reason' => 0, // REASON_DEFAULT
            'error_code' => 0,
            'do_log' => true,
        ]);

        $result = $provider->resolveIntegerValue('flag.int', 0);
        $this->assertSame('DEFAULT', $result->getReason());
    }

    // -------------------------------------------------------------------------
    // JSON decode edge cases
    // -------------------------------------------------------------------------

    public function testDefaultReturnedWhenValueJsonIsNull(): void
    {
        $provider = $this->createProvider(fn () => [
            'value_json' => null,
            'variant' => null,
            'allocation_key' => null,
            'reason' => 0,
            'error_code' => 0,
            'do_log' => false,
        ]);

        $result = $provider->resolveStringValue('flag.str', 'fallback');

        $this->assertSame('fallback', $result->getValue());
        $this->assertSame(Reason::ERROR, $result->getReason());
    }

    public function testDefaultReturnedWhenValueJsonIsInvalid(): void
    {
        $provider = $this->createProvider(fn () => [
            'value_json' => '{invalid json',
            'variant' => 'v1',
            'allocation_key' => 'a1',
            'reason' => 1,
            'error_code' => 0,
            'do_log' => true,
        ]);

        $result = $provider->resolveBooleanValue('flag.bool', true);

        $this->assertTrue($result->getValue());
        $this->assertSame(Reason::ERROR, $result->getReason());
        $this->assertEquals(
            ErrorCode::PARSE_ERROR(),
            $result->getError()->getResolutionErrorCode()
        );
    }

    public function testDefaultReturnedWhenTypeMismatchInValue(): void
    {
        // Bridge returns a string JSON but we ask for boolean
        $provider = $this->createProvider($this->successBridge('"not-a-bool"', 'v1', 'a1'));

        $result = $provider->resolveBooleanValue('flag.bool', false);

        $this->assertFalse($result->getValue());
        $this->assertSame(Reason::ERROR, $result->getReason());
    }

    // -------------------------------------------------------------------------
    // EvaluationContextNormalizer: targeting key behavior
    // -------------------------------------------------------------------------

    public function testTargetingKeyIsNullWhenContextIsNull(): void
    {
        $capturedArgs = [];
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedArgs) {
            $capturedArgs = compact('flagKey', 'type', 'targetingKey', 'attrs');
            return $this->makeSuccessResult('true');
        });

        $provider->resolveBooleanValue('flag.bool', false, null);

        $this->assertNull($capturedArgs['targetingKey']);
    }

    public function testTargetingKeyIsNullWhenContextHasNoTargetingKey(): void
    {
        $capturedArgs = [];
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedArgs) {
            $capturedArgs = compact('flagKey', 'type', 'targetingKey', 'attrs');
            return $this->makeSuccessResult('true');
        });

        $context = new EvaluationContext(null, new Attributes(['key1' => 'val1']));
        $provider->resolveBooleanValue('flag.bool', false, $context);

        $this->assertNull($capturedArgs['targetingKey']);
    }

    public function testTargetingKeyPassedThroughWhenPresent(): void
    {
        $capturedArgs = [];
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedArgs) {
            $capturedArgs = compact('flagKey', 'type', 'targetingKey', 'attrs');
            return $this->makeSuccessResult('true');
        });

        $context = new EvaluationContext('user-123');
        $provider->resolveBooleanValue('flag.bool', false, $context);

        $this->assertSame('user-123', $capturedArgs['targetingKey']);
    }

    // -------------------------------------------------------------------------
    // EvaluationContextNormalizer: attribute filtering
    // -------------------------------------------------------------------------

    public function testPrimitiveAttributesAreForwarded(): void
    {
        $capturedArgs = [];
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedArgs) {
            $capturedArgs = compact('flagKey', 'type', 'targetingKey', 'attrs');
            return $this->makeSuccessResult('"ok"');
        });

        $context = new EvaluationContext('user-1', new Attributes([
            'str_attr' => 'hello',
            'int_attr' => 42,
            'float_attr' => 3.14,
            'bool_attr' => true,
        ]));

        $provider->resolveStringValue('flag.str', 'default', $context);

        $this->assertSame([
            'str_attr' => 'hello',
            'int_attr' => 42,
            'float_attr' => 3.14,
            'bool_attr' => true,
        ], $capturedArgs['attrs']);
    }

    public function testNestedArraysAreDropped(): void
    {
        $capturedArgs = [];
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedArgs) {
            $capturedArgs = compact('flagKey', 'type', 'targetingKey', 'attrs');
            return $this->makeSuccessResult('"ok"');
        });

        $context = new EvaluationContext(null, new Attributes([
            'valid' => 'yes',
            'nested' => ['a' => 1, 'b' => 2],
            'also_valid' => 10,
        ]));

        $provider->resolveStringValue('flag.str', 'default', $context);

        // Only flat primitives should remain
        $this->assertSame([
            'valid' => 'yes',
            'also_valid' => 10,
        ], $capturedArgs['attrs']);
        $this->assertArrayNotHasKey('nested', $capturedArgs['attrs']);
    }

    public function testNullAttributeValuesAreDropped(): void
    {
        $capturedArgs = [];
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedArgs) {
            $capturedArgs = compact('flagKey', 'type', 'targetingKey', 'attrs');
            return $this->makeSuccessResult('"ok"');
        });

        $context = new EvaluationContext(null, new Attributes([
            'valid' => 'yes',
            'null_val' => null,
        ]));

        $provider->resolveStringValue('flag.str', 'default', $context);

        $this->assertSame(['valid' => 'yes'], $capturedArgs['attrs']);
    }

    public function testDateTimeAttributeValuesAreDropped(): void
    {
        $capturedArgs = [];
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedArgs) {
            $capturedArgs = compact('flagKey', 'type', 'targetingKey', 'attrs');
            return $this->makeSuccessResult('"ok"');
        });

        $context = new EvaluationContext(null, new Attributes([
            'valid' => 'yes',
            'timestamp' => new DateTime('2024-01-01'),
        ]));

        $provider->resolveStringValue('flag.str', 'default', $context);

        $this->assertSame(['valid' => 'yes'], $capturedArgs['attrs']);
        $this->assertArrayNotHasKey('timestamp', $capturedArgs['attrs']);
    }

    public function testEmptyContextProducesEmptyAttributes(): void
    {
        $capturedArgs = [];
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedArgs) {
            $capturedArgs = compact('flagKey', 'type', 'targetingKey', 'attrs');
            return $this->makeSuccessResult('"ok"');
        });

        $context = new EvaluationContext();
        $provider->resolveStringValue('flag.str', 'default', $context);

        $this->assertNull($capturedArgs['targetingKey']);
        $this->assertSame([], $capturedArgs['attrs']);
    }

    // -------------------------------------------------------------------------
    // Bridge type constants
    // -------------------------------------------------------------------------

    public function testBooleanResolverPassesCorrectTypeConstant(): void
    {
        $capturedType = null;
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedType) {
            $capturedType = $type;
            return $this->makeSuccessResult('true');
        });

        $provider->resolveBooleanValue('flag', false);
        $this->assertSame(3, $capturedType); // TYPE_BOOL = 3
    }

    public function testStringResolverPassesCorrectTypeConstant(): void
    {
        $capturedType = null;
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedType) {
            $capturedType = $type;
            return $this->makeSuccessResult('"hello"');
        });

        $provider->resolveStringValue('flag', 'default');
        $this->assertSame(0, $capturedType); // TYPE_STRING = 0
    }

    public function testIntegerResolverPassesCorrectTypeConstant(): void
    {
        $capturedType = null;
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedType) {
            $capturedType = $type;
            return $this->makeSuccessResult('42');
        });

        $provider->resolveIntegerValue('flag', 0);
        $this->assertSame(1, $capturedType); // TYPE_INT = 1
    }

    public function testFloatResolverPassesCorrectTypeConstant(): void
    {
        $capturedType = null;
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedType) {
            $capturedType = $type;
            return $this->makeSuccessResult('1.5');
        });

        $provider->resolveFloatValue('flag', 0.0);
        $this->assertSame(2, $capturedType); // TYPE_FLOAT = 2
    }

    public function testObjectResolverPassesCorrectTypeConstant(): void
    {
        $capturedType = null;
        $provider = $this->createProvider(function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedType) {
            $capturedType = $type;
            return $this->makeSuccessResult('{}');
        });

        $provider->resolveObjectValue('flag', []);
        $this->assertSame(4, $capturedType); // TYPE_OBJECT = 4
    }

    // -------------------------------------------------------------------------
    // All resolvers delegate through the same bridge call path
    // -------------------------------------------------------------------------

    public function testAllResolversCallBridgeWithFlagKey(): void
    {
        $capturedKeys = [];
        $bridge = function (string $flagKey, int $type, ?string $targetingKey, array $attrs) use (&$capturedKeys) {
            $capturedKeys[] = $flagKey;
            return match ($type) {
                3 => $this->makeSuccessResult('true'),       // boolean
                0 => $this->makeSuccessResult('"hello"'),    // string
                1 => $this->makeSuccessResult('42'),         // integer
                2 => $this->makeSuccessResult('1.5'),        // float
                4 => $this->makeSuccessResult('{"a":1}'),    // object
            };
        };

        $provider = $this->createProvider($bridge);

        $provider->resolveBooleanValue('flag.bool', false);
        $provider->resolveStringValue('flag.str', '');
        $provider->resolveIntegerValue('flag.int', 0);
        $provider->resolveFloatValue('flag.float', 0.0);
        $provider->resolveObjectValue('flag.obj', []);

        $this->assertSame([
            'flag.bool',
            'flag.str',
            'flag.int',
            'flag.float',
            'flag.obj',
        ], $capturedKeys);
    }

    // -------------------------------------------------------------------------
    // BridgeResultMapper unit tests
    // -------------------------------------------------------------------------

    public function testBridgeResultMapperHandlesUnknownErrorCode(): void
    {
        $mapper = new BridgeResultMapper();

        $result = $mapper->mapBoolean([
            'value_json' => 'true',
            'variant' => 'v1',
            'allocation_key' => 'a1',
            'reason' => 0,
            'error_code' => 999, // Unknown error code
            'do_log' => false,
        ], false);

        $this->assertFalse($result->getValue());
        $this->assertSame(Reason::ERROR, $result->getReason());
        $this->assertEquals(
            ErrorCode::GENERAL(),
            $result->getError()->getResolutionErrorCode()
        );
    }

    public function testBridgeResultMapperHandlesEmptyValueJson(): void
    {
        $mapper = new BridgeResultMapper();

        $result = $mapper->mapString([
            'value_json' => '',
            'variant' => null,
            'allocation_key' => null,
            'reason' => 0,
            'error_code' => 0,
            'do_log' => false,
        ], 'default');

        $this->assertSame('default', $result->getValue());
        $this->assertSame(Reason::ERROR, $result->getReason());
    }

    // -------------------------------------------------------------------------
    // EvaluationContextNormalizer standalone tests
    // -------------------------------------------------------------------------

    public function testNormalizerReturnsNullTargetingKeyForNullContext(): void
    {
        $normalizer = new EvaluationContextNormalizer();
        [$targetingKey, $attrs] = $normalizer->normalize(null);

        $this->assertNull($targetingKey);
        $this->assertSame([], $attrs);
    }

    public function testNormalizerPreservesNullTargetingKey(): void
    {
        $normalizer = new EvaluationContextNormalizer();
        $context = new EvaluationContext(null, new Attributes(['key' => 'val']));
        [$targetingKey, $attrs] = $normalizer->normalize($context);

        $this->assertNull($targetingKey);
        $this->assertSame(['key' => 'val'], $attrs);
    }

    public function testNormalizerExtractsTargetingKey(): void
    {
        $normalizer = new EvaluationContextNormalizer();
        $context = new EvaluationContext('user-456');
        [$targetingKey, $attrs] = $normalizer->normalize($context);

        $this->assertSame('user-456', $targetingKey);
    }

    public function testNormalizerDropsAllNonPrimitiveTypes(): void
    {
        $normalizer = new EvaluationContextNormalizer();
        $context = new EvaluationContext(null, new Attributes([
            'str' => 'hello',
            'int' => 1,
            'float' => 2.5,
            'bool' => true,
            'array' => [1, 2, 3],
            'null' => null,
            'datetime' => new DateTime(),
        ]));

        [$targetingKey, $attrs] = $normalizer->normalize($context);

        $this->assertSame([
            'str' => 'hello',
            'int' => 1,
            'float' => 2.5,
            'bool' => true,
        ], $attrs);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createProvider(Closure $bridge): DataDogProvider
    {
        // Inject a lifecycle that reports ready so evaluation tests
        // exercise the bridge path, not the not-ready short-circuit.
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => true,
            configChangedCallable: fn () => false,
        );

        $noopWriter = new ExposureWriter(
            sidecarCallable: fn () => true,
        );

        return new DataDogProvider(
            resultMapper: new BridgeResultMapper(),
            contextNormalizer: new EvaluationContextNormalizer(),
            bridgeCallable: $bridge,
            lifecycle: $lifecycle,
            exposureWriter: $noopWriter,
        );
    }

    private function createProviderWithSuccessResult(string $valueJson, ?string $variant): DataDogProvider
    {
        return $this->createProvider(fn () => $this->makeSuccessResult($valueJson, $variant));
    }

    /**
     * @return array<string, mixed>
     */
    private function makeSuccessResult(string $valueJson, ?string $variant = 'default-variant', ?string $allocationKey = 'default-alloc'): array
    {
        return [
            'value_json' => $valueJson,
            'variant' => $variant,
            'allocation_key' => $allocationKey,
            'reason' => 1, // REASON_TARGETING_MATCH
            'error_code' => 0, // ERROR_NONE
            'do_log' => true,
        ];
    }

    /**
     * Create a bridge callable returning a successful result.
     */
    private function successBridge(string $valueJson, string $variant, string $allocationKey): Closure
    {
        return fn () => [
            'value_json' => $valueJson,
            'variant' => $variant,
            'allocation_key' => $allocationKey,
            'reason' => 1, // REASON_TARGETING_MATCH
            'error_code' => 0,
            'do_log' => true,
        ];
    }

    /**
     * Create a bridge callable returning an error result.
     */
    private function errorBridge(int $errorCode): Closure
    {
        return fn () => [
            'value_json' => null,
            'variant' => null,
            'allocation_key' => null,
            'reason' => 4, // REASON_ERROR
            'error_code' => $errorCode,
            'do_log' => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Exposure Writer Integration
    // -------------------------------------------------------------------------

    public function testExposureWriterCalledOnSuccessfulEvaluation(): void
    {
        $exposureSent = false;
        $capturedEvent = null;
        $writer = new ExposureWriter(
            sidecarCallable: function (string $eventJson) use (&$exposureSent, &$capturedEvent): bool {
                $exposureSent = true;
                $capturedEvent = json_decode($eventJson, true);
                return true;
            },
        );

        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => true,
            configChangedCallable: fn () => false,
        );

        $provider = new DataDogProvider(
            bridgeCallable: fn () => $this->makeSuccessResult('true'),
            lifecycle: $lifecycle,
            exposureWriter: $writer,
        );

        $provider->resolveBooleanValue('my-flag', false);

        $this->assertTrue($exposureSent, 'Exposure event should be sent on successful evaluation');
        $this->assertSame('my-flag', $capturedEvent['flag']['key']);
    }

    public function testExposureWriterNotCalledWhenDoLogFalse(): void
    {
        $exposureSent = false;
        $writer = new ExposureWriter(
            sidecarCallable: function () use (&$exposureSent): bool {
                $exposureSent = true;
                return true;
            },
        );

        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => true,
            configChangedCallable: fn () => false,
        );

        $result = $this->makeSuccessResult('true');
        $result['do_log'] = false;

        $provider = new DataDogProvider(
            bridgeCallable: fn () => $result,
            lifecycle: $lifecycle,
            exposureWriter: $writer,
        );

        $provider->resolveBooleanValue('my-flag', false);

        $this->assertFalse($exposureSent, 'No exposure should be sent when do_log is false');
    }

    public function testExposureWriterNotCalledOnError(): void
    {
        $exposureSent = false;
        $writer = new ExposureWriter(
            sidecarCallable: function () use (&$exposureSent): bool {
                $exposureSent = true;
                return true;
            },
        );

        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => true,
            configChangedCallable: fn () => false,
        );

        $provider = new DataDogProvider(
            bridgeCallable: fn () => null,
            lifecycle: $lifecycle,
            exposureWriter: $writer,
        );

        $provider->resolveBooleanValue('my-flag', false);

        $this->assertFalse($exposureSent, 'No exposure should be sent on bridge error');
    }

    public function testExposureWriterNotCalledWhenProviderNotReady(): void
    {
        $exposureSent = false;
        $writer = new ExposureWriter(
            sidecarCallable: function () use (&$exposureSent): bool {
                $exposureSent = true;
                return true;
            },
        );

        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => false,
            configChangedCallable: fn () => false,
        );

        $provider = new DataDogProvider(
            bridgeCallable: fn () => $this->makeSuccessResult('true'),
            lifecycle: $lifecycle,
            exposureWriter: $writer,
        );

        $provider->resolveBooleanValue('my-flag', false);

        $this->assertFalse($exposureSent, 'No exposure should be sent when provider not ready');
    }

    // -------------------------------------------------------------------------
    // MetricsCounter Integration
    // -------------------------------------------------------------------------

    public function testMetricsCounterFiresOnSuccessfulEvaluation(): void
    {
        $captured = new \ArrayObject();
        $counter = new MetricsCounter(
            counterCallable: static function (array $attributes) use ($captured): void {
                $captured[] = $attributes;
            },
            envReader: static fn(string $name): ?string => $name === 'DD_METRICS_OTEL_ENABLED' ? 'true' : null,
        );

        $provider = $this->createProviderWithCounter(
            bridge: fn () => $this->makeSuccessResult('true', 'variant-a', 'alloc-1'),
            lifecycleReady: true,
            counter: $counter,
        );

        $provider->resolveBooleanValue('my-flag', false);

        $this->assertCount(1, $captured, 'counter should fire exactly once on successful evaluation');
        $this->assertSame('my-flag', $captured[0]['feature_flag.key']);
        $this->assertSame('variant-a', $captured[0]['feature_flag.result.variant']);
        $this->assertSame('alloc-1', $captured[0]['feature_flag.result.allocation_key']);
        $this->assertSame('TARGETING_MATCH', $captured[0]['feature_flag.result.reason']);
        $this->assertSame('', $captured[0]['error.type']);
    }

    public function testMetricsCounterFiresOnErrorEvaluation(): void
    {
        $captured = new \ArrayObject();
        $counter = new MetricsCounter(
            counterCallable: static function (array $attributes) use ($captured): void {
                $captured[] = $attributes;
            },
            envReader: static fn(string $name): ?string => $name === 'DD_METRICS_OTEL_ENABLED' ? 'true' : null,
        );

        $provider = $this->createProviderWithCounter(
            bridge: $this->errorBridge(1), // ERROR_FLAG_NOT_FOUND
            lifecycleReady: true,
            counter: $counter,
        );

        $provider->resolveBooleanValue('missing-flag', false);

        $this->assertCount(1, $captured, 'counter should fire on error path too');
        $this->assertSame('missing-flag', $captured[0]['feature_flag.key']);
        $this->assertSame('FLAG_NOT_FOUND', $captured[0]['error.type']);
        $this->assertSame('ERROR', $captured[0]['feature_flag.result.reason']);
    }

    public function testMetricsCounterFiresOnNotReadyState(): void
    {
        $captured = new \ArrayObject();
        $counter = new MetricsCounter(
            counterCallable: static function (array $attributes) use ($captured): void {
                $captured[] = $attributes;
            },
            envReader: static fn(string $name): ?string => $name === 'DD_METRICS_OTEL_ENABLED' ? 'true' : null,
        );

        $provider = $this->createProviderWithCounter(
            // Bridge will never be called when lifecycle reports not-ready.
            bridge: fn () => $this->makeSuccessResult('true'),
            lifecycleReady: false,
            counter: $counter,
        );

        $provider->resolveBooleanValue('my-flag', false);

        $this->assertCount(1, $captured, 'counter must fire on not-ready early-return path');
        $this->assertSame('my-flag', $captured[0]['feature_flag.key']);
        $this->assertSame('PROVIDER_NOT_READY', $captured[0]['error.type']);
        $this->assertSame('ERROR', $captured[0]['feature_flag.result.reason']);
    }

    public function testMetricsCounterFiresOnlyOncePerEvaluation(): void
    {
        $captured = new \ArrayObject();
        $counter = new MetricsCounter(
            counterCallable: static function (array $attributes) use ($captured): void {
                $captured[] = $attributes;
            },
            envReader: static fn(string $name): ?string => $name === 'DD_METRICS_OTEL_ENABLED' ? 'true' : null,
        );

        $provider = $this->createProviderWithCounter(
            bridge: fn () => $this->makeSuccessResult('true'),
            lifecycleReady: true,
            counter: $counter,
        );

        $provider->resolveBooleanValue('my-flag', false);

        $this->assertCount(1, $captured, 'single resolveBooleanValue() must record exactly one counter event');
    }

    /**
     * Build a provider with a caller-supplied MetricsCounter.
     *
     * Mirrors createProvider() but exposes the metricsCounter slot so the
     * counter-integration tests can assert against a capturing callable.
     */
    private function createProviderWithCounter(
        Closure $bridge,
        bool $lifecycleReady,
        MetricsCounter $counter,
    ): DataDogProvider {
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => $lifecycleReady,
            configChangedCallable: fn () => false,
        );

        $noopWriter = new ExposureWriter(
            sidecarCallable: fn () => true,
        );

        return new DataDogProvider(
            resultMapper: new BridgeResultMapper(),
            contextNormalizer: new EvaluationContextNormalizer(),
            bridgeCallable: $bridge,
            lifecycle: $lifecycle,
            exposureWriter: $noopWriter,
            metricsCounter: $counter,
        );
    }
}
