<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use DDTrace\OpenFeature\ExposureContext;
use DDTrace\OpenFeature\ExposureWriter;
use PHPUnit\Framework\TestCase;

/**
 * Staging approximation of the system-test cross-request dedup scenario.
 *
 * This test does NOT run the real Rust EXPOSURE_STATE Mutex or the sidecar.
 * It constructs an in-memory mock sidecar callable that mirrors the Rust
 * dedup semantics (null-byte-joined dedup key + variant comparison) and
 * exercises ExposureWriter.send() twice with identical dedup triples.
 *
 * Purpose: catch obvious regressions in the PHP-side dedup field passing
 * without needing the full Docker system-tests framework. The real
 * cross-request dedup test runs in DataDog/system-tests per QUAL-04.
 *
 * Per CONTEXT.md D-07: "Cross-request dedup smoke test -- calls
 * ffe_send_exposure() twice with identical dedup triple, asserts second
 * returns false (staging approximation of system test)".
 *
 * The mock intentionally duplicates a small amount of dedup logic (null-byte
 * key, variant comparison) from the Rust implementation because the purpose
 * is to pin the PHP contract (what gets passed where), not to retest the
 * Rust engine. When Rust semantics change, both the Rust test and this
 * smoke test must update -- a desirable property for a cross-tracer contract.
 */
final class CrossRequestDedupTest extends TestCase
{
    public function testSecondSendWithIdenticalDedupTripleReturnsFalse(): void
    {
        $writer = new ExposureWriter($this->makeMockSidecarCallable());

        $context = new ExposureContext(
            service: null,
            env: null,
            version: null,
            flagKey: 'flag-1',
            allocationKey: 'alloc-1',
            variant: 'on',
            targetingKey: 'user-1',
        );

        $firstResult = $writer->send($context, ['a' => 1]);
        $secondResult = $writer->send($context, ['a' => 1]);

        $this->assertTrue($firstResult, 'First send with a fresh dedup triple must be enqueued');
        $this->assertFalse(
            $secondResult,
            'Second send with identical dedup triple + variant must be deduplicated',
        );
    }

    public function testSendWithDifferentTargetingKeyIsNotDeduplicated(): void
    {
        $writer = new ExposureWriter($this->makeMockSidecarCallable());

        $contextUser1 = new ExposureContext(
            service: null,
            env: null,
            version: null,
            flagKey: 'flag-1',
            allocationKey: 'alloc-1',
            variant: 'on',
            targetingKey: 'user-1',
        );

        $contextUser2 = new ExposureContext(
            service: null,
            env: null,
            version: null,
            flagKey: 'flag-1',
            allocationKey: 'alloc-1',
            variant: 'on',
            targetingKey: 'user-2',
        );

        $this->assertTrue(
            $writer->send($contextUser1, null),
            'First send (user-1) must be enqueued',
        );
        $this->assertTrue(
            $writer->send($contextUser2, null),
            'Different targeting key must not dedupe against user-1 cache entry',
        );
    }

    public function testSendWithSameTripleButDifferentVariantIsRecordedAsNewExposure(): void
    {
        // Mirrors Rust behavior: if cached variant != new variant, the dedup
        // logic treats the new exposure as a new cache entry (returns true).
        // This asserts the PHP layer passes variant through faithfully so the
        // Rust dedup can make the distinction.
        $writer = new ExposureWriter($this->makeMockSidecarCallable());

        $contextVariantOn = new ExposureContext(
            service: null,
            env: null,
            version: null,
            flagKey: 'flag-1',
            allocationKey: 'alloc-1',
            variant: 'on',
            targetingKey: 'user-1',
        );

        $contextVariantOff = new ExposureContext(
            service: null,
            env: null,
            version: null,
            flagKey: 'flag-1',
            allocationKey: 'alloc-1',
            variant: 'off',
            targetingKey: 'user-1',
        );

        $this->assertTrue(
            $writer->send($contextVariantOn, null),
            'First send (variant=on) must be enqueued',
        );
        $this->assertTrue(
            $writer->send($contextVariantOff, null),
            'Same triple with a different variant must record a new exposure',
        );
    }

    /**
     * Build a mock sidecar callable that mirrors the Rust EXPOSURE_STATE dedup
     * semantics: dedup key is flag_key + "\0" + allocation_key + "\0" + (targeting_key ?? "").
     * On second call with the same dedup key AND the same variant, returns false
     * (deduplicated). On different dedup key or different variant, returns true
     * (recorded as a new/updated exposure).
     *
     * This keeps the null-byte separator format from components-rs/ffe.rs so
     * drift between Rust and PHP dedup key construction is caught here.
     *
     * @return \Closure(string, string, string, ?string, string): bool
     */
    private function makeMockSidecarCallable(): \Closure
    {
        /** @var array<string, string> $state */
        $state = [];

        return static function (
            string $eventJson,
            string $flagKey,
            string $allocationKey,
            ?string $targetingKey,
            string $variantKey,
        ) use (&$state): bool {
            $key = $flagKey . "\0" . $allocationKey . "\0" . ($targetingKey ?? '');

            if (isset($state[$key]) && $state[$key] === $variantKey) {
                // Same dedup triple, same variant -> deduplicated
                return false;
            }

            // New triple or variant changed -> recorded as new exposure
            $state[$key] = $variantKey;
            return true;
        };
    }
}
