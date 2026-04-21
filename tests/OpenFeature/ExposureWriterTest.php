<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use Closure;
use DDTrace\OpenFeature\ExposureContext;
use DDTrace\OpenFeature\ExposureWriter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExposureWriter.
 *
 * Tests use an injectable sidecar callable to avoid requiring the compiled
 * C extension. The callable captures arguments for assertion.
 */
class ExposureWriterTest extends TestCase
{
    private const FIXED_TIMESTAMP = 1713382853716;

    // ---------- Event Construction ----------

    public function testSendBuildsCorrectEventJson(): void
    {
        $capturedArgs = [];
        $writer = $this->createWriter($capturedArgs);

        $context = new ExposureContext(
            service: 'my-app',
            env: 'production',
            version: '1.2.3',
            flagKey: 'test-flag',
            allocationKey: 'default-alloc',
            variant: 'on',
            targetingKey: 'user-123',
        );

        $writer->send($context);

        $event = json_decode($capturedArgs['eventJson'], true);
        $this->assertSame(self::FIXED_TIMESTAMP, $event['timestamp']);
        $this->assertSame('test-flag', $event['flag']['key']);
        $this->assertSame('default-alloc', $event['allocation']['key']);
        $this->assertSame('on', $event['variant']['key']);
        $this->assertSame('user-123', $event['subject']['id']);
    }

    public function testSendPassesDedupFieldsToCallable(): void
    {
        $capturedArgs = [];
        $writer = $this->createWriter($capturedArgs);

        $context = new ExposureContext(
            service: 'app',
            env: 'prod',
            version: '1.0',
            flagKey: 'my-flag',
            allocationKey: 'alloc-1',
            variant: 'variant-a',
            targetingKey: 'user-456',
        );

        $writer->send($context);

        $this->assertSame('my-flag', $capturedArgs['flagKey']);
        $this->assertSame('alloc-1', $capturedArgs['allocationKey']);
        $this->assertSame('user-456', $capturedArgs['targetingKey']);
        $this->assertSame('variant-a', $capturedArgs['variantKey']);
    }

    // ---------- Subject Attributes ----------

    public function testSendFlattensEvaluationAttributes(): void
    {
        $capturedArgs = [];
        $writer = $this->createWriter($capturedArgs);

        $context = new ExposureContext(
            service: 'app',
            env: 'prod',
            version: '1.0',
            flagKey: 'flag',
            allocationKey: 'alloc',
            variant: 'v',
            targetingKey: 'user',
        );

        $writer->send($context, [
            'plan' => 'enterprise',
            'org' => ['name' => 'Acme'],
        ]);

        $event = json_decode($capturedArgs['eventJson'], true);
        $attrs = (array)$event['subject']['attributes'];
        $this->assertSame('enterprise', $attrs['plan']);
        $this->assertSame('Acme', $attrs['org.name']);
    }

    public function testSendWithNullAttributesProducesEmptySubjectAttributes(): void
    {
        $capturedArgs = [];
        $writer = $this->createWriter($capturedArgs);

        $context = new ExposureContext(
            service: 'app',
            env: 'prod',
            version: '1.0',
            flagKey: 'flag',
            allocationKey: 'alloc',
            variant: 'v',
            targetingKey: 'user',
        );

        $writer->send($context, null);

        $event = json_decode($capturedArgs['eventJson'], true);
        $this->assertSame([], (array)$event['subject']['attributes']);
    }

    // ---------- Null Field Handling ----------

    public function testSendHandlesNullAllocationKey(): void
    {
        $capturedArgs = [];
        $writer = $this->createWriter($capturedArgs);

        $context = new ExposureContext(
            service: 'app',
            env: 'prod',
            version: '1.0',
            flagKey: 'flag',
            allocationKey: null,
            variant: 'v',
            targetingKey: 'user',
        );

        $writer->send($context);

        $this->assertSame('', $capturedArgs['allocationKey']);
        $event = json_decode($capturedArgs['eventJson'], true);
        $this->assertSame('', $event['allocation']['key']);
    }

    public function testSendHandlesNullTargetingKey(): void
    {
        $capturedArgs = [];
        $writer = $this->createWriter($capturedArgs);

        $context = new ExposureContext(
            service: 'app',
            env: 'prod',
            version: '1.0',
            flagKey: 'flag',
            allocationKey: 'alloc',
            variant: 'v',
            targetingKey: null,
        );

        $writer->send($context);

        $this->assertNull($capturedArgs['targetingKey']);
        $event = json_decode($capturedArgs['eventJson'], true);
        $this->assertSame('', $event['subject']['id']);
    }

    public function testSendHandlesNullVariant(): void
    {
        $capturedArgs = [];
        $writer = $this->createWriter($capturedArgs);

        $context = new ExposureContext(
            service: 'app',
            env: 'prod',
            version: '1.0',
            flagKey: 'flag',
            allocationKey: 'alloc',
            variant: null,
            targetingKey: 'user',
        );

        $writer->send($context);

        $this->assertSame('', $capturedArgs['variantKey']);
        $event = json_decode($capturedArgs['eventJson'], true);
        $this->assertSame('', $event['variant']['key']);
    }

    // ---------- Return Value ----------

    public function testSendReturnsTrueWhenCallableReturnsTrue(): void
    {
        $writer = new ExposureWriter(
            sidecarCallable: fn() => true,
            timestampProvider: fn() => self::FIXED_TIMESTAMP,
        );

        $context = $this->createMinimalContext();
        $this->assertTrue($writer->send($context));
    }

    public function testSendReturnsFalseWhenCallableReturnsFalse(): void
    {
        $writer = new ExposureWriter(
            sidecarCallable: fn() => false,
            timestampProvider: fn() => self::FIXED_TIMESTAMP,
        );

        $context = $this->createMinimalContext();
        $this->assertFalse($writer->send($context));
    }

    // ---------- Timestamp ----------

    public function testTimestampCapturedAtSendTime(): void
    {
        $capturedArgs = [];
        $writer = $this->createWriter($capturedArgs);

        $context = $this->createMinimalContext();
        $writer->send($context);

        $event = json_decode($capturedArgs['eventJson'], true);
        $this->assertSame(self::FIXED_TIMESTAMP, $event['timestamp']);
    }

    // ---------- Helpers ----------

    /**
     * Create an ExposureWriter with capturing sidecar callable and fixed timestamp.
     */
    private function createWriter(array &$capturedArgs): ExposureWriter
    {
        return new ExposureWriter(
            sidecarCallable: function (
                string $eventJson,
                string $flagKey,
                string $allocationKey,
                ?string $targetingKey,
                string $variantKey,
            ) use (&$capturedArgs): bool {
                $capturedArgs = compact('eventJson', 'flagKey', 'allocationKey', 'targetingKey', 'variantKey');
                return true;
            },
            timestampProvider: fn() => self::FIXED_TIMESTAMP,
        );
    }

    private function createMinimalContext(): ExposureContext
    {
        return new ExposureContext(
            service: 'app',
            env: 'prod',
            version: '1.0',
            flagKey: 'flag',
            allocationKey: 'alloc',
            variant: 'on',
            targetingKey: 'user',
        );
    }
}
