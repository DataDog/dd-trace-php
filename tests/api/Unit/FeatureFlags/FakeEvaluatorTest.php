<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Testing\FakeEvaluator;
use PHPUnit\Framework\TestCase;

final class FakeEvaluatorTest extends TestCase
{
    public function testFakeEvaluatorReturnsConfiguredSuccessAndCapturesCall()
    {
        $evaluator = new FakeEvaluator();
        $evaluator->setSuccess(
            'checkout-redesign',
            true,
            EvaluationReason::TARGETING_MATCH,
            'on',
            array('source' => 'fixture'),
            array('allocationKey' => 'alloc-1'),
            array('hasConfig' => true)
        );

        $details = $evaluator->evaluate(
            'checkout-redesign',
            EvaluationType::BOOLEAN,
            false,
            'user-123',
            array('plan' => 'pro')
        );

        $this->assertTrue($details->getValue());
        $this->assertSame(EvaluationType::BOOLEAN, $details->getValueType());
        $this->assertSame(EvaluationReason::TARGETING_MATCH, $details->getReason());
        $this->assertSame('on', $details->getVariant());
        $this->assertNull($details->getErrorCode());
        $this->assertSame(array('source' => 'fixture'), $details->getFlagMetadata());
        $this->assertSame(array('allocationKey' => 'alloc-1'), $details->getExposureData());
        $this->assertSame(array('hasConfig' => true), $details->getProviderState());

        $this->assertSame(array(
            array(
                'flagKey' => 'checkout-redesign',
                'expectedType' => EvaluationType::BOOLEAN,
                'defaultValue' => false,
                'targetingKey' => 'user-123',
                'attributes' => array('plan' => 'pro'),
            ),
        ), $evaluator->getCalls());
    }

    /**
     * @dataProvider errorFixtureProvider
     */
    public function testFakeEvaluatorErrorFixturesReturnDefaultAndErrorReason($method, $expectedErrorCode)
    {
        $evaluator = new FakeEvaluator();
        $evaluator->$method('flag.error');

        $details = $evaluator->evaluate('flag.error', EvaluationType::STRING, 'fallback');

        $this->assertSame('fallback', $details->getValue());
        $this->assertSame(EvaluationReason::ERROR, $details->getReason());
        $this->assertSame($expectedErrorCode, $details->getErrorCode());
        $this->assertTrue($details->isError());
    }

    public function errorFixtureProvider()
    {
        return array(
            'flag not found' => array('setFlagNotFound', EvaluationErrorCode::FLAG_NOT_FOUND),
            'type mismatch' => array('setTypeMismatch', EvaluationErrorCode::TYPE_MISMATCH),
            'parse error' => array('setParseError', EvaluationErrorCode::PARSE_ERROR),
            'provider not ready' => array('setProviderNotReady', EvaluationErrorCode::PROVIDER_NOT_READY),
            'general error' => array('setGeneralError', EvaluationErrorCode::GENERAL),
        );
    }

    public function testFakeEvaluatorCanReturnDefaultAndDisabledReasons()
    {
        $evaluator = new FakeEvaluator();
        $evaluator
            ->setDefault('flag.default', 'control', 'control-variant')
            ->setDisabled('flag.disabled', false, 'off');

        $default = $evaluator->evaluate('flag.default', EvaluationType::STRING, 'fallback');
        $disabled = $evaluator->evaluate('flag.disabled', EvaluationType::BOOLEAN, true);

        $this->assertSame('control', $default->getValue());
        $this->assertSame(EvaluationReason::DEFAULT_REASON, $default->getReason());
        $this->assertSame('control-variant', $default->getVariant());

        $this->assertFalse($disabled->getValue());
        $this->assertSame(EvaluationReason::DISABLED, $disabled->getReason());
        $this->assertSame('off', $disabled->getVariant());
    }

    public function testUnconfiguredFlagMapsToProviderNotReady()
    {
        $evaluator = new FakeEvaluator();

        $details = $evaluator->evaluate('unconfigured', EvaluationType::BOOLEAN, true);

        $this->assertTrue($details->getValue());
        $this->assertSame(EvaluationReason::ERROR, $details->getReason());
        $this->assertSame(EvaluationErrorCode::PROVIDER_NOT_READY, $details->getErrorCode());
        $this->assertSame(array('ready' => false), $details->getProviderState());
    }
}
