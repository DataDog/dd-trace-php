<?php

namespace DDTrace\Tests\Unit\Log;

use DDTrace\Log\PsrLogger;
use DDTrace\Tests\Common\BaseTestCase;

class PsrLoggerTest extends BaseTestCase
{
    protected function ddSetUp()
    {
        $this->putEnvAndReloadConfig([
            'DD_LOGS_INJECTION=false',
        ]);
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_LOGS_INJECTION',
        ];
    }

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
