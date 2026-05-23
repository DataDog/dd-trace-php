<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\EvaluationCompleted;
use DDTrace\FeatureFlags\Internal\Exposure\ExposureHook;
use DDTrace\FeatureFlags\Internal\Exposure\ExposureTransport;
use DDTrace\FeatureFlags\Internal\Exposure\ExposureWriter;
use PHPUnit\Framework\TestCase;

final class ExposureWriterTest extends TestCase
{
    public function testRecordSkipsWhenDoLogIsFalse()
    {
        $transport = new RecordingExposureTransport();
        $writer = new ExposureWriter($transport);

        $this->assertFalse($writer->record($this->evaluation('flag.off', 'user-1', array(), false)));
        $this->assertSame(0, $writer->bufferedCount());
        $this->assertTrue($writer->flush());
        $this->assertSame(array(), $transport->payloads());
        $this->assertSame(0, $writer->droppedCount());
    }

    public function testFlushSendsBatchedPayloadWithContextAndSubjectAttributes()
    {
        $transport = new RecordingExposureTransport();
        $writer = new ExposureWriter($transport, array(
            'service' => 'checkout',
            'env' => 'staging',
            'version' => '1.2.3',
        ));

        $this->assertTrue($writer->record($this->evaluation(
            'checkout.enabled',
            'user-123',
            array('plan' => 'pro', 'beta' => true),
            true,
            'allocation-a',
            'treatment'
        )));
        $this->assertSame(1, $writer->bufferedCount());
        $this->assertTrue($writer->flush());

        $payloads = $transport->payloads();
        $this->assertCount(1, $payloads);
        $this->assertSame(array('service' => 'checkout', 'env' => 'staging', 'version' => '1.2.3'), $payloads[0]['context']);
        $this->assertCount(1, $payloads[0]['exposures']);

        $exposure = $payloads[0]['exposures'][0];
        $this->assertTrue(is_int($exposure['timestamp']));
        $this->assertSame(array('key' => 'allocation-a'), $exposure['allocation']);
        $this->assertSame(array('key' => 'checkout.enabled'), $exposure['flag']);
        $this->assertSame(array('key' => 'treatment'), $exposure['variant']);
        $this->assertSame(array(
            'id' => 'user-123',
            'attributes' => array('plan' => 'pro', 'beta' => true),
        ), $exposure['subject']);
        $this->assertSame(0, $writer->bufferedCount());
    }

    public function testMissingAndEmptyTargetingKeysUseEmptySubjectId()
    {
        $transport = new RecordingExposureTransport();
        $writer = new ExposureWriter($transport);

        $this->assertTrue($writer->record($this->evaluation('flag.null-target', null)));
        $this->assertTrue($writer->record($this->evaluation('flag.empty-target', '')));
        $this->assertTrue($writer->flush());

        $exposures = $transport->payloads()[0]['exposures'];
        $this->assertSame('', $exposures[0]['subject']['id']);
        $this->assertSame('', $exposures[1]['subject']['id']);
    }

    public function testRecordSkipsMissingAllocationOrVariant()
    {
        $transport = new RecordingExposureTransport();
        $writer = new ExposureWriter($transport);

        $this->assertFalse($writer->record($this->evaluation('flag.missing-allocation', 'user-1', array(), true, null, 'on')));
        $this->assertFalse($writer->record($this->evaluation('flag.empty-allocation', 'user-1', array(), true, '', 'on')));
        $this->assertFalse($writer->record($this->evaluation('flag.missing-variant', 'user-1', array(), true, 'alloc', null)));
        $this->assertFalse($writer->record($this->evaluation('flag.empty-variant', 'user-1', array(), true, 'alloc', '')));

        $this->assertSame(0, $writer->bufferedCount());
        $this->assertTrue($writer->flush());
        $this->assertSame(array(), $transport->payloads());
    }

    public function testDeduplicatesByFlagAndSubjectUsingAllocationAndVariant()
    {
        $transport = new RecordingExposureTransport();
        $writer = new ExposureWriter($transport);

        $this->assertTrue($writer->record($this->evaluation('flag.dedupe', 'user-1', array(), true, 'alloc-a', 'on')));
        $this->assertFalse($writer->record($this->evaluation('flag.dedupe', 'user-1', array(), true, 'alloc-a', 'on')));
        $this->assertTrue($writer->record($this->evaluation('flag.dedupe', 'user-1', array(), true, 'alloc-a', 'off')));
        $this->assertTrue($writer->record($this->evaluation('flag.dedupe', 'user-1', array(), true, 'alloc-b', 'off')));
        $this->assertTrue($writer->record($this->evaluation('flag.dedupe', 'user-2', array(), true, 'alloc-b', 'off')));
        $this->assertTrue($writer->record($this->evaluation('flag.other', 'user-1', array(), true, 'alloc-b', 'off')));
        $this->assertTrue($writer->flush());

        $exposures = $transport->payloads()[0]['exposures'];
        $this->assertCount(5, $exposures);
        $this->assertSame('on', $exposures[0]['variant']['key']);
        $this->assertSame('off', $exposures[1]['variant']['key']);
        $this->assertSame('alloc-b', $exposures[2]['allocation']['key']);
        $this->assertSame('user-2', $exposures[3]['subject']['id']);
        $this->assertSame('flag.other', $exposures[4]['flag']['key']);
    }

    public function testCacheEvictsLeastRecentlyUsedEntry()
    {
        $transport = new RecordingExposureTransport();
        $writer = new ExposureWriter($transport, array(), 2);

        $this->assertTrue($writer->record($this->evaluation('flag.a', 'user-1')));
        $this->assertTrue($writer->record($this->evaluation('flag.b', 'user-1')));
        $this->assertFalse($writer->record($this->evaluation('flag.a', 'user-1')));
        $this->assertTrue($writer->record($this->evaluation('flag.c', 'user-1')));
        $this->assertTrue($writer->record($this->evaluation('flag.b', 'user-1')));
        $this->assertTrue($writer->flush());

        $exposures = $transport->payloads()[0]['exposures'];
        $this->assertCount(4, $exposures);
        $this->assertSame(array('flag.a', 'flag.b', 'flag.c', 'flag.b'), array(
            $exposures[0]['flag']['key'],
            $exposures[1]['flag']['key'],
            $exposures[2]['flag']['key'],
            $exposures[3]['flag']['key'],
        ));
    }

    public function testFullBufferDropsWithoutPoisoningDedupCache()
    {
        $transport = new RecordingExposureTransport();
        $writer = new ExposureWriter($transport, array(), 65536, 1);

        $this->assertTrue($writer->record($this->evaluation('flag.full', 'user-1', array(), true, 'alloc', 'first')));
        $this->assertFalse($writer->record($this->evaluation('flag.full', 'user-1', array(), true, 'alloc', 'second')));
        $this->assertSame(1, $writer->droppedCount());
        $this->assertTrue($writer->flush());

        $this->assertTrue($writer->record($this->evaluation('flag.full', 'user-1', array(), true, 'alloc', 'second')));
        $this->assertTrue($writer->flush());

        $payloads = $transport->payloads();
        $this->assertCount(2, $payloads);
        $this->assertSame('first', $payloads[0]['exposures'][0]['variant']['key']);
        $this->assertSame('second', $payloads[1]['exposures'][0]['variant']['key']);
    }

    public function testFailedTransportDropsFlushedEventsAndClearsBuffer()
    {
        $transport = new RecordingExposureTransport(false);
        $writer = new ExposureWriter($transport);

        $this->assertTrue($writer->record($this->evaluation('flag.one', 'user-1')));
        $this->assertTrue($writer->record($this->evaluation('flag.two', 'user-1')));

        $this->assertFalse($writer->flush());
        $this->assertSame(0, $writer->bufferedCount());
        $this->assertSame(2, $writer->droppedCount());
        $this->assertTrue($writer->flush());
        $this->assertCount(1, $transport->payloads());
    }

    public function testTransportExceptionDropsFlushedEventsAndDoesNotEscape()
    {
        $transport = new ThrowingExposureTransport();
        $writer = new ExposureWriter($transport);

        $this->assertTrue($writer->record($this->evaluation('flag.throwing', 'user-1')));

        $this->assertFalse($writer->flush());
        $this->assertSame(0, $writer->bufferedCount());
        $this->assertSame(1, $writer->droppedCount());
    }

    public function testExposureHookRecordsThroughWriter()
    {
        $transport = new RecordingExposureTransport();
        $writer = new ExposureWriter($transport);
        $hook = new ExposureHook($writer);

        $hook->evaluationCompleted($this->evaluation('flag.hook', 'user-1'));
        $this->assertSame(1, $writer->bufferedCount());
        $this->assertTrue($writer->flush());

        $this->assertSame('flag.hook', $transport->payloads()[0]['exposures'][0]['flag']['key']);
    }

    // The former `testAgentTransportBuildsAgentEvpRequest` covered the
    // raw-socket `AgentExposureTransport` HTTP request construction. That
    // transport is deleted in this PR — exposure delivery now goes through
    // the libdatadog sidecar via `SidecarExposureTransport`, which calls
    // `\DDTrace\send_ffe_exposures()` (a native FFI). HTTP request
    // construction is covered by `cargo test -p datadog-sidecar ffe_flusher`
    // on the libdatadog side (PR DataDog/libdatadog#2026); there is no
    // PHP-side HTTP construction to assert anymore.

    private function evaluation(
        $flagKey,
        $targetingKey,
        array $attributes = array(),
        $doLog = true,
        $allocationKey = 'allocation',
        $variant = 'on'
    ) {
        $exposureData = array('doLog' => $doLog);
        if ($allocationKey !== null) {
            $exposureData['allocationKey'] = $allocationKey;
        }

        return new EvaluationCompleted(
            $flagKey,
            EvaluationType::BOOLEAN,
            false,
            $targetingKey,
            $attributes,
            new EvaluationDetails(
                true,
                EvaluationType::BOOLEAN,
                EvaluationReason::SPLIT,
                $variant,
                null,
                null,
                array(),
                $exposureData
            )
        );
    }
}

final class RecordingExposureTransport implements ExposureTransport
{
    private $sent;
    private $payloads = array();

    public function __construct($sent = true)
    {
        $this->sent = $sent;
    }

    public function send(array $payload)
    {
        $this->payloads[] = $payload;

        return $this->sent;
    }

    public function payloads()
    {
        return $this->payloads;
    }
}

final class ThrowingExposureTransport implements ExposureTransport
{
    public function send(array $payload)
    {
        throw new \RuntimeException('transport failed');
    }
}
