<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\Client;
use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Evaluator;
use DDTrace\FeatureFlags\ExposureWriter;
use DDTrace\FeatureFlags\NoopMetricsRecorder;
use DDTrace\FeatureFlags\UnavailableEvaluator;
use DDTrace\FeatureFlags\WarningEmitter;
use PHPUnit\Framework\TestCase;

final class ExposureWriterTest extends TestCase
{
    public function testClientWritesExposureEventFromEvaluationDetails()
    {
        $writer = new RecordingExposureWriter();

        $evaluator = new ExposureTestEvaluator();
        $evaluator->setDetails('checkout-redesign', new EvaluationDetails(
            true,
            EvaluationType::BOOLEAN,
            EvaluationReason::SPLIT,
            'treatment',
            null,
            null,
            array('owner' => 'ffe'),
            array('allocationKey' => 'alloc-1', 'doLog' => true)
        ));

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

        $this->assertSame(array(array(
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
        )), $writer->events());
    }

    public function testClientSkipsExposureWhenDoLogFalseOrEvaluationErrors()
    {
        $writer = new RecordingExposureWriter();

        $evaluator = new ExposureTestEvaluator();
        $evaluator
            ->setDetails('do-not-log', new EvaluationDetails(
                true,
                EvaluationType::BOOLEAN,
                EvaluationReason::SPLIT,
                'control',
                null,
                null,
                array(),
                array('allocationKey' => 'alloc-1', 'doLog' => false)
            ))
            ->setDetails('provider-not-ready', new EvaluationDetails(
                false,
                EvaluationType::BOOLEAN,
                EvaluationReason::ERROR,
                null,
                EvaluationErrorCode::PROVIDER_NOT_READY,
                UnavailableEvaluator::WARNING_MESSAGE,
                array(),
                array(),
                array('ready' => false, 'productionRuntime' => false)
            ));

        $client = Client::create(
            $evaluator,
            new ExposureTestWarningEmitter(),
            $writer,
            new NoopMetricsRecorder()
        );

        $client->getBooleanValue('do-not-log', false);
        $client->getBooleanValue('provider-not-ready', false);

        $this->assertSame(array(), $writer->events());
    }
}

final class RecordingExposureWriter implements ExposureWriter
{
    private $events = array();

    public function write(array $event)
    {
        $this->events[] = $event;
    }

    public function flush()
    {
    }

    public function events()
    {
        return $this->events;
    }
}

final class ExposureTestEvaluator implements Evaluator
{
    private $details = array();

    public function setDetails($flagKey, EvaluationDetails $details)
    {
        $this->details[$flagKey] = $details;
        return $this;
    }

    public function evaluate($flagKey, $expectedType, $defaultValue, $targetingKey = null, array $attributes = array())
    {
        return array_key_exists($flagKey, $this->details)
            ? $this->details[$flagKey]
            : new EvaluationDetails($defaultValue, $expectedType, EvaluationReason::ERROR);
    }
}

final class ExposureTestWarningEmitter implements WarningEmitter
{
    public function warning($message)
    {
    }
}
