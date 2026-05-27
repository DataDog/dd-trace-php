<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\Client;
use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\Evaluator;
use DDTrace\FeatureFlags\Internal\NativeEvaluator;
use DDTrace\FeatureFlags\Internal\UnavailableEvaluator;
use DDTrace\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testCreateBuildsDefaultRemoteConfigBackedClient()
    {
        $this->assertInstanceOf(Client::class, Client::create());
    }

    public function testValueMethodsReturnEvaluatedValues()
    {
        $evaluator = new ClientTestEvaluator();
        $evaluator
            ->setSuccess('bool.flag', true)
            ->setSuccess('string.flag', 'blue')
            ->setSuccess('integer.flag', 42)
            ->setSuccess('float.flag', 3.5)
            ->setSuccess('object.flag', array('enabled' => true));

        $client = Client::createWithDependencies($evaluator, new RecordingLogger());

        $this->assertTrue($client->getBooleanValue('bool.flag', false));
        $this->assertSame('blue', $client->getStringValue('string.flag', 'red'));
        $this->assertSame(42, $client->getIntegerValue('integer.flag', 0));
        $this->assertSame(3.5, $client->getFloatValue('float.flag', 0.0));
        $this->assertSame(array('enabled' => true), $client->getObjectValue('object.flag', array()));
    }

    public function testDetailsMethodsExposeEvaluationDetails()
    {
        $evaluator = new ClientTestEvaluator();
        $evaluator->setSuccess(
            'checkout-redesign',
            true,
            EvaluationReason::SPLIT,
            'treatment',
            array('owner' => 'ffe'),
            array('allocationKey' => 'alloc-1'),
            array('runtime' => 'test', 'hasConfig' => true)
        );

        $client = Client::createWithDependencies($evaluator, new RecordingLogger());

        $details = $client->getBooleanDetails('checkout-redesign', false);

        $this->assertTrue($details->getValue());
        $this->assertSame(EvaluationType::BOOLEAN, $details->getValueType());
        $this->assertSame(EvaluationReason::SPLIT, $details->getReason());
        $this->assertSame('treatment', $details->getVariant());
        $this->assertSame(array('owner' => 'ffe'), $details->getFlagMetadata());
        $this->assertSame(array('allocationKey' => 'alloc-1'), $details->getExposureData());
        $this->assertSame(array('runtime' => 'test', 'hasConfig' => true), $details->getProviderState());
    }

    public function testContextNormalizesTargetingKeyAndPrimitiveAttributes()
    {
        $evaluator = new ClientTestEvaluator();
        $evaluator->setSuccess('flag.context', 'on');

        $client = Client::createWithDependencies($evaluator, new RecordingLogger());
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
        $logger = new RecordingLogger();
        $client = Client::createWithDependencies(null, $logger);

        $value = $client->getBooleanValue('checkout-redesign', true);
        $details = $client->getStringDetails('checkout-copy', 'fallback');

        $this->assertTrue($value);
        $this->assertSame('fallback', $details->getValue());
        $this->assertSame(EvaluationReason::ERROR, $details->getReason());
        $this->assertSame(EvaluationErrorCode::PROVIDER_NOT_READY, $details->getErrorCode());
        $this->assertContains($details->getErrorMessage(), array(
            NativeEvaluator::WARNING_MESSAGE,
            UnavailableEvaluator::WARNING_MESSAGE,
        ));

        $providerState = $details->getProviderState();
        $this->assertSame(false, $providerState['ready']);
        $this->assertSame(false, $providerState['productionRuntime']);
        $this->assertTrue(in_array($providerState['reason'], array(
            'configuration_missing',
            'runtime_unavailable',
        ), true));
        $this->assertSame(array($details->getErrorMessage()), $logger->warnings());
    }

    public function testWarningIsEmittedOncePerClientNotOncePerEvaluation()
    {
        $logger = new RecordingLogger();
        $client = Client::createWithDependencies(null, $logger);

        $client->getBooleanValue('flag-1', false);
        $client->getBooleanValue('flag-2', false);
        $client->getStringDetails('flag-3', 'fallback');

        $this->assertCount(1, $logger->warnings());
    }

    /**
     * @dataProvider invalidDefaultProvider
     */
    public function testTypedMethodsRejectInvalidDefaults($method, $defaultValue)
    {
        $client = Client::createWithDependencies(new ClientTestEvaluator(), new RecordingLogger());

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

final class ClientTestEvaluator implements Evaluator
{
    private $details = array();
    private $calls = array();

    public function setSuccess(
        $flagKey,
        $value,
        $reason = EvaluationReason::STATIC_REASON,
        $variant = null,
        array $metadata = array(),
        array $exposureData = array(),
        array $providerState = array()
    ) {
        $this->details[$flagKey] = new EvaluationDetails(
            $value,
            $this->typeForValue($value),
            $reason,
            $variant,
            null,
            null,
            $metadata,
            $exposureData,
            $providerState
        );

        return $this;
    }

    public function evaluate($flagKey, $expectedType, $defaultValue, $targetingKey = null, array $attributes = array())
    {
        $this->calls[] = array(
            'flagKey' => $flagKey,
            'targetingKey' => $targetingKey,
            'attributes' => $attributes,
        );

        if (array_key_exists($flagKey, $this->details)) {
            return $this->details[$flagKey];
        }

        return new EvaluationDetails(
            $defaultValue,
            $expectedType,
            EvaluationReason::ERROR,
            null,
            EvaluationErrorCode::PROVIDER_NOT_READY,
            UnavailableEvaluator::WARNING_MESSAGE,
            array(),
            array(),
            array('ready' => false, 'productionRuntime' => false, 'reason' => 'test_missing_result')
        );
    }

    public function getCalls()
    {
        return $this->calls;
    }

    private function typeForValue($value)
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
}

final class RecordingLogger implements LoggerInterface
{
    private $warnings = array();

    public function debug($message, array $context = array())
    {
    }

    public function warning($message, array $context = array())
    {
        $this->warnings[] = $message;
    }

    public function error($message, array $context = array())
    {
    }

    public function isLevelActive($level)
    {
        return true;
    }

    public function warnings()
    {
        return $this->warnings;
    }
}
