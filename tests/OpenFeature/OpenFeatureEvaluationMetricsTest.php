<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature {

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\Evaluator;
use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetric;
use DDTrace\OpenFeature\DataDogProvider;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\OpenFeatureAPI;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the PHP 8 OpenFeature path records `feature_flag.evaluations` from
 * provider-owned EvaluationDetails. Native metric recording is disabled for the
 * OpenFeature evaluator path, so the provider records exactly once per evaluation.
 */
final class OpenFeatureEvaluationMetricsTest extends TestCase
{
    public function testRecordsMetricThroughProviderOnSuccess(): void
    {
        $recorder = new EvalMetricsRecordingRecorder();
        $evaluator = new OpenFeatureMetricEvaluator();
        $evaluator->setSuccess(
            'flag.allocation',
            'blue',
            EvaluationReason::TARGETING_MATCH,
            'on',
            ['allocationKey' => 'allocation-3baabb3c', 'doLog' => true]
        );

        $provider = DataDogProvider::createWithDependencies(
            $evaluator,
            null,
            $recorder
        );
        $client = $this->openFeatureClientFor($provider);

        $client->getStringValue('flag.allocation', 'red');

        $calls = $recorder->calls();
        self::assertCount(1, $calls);
        self::assertSame([
            'flagKey' => 'flag.allocation',
            'variant' => 'on',
            'reason' => EvaluationReason::TARGETING_MATCH,
            'errorCode' => null,
            'allocationKey' => 'allocation-3baabb3c',
        ], $calls[0]);
    }

    public function testRecordsMetricThroughProviderOnTypeMismatch(): void
    {
        $recorder = new EvalMetricsRecordingRecorder();
        $evaluator = new OpenFeatureMetricEvaluator();
        // Provider resolves a type mismatch before returning to the OpenFeature
        // SDK, so no separate PHP OpenFeature hook is needed for this case.
        $evaluator->setSuccess('flag.mismatch', 'not-an-int', EvaluationReason::STATIC_REASON);

        $provider = DataDogProvider::createWithDependencies(
            $evaluator,
            null,
            $recorder
        );
        $client = $this->openFeatureClientFor($provider);

        $details = $client->getIntegerDetails('flag.mismatch', 7);
        self::assertSame(EvaluationErrorCode::TYPE_MISMATCH, $details->getError()->getResolutionErrorCode()->getValue());

        $calls = $recorder->calls();

        self::assertCount(1, $calls);
        self::assertSame('flag.mismatch', $calls[0]['flagKey']);
        self::assertSame(EvaluationReason::ERROR, $calls[0]['reason']);
        self::assertSame(EvaluationErrorCode::TYPE_MISMATCH, $calls[0]['errorCode']);
    }

    public function testOpenFeaturePathRecordsOncePerEvaluation(): void
    {
        $recorder = new EvalMetricsRecordingRecorder();
        $evaluator = new OpenFeatureMetricEvaluator();
        $evaluator->setSuccess('flag.basic', 'value', EvaluationReason::STATIC_REASON, 'v1');

        $provider = DataDogProvider::createWithDependencies(
            $evaluator,
            null,
            $recorder
        );
        $client = $this->openFeatureClientFor($provider);

        $client->getStringValue('flag.basic', 'default');
        $client->getStringValue('flag.basic', 'default');
        $client->getStringValue('flag.basic', 'default');

        // Three evaluations, exactly three recorder calls. Aggregation happens
        // natively in the sidecar, not in PHP.
        self::assertCount(3, $recorder->calls());
    }

    public function testSupportsAllFlagValueTypes(): void
    {
        $recorder = new EvalMetricsRecordingRecorder();
        $evaluator = new OpenFeatureMetricEvaluator();
        $evaluator
            ->setSuccess('b', true, EvaluationReason::STATIC_REASON)
            ->setSuccess('s', 'x', EvaluationReason::STATIC_REASON)
            ->setSuccess('i', 1, EvaluationReason::STATIC_REASON)
            ->setSuccess('f', 1.5, EvaluationReason::STATIC_REASON)
            ->setSuccess('o', ['k' => 'v'], EvaluationReason::STATIC_REASON);

        $provider = DataDogProvider::createWithDependencies(
            $evaluator,
            null,
            $recorder
        );
        $client = $this->openFeatureClientFor($provider);

        $client->getBooleanValue('b', false);
        $client->getStringValue('s', '');
        $client->getIntegerValue('i', 0);
        $client->getFloatValue('f', 0.0);
        $client->getObjectValue('o', []);

        self::assertCount(5, $recorder->calls(), 'Recorder records for every supported flag value type');
    }

    private function openFeatureClientFor(DataDogProvider $provider)
    {
        $api = OpenFeatureAPI::getInstance();
        $api->setProvider($provider);
        return $api->getClient('datadog-evalmetrics-test');
    }
}

final class EvalMetricsRecordingRecorder
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
