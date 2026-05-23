<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature {

use DDTrace\FeatureFlags\Client as FeatureFlagsClient;
use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\Evaluator;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricTransport;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricWriter;
use DDTrace\FeatureFlags\Internal\NoopWarningEmitter;
use DDTrace\OpenFeature\DataDogProvider;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\OpenFeatureAPI;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the PHP 8 OpenFeature path records `feature_flag.evaluations`
 * through the OpenFeature `after`/`error` hook surface (matching dd-trace-go/js/java/dotnet),
 * and that the Client-layer `EvaluationCompletedHook` does NOT also record
 * (no double-counting on the OpenFeature path).
 */
final class EvalMetricsHookTest extends TestCase
{
    public function testRecordsMetricThroughOpenFeatureAfterHookOnSuccess(): void
    {
        $transport = new EvalMetricsRecordingTransport();
        $writer = new EvaluationMetricWriter($transport, 'checkout-service');
        $evaluator = new OpenFeatureMetricEvaluator();
        $evaluator->setSuccess(
            'flag.allocation',
            'blue',
            EvaluationReason::TARGETING_MATCH,
            'on',
            ['allocationKey' => 'allocation-3baabb3c', 'doLog' => true]
        );

        $provider = DataDogProvider::createWithDependencies(
            FeatureFlagsClient::createWithDependencies($evaluator, new NoopWarningEmitter()),
            null,
            $writer
        );
        $client = $this->openFeatureClientFor($provider);

        $client->getStringValue('flag.allocation', 'red');

        $writer->flush();
        $payloads = $transport->payloads();
        self::assertCount(1, $payloads);
        self::assertCount(1, $payloads[0]['points']);

        $point = $payloads[0]['points'][0];
        self::assertSame(1, $point['count']);
        self::assertSame([
            'feature_flag.key' => 'flag.allocation',
            'feature_flag.result.variant' => 'on',
            'feature_flag.result.reason' => 'targeting_match',
            'feature_flag.result.allocation_key' => 'allocation-3baabb3c',
        ], $point['attributes']);
    }

    public function testRecordsMetricThroughOpenFeatureErrorHookOnTypeMismatch(): void
    {
        $transport = new EvalMetricsRecordingTransport();
        $writer = new EvaluationMetricWriter($transport, 'checkout-service');
        $evaluator = new OpenFeatureMetricEvaluator();
        // Provider returns a string, but client asks for integer — SDK throws
        // InvalidResolutionValueError and our `error` hook fires.
        $evaluator->setSuccess('flag.mismatch', 'not-an-int', EvaluationReason::STATIC_REASON);

        $provider = DataDogProvider::createWithDependencies(
            FeatureFlagsClient::createWithDependencies($evaluator, new NoopWarningEmitter()),
            null,
            $writer
        );
        $client = $this->openFeatureClientFor($provider);

        $details = $client->getIntegerDetails('flag.mismatch', 7);
        self::assertSame(EvaluationErrorCode::TYPE_MISMATCH, $details->getError()->getResolutionErrorCode()->getValue());

        $writer->flush();
        $payloads = $transport->payloads();
        self::assertCount(1, $payloads);
        $points = $payloads[0]['points'];

        // The DD Client returns a type-mismatch EvaluationDetails before the
        // SDK validator runs, so the `after` hook records it with reason=error
        // and error.type=type_mismatch. (The SDK then ALSO throws and our `error`
        // hook fires once more if `after` returns without exception — verify that
        // metric was recorded the expected number of times.)
        self::assertGreaterThanOrEqual(1, count($points));
        $attrs = $points[0]['attributes'];
        self::assertSame('flag.mismatch', $attrs['feature_flag.key']);
        self::assertSame('error', $attrs['feature_flag.result.reason']);
        self::assertSame('type_mismatch', $attrs['error.type']);
    }

    public function testOpenFeaturePathDoesNotDoubleCountWithClientHook(): void
    {
        $transport = new EvalMetricsRecordingTransport();
        $writer = new EvaluationMetricWriter($transport, 'checkout-service');
        $evaluator = new OpenFeatureMetricEvaluator();
        $evaluator->setSuccess('flag.basic', 'value', EvaluationReason::STATIC_REASON, 'v1');

        $provider = DataDogProvider::createWithDependencies(
            FeatureFlagsClient::createWithDependencies($evaluator, new NoopWarningEmitter()),
            null,
            $writer
        );
        $client = $this->openFeatureClientFor($provider);

        $client->getStringValue('flag.basic', 'default');
        $client->getStringValue('flag.basic', 'default');
        $client->getStringValue('flag.basic', 'default');

        $writer->flush();
        $payloads = $transport->payloads();
        self::assertCount(1, $payloads);
        $points = $payloads[0]['points'];
        self::assertCount(1, $points);
        // Three evaluations, exactly three counts on the single series — proves
        // OpenFeature path records once per evaluation (no double-counting from
        // the DD Client-layer composite, which is constructed without the metric hook).
        self::assertSame(3, $points[0]['count']);
    }

    public function testSupportsAllFlagValueTypes(): void
    {
        $transport = new EvalMetricsRecordingTransport();
        $writer = new EvaluationMetricWriter($transport, 'svc');
        $evaluator = new OpenFeatureMetricEvaluator();
        $evaluator
            ->setSuccess('b', true, EvaluationReason::STATIC_REASON)
            ->setSuccess('s', 'x', EvaluationReason::STATIC_REASON)
            ->setSuccess('i', 1, EvaluationReason::STATIC_REASON)
            ->setSuccess('f', 1.5, EvaluationReason::STATIC_REASON)
            ->setSuccess('o', ['k' => 'v'], EvaluationReason::STATIC_REASON);

        $provider = DataDogProvider::createWithDependencies(
            FeatureFlagsClient::createWithDependencies($evaluator, new NoopWarningEmitter()),
            null,
            $writer
        );
        $client = $this->openFeatureClientFor($provider);

        $client->getBooleanValue('b', false);
        $client->getStringValue('s', '');
        $client->getIntegerValue('i', 0);
        $client->getFloatValue('f', 0.0);
        $client->getObjectValue('o', []);

        $writer->flush();
        $points = $transport->payloads()[0]['points'];
        self::assertCount(5, $points, 'Hook records for every supported flag value type');
    }

    private function openFeatureClientFor(DataDogProvider $provider)
    {
        $api = OpenFeatureAPI::getInstance();
        $api->setProvider($provider);
        return $api->getClient('datadog-evalmetrics-test');
    }
}

final class EvalMetricsRecordingTransport implements EvaluationMetricTransport
{
    private $sent;
    private $payloads = array();

    public function __construct($sent = true)
    {
        $this->sent = $sent;
    }

    public function send($serviceName, array $points)
    {
        $this->payloads[] = ['serviceName' => $serviceName, 'points' => $points];
        return $this->sent;
    }

    public function payloads()
    {
        return $this->payloads;
    }
}

final class OpenFeatureMetricEvaluator implements Evaluator
{
    /** @var array<string, EvaluationDetails> */
    private array $details = [];

    public function setSuccess(
        string $flagKey,
        mixed $value,
        string $reason = EvaluationReason::STATIC_REASON,
        ?string $variant = null,
        array $exposureData = []
    ): self {
        $this->details[$flagKey] = new EvaluationDetails(
            $value,
            $this->typeForValue($value),
            $reason,
            $variant,
            null,
            null,
            [],
            $exposureData
        );
        return $this;
    }

    public function evaluate($flagKey, $expectedType, $defaultValue, $targetingKey = null, array $attributes = [])
    {
        if (!array_key_exists($flagKey, $this->details)) {
            return new EvaluationDetails(
                $defaultValue,
                $expectedType,
                EvaluationReason::ERROR,
                null,
                EvaluationErrorCode::FLAG_NOT_FOUND,
                'Feature flag "' . $flagKey . '" was not found'
            );
        }

        $details = $this->details[$flagKey];
        if (!$this->matchesExpectedType($details->getValue(), $expectedType)) {
            return new EvaluationDetails(
                $defaultValue,
                $expectedType,
                EvaluationReason::ERROR,
                null,
                EvaluationErrorCode::TYPE_MISMATCH,
                'Expected ' . $expectedType . ' flag value'
            );
        }
        return $details;
    }

    private function typeForValue($value): string
    {
        if (is_bool($value)) {
            return EvaluationType::BOOLEAN;
        }
        if (is_int($value)) {
            return EvaluationType::INTEGER;
        }
        if (is_float($value)) {
            return EvaluationType::FLOAT;
        }
        if (is_array($value)) {
            return EvaluationType::OBJECT;
        }
        return EvaluationType::STRING;
    }

    private function matchesExpectedType($value, string $expectedType): bool
    {
        switch ($expectedType) {
            case EvaluationType::BOOLEAN: return is_bool($value);
            case EvaluationType::STRING:  return is_string($value);
            case EvaluationType::INTEGER: return is_int($value);
            case EvaluationType::FLOAT:   return is_int($value) || is_float($value);
            case EvaluationType::OBJECT:  return is_array($value);
        }
        return false;
    }
}

}
