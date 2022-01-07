<?php

namespace DDTrace\Tests\Unit\Log;

use DDTrace\Log\Logger;
use DDTrace\Tests\Common\BaseTestCase;

class LoggerTest extends BaseTestCase
{
    public function testDefaultsToNullLogger()
    {
        Logger::reset();

        $original_debug = ini_get("datadog.trace.debug");
        ini_set("datadog.trace.debug", false);
        try {
            $this->assertInstanceOf('DDTrace\Log\NullLogger', Logger::get());
        } catch (\Exception $e) {
        }
        ini_set("datadog.trace.debug", $original_debug);
        if (isset($e)) {
            throw $e;
        }
    }

    public function testProvidedLoggerIsHonored()
    {
        $logger = \Mockery::mock('DDTrace\Log\LoggerInterface');
        Logger::set($logger);
        $this->assertSame($logger, Logger::get());
    }
}
