<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\ResultMapper;
use PHPUnit\Framework\TestCase;

final class ResultMapperTest extends TestCase
{
    public function testMapsSuccessfulBridgeResultToEvaluationDetails()
    {
        $details = (new ResultMapper())->map(array(
            'value_json' => '"blue"',
            'variant' => 'variant-a',
            'allocation_key' => 'alloc-1',
            'reason' => ResultMapper::BRIDGE_REASON_TARGETING_MATCH,
            'error_code' => ResultMapper::BRIDGE_ERROR_NONE,
            'do_log' => true,
            'flag_metadata' => array('owner' => 'ffe'),
            'provider_state' => array('ready' => true),
            'has_config' => true,
            'config_version' => 42,
        ), EvaluationType::STRING, 'red');

        $this->assertSame('blue', $details->getValue());
        $this->assertSame(EvaluationType::STRING, $details->getValueType());
        $this->assertSame(EvaluationReason::TARGETING_MATCH, $details->getReason());
        $this->assertSame('variant-a', $details->getVariant());
        $this->assertNull($details->getErrorCode());
        $this->assertFalse($details->isError());
        $this->assertSame(array('owner' => 'ffe'), $details->getFlagMetadata());
        $this->assertSame(array('allocationKey' => 'alloc-1', 'doLog' => true), $details->getExposureData());
        $this->assertSame(
            array('ready' => true, 'hasConfig' => true, 'configVersion' => 42),
            $details->getProviderState()
        );
    }

    public function testNonZeroErrorReturnsDefaultAndForcesErrorReason()
    {
        $details = (new ResultMapper())->map(array(
            'value_json' => '"ignored"',
            'variant' => 'ignored-variant',
            'reason' => ResultMapper::BRIDGE_REASON_TARGETING_MATCH,
            'error_code' => ResultMapper::BRIDGE_ERROR_FLAG_UNRECOGNIZED,
            'error_message' => 'Unknown flag',
        ), EvaluationType::STRING, 'fallback');

        $this->assertSame('fallback', $details->getValue());
        $this->assertSame(EvaluationReason::ERROR, $details->getReason());
        $this->assertNull($details->getVariant());
        $this->assertSame(EvaluationErrorCode::FLAG_NOT_FOUND, $details->getErrorCode());
        $this->assertSame('Unknown flag', $details->getErrorMessage());
        $this->assertTrue($details->isError());
    }

    public function testNullResultMapsToProviderNotReady()
    {
        $details = (new ResultMapper())->map(null, EvaluationType::BOOLEAN, true);

        $this->assertTrue($details->getValue());
        $this->assertSame(EvaluationReason::ERROR, $details->getReason());
        $this->assertSame(EvaluationErrorCode::PROVIDER_NOT_READY, $details->getErrorCode());
        $this->assertSame('FFE evaluator is not ready', $details->getErrorMessage());
        $this->assertSame(array('ready' => false), $details->getProviderState());
    }

    public function testConfigMissingErrorMapsToProviderNotReady()
    {
        $details = (new ResultMapper())->map(array(
            'value_json' => 'null',
            'reason' => ResultMapper::BRIDGE_REASON_ERROR,
            'error_code' => ResultMapper::BRIDGE_ERROR_CONFIG_MISSING,
            'provider_state' => array('hasConfig' => false),
        ), EvaluationType::BOOLEAN, false);

        $this->assertFalse($details->getValue());
        $this->assertSame(EvaluationErrorCode::PROVIDER_NOT_READY, $details->getErrorCode());
        $this->assertSame(array('hasConfig' => false), $details->getProviderState());
    }

    public function testInvalidJsonMapsToParseError()
    {
        $details = (new ResultMapper())->map(array(
            'value_json' => '{bad-json',
            'reason' => ResultMapper::BRIDGE_REASON_TARGETING_MATCH,
            'error_code' => ResultMapper::BRIDGE_ERROR_NONE,
        ), EvaluationType::OBJECT, array('fallback' => true));

        $this->assertSame(array('fallback' => true), $details->getValue());
        $this->assertSame(EvaluationReason::ERROR, $details->getReason());
        $this->assertSame(EvaluationErrorCode::PARSE_ERROR, $details->getErrorCode());
    }

    public function testDecodedTypeMismatchMapsToTypeMismatch()
    {
        $details = (new ResultMapper())->map(array(
            'value_json' => '"not-a-bool"',
            'reason' => ResultMapper::BRIDGE_REASON_TARGETING_MATCH,
            'error_code' => ResultMapper::BRIDGE_ERROR_NONE,
        ), EvaluationType::BOOLEAN, false);

        $this->assertFalse($details->getValue());
        $this->assertSame(EvaluationReason::ERROR, $details->getReason());
        $this->assertSame(EvaluationErrorCode::TYPE_MISMATCH, $details->getErrorCode());
    }

    public function testDisabledResultReturnsDefaultWithoutTypeMismatch()
    {
        $details = (new ResultMapper())->map(array(
            'value_json' => 'null',
            'variant' => null,
            'allocation_key' => null,
            'reason' => ResultMapper::BRIDGE_REASON_DISABLED,
            'error_code' => ResultMapper::BRIDGE_ERROR_NONE,
            'do_log' => false,
            'has_config' => true,
            'config_version' => 7,
        ), EvaluationType::BOOLEAN, true);

        $this->assertTrue($details->getValue());
        $this->assertSame(EvaluationReason::DISABLED, $details->getReason());
        $this->assertNull($details->getErrorCode());
        $this->assertNull($details->getVariant());
        $this->assertSame(array(), $details->getExposureData());
        $this->assertSame(array('hasConfig' => true, 'configVersion' => 7), $details->getProviderState());
        $this->assertFalse($details->isError());
    }

    public function testDefaultNullResultReturnsDefaultWithoutTypeMismatch()
    {
        $details = (new ResultMapper())->map(array(
            'value_json' => 'null',
            'reason' => ResultMapper::BRIDGE_REASON_DEFAULT,
            'error_code' => ResultMapper::BRIDGE_ERROR_NONE,
        ), EvaluationType::STRING, 'fallback');

        $this->assertSame('fallback', $details->getValue());
        $this->assertSame(EvaluationReason::DEFAULT_REASON, $details->getReason());
        $this->assertNull($details->getErrorCode());
        $this->assertFalse($details->isError());
    }

    public function testIntegerJsonCanMapToFloat()
    {
        $details = (new ResultMapper())->map(array(
            'value_json' => '10',
            'reason' => ResultMapper::BRIDGE_REASON_SPLIT,
            'error_code' => ResultMapper::BRIDGE_ERROR_NONE,
        ), EvaluationType::FLOAT, 0.0);

        $this->assertSame(10.0, $details->getValue());
        $this->assertSame(EvaluationReason::SPLIT, $details->getReason());
    }

    public function testJsonObjectMapsToObjectDetails()
    {
        $details = (new ResultMapper())->map(array(
            'value_json' => '{"enabled":true,"threshold":2,"labels":["a","b"]}',
            'variant' => 'json-a',
            'allocation_key' => 'alloc-json',
            'reason' => ResultMapper::BRIDGE_REASON_SPLIT,
            'error_code' => ResultMapper::BRIDGE_ERROR_NONE,
            'do_log' => true,
        ), EvaluationType::OBJECT, array('fallback' => true));

        $this->assertSame(array(
            'enabled' => true,
            'threshold' => 2,
            'labels' => array('a', 'b'),
        ), $details->getValue());
        $this->assertSame(EvaluationReason::SPLIT, $details->getReason());
        $this->assertSame('json-a', $details->getVariant());
        $this->assertSame(array('allocationKey' => 'alloc-json', 'doLog' => true), $details->getExposureData());
    }

    /**
     * @dataProvider reasonProvider
     */
    public function testReasonMapping($bridgeReason, $expectedReason)
    {
        $details = (new ResultMapper())->map(array(
            'value_json' => 'true',
            'reason' => $bridgeReason,
            'error_code' => ResultMapper::BRIDGE_ERROR_NONE,
        ), EvaluationType::BOOLEAN, false);

        $this->assertSame($expectedReason, $details->getReason());
    }

    public function reasonProvider()
    {
        return array(
            'static' => array(ResultMapper::BRIDGE_REASON_STATIC, EvaluationReason::STATIC_REASON),
            'default' => array(ResultMapper::BRIDGE_REASON_DEFAULT, EvaluationReason::DEFAULT_REASON),
            'targeting match' => array(ResultMapper::BRIDGE_REASON_TARGETING_MATCH, EvaluationReason::TARGETING_MATCH),
            'split' => array(ResultMapper::BRIDGE_REASON_SPLIT, EvaluationReason::SPLIT),
            'disabled' => array(ResultMapper::BRIDGE_REASON_DISABLED, EvaluationReason::DISABLED),
            'error' => array(ResultMapper::BRIDGE_REASON_ERROR, EvaluationReason::ERROR),
        );
    }

    public function testExistingEvaluationDetailsPassThrough()
    {
        $existing = new EvaluationDetails(
            'kept',
            EvaluationType::STRING,
            EvaluationReason::DEFAULT_REASON
        );

        $details = (new ResultMapper())->map($existing, EvaluationType::STRING, 'fallback');

        $this->assertSame($existing, $details);
    }
}
