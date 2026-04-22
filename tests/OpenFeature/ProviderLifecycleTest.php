<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use Closure;
use DDTrace\OpenFeature\BridgeResultMapper;
use DDTrace\OpenFeature\DataDogProvider;
use DDTrace\OpenFeature\EvaluationContextNormalizer;
use DDTrace\OpenFeature\ExposureContext;
use DDTrace\OpenFeature\OpenFeatureLifecycleCompatibility;
use DDTrace\OpenFeature\ProviderLifecycle;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\interfaces\provider\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * Tests for provider lifecycle behavior: readiness, blocking/non-blocking init,
 * PROVIDER_READY event semantics, and lifecycle integration with DataDogProvider.
 */
class ProviderLifecycleTest extends TestCase
{
    // -------------------------------------------------------------------------
    // ProviderLifecycle: basic readiness
    // -------------------------------------------------------------------------

    public function testIsReadyReturnsFalseWhenNoConfig(): void
    {
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => false,
            configVersionCallable: fn (): int => 0,
        );

        $this->assertFalse($lifecycle->isReady());
    }

    public function testIsReadyReturnsTrueWhenConfigAvailable(): void
    {
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => true,
            configVersionCallable: fn (): int => 0,
        );

        $this->assertTrue($lifecycle->isReady());
    }

    public function testIsReadyBecomesReadyWhenConfigAppears(): void
    {
        $hasConfig = false;
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: function () use (&$hasConfig): bool { return $hasConfig; },
            configVersionCallable: fn (): int => 0,
        );

        $this->assertFalse($lifecycle->isReady());

        // Simulate config arriving
        $hasConfig = true;
        $this->assertTrue($lifecycle->isReady());
    }

    public function testIsReadyStaysReadyOnceSet(): void
    {
        $hasConfig = true;
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: function () use (&$hasConfig): bool { return $hasConfig; },
            configVersionCallable: fn (): int => 0,
        );

        $this->assertTrue($lifecycle->isReady());

        // Even if has_config goes false, ready should stick
        $hasConfig = false;
        $this->assertTrue($lifecycle->isReady());
    }

    // -------------------------------------------------------------------------
    // ProviderLifecycle: blocking init (waitUntilReady)
    // -------------------------------------------------------------------------

    public function testBlockingInitSucceedsWhenConfigAvailableImmediately(): void
    {
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => true,
            configVersionCallable: fn (): int => 0,
        );

        $result = $lifecycle->waitUntilReady(1.0);

        $this->assertTrue($result);
        $this->assertTrue($lifecycle->isReady());
    }

    public function testBlockingInitSucceedsWhenConfigArrivesBeforeTimeout(): void
    {
        $callCount = 0;
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: function () use (&$callCount): bool {
                $callCount++;
                // Config arrives on 3rd check
                return $callCount >= 3;
            },
            configVersionCallable: fn (): int => 0,
        );

        $result = $lifecycle->waitUntilReady(2.0, 1_000); // 1ms poll interval

        $this->assertTrue($result);
        $this->assertTrue($lifecycle->isReady());
    }

    public function testBlockingInitTimesOutCorrectly(): void
    {
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => false,
            configVersionCallable: fn (): int => 0,
        );

        $start = microtime(true);
        $result = $lifecycle->waitUntilReady(0.05, 5_000); // 50ms timeout, 5ms poll
        $elapsed = microtime(true) - $start;

        $this->assertFalse($result);
        $this->assertFalse($lifecycle->isReady());
        // Should not take significantly longer than the timeout
        $this->assertLessThan(0.2, $elapsed, 'Timeout should complete within reasonable bounds');
    }

    public function testBlockingInitWithZeroTimeoutReturnsImmediately(): void
    {
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => false,
            configVersionCallable: fn (): int => 0,
        );

        $start = microtime(true);
        $result = $lifecycle->waitUntilReady(0.0);
        $elapsed = microtime(true) - $start;

        $this->assertFalse($result);
        $this->assertLessThan(0.01, $elapsed, 'Zero timeout should return immediately');
    }

    // -------------------------------------------------------------------------
    // ProviderLifecycle: PROVIDER_READY event
    // -------------------------------------------------------------------------

    public function testProviderReadyFiredOnceOnFirstReadyTransition(): void
    {
        $readyCount = 0;
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => true,
            configVersionCallable: fn (): int => 0,
            onReady: function () use (&$readyCount): void { $readyCount++; },
        );

        // Should have fired once immediately since config was available at construction
        $this->assertSame(1, $readyCount);

        // Multiple isReady checks should not re-fire
        $lifecycle->isReady();
        $lifecycle->isReady();
        $lifecycle->isReady();
        $this->assertSame(1, $readyCount);
    }

    public function testProviderReadyNotFiredWhenNotReady(): void
    {
        $readyCount = 0;
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => false,
            configVersionCallable: fn (): int => 0,
            onReady: function () use (&$readyCount): void { $readyCount++; },
        );

        $this->assertSame(0, $readyCount);
        $lifecycle->isReady(); // Still not ready
        $this->assertSame(0, $readyCount);
    }

    public function testProviderReadyFiredOnDelayedConfigArrival(): void
    {
        $hasConfig = false;
        $readyCount = 0;
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: function () use (&$hasConfig): bool { return $hasConfig; },
            configVersionCallable: fn (): int => 0,
            onReady: function () use (&$readyCount): void { $readyCount++; },
        );

        $this->assertSame(0, $readyCount);

        // Config arrives
        $hasConfig = true;
        $lifecycle->isReady();

        $this->assertSame(1, $readyCount);
    }

    public function testProviderReadyFiredOnlyOnceEvenWithConfigChanges(): void
    {
        $readyCount = 0;
        $version = 1;

        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => true,
            configVersionCallable: function () use (&$version): int {
                return $version;
            },
            onReady: function () use (&$readyCount): void { $readyCount++; },
        );

        // Should have fired once at construction (has_config=true → transitionToReady)
        $this->assertSame(1, $readyCount);

        // Simulate subsequent RC updates bumping the version counter.
        $version = 2;
        $lifecycle->checkForConfigChange();
        $version = 3;
        $lifecycle->checkForConfigChange();

        // Still only 1 — ready is sticky, PROVIDER_READY fires exactly once.
        $this->assertSame(1, $readyCount);
    }

    public function testLateOnReadyCallbackFiresImmediatelyIfAlreadyReady(): void
    {
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => true,
            configVersionCallable: fn (): int => 0,
        );

        // Provider is already ready, register callback late
        $called = false;
        $lifecycle->onReady(function () use (&$called): void { $called = true; });

        $this->assertTrue($called, 'Late onReady callback should fire immediately when already ready');
    }

    // -------------------------------------------------------------------------
    // ProviderLifecycle: checkForConfigChange
    // -------------------------------------------------------------------------

    public function testCheckForConfigChangeReturnsTrueWhenChanged(): void
    {
        $version = 1;
        $lifecycle = new ProviderLifecycle(
            hasConfigCallable: fn () => true,
            configVersionCallable: function () use (&$version): int {
                return $version;
            },
        );

        // Constructor syncs last-seen to 1; bump to simulate an RC update.
        $version = 2;
        $this->assertTrue($lifecycle->checkForConfigChange());
        $this->assertFalse($lifecycle->checkForConfigChange());
    }

    // -------------------------------------------------------------------------
    // DataDogProvider: lifecycle integration
    // -------------------------------------------------------------------------

    public function testProviderReturnsDefaultWhenNotReady(): void
    {
        $bridgeCalled = false;
        $provider = new DataDogProvider(
            resultMapper: new BridgeResultMapper(),
            contextNormalizer: new EvaluationContextNormalizer(),
            bridgeCallable: function () use (&$bridgeCalled): ?array {
                $bridgeCalled = true;
                return $this->makeSuccessResult('true');
            },
            lifecycle: new ProviderLifecycle(
                hasConfigCallable: fn () => false,
                configVersionCallable: fn (): int => 0,
            ),
        );

        $result = $provider->resolveBooleanValue('flag.bool', true);

        $this->assertTrue($result->getValue()); // Default returned
        $this->assertSame(Reason::ERROR, $result->getReason());
        $this->assertEquals(
            ErrorCode::PROVIDER_NOT_READY(),
            $result->getError()->getResolutionErrorCode()
        );
        $this->assertFalse($bridgeCalled, 'Bridge should not be called when provider is not ready');
    }

    public function testProviderCallsBridgeWhenReady(): void
    {
        $bridgeCalled = false;
        $provider = new DataDogProvider(
            resultMapper: new BridgeResultMapper(),
            contextNormalizer: new EvaluationContextNormalizer(),
            bridgeCallable: function () use (&$bridgeCalled): ?array {
                $bridgeCalled = true;
                return $this->makeSuccessResult('"hello"');
            },
            lifecycle: new ProviderLifecycle(
                hasConfigCallable: fn () => true,
                configVersionCallable: fn (): int => 0,
            ),
        );

        $result = $provider->resolveStringValue('flag.str', 'default');

        $this->assertSame('hello', $result->getValue());
        $this->assertTrue($bridgeCalled, 'Bridge should be called when provider is ready');
    }

    public function testNonBlockingModeReturnsDefaultsBeforeReady(): void
    {
        $hasConfig = false;
        $provider = new DataDogProvider(
            resultMapper: new BridgeResultMapper(),
            contextNormalizer: new EvaluationContextNormalizer(),
            bridgeCallable: fn () => $this->makeSuccessResult('42'),
            lifecycle: new ProviderLifecycle(
                hasConfigCallable: function () use (&$hasConfig): bool { return $hasConfig; },
                configVersionCallable: fn (): int => 0,
            ),
        );

        // Before config arrives: defaults
        $result = $provider->resolveIntegerValue('flag.int', 99);
        $this->assertSame(99, $result->getValue());
        $this->assertSame(Reason::ERROR, $result->getReason());

        // Config arrives
        $hasConfig = true;

        // Now should evaluate via bridge
        $result = $provider->resolveIntegerValue('flag.int', 99);
        $this->assertSame(42, $result->getValue());
    }

    public function testAllTypedResolversReturnDefaultWhenNotReady(): void
    {
        $provider = new DataDogProvider(
            resultMapper: new BridgeResultMapper(),
            contextNormalizer: new EvaluationContextNormalizer(),
            bridgeCallable: fn () => $this->makeSuccessResult('"should-not-reach"'),
            lifecycle: new ProviderLifecycle(
                hasConfigCallable: fn () => false,
                configVersionCallable: fn (): int => 0,
            ),
        );

        // Boolean
        $result = $provider->resolveBooleanValue('f', true);
        $this->assertTrue($result->getValue());
        $this->assertEquals(ErrorCode::PROVIDER_NOT_READY(), $result->getError()->getResolutionErrorCode());

        // String
        $result = $provider->resolveStringValue('f', 'fallback');
        $this->assertSame('fallback', $result->getValue());
        $this->assertEquals(ErrorCode::PROVIDER_NOT_READY(), $result->getError()->getResolutionErrorCode());

        // Integer
        $result = $provider->resolveIntegerValue('f', 42);
        $this->assertSame(42, $result->getValue());
        $this->assertEquals(ErrorCode::PROVIDER_NOT_READY(), $result->getError()->getResolutionErrorCode());

        // Float
        $result = $provider->resolveFloatValue('f', 3.14);
        $this->assertSame(3.14, $result->getValue());
        $this->assertEquals(ErrorCode::PROVIDER_NOT_READY(), $result->getError()->getResolutionErrorCode());

        // Object
        $result = $provider->resolveObjectValue('f', ['default' => true]);
        $this->assertSame(['default' => true], $result->getValue());
        $this->assertEquals(ErrorCode::PROVIDER_NOT_READY(), $result->getError()->getResolutionErrorCode());
    }

    // -------------------------------------------------------------------------
    // OpenFeatureLifecycleCompatibility
    // -------------------------------------------------------------------------

    public function testSetProviderAndWaitReturnsTrueWhenReady(): void
    {
        $provider = new DataDogProvider(
            lifecycle: new ProviderLifecycle(
                hasConfigCallable: fn () => true,
                configVersionCallable: fn (): int => 0,
            ),
        );

        $result = OpenFeatureLifecycleCompatibility::setProviderAndWait($provider, 1.0);

        $this->assertTrue($result);
    }

    public function testSetProviderAndWaitReturnsFalseOnTimeout(): void
    {
        $provider = new DataDogProvider(
            lifecycle: new ProviderLifecycle(
                hasConfigCallable: fn () => false,
                configVersionCallable: fn (): int => 0,
            ),
        );

        $result = OpenFeatureLifecycleCompatibility::setProviderAndWait($provider, 0.05);

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // ExposureContext: standalone tests
    // -------------------------------------------------------------------------

    public function testExposureContextFromBridgeResultIncludesServiceEnvVersion(): void
    {
        $envVars = [
            'DD_SERVICE' => 'my-service',
            'DD_ENV' => 'production',
            'DD_VERSION' => '1.2.3',
        ];

        $bridgeResult = [
            'value_json' => '"blue"',
            'variant' => 'color-variant',
            'allocation_key' => 'alloc-abc',
            'reason' => 1,
            'error_code' => 0,
            'do_log' => true,
        ];

        $ctx = ExposureContext::fromBridgeResult(
            $bridgeResult,
            'flag.color',
            'user-123',
            fn (string $name): ?string => $envVars[$name] ?? null,
        );

        $this->assertNotNull($ctx);
        $this->assertSame('my-service', $ctx->service);
        $this->assertSame('production', $ctx->env);
        $this->assertSame('1.2.3', $ctx->version);
        $this->assertSame('flag.color', $ctx->flagKey);
        $this->assertSame('alloc-abc', $ctx->allocationKey);
        $this->assertSame('color-variant', $ctx->variant);
        $this->assertSame('user-123', $ctx->targetingKey);
    }

    public function testExposureContextReturnsNullWhenDoLogIsFalse(): void
    {
        $bridgeResult = [
            'value_json' => '"blue"',
            'variant' => 'color-variant',
            'allocation_key' => 'alloc-abc',
            'reason' => 1,
            'error_code' => 0,
            'do_log' => false,
        ];

        $ctx = ExposureContext::fromBridgeResult(
            $bridgeResult,
            'flag.color',
            'user-123',
            fn (string $name): ?string => null,
        );

        $this->assertNull($ctx, 'do_log=false must suppress exposure context');
    }

    public function testExposureContextHandlesMissingEnvVars(): void
    {
        $bridgeResult = [
            'value_json' => 'true',
            'variant' => 'v1',
            'allocation_key' => 'a1',
            'reason' => 1,
            'error_code' => 0,
            'do_log' => true,
        ];

        $ctx = ExposureContext::fromBridgeResult(
            $bridgeResult,
            'flag.bool',
            null,
            fn (string $name): ?string => null, // No env vars set
        );

        $this->assertNotNull($ctx);
        $this->assertNull($ctx->service);
        $this->assertNull($ctx->env);
        $this->assertNull($ctx->version);
        $this->assertNull($ctx->targetingKey);
    }

    public function testExposureContextCarriesEvaluatorMetadataIntact(): void
    {
        $bridgeResult = [
            'value_json' => '42',
            'variant' => 'experiment-v2',
            'allocation_key' => 'alloc-xyz-789',
            'reason' => 2,
            'error_code' => 0,
            'do_log' => true,
        ];

        $ctx = ExposureContext::fromBridgeResult(
            $bridgeResult,
            'flag.experiment',
            'target-key-456',
            fn (string $name): ?string => null,
        );

        $this->assertNotNull($ctx);
        $this->assertSame('flag.experiment', $ctx->flagKey);
        $this->assertSame('alloc-xyz-789', $ctx->allocationKey);
        $this->assertSame('experiment-v2', $ctx->variant);
        $this->assertSame('target-key-456', $ctx->targetingKey);
    }

    public function testExposureContextToArrayReturnsExpectedShape(): void
    {
        $ctx = new ExposureContext(
            service: 'svc',
            env: 'staging',
            version: '0.1.0',
            flagKey: 'flag.test',
            allocationKey: 'alloc-1',
            variant: 'v1',
            targetingKey: 'user-1',
        );

        $this->assertSame([
            'service' => 'svc',
            'env' => 'staging',
            'version' => '0.1.0',
            'flag_key' => 'flag.test',
            'allocation_key' => 'alloc-1',
            'variant' => 'v1',
            'targeting_key' => 'user-1',
        ], $ctx->toArray());
    }

    // -------------------------------------------------------------------------
    // DataDogProvider: exposure context integration
    // -------------------------------------------------------------------------

    public function testExposureContextProducedOnSuccessfulEvaluation(): void
    {
        $provider = $this->createReadyProvider(
            fn () => $this->makeSuccessResult('"hello"', 'v1', 'alloc-1'),
            ['DD_SERVICE' => 'test-svc', 'DD_ENV' => 'test', 'DD_VERSION' => '1.0'],
        );

        $provider->resolveStringValue('flag.str', 'default');

        $ctx = $provider->getLastExposureContext();
        $this->assertNotNull($ctx);
        $this->assertSame('test-svc', $ctx->service);
        $this->assertSame('test', $ctx->env);
        $this->assertSame('1.0', $ctx->version);
        $this->assertSame('flag.str', $ctx->flagKey);
        $this->assertSame('alloc-1', $ctx->allocationKey);
        $this->assertSame('v1', $ctx->variant);
    }

    public function testExposureContextSuppressedWhenDoLogFalse(): void
    {
        $provider = $this->createReadyProvider(
            fn () => [
                'value_json' => '"hello"',
                'variant' => 'v1',
                'allocation_key' => 'alloc-1',
                'reason' => 1,
                'error_code' => 0,
                'do_log' => false,
            ],
        );

        $provider->resolveStringValue('flag.str', 'default');

        $this->assertNull(
            $provider->getLastExposureContext(),
            'do_log=false must suppress exposure context in provider'
        );
    }

    public function testExposureContextNullWhenProviderNotReady(): void
    {
        $provider = new DataDogProvider(
            resultMapper: new BridgeResultMapper(),
            contextNormalizer: new EvaluationContextNormalizer(),
            bridgeCallable: fn () => $this->makeSuccessResult('"hello"'),
            lifecycle: new ProviderLifecycle(
                hasConfigCallable: fn () => false,
                configVersionCallable: fn (): int => 0,
            ),
        );

        $provider->resolveStringValue('flag.str', 'default');

        $this->assertNull($provider->getLastExposureContext());
    }

    public function testExposureContextNullOnBridgeError(): void
    {
        $provider = $this->createReadyProvider(
            fn () => [
                'value_json' => null,
                'variant' => null,
                'allocation_key' => null,
                'reason' => 4,
                'error_code' => 1, // FLAG_NOT_FOUND
                'do_log' => false,
            ],
        );

        $provider->resolveBooleanValue('missing.flag', true);

        $this->assertNull($provider->getLastExposureContext());
    }

    public function testExposureContextUpdatedOnSubsequentEvaluations(): void
    {
        $callCount = 0;
        $provider = $this->createReadyProvider(
            function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    return $this->makeSuccessResult('"first"', 'v1', 'alloc-1');
                }
                return [
                    'value_json' => '"second"',
                    'variant' => 'v2',
                    'allocation_key' => 'alloc-2',
                    'reason' => 1,
                    'error_code' => 0,
                    'do_log' => false, // Second eval suppresses
                ];
            },
        );

        // First evaluation: exposure context produced
        $provider->resolveStringValue('flag.first', 'default');
        $this->assertNotNull($provider->getLastExposureContext());
        $this->assertSame('flag.first', $provider->getLastExposureContext()->flagKey);

        // Second evaluation: do_log=false suppresses
        $provider->resolveStringValue('flag.second', 'default');
        $this->assertNull($provider->getLastExposureContext());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
     * Create a provider with ready lifecycle for exposure context tests.
     *
     * @param \Closure $bridge Bridge callable
     * @param array<string, string> $envVars Environment variable overrides
     */
    private function createReadyProvider(\Closure $bridge, array $envVars = []): DataDogProvider
    {
        return new DataDogProvider(
            resultMapper: new BridgeResultMapper(),
            contextNormalizer: new EvaluationContextNormalizer(),
            bridgeCallable: $bridge,
            lifecycle: new ProviderLifecycle(
                hasConfigCallable: fn () => true,
                configVersionCallable: fn (): int => 0,
            ),
            envReader: fn (string $name): ?string => $envVars[$name] ?? null,
        );
    }
}
