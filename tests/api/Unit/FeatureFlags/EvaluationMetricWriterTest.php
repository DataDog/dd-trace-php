<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\CompositeEvaluationCompletedHook;
use DDTrace\FeatureFlags\Internal\EvaluationCompleted;
use DDTrace\FeatureFlags\Internal\EvaluationCompletedHook;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricHook;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricTransport;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricWriter;
use DDTrace\FeatureFlags\Internal\Metric\OtlpMetricEncoder;
use PHPUnit\Framework\TestCase;

final class EvaluationMetricWriterTest extends TestCase
{
    public function testRecordsSuccessMetricAttributes()
    {
        $transport = new RecordingEvaluationMetricTransport();
        $writer = new EvaluationMetricWriter($transport, 'checkout-service');

        $this->assertTrue($writer->record($this->evaluation(
            'checkout.enabled',
            'treatment',
            EvaluationReason::SPLIT,
            null,
            'allocation-a'
        )));
        $this->assertTrue($writer->flush());

        $payloads = $transport->payloads();
        $this->assertCount(1, $payloads);
        $this->assertSame('checkout-service', $payloads[0]['serviceName']);
        $this->assertCount(1, $payloads[0]['points']);
        $this->assertSame(1, $payloads[0]['points'][0]['count']);
        $this->assertSame(array(
            'feature_flag.key' => 'checkout.enabled',
            'feature_flag.result.variant' => 'treatment',
            'feature_flag.result.reason' => 'split',
            'feature_flag.result.allocation_key' => 'allocation-a',
        ), $payloads[0]['points'][0]['attributes']);
    }

    public function testRecordsDefaultErrorMetricAttributes()
    {
        $transport = new RecordingEvaluationMetricTransport();
        $writer = new EvaluationMetricWriter($transport, 'checkout-service');

        $this->assertTrue($writer->record($this->evaluation(
            'missing.flag',
            null,
            EvaluationReason::ERROR,
            EvaluationErrorCode::PROVIDER_NOT_READY,
            null
        )));
        $this->assertTrue($writer->flush());

        $attributes = $transport->payloads()[0]['points'][0]['attributes'];
        $this->assertSame('missing.flag', $attributes['feature_flag.key']);
        $this->assertSame('', $attributes['feature_flag.result.variant']);
        $this->assertSame('error', $attributes['feature_flag.result.reason']);
        $this->assertSame('provider_not_ready', $attributes['error.type']);
        $this->assertFalse(isset($attributes['feature_flag.result.allocation_key']));
    }

    public function testAggregatesSameAttributeSetAndSeparatesDifferentVariants()
    {
        $transport = new RecordingEvaluationMetricTransport();
        $writer = new EvaluationMetricWriter($transport, 'checkout-service');

        $this->assertTrue($writer->record($this->evaluation('flag.metric', 'on')));
        $this->assertTrue($writer->record($this->evaluation('flag.metric', 'on')));
        $this->assertTrue($writer->record($this->evaluation('flag.metric', 'off')));
        $this->assertTrue($writer->flush());

        $points = $transport->payloads()[0]['points'];
        $this->assertCount(2, $points);
        $this->assertSame(2, $points[0]['count']);
        $this->assertSame('on', $points[0]['attributes']['feature_flag.result.variant']);
        $this->assertSame(1, $points[1]['count']);
        $this->assertSame('off', $points[1]['attributes']['feature_flag.result.variant']);
    }

    public function testSeriesOverflowAutoFlushesSoLongRunningRuntimesDoNotDrop()
    {
        // Simulates a long-running PHP runtime that records more unique
        // attribute-set keys than `seriesLimit` between worker exits. Without
        // an inline flush when the series cap is hit, every new key after
        // the first `seriesLimit` would be silently dropped for the worker's
        // lifetime — and that lifetime is hours/days in Swoole/RoadRunner.
        $transport = new RecordingEvaluationMetricTransport();
        $writer = new EvaluationMetricWriter($transport, 'checkout-service', 1);

        $this->assertTrue($writer->record($this->evaluation('flag.one', 'on')));
        // Second unique key hits the cap → inline flush of {flag.one:1} →
        // {flag.two:1} now buffered.
        $this->assertTrue($writer->record($this->evaluation('flag.two', 'on')));
        // Hitting flag.one again triggers another inline flush of {flag.two:1}
        // because flag.one is no longer in the series map after the previous flush.
        $this->assertTrue($writer->record($this->evaluation('flag.one', 'on')));
        $this->assertSame(0, $writer->droppedCount());
        $this->assertTrue($writer->flush());

        // Three flushes total: {flag.one:1}, {flag.two:1}, {flag.one:1}.
        $payloads = $transport->payloads();
        $this->assertCount(3, $payloads);
        $this->assertSame('flag.one', $payloads[0]['points'][0]['attributes']['feature_flag.key']);
        $this->assertSame(1, $payloads[0]['points'][0]['count']);
        $this->assertSame('flag.two', $payloads[1]['points'][0]['attributes']['feature_flag.key']);
        $this->assertSame(1, $payloads[1]['points'][0]['count']);
        $this->assertSame('flag.one', $payloads[2]['points'][0]['attributes']['feature_flag.key']);
        $this->assertSame(1, $payloads[2]['points'][0]['count']);
    }

    public function testFailedTransportDropsCountsAndClearsBuffer()
    {
        $transport = new RecordingEvaluationMetricTransport(false);
        $writer = new EvaluationMetricWriter($transport, 'checkout-service');

        $this->assertTrue($writer->record($this->evaluation('flag.one', 'on')));
        $this->assertTrue($writer->record($this->evaluation('flag.one', 'on')));
        $this->assertFalse($writer->flush());

        $this->assertSame(0, $writer->bufferedSeriesCount());
        $this->assertSame(2, $writer->droppedCount());
        $this->assertTrue($writer->flush());
        $this->assertCount(1, $transport->payloads());
    }

    public function testTransportExceptionDropsCountsAndDoesNotEscape()
    {
        $writer = new EvaluationMetricWriter(new ThrowingEvaluationMetricTransport(), 'checkout-service');

        $this->assertTrue($writer->record($this->evaluation('flag.throwing', 'on')));
        $this->assertFalse($writer->flush());
        $this->assertSame(1, $writer->droppedCount());
    }

    public function testMetricHookRecordsThroughWriter()
    {
        $transport = new RecordingEvaluationMetricTransport();
        $writer = new EvaluationMetricWriter($transport, 'checkout-service');
        $hook = new EvaluationMetricHook($writer);

        $hook->evaluationCompleted($this->evaluation('flag.hook', 'on'));
        $this->assertSame(1, $writer->bufferedSeriesCount());
        $this->assertTrue($writer->flush());

        $this->assertSame('flag.hook', $transport->payloads()[0]['points'][0]['attributes']['feature_flag.key']);
    }

    public function testCompositeHookContinuesAfterInnerHookFailure()
    {
        $recording = new MetricRecordingEvaluationCompletedHook();
        $hook = new CompositeEvaluationCompletedHook(array(
            new MetricThrowingEvaluationCompletedHook(),
            $recording,
        ));

        $hook->evaluationCompleted($this->evaluation('flag.composite', 'on'));

        $this->assertCount(1, $recording->evaluations());
        $this->assertSame('flag.composite', $recording->evaluations()[0]->getFlagKey());
    }

    public function testOtlpMetricEncoderIncludesExpectedMetricStrings()
    {
        $payload = OtlpMetricEncoder::encode('checkout-service', array(array(
            'attributes' => array(
                'feature_flag.key' => 'checkout.enabled',
                'feature_flag.result.variant' => 'treatment',
                'feature_flag.result.reason' => 'split',
                'feature_flag.result.allocation_key' => 'allocation-a',
            ),
            'count' => 3,
        )), 1000, 2000);

        foreach (array(
            'service.name',
            'checkout-service',
            OtlpMetricEncoder::METRIC_NAME,
            OtlpMetricEncoder::METRIC_UNIT,
            OtlpMetricEncoder::METRIC_DESCRIPTION,
            'feature_flag.key',
            'checkout.enabled',
            'feature_flag.result.variant',
            'treatment',
            'feature_flag.result.reason',
            'split',
            'feature_flag.result.allocation_key',
            'allocation-a',
        ) as $needle) {
            $this->assertTrue(strpos($payload, $needle) !== false, 'Missing protobuf string: ' . $needle);
        }
    }

    // The former `testOtlpTransportBuildsHttpProtobufRequest` covered the
    // raw-socket `OtlpHttpMetricTransport` HTTP request construction. That
    // transport is deleted in this PR — metric delivery now goes through
    // the libdatadog sidecar via `SidecarOtlpMetricsTransport`, which calls
    // `\DDTrace\send_ffe_metrics()` (a native FFI). HTTP request
    // construction is covered by `cargo test -p datadog-sidecar
    // ffe_metrics_flusher` on the libdatadog side
    // (DataDog/libdatadog#2026); there is no PHP-side HTTP construction
    // to assert anymore.

    private function evaluation(
        $flagKey,
        $variant,
        $reason = EvaluationReason::SPLIT,
        $errorCode = null,
        $allocationKey = 'allocation'
    ) {
        $exposureData = array();
        if ($allocationKey !== null) {
            $exposureData['allocationKey'] = $allocationKey;
        }

        return new EvaluationCompleted(
            $flagKey,
            EvaluationType::BOOLEAN,
            false,
            null,
            array(),
            new EvaluationDetails(
                $errorCode === null,
                EvaluationType::BOOLEAN,
                $reason,
                $variant,
                $errorCode,
                $errorCode === null ? null : 'evaluation failed',
                array(),
                $exposureData
            )
        );
    }
}

final class RecordingEvaluationMetricTransport implements EvaluationMetricTransport
{
    private $sent;
    private $payloads = array();

    public function __construct($sent = true)
    {
        $this->sent = $sent;
    }

    public function send($serviceName, array $points)
    {
        $this->payloads[] = array(
            'serviceName' => $serviceName,
            'points' => $points,
        );

        return $this->sent;
    }

    public function payloads()
    {
        return $this->payloads;
    }
}

final class ThrowingEvaluationMetricTransport implements EvaluationMetricTransport
{
    public function send($serviceName, array $points)
    {
        throw new \RuntimeException('metric transport failed');
    }
}

final class MetricRecordingEvaluationCompletedHook implements EvaluationCompletedHook
{
    private $evaluations = array();

    public function evaluationCompleted(EvaluationCompleted $evaluation)
    {
        $this->evaluations[] = $evaluation;
    }

    public function evaluations()
    {
        return $this->evaluations;
    }
}

final class MetricThrowingEvaluationCompletedHook implements EvaluationCompletedHook
{
    public function evaluationCompleted(EvaluationCompleted $evaluation)
    {
        throw new \RuntimeException('hook failed');
    }
}
