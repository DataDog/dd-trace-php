<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\DebugLogger;
use DDTrace\Log\LoggerInterface;
use DDTrace\Log\LogLevel;
use PHPUnit\Framework\TestCase;

final class DebugLoggerTest extends TestCase
{
    public function testEvaluationLogsIdentifiersAndOutcomeWithoutCustomerData()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('isLevelActive')->with(LogLevel::DEBUG)->willReturn(true);
        $logger->expects($this->once())->method('debug')->with(
            'Feature Flags: evaluated {details}',
            $this->callback(function ($context) {
                $this->assertSame(array(
                    'flagKey' => "checkout\nflag",
                    'valueType' => EvaluationType::STRING,
                    'reason' => EvaluationReason::SPLIT,
                    'variant' => 'treatment',
                    'errorCode' => null,
                    'configVersion' => 42,
                ), json_decode($context['details'], true));
                $this->assertFalse(strpos($context['details'], 'secret'));
                $this->assertFalse(strpos($context['details'], "\n"));
                return true;
            })
        );

        DebugLogger::create($logger)->evaluation("checkout\nflag", new EvaluationDetails(
            'secret-value', EvaluationType::STRING, EvaluationReason::SPLIT, 'treatment', null,
            'secret-message', array('secret-metadata'), array('secret-exposure'),
            array('configVersion' => 42, 'secret-state' => 'secret')
        ));
    }

    /** @dataProvider fallbackProvider */
    public function testFallbackLogsReasonAndError($reason, $errorCode)
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('isLevelActive')->willReturn(true);
        $logger->expects($this->once())->method('debug')->with(
            'Feature Flags: evaluated {details}',
            $this->callback(function ($context) use ($reason, $errorCode) {
                $details = json_decode($context['details'], true);
                $this->assertSame($reason, $details['reason']);
                $this->assertSame($errorCode, $details['errorCode']);
                $this->assertNull($details['variant']);
                $this->assertNull($details['configVersion']);
                return true;
            })
        );
        DebugLogger::create($logger)->evaluation('flag', new EvaluationDetails(
            false, EvaluationType::BOOLEAN, $reason, null, $errorCode
        ));
    }

    public function fallbackProvider()
    {
        return array(
            array(EvaluationReason::ERROR, EvaluationErrorCode::PROVIDER_NOT_READY),
            array(EvaluationReason::ERROR, EvaluationErrorCode::FLAG_NOT_FOUND),
            array(EvaluationReason::ERROR, EvaluationErrorCode::TYPE_MISMATCH),
            array(EvaluationReason::ERROR, EvaluationErrorCode::PARSE_ERROR),
            array(EvaluationReason::DISABLED, null),
            array(EvaluationReason::DEFAULT_REASON, null),
        );
    }

    public function testReadinessLogsOnlyTransitions()
    {
        $messages = array();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('isLevelActive')->willReturn(true);
        $logger->method('debug')->willReturnCallback(function ($message) use (&$messages) {
            if (strpos($message, 'Feature Flags: provider ') === 0) {
                $messages[] = $message;
            }
        });
        $debug = DebugLogger::create($logger);
        foreach (array(false, false, true, true, false) as $ready) {
            $debug->evaluation('flag', new EvaluationDetails(
                false, EvaluationType::BOOLEAN, EvaluationReason::DEFAULT_REASON,
                null, null, null, array(), array(), array('ready' => $ready)
            ));
        }
        $this->assertSame(array(
            'Feature Flags: provider not ready; no usable Remote Configuration, returning default values',
            'Feature Flags: provider ready; Remote Configuration is available',
            'Feature Flags: provider not ready; no usable Remote Configuration, returning default values',
        ), $messages);
    }

    public function testDisabledLoggingDoesNotEmitDiagnostics()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('isLevelActive')->with(LogLevel::DEBUG)->willReturn(false);
        $logger->expects($this->never())->method('debug');
        $this->assertNull(DebugLogger::create($logger));
    }

    /** @dataProvider throwingLoggerProvider */
    public function testLoggerFailuresAreIsolated($method)
    {
        $logger = $this->createMock(LoggerInterface::class);
        if ($method !== 'isLevelActive') {
            $logger->method('isLevelActive')->willReturn(true);
        }
        $logger->method($method)->willThrowException(new \RuntimeException('logger failed'));
        $debug = DebugLogger::create($logger);
        if ($method === 'isLevelActive') {
            $this->assertNull($debug);
        } else {
            $this->assertInstanceOf(DebugLogger::class, $debug);
            $debug->initialize();
            $debug->evaluation('flag', new EvaluationDetails(true, EvaluationType::BOOLEAN, EvaluationReason::STATIC_REASON));
        }
    }

    public function throwingLoggerProvider()
    {
        return array(array('isLevelActive'), array('debug'));
    }
}
