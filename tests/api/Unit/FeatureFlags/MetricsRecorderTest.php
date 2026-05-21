<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\CallableMetricsRecorder;
use DDTrace\FeatureFlags\Client;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\NoopExposureWriter;
use DDTrace\FeatureFlags\Testing\FakeEvaluator;
use DDTrace\FeatureFlags\UnavailableEvaluator;
use DDTrace\FeatureFlags\WarningEmitter;
use PHPUnit\Framework\TestCase;

final class MetricsRecorderTest extends TestCase
{
    public function testCallableMetricsRecorderUsesFeatureFlagEvaluationAttributes()
    {
        $metrics = array();
        $recorder = new CallableMetricsRecorder(function (array $metric) use (&$metrics) {
            $metrics[] = $metric;
        }, true);

        $recorder->recordEvaluation('checkout-redesign', 'BOOLEAN', EvaluationReason::SPLIT);

        $this->assertSame(array(array(
            'name' => CallableMetricsRecorder::METRIC_NAME,
            'attributes' => array(
                'feature_flag.key' => 'checkout-redesign',
                'feature_flag.result.reason' => EvaluationReason::SPLIT,
                'feature_flag.result.value_type' => 'BOOLEAN',
                'feature_flag.error.code' => 'none',
            ),
        )), $metrics);
    }

    public function testMetricsRecorderCanBeGatedByEnvironment()
    {
        $previous = getenv('DD_METRICS_OTEL_ENABLED');
        $metrics = array();

        try {
            putenv('DD_METRICS_OTEL_ENABLED=false');
            $disabled = CallableMetricsRecorder::createFromEnvironment(function (array $metric) use (&$metrics) {
                $metrics[] = $metric;
            });
            $disabled->recordEvaluation('checkout-redesign', 'BOOLEAN', EvaluationReason::SPLIT);
            $this->assertSame(array(), $metrics);

            putenv('DD_METRICS_OTEL_ENABLED=true');
            $enabled = CallableMetricsRecorder::createFromEnvironment(function (array $metric) use (&$metrics) {
                $metrics[] = $metric;
            });
            $enabled->recordEvaluation('checkout-redesign', 'BOOLEAN', EvaluationReason::SPLIT);
            $this->assertCount(1, $metrics);
        } finally {
            if ($previous === false) {
                putenv('DD_METRICS_OTEL_ENABLED');
            } else {
                putenv('DD_METRICS_OTEL_ENABLED=' . $previous);
            }
        }
    }

    public function testClientRecordsMetricsOncePerEvaluationForSuccessAndErrors()
    {
        $metrics = array();
        $recorder = new CallableMetricsRecorder(function (array $metric) use (&$metrics) {
            $metrics[] = $metric;
        }, true);

        $evaluator = new FakeEvaluator();
        $evaluator
            ->setSuccess('success.flag', true)
            ->setTypeMismatch('type-mismatch.flag');

        $client = Client::create(
            $evaluator,
            new MetricsTestWarningEmitter(),
            new NoopExposureWriter(),
            $recorder
        );

        $client->getBooleanValue('success.flag', false);
        $client->getBooleanValue('type-mismatch.flag', false);

        $this->assertCount(2, $metrics);
        $this->assertSame('none', $metrics[0]['attributes']['feature_flag.error.code']);
        $this->assertSame(
            EvaluationErrorCode::TYPE_MISMATCH,
            $metrics[1]['attributes']['feature_flag.error.code']
        );
    }

    public function testProviderNotReadyMetricsUseErrorCode()
    {
        $metrics = array();
        $recorder = new CallableMetricsRecorder(function (array $metric) use (&$metrics) {
            $metrics[] = $metric;
        }, true);

        $client = Client::create(
            new UnavailableEvaluator(),
            new MetricsTestWarningEmitter(),
            new NoopExposureWriter(),
            $recorder
        );

        $client->getBooleanValue('not-ready.flag', false);

        $this->assertSame(
            EvaluationErrorCode::PROVIDER_NOT_READY,
            $metrics[0]['attributes']['feature_flag.error.code']
        );
    }

    public function testRootComposerDoesNotRequireOpenTelemetrySdk()
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../../../composer.json'), true);

        $this->assertArrayNotHasKey('open-telemetry/sdk', isset($composer['require']) ? $composer['require'] : array());
    }
}

final class MetricsTestWarningEmitter implements WarningEmitter
{
    public function warning($message)
    {
    }
}
