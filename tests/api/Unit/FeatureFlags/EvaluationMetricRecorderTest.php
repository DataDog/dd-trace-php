<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetric;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricRecorder;
use PHPUnit\Framework\TestCase;

final class EvaluationMetricRecorderTest extends TestCase
{
    public function testRecorderRecordsThroughCallable()
    {
        $recorder = new RecordingEvaluationMetricRecorder();
        $metricRecorder = new EvaluationMetricRecorder($recorder);

        $this->assertTrue($metricRecorder->record(EvaluationMetric::create(
            'checkout.enabled',
            'treatment',
            EvaluationReason::SPLIT,
            null,
            'allocation-a'
        )));

        $this->assertSame(array(array(
            'flagKey' => 'checkout.enabled',
            'variant' => 'treatment',
            'reason' => EvaluationReason::SPLIT,
            'errorCode' => null,
            'allocationKey' => 'allocation-a',
        )), $recorder->calls());
    }

    public function testRecorderNoopsWithoutCallable()
    {
        $metricRecorder = new EvaluationMetricRecorder(null);

        $this->assertFalse($metricRecorder->record(EvaluationMetric::create('flag.noop')));
    }

    public function testRecorderExceptionDoesNotEscape()
    {
        $metricRecorder = new EvaluationMetricRecorder(new ThrowingEvaluationMetricRecorder());

        $this->assertFalse($metricRecorder->record(EvaluationMetric::create(
            'flag.throwing',
            'on',
            EvaluationReason::SPLIT
        )));
    }
}

final class RecordingEvaluationMetricRecorder
{
    private $calls = array();

    public function __invoke(EvaluationMetric $metric)
    {
        $this->calls[] = array(
            'flagKey' => $metric->getFlagKey(),
            'variant' => $metric->getVariant(),
            'reason' => $metric->getReason(),
            'errorCode' => $metric->getErrorCode(),
            'allocationKey' => $metric->getAllocationKey(),
        );

        return true;
    }

    public function calls()
    {
        return $this->calls;
    }
}

final class ThrowingEvaluationMetricRecorder
{
    public function __invoke()
    {
        throw new \RuntimeException('metric recorder failed');
    }
}
