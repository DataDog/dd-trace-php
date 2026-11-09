<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\Client;
use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\Evaluator;
use DDTrace\FeatureFlags\Internal\UnavailableEvaluator;
use DDTrace\Log\ErrorLogLogger;
use DDTrace\Log\LoggerInterface;
use DDTrace\Log\LogLevel;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testValueMethodsReturnEvaluatedValues()
    {
        $evaluator = new ClientTestEvaluator();
        $evaluator
            ->setSuccess('bool.flag', true)
            ->setSuccess('string.flag', 'blue')
            ->setSuccess('integer.flag', 42)
            ->setSuccess('float.flag', 3.5)
            ->setSuccess('object.flag', array('enabled' => true));

        $client = $this->clientForEvaluator($evaluator, new RecordingLogger());

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

        $client = $this->clientForEvaluator($evaluator, new RecordingLogger());

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

        $client = $this->clientForEvaluator($evaluator, new RecordingLogger());
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
        $client = $this->clientForEvaluator(new UnavailableEvaluator(), $logger);

        $value = $client->getBooleanValue('checkout-redesign', true);
        $details = $client->getStringDetails('checkout-copy', 'fallback');

        $this->assertTrue($value);
        $this->assertSame('fallback', $details->getValue());
        $this->assertSame(EvaluationReason::ERROR, $details->getReason());
        $this->assertSame(EvaluationErrorCode::PROVIDER_NOT_READY, $details->getErrorCode());
        $this->assertSame(UnavailableEvaluator::WARNING_MESSAGE, $details->getErrorMessage());

        $providerState = $details->getProviderState();
        $this->assertSame(false, $providerState['ready']);
        $this->assertSame(false, $providerState['productionRuntime']);
        $this->assertSame('runtime_unavailable', $providerState['reason']);
        $this->assertSame(array($details->getErrorMessage()), $logger->warnings());
    }

    public function testWarningIsEmittedOncePerClientNotOncePerEvaluation()
    {
        $logger = new RecordingLogger();
        $client = $this->clientForEvaluator(new ClientTestEvaluator(), $logger);

        $client->getBooleanValue('flag-1', false);
        $client->getBooleanValue('flag-2', false);
        $client->getStringDetails('flag-3', 'fallback');

        $this->assertCount(1, $logger->warnings());
    }

    public function testThrowingLoggerDoesNotChangeProviderNotReadyEvaluation()
    {
        $evaluator = new ClientTestEvaluator();
        $logger = new ThrowingLogger();
        $client = $this->clientForEvaluator($evaluator, $logger);

        $firstDetails = $client->getBooleanDetails('preview.flag', false);
        $secondDetails = $client->getBooleanDetails('preview.flag', false);

        $this->assertFalse($firstDetails->getValue());
        $this->assertSame(EvaluationReason::ERROR, $firstDetails->getReason());
        $this->assertSame(EvaluationErrorCode::PROVIDER_NOT_READY, $firstDetails->getErrorCode());
        $this->assertFalse($secondDetails->getValue());
        $this->assertSame(1, $logger->warningCount());
    }

    public function testDebugLogsRawEvaluationInputsAndReturnedValue()
    {
        $evaluator = new ClientTestEvaluator();
        $evaluator->setSuccess(
            'checkout/redesign',
            1.0,
            EvaluationReason::SPLIT,
            'treatment',
            array('owner' => 'ffe'),
            array('allocationKey' => 'alloc-1'),
            array('configVersion' => 3)
        );
        $logger = new RecordingLogger();
        $client = $this->clientForEvaluator($evaluator, $logger);

        $client->getFloatValue('checkout/redesign', 0, array(
            'targetingKey' => 'user-1',
            'attributes' => array('email' => 'user@example.com', 'nested' => array('drop')),
        ));
        $client->getStringValue('missing', 'fallback');

        $this->assertSame(array(
            'Feature Flags: evaluated {"flagKey":"checkout/redesign","valueType":"float","targetingKey":"user-1",'
                . '"attributes":{"email":"user@example.com"},"defaultValue":0.0,"value":1.0,"variant":"treatment",'
                . '"reason":"SPLIT","errorCode":null,"errorMessage":null,"flagMetadata":{"owner":"ffe"},"configVersion":3}',
            'Feature Flags: evaluated {"flagKey":"missing","valueType":"string","targetingKey":null,"attributes":{},'
                . '"defaultValue":"fallback","value":"fallback","variant":null,"reason":"ERROR",'
                . '"errorCode":"PROVIDER_NOT_READY","errorMessage":"' . UnavailableEvaluator::WARNING_MESSAGE . '",'
                . '"flagMetadata":{},"configVersion":null}',
        ), $logger->debugs());
    }

    public function testDebugLogIsSkippedWhenDebugIsInactive()
    {
        $evaluator = new ClientTestEvaluator();
        $evaluator->setSuccess('flag', true);
        $logger = new RecordingLogger(false);
        $client = $this->clientForEvaluator($evaluator, $logger);

        $this->assertTrue($client->getBooleanValue('flag', false));
        $this->assertSame(array(), $logger->debugs());
    }

    public function testDebugLogFormattingFailureIsReportedWithoutChangingTheResult()
    {
        $logger = new RecordingLogger();
        $client = $this->clientForEvaluator(new ClientTestEvaluator(), $logger);
        $default = array('item' => new ThrowingJsonValue());

        $this->assertSame($default, $client->getObjectValue('object.flag', $default));
        $this->assertSame(array(
            'Feature Flags: evaluated {"flagKey":"object.flag","reason":"ERROR","errorCode":"PROVIDER_NOT_READY",'
                . '"detailsUnavailable":"RuntimeException: not serializable"}',
        ), $logger->debugs());
    }

    public function testThrowingDebugLoggerDoesNotChangeEvaluation()
    {
        $evaluator = new ClientTestEvaluator();
        $evaluator->setSuccess('flag', 'on');
        $client = $this->clientForEvaluator($evaluator, new ThrowingLogger());

        $this->assertSame('on', $client->getStringValue('flag', 'off'));
    }

    public function testPartialJsonOutputDoesNotSilentlyReplaceRawValues()
    {
        $logger = new RecordingLogger();
        $client = $this->clientForEvaluator(new ClientTestEvaluator(), $logger);
        $default = array('number' => INF);

        $this->assertSame($default, $client->getObjectValue('object.flag', $default));
        $logged = json_decode(substr($logger->debugs()[0], strlen('Feature Flags: evaluated ')), true);
        $this->assertSame('Inf and NaN cannot be JSON encoded', $logged['detailsUnavailable']);
    }

    public function testDebugOffDoesNotSerializeDefaultValues()
    {
        $logger = new RecordingLogger(false);
        $client = $this->clientForEvaluator(new ClientTestEvaluator(), $logger);
        $item = new CountingJsonValue();
        $default = array('item' => $item);

        $this->assertSame($default, $client->getObjectValue('object.flag', $default));
        $this->assertSame(0, $item->calls);
        $this->assertSame(array(), $logger->debugs());
    }

    /**
     * @dataProvider invalidDefaultProvider
     */
    public function testTypedMethodsRejectInvalidDefaults($method, $defaultValue)
    {
        $client = $this->clientForEvaluator(new ClientTestEvaluator(), new RecordingLogger());

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

    private function clientForEvaluator(Evaluator $evaluator, $logger = null)
    {
        $client = new Client($logger);
        (function () use ($evaluator) {
            $this->evaluator = $evaluator;
        })->call($client);

        return $client;
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

    public function evaluate($flagKey, $expectedType, $defaultValue, $targetingKey = null, array $attributes = array(), $targetingKeyInvalid = false)
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
    private $debugEnabled;
    private $debugs = array();
    private $warnings = array();

    public function __construct($debugEnabled = true)
    {
        $this->debugEnabled = $debugEnabled;
    }

    public function debug($message, array $context = array())
    {
        $this->debugs[] = ErrorLogLogger::interpolate($message, $context);
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
        return $level !== LogLevel::DEBUG || $this->debugEnabled;
    }

    public function debugs()
    {
        return $this->debugs;
    }

    public function warnings()
    {
        return $this->warnings;
    }
}

final class ThrowingJsonValue implements \JsonSerializable
{
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        throw new \RuntimeException('not serializable');
    }
}

final class CountingJsonValue implements \JsonSerializable
{
    public $calls = 0;

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        ++$this->calls;
        return 'value';
    }
}

final class ThrowingLogger implements LoggerInterface
{
    private $warningCount = 0;

    public function debug($message, array $context = array())
    {
        throw new \ErrorException($message);
    }

    public function warning($message, array $context = array())
    {
        ++$this->warningCount;
        throw new \ErrorException($message);
    }

    public function error($message, array $context = array())
    {
    }

    public function isLevelActive($level)
    {
        return true;
    }

    public function warningCount()
    {
        return $this->warningCount;
    }
}
