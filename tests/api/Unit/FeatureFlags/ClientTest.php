<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\Client;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Testing\FakeEvaluator;
use DDTrace\FeatureFlags\UnavailableEvaluator;
use DDTrace\FeatureFlags\WarningEmitter;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testValueMethodsReturnEvaluatedValues()
    {
        $evaluator = new FakeEvaluator();
        $evaluator
            ->setSuccess('bool.flag', true)
            ->setSuccess('string.flag', 'blue')
            ->setSuccess('integer.flag', 42)
            ->setSuccess('float.flag', 3.5)
            ->setSuccess('object.flag', array('enabled' => true));

        $client = Client::create($evaluator, new RecordingWarningEmitter());

        $this->assertTrue($client->getBooleanValue('bool.flag', false));
        $this->assertSame('blue', $client->getStringValue('string.flag', 'red'));
        $this->assertSame(42, $client->getIntegerValue('integer.flag', 0));
        $this->assertSame(3.5, $client->getFloatValue('float.flag', 0.0));
        $this->assertSame(array('enabled' => true), $client->getObjectValue('object.flag', array()));
    }

    public function testDetailsMethodsExposeEvaluationDetails()
    {
        $evaluator = new FakeEvaluator();
        $evaluator->setSuccess(
            'checkout-redesign',
            true,
            EvaluationReason::SPLIT,
            'treatment',
            array('owner' => 'ffe'),
            array('allocationKey' => 'alloc-1'),
            array('hasConfig' => true)
        );

        $client = Client::create($evaluator, new RecordingWarningEmitter());

        $details = $client->getBooleanDetails('checkout-redesign', false);

        $this->assertTrue($details->getValue());
        $this->assertSame(EvaluationType::BOOLEAN, $details->getValueType());
        $this->assertSame(EvaluationReason::SPLIT, $details->getReason());
        $this->assertSame('treatment', $details->getVariant());
        $this->assertSame(array('owner' => 'ffe'), $details->getFlagMetadata());
        $this->assertSame(array('allocationKey' => 'alloc-1'), $details->getExposureData());
        $this->assertSame(array(
            'evaluator' => 'fake',
            'productionRuntime' => false,
            'hasConfig' => true,
        ), $details->getProviderState());
    }

    public function testContextNormalizesTargetingKeyAndPrimitiveAttributes()
    {
        $evaluator = new FakeEvaluator();
        $evaluator->setSuccess('flag.context', 'on');

        $client = Client::create($evaluator, new RecordingWarningEmitter());
        $client->getStringValue('flag.context', 'off', array(
            'targetingKey' => 123,
            'attributes' => array(
                'plan' => 'pro',
                'age' => 41,
                'rate' => 1.5,
                'beta' => true,
                'nested' => array('drop'),
                'null' => null,
                'object' => new \stdClass(),
            ),
        ));

        $calls = $evaluator->getCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('123', $calls[0]['targetingKey']);
        $this->assertSame(array(
            'plan' => 'pro',
            'age' => 41,
            'rate' => 1.5,
            'beta' => true,
        ), $calls[0]['attributes']);
    }

    public function testUnavailableRuntimeReturnsDefaultWithProviderNotReadyDetailsAndWarning()
    {
        $warnings = new RecordingWarningEmitter();
        $client = Client::create(null, $warnings);

        $value = $client->getBooleanValue('checkout-redesign', true);
        $details = $client->getStringDetails('checkout-copy', 'fallback');

        $this->assertTrue($value);
        $this->assertSame('fallback', $details->getValue());
        $this->assertSame(EvaluationReason::ERROR, $details->getReason());
        $this->assertSame(EvaluationErrorCode::PROVIDER_NOT_READY, $details->getErrorCode());
        $this->assertSame(UnavailableEvaluator::WARNING_MESSAGE, $details->getErrorMessage());
        $this->assertSame(array(
            'ready' => false,
            'productionRuntime' => false,
            'reason' => 'runtime_unavailable',
        ), $details->getProviderState());
        $this->assertSame(array(UnavailableEvaluator::WARNING_MESSAGE), $warnings->warnings());
    }

    public function testWarningIsEmittedOncePerClientNotOncePerEvaluation()
    {
        $warnings = new RecordingWarningEmitter();
        $client = Client::create(null, $warnings);

        $client->getBooleanValue('flag-1', false);
        $client->getBooleanValue('flag-2', false);
        $client->getStringDetails('flag-3', 'fallback');

        $this->assertCount(1, $warnings->warnings());
    }

    public function testFakeEvaluatorModeWarnsAndIsIdentifiable()
    {
        $warnings = new RecordingWarningEmitter();
        $evaluator = new FakeEvaluator();
        $evaluator->setSuccess('flag.fake', true);

        $client = Client::create($evaluator, $warnings);
        $details = $client->getBooleanDetails('flag.fake', false);

        $this->assertTrue($details->getValue());
        $this->assertSame(array(
            'evaluator' => 'fake',
            'productionRuntime' => false,
        ), $details->getProviderState());
        $this->assertCount(1, $warnings->warnings());
    }

    /**
     * @dataProvider invalidDefaultProvider
     */
    public function testTypedMethodsRejectInvalidDefaults($method, $defaultValue)
    {
        $client = Client::create(new FakeEvaluator(), new RecordingWarningEmitter());

        $this->expectException(\InvalidArgumentException::class);

        $client->$method('flag.invalid', $defaultValue);
    }

    public function invalidDefaultProvider()
    {
        return array(
            'boolean' => array('getBooleanDetails', 'false'),
            'string' => array('getStringDetails', false),
            'integer' => array('getIntegerDetails', 1.2),
            'float' => array('getFloatDetails', '1.2'),
        );
    }
}

final class RecordingWarningEmitter implements WarningEmitter
{
    private $warnings = array();

    public function warning($message)
    {
        $this->warnings[] = $message;
    }

    public function warnings()
    {
        return $this->warnings;
    }
}
