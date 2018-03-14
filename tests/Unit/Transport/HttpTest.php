<?php

namespace DDTrace\Tests\Unit\Transport;

use DDTrace\Encoders\Json;
use DDTrace\Transport\Http;
use Psr\Log\NullLogger;

final class HttpTest extends \PHPUnit_Framework_TestCase
{
    const ENDPOINT = 'http://myserver:8126/v0.3/traces';

    public function testConfigWithDefaultValues()
    {
        $httpTransport = new Http(new Json, new NullLogger);
        $this->assertEquals('http://localhost:8126/v0.3/traces', $httpTransport->getConfig()['endpoint']);
    }

    public function testConfig()
    {
        $httpTransport = new Http(new Json, new NullLogger, ['endpoint' => self::ENDPOINT]);
        $this->assertEquals(self::ENDPOINT, $httpTransport->getConfig()['endpoint']);
    }
}
