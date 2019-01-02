<?php

namespace DDTrace\Tests\Unit\Log;

use DDTrace\Log\Logger;
use DDTrace\Tests\Unit\BaseTestCase;

class LoggerTest extends BaseTestCase
{
    public function testDefaultsToNullLogger()
    {
        $this->assertInstanceOf('DDTrace\Log\NullLogger', Logger::get());
    }

    public function testProvidedLoggerIsHonored()
    {
        $logger = \Mockery::mock('DDTrace\Log\LoggerInterface');
        Logger::set($logger);
        $this->assertSame($logger, Logger::get());
    }
}
