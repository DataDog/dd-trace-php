<?php

namespace DDTrace\Tests\Unit\Log;

use DDTrace\Log\PsrLogger;
use DDTrace\Tests\Unit\BaseTestCase;

class PsrLoggerTest extends BaseTestCase
{
    public function testForwardDebugToPsrLogger()
    {
        $message = '__message__';
        $context = ['key' => 'value'];
        $psrLogger = \Mockery::mock('Psr\Log\LoggerInterface');
        $psrLogger->shouldReceive('debug')->with($message, $context)->once();

        $logger = new PsrLogger($psrLogger);
        $logger->debug($message, $context);

        $this->addToAssertionCount(1);
    }
}
