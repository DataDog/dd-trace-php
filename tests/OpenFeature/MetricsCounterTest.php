<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use Closure;
use DDTrace\OpenFeature\MetricsCounter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetricsCounter.
 *
 * Covers:
 *   - Attribute shape exactly matches OBSV-02 (feature_flag.key,
 *     feature_flag.result.variant, feature_flag.result.reason,
 *     feature_flag.result.allocation_key, error.type)
 *   - error.type mapping for all 5 bridge error codes
 *   - error.type="PROVIDER_NOT_READY" when bridgeResult is null
 *   - reason="ERROR" on any error path, mapped reason string on success
 *   - DD_METRICS_OTEL_ENABLED gate (enabled/disabled, case-insensitive values)
 *   - Default counterCallable is a no-op (safe without compiled extension)
 *
 * No bridge calls, no extension dependency -- pure unit tests.
 */
final class MetricsCounterTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Success path: attribute shape and reason mapping
    // -------------------------------------------------------------------------

    public function testRecordOnSuccessProducesFullAttributeShape(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', [
            'value_json' => 'true',
            'variant' => 'on',
            'allocation_key' => 'alloc-1',
            'reason' => 1, // REASON_TARGETING_MATCH
            'error_code' => 0,
            'do_log' => true,
        ]);

        $this->assertCount(1, $captured);
        $this->assertSame([
            'feature_flag.key' => 'flag-1',
            'feature_flag.result.variant' => 'on',
            'feature_flag.result.reason' => 'TARGETING_MATCH',
            'feature_flag.result.allocation_key' => 'alloc-1',
            'error.type' => '',
        ], $captured[0]);
    }

    public function testRecordOnSuccessReasonDefault(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', $this->successBridgeResult(reason: 0));

        $this->assertSame('DEFAULT', $captured[0]['feature_flag.result.reason']);
        $this->assertSame('', $captured[0]['error.type']);
    }

    public function testRecordOnSuccessReasonSplit(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', $this->successBridgeResult(reason: 2));

        $this->assertSame('SPLIT', $captured[0]['feature_flag.result.reason']);
    }

    public function testRecordOnSuccessReasonDisabled(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', $this->successBridgeResult(reason: 3));

        $this->assertSame('DISABLED', $captured[0]['feature_flag.result.reason']);
    }

    // -------------------------------------------------------------------------
    // Error path: error.type mapping for each bridge error code
    // -------------------------------------------------------------------------

    public function testRecordOnFlagNotFoundSetsErrorType(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('missing-flag', $this->errorBridgeResult(errorCode: 1));

        $this->assertSame('FLAG_NOT_FOUND', $captured[0]['error.type']);
        $this->assertSame('ERROR', $captured[0]['feature_flag.result.reason']);
    }

    public function testRecordOnParseErrorSetsErrorType(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', $this->errorBridgeResult(errorCode: 2));

        $this->assertSame('PARSE_ERROR', $captured[0]['error.type']);
        $this->assertSame('ERROR', $captured[0]['feature_flag.result.reason']);
    }

    public function testRecordOnTypeMismatchSetsErrorType(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', $this->errorBridgeResult(errorCode: 3));

        $this->assertSame('TYPE_MISMATCH', $captured[0]['error.type']);
        $this->assertSame('ERROR', $captured[0]['feature_flag.result.reason']);
    }

    public function testRecordOnGeneralErrorSetsErrorType(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', $this->errorBridgeResult(errorCode: 4));

        $this->assertSame('GENERAL', $captured[0]['error.type']);
        $this->assertSame('ERROR', $captured[0]['feature_flag.result.reason']);
    }

    public function testRecordOnProviderNotReadySetsErrorType(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', $this->errorBridgeResult(errorCode: 5));

        $this->assertSame('PROVIDER_NOT_READY', $captured[0]['error.type']);
        $this->assertSame('ERROR', $captured[0]['feature_flag.result.reason']);
    }

    public function testRecordOnUnknownErrorCodeFallsBackToGeneral(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', $this->errorBridgeResult(errorCode: 999));

        $this->assertSame('GENERAL', $captured[0]['error.type']);
        $this->assertSame('ERROR', $captured[0]['feature_flag.result.reason']);
    }

    public function testRecordWithNullBridgeResultTreatsAsProviderNotReady(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', null);

        $this->assertCount(1, $captured);
        $this->assertSame('PROVIDER_NOT_READY', $captured[0]['error.type']);
        $this->assertSame('ERROR', $captured[0]['feature_flag.result.reason']);
        $this->assertSame('', $captured[0]['feature_flag.result.variant']);
        $this->assertSame('', $captured[0]['feature_flag.result.allocation_key']);
        $this->assertSame('flag-1', $captured[0]['feature_flag.key']);
    }

    // -------------------------------------------------------------------------
    // DD_METRICS_OTEL_ENABLED gate
    // -------------------------------------------------------------------------

    public function testRecordSkipsCallableWhenMetricsDisabled(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->disabledEnvReader());

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(0, $captured, 'counter callable must not be invoked when gate is disabled');
    }

    public function testRecordSkipsCallableWhenDDMetricsOtelEnabledIsFalse(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->envReaderReturning('false'));

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(0, $captured);
    }

    public function testRecordSkipsCallableWhenDDMetricsOtelEnabledIsZero(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->envReaderReturning('0'));

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(0, $captured);
    }

    public function testRecordSkipsCallableWhenDDMetricsOtelEnabledIsEmpty(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->envReaderReturning(''));

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(0, $captured);
    }

    public function testRecordSkipsCallableWhenDDMetricsOtelEnabledIsNo(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->envReaderReturning('no'));

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(0, $captured);
    }

    public function testRecordFiresWhenDDMetricsOtelEnabledIsTrue(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->envReaderReturning('true'));

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(1, $captured);
    }

    public function testRecordFiresWhenDDMetricsOtelEnabledIsOne(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->envReaderReturning('1'));

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(1, $captured);
    }

    public function testRecordFiresWhenDDMetricsOtelEnabledIsYes(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->envReaderReturning('yes'));

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(1, $captured);
    }

    public function testRecordFiresWhenDDMetricsOtelEnabledIsOn(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->envReaderReturning('on'));

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(1, $captured);
    }

    public function testRecordFiresWhenDDMetricsOtelEnabledIsCaseInsensitive(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->envReaderReturning('TRUE'));

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(1, $captured, 'TRUE (uppercase) must enable the gate (case-insensitive)');
    }

    public function testRecordFiresWhenDDMetricsOtelEnabledHasWhitespace(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->envReaderReturning(' true '));

        $counter->record('flag-1', $this->successBridgeResult());

        $this->assertCount(1, $captured, 'Whitespace-padded true must enable the gate (trimmed)');
    }

    // -------------------------------------------------------------------------
    // Default counterCallable is a no-op (safe without compiled extension)
    // -------------------------------------------------------------------------

    public function testDefaultCounterCallableIsNoop(): void
    {
        // No counterCallable, no envReader. Record must not throw even with the gate enabled.
        $counter = new MetricsCounter(null, $this->enabledEnvReader());

        // Calling record() should not throw even when no extension function exists.
        $counter->record('flag-1', $this->successBridgeResult());

        $this->expectNotToPerformAssertions();
    }

    public function testDefaultCounterCallableIsNoopWithDefaultEnvReader(): void
    {
        // No counterCallable at all. Record falls back to getenv() which will typically
        // return false/null for DD_METRICS_OTEL_ENABLED in the test process, so the
        // counter should never fire. This must not throw regardless.
        $counter = new MetricsCounter();

        $counter->record('flag-1', $this->successBridgeResult());
        $counter->record('flag-1', null);

        $this->expectNotToPerformAssertions();
    }

    // -------------------------------------------------------------------------
    // Attribute shape invariants
    // -------------------------------------------------------------------------

    public function testMissingVariantProducesEmptyString(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', [
            'value_json' => 'true',
            'allocation_key' => 'alloc-1',
            'reason' => 1,
            'error_code' => 0,
            'do_log' => true,
        ]);

        $this->assertSame('', $captured[0]['feature_flag.result.variant']);
    }

    public function testMissingAllocationKeyProducesEmptyString(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', [
            'value_json' => 'true',
            'variant' => 'on',
            'reason' => 1,
            'error_code' => 0,
            'do_log' => true,
        ]);

        $this->assertSame('', $captured[0]['feature_flag.result.allocation_key']);
    }

    public function testNullVariantProducesEmptyString(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', [
            'value_json' => 'true',
            'variant' => null,
            'allocation_key' => null,
            'reason' => 1,
            'error_code' => 0,
            'do_log' => true,
        ]);

        $this->assertSame('', $captured[0]['feature_flag.result.variant']);
        $this->assertSame('', $captured[0]['feature_flag.result.allocation_key']);
    }

    public function testAttributeKeysAreExactlyFive(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', $this->successBridgeResult());

        $expectedKeys = [
            'feature_flag.key',
            'feature_flag.result.variant',
            'feature_flag.result.reason',
            'feature_flag.result.allocation_key',
            'error.type',
        ];

        $actualKeys = array_keys($captured[0]);
        sort($expectedKeys);
        sort($actualKeys);

        $this->assertSame($expectedKeys, $actualKeys, 'Counter attributes must have exactly these five keys');
    }

    public function testAttributeKeysAreExactlyFiveOnErrorPath(): void
    {
        [$callable, $captured] = $this->makeCapturingCounter();
        $counter = new MetricsCounter($callable, $this->enabledEnvReader());

        $counter->record('flag-1', null);

        $expectedKeys = [
            'feature_flag.key',
            'feature_flag.result.variant',
            'feature_flag.result.reason',
            'feature_flag.result.allocation_key',
            'error.type',
        ];

        $actualKeys = array_keys($captured[0]);
        sort($expectedKeys);
        sort($actualKeys);

        $this->assertSame($expectedKeys, $actualKeys, 'Error-path attributes must also have exactly these five keys');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a capturing counter callable and an ArrayObject holding the captured list.
     *
     * The captor is returned as an ArrayObject so the reference is preserved
     * across PHP's list-destructuring semantics (which copy by value).
     *
     * @return array{0: Closure, 1: \ArrayObject<int, array<string, string>>}
     */
    private function makeCapturingCounter(): array
    {
        /** @var \ArrayObject<int, array<string, string>> $captured */
        $captured = new \ArrayObject();
        $callable = static function (array $attributes) use ($captured): void {
            $captured[] = $attributes;
        };
        return [$callable, $captured];
    }

    private function enabledEnvReader(): Closure
    {
        return static fn(string $name): ?string => $name === 'DD_METRICS_OTEL_ENABLED' ? 'true' : null;
    }

    private function disabledEnvReader(): Closure
    {
        return static fn(string $name): ?string => null;
    }

    private function envReaderReturning(string $value): Closure
    {
        return static fn(string $name): ?string => $name === 'DD_METRICS_OTEL_ENABLED' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function successBridgeResult(int $reason = 1): array
    {
        return [
            'value_json' => 'true',
            'variant' => 'on',
            'allocation_key' => 'alloc-1',
            'reason' => $reason,
            'error_code' => 0,
            'do_log' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorBridgeResult(int $errorCode): array
    {
        return [
            'value_json' => null,
            'variant' => null,
            'allocation_key' => null,
            'reason' => 4, // REASON_ERROR
            'error_code' => $errorCode,
            'do_log' => false,
        ];
    }
}
