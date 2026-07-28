<?php

namespace DDTrace\Tests\Api\Unit\Log;

use DDTrace\Log\LoggerInterface;
use DDTrace\Log\LogLevel;
use DDTrace\Log\NonThrowingLogger;
use PHPUnit\Framework\TestCase;

final class NonThrowingLoggerTest extends TestCase
{
    public function testForwardsCallsAndLevelState()
    {
        $delegate = new NonThrowingRecordingLogger();
        $logger = new NonThrowingLogger($delegate);

        $logger->debug('debug message', array('level' => 'debug'));
        $logger->warning('warning message', array('level' => 'warning'));
        $logger->error('error message', array('level' => 'error'));

        $this->assertSame(array(
            array('debug', 'debug message', array('level' => 'debug')),
            array('warning', 'warning message', array('level' => 'warning')),
            array('error', 'error message', array('level' => 'error')),
        ), $delegate->calls());
        $this->assertTrue($logger->isLevelActive(LogLevel::WARNING));
        $this->assertFalse($logger->isLevelActive(LogLevel::DEBUG));
    }

    public function testSuppressesFailuresFromEveryLoggerMethod()
    {
        $logger = new NonThrowingLogger(new AlwaysThrowingLogger());

        $this->assertNull($logger->debug('debug message'));
        $this->assertNull($logger->warning('warning message'));
        $this->assertNull($logger->error('error message'));
        $this->assertFalse($logger->isLevelActive(LogLevel::WARNING));
    }
}

final class NonThrowingRecordingLogger implements LoggerInterface
{
    private $calls = array();

    public function debug($message, array $context = array())
    {
        $this->calls[] = array('debug', $message, $context);
    }

    public function warning($message, array $context = array())
    {
        $this->calls[] = array('warning', $message, $context);
    }

    public function error($message, array $context = array())
    {
        $this->calls[] = array('error', $message, $context);
    }

    public function isLevelActive($level)
    {
        return $level === LogLevel::WARNING;
    }

    public function calls()
    {
        return $this->calls;
    }
}

final class AlwaysThrowingLogger implements LoggerInterface
{
    public function debug($message, array $context = array())
    {
        throw new \ErrorException('debug logger failed');
    }

    public function warning($message, array $context = array())
    {
        throw new \ErrorException('warning logger failed');
    }

    public function error($message, array $context = array())
    {
        throw new \ErrorException('error logger failed');
    }

    public function isLevelActive($level)
    {
        throw new \ErrorException('logger level check failed');
    }
}
