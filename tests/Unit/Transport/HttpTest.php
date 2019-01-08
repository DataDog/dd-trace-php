<?php

namespace DDTrace\Tests\Unit\Transport;

use DDTrace\Encoders\Json;
use DDTrace\Log\NullLogger;
use DDTrace\Tests\Unit\CleanEnvTrait;
use DDTrace\Transport\Http;
use PHPUnit\Framework;

final class HttpTest extends Framework\TestCase
{
    use CleanEnvTrait;

    public function getCleanEnvs()
    {
        return ['DD_AGENT_HOST', 'DD_TRACE_AGENT_PORT'];
    }

    public function testConfigWithDefaultValues()
    {
        $httpTransport = new Http(new Json(), new NullLogger());
        $this->assertEquals('http://localhost:8126/v0.3/traces', $httpTransport->getConfig()['endpoint']);
    }

    public function testConfig()
    {
        $endpoint = '__end_point___';
        $httpTransport = new Http(new Json(), new NullLogger(), ['endpoint' => $endpoint]);
        $this->assertEquals($endpoint, $httpTransport->getConfig()['endpoint']);
    }

    public function testConfigPortFromEnv()
    {
        putenv('DD_TRACE_AGENT_PORT=8888');
        $httpTransport = new Http(new Json(), new NullLogger());
        $this->assertEquals('http://localhost:8888/v0.3/traces', $httpTransport->getConfig()['endpoint']);
    }

    public function testConfigHostFromEnv()
    {
        putenv('DD_AGENT_HOST=other_host');
        $httpTransport = new Http(new Json(), new NullLogger());
        $this->assertEquals('http://other_host:8126/v0.3/traces', $httpTransport->getConfig()['endpoint']);
    }
}
