<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\BufferedExposureWriter;
use DDTrace\FeatureFlags\Client;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\NoopMetricsRecorder;
use DDTrace\FeatureFlags\Testing\FakeEvaluator;
use DDTrace\FeatureFlags\WarningEmitter;
use PHPUnit\Framework\TestCase;

final class ExposureWriterTest extends TestCase
{
    public function testClientWritesExposureEventFromEvaluationDetails()
    {
        $batches = array();
        $writer = new BufferedExposureWriter(function (array $batch) use (&$batches) {
            $batches[] = $batch;
        });

        $evaluator = new FakeEvaluator();
        $evaluator->setSuccess(
            'checkout-redesign',
            true,
            EvaluationReason::SPLIT,
            'treatment',
            array('owner' => 'ffe'),
            array('allocationKey' => 'alloc-1', 'doLog' => true)
        );

        $client = Client::create(
            $evaluator,
            new ExposureTestWarningEmitter(),
            $writer,
            new NoopMetricsRecorder()
        );

        $client->getBooleanValue('checkout-redesign', false, array(
            'targetingKey' => 'user-123',
            'attributes' => array('plan' => 'pro'),
        ));
        $writer->flush();

        $this->assertCount(1, $batches);
        $this->assertCount(1, $batches[0]);
        $this->assertSame(array(
            'flagKey' => 'checkout-redesign',
            'targetingKey' => 'user-123',
            'attributes' => array('plan' => 'pro'),
            'value' => true,
            'valueType' => 'boolean',
            'reason' => EvaluationReason::SPLIT,
            'variant' => 'treatment',
            'flagMetadata' => array('owner' => 'ffe'),
            'exposureData' => array('allocationKey' => 'alloc-1', 'doLog' => true),
            'allocationKey' => 'alloc-1',
            'doLog' => true,
        ), $batches[0][0]);
    }

    public function testClientSkipsExposureWhenDoLogFalseOrEvaluationErrors()
    {
        $batches = array();
        $writer = new BufferedExposureWriter(function (array $batch) use (&$batches) {
            $batches[] = $batch;
        });

        $evaluator = new FakeEvaluator();
        $evaluator
            ->setSuccess(
                'do-not-log',
                true,
                EvaluationReason::SPLIT,
                'control',
                array(),
                array('allocationKey' => 'alloc-1', 'doLog' => false)
            )
            ->setProviderNotReady('provider-not-ready');

        $client = Client::create(
            $evaluator,
            new ExposureTestWarningEmitter(),
            $writer,
            new NoopMetricsRecorder()
        );

        $client->getBooleanValue('do-not-log', false);
        $client->getBooleanValue('provider-not-ready', false);
        $writer->flush();

        $this->assertSame(array(), $batches);
    }

    public function testBufferedWriterSuppressesDuplicatesAndReemitsChangedAssignments()
    {
        $batches = array();
        $writer = new BufferedExposureWriter(function (array $batch) use (&$batches) {
            $batches[] = $batch;
        });

        $writer->write($this->event('checkout', 'user-1', 'alloc-1', 'control'));
        $writer->write($this->event('checkout', 'user-1', 'alloc-1', 'control'));
        $writer->write($this->event('checkout', 'user-1', 'alloc-1', 'treatment'));
        $writer->write($this->event('checkout', 'user-1', 'alloc-2', 'treatment'));
        $writer->flush();

        $this->assertCount(1, $batches);
        $this->assertSame(array('control', 'treatment', 'treatment'), array(
            $batches[0][0]['variant'],
            $batches[0][1]['variant'],
            $batches[0][2]['variant'],
        ));
        $this->assertSame('alloc-2', $batches[0][2]['allocationKey']);
    }

    public function testBufferedWriterFlushesAtBatchCapAndEmptyFlushIsNoop()
    {
        $batches = array();
        $writer = new BufferedExposureWriter(function (array $batch) use (&$batches) {
            $batches[] = $batch;
        }, 2);

        $writer->write($this->event('flag-a', 'user-1', 'alloc-1', 'control'));
        $this->assertSame(1, $writer->getBufferedCount());
        $writer->write($this->event('flag-b', 'user-2', 'alloc-1', 'control'));

        $this->assertSame(0, $writer->getBufferedCount());
        $this->assertCount(1, $batches);
        $this->assertCount(2, $batches[0]);

        $writer->flush();
        $this->assertCount(1, $batches);
    }

    private function event($flagKey, $targetingKey, $allocationKey, $variant)
    {
        return array(
            'flagKey' => $flagKey,
            'targetingKey' => $targetingKey,
            'allocationKey' => $allocationKey,
            'variant' => $variant,
            'doLog' => true,
        );
    }
}

final class ExposureTestWarningEmitter implements WarningEmitter
{
    public function warning($message)
    {
    }
}
