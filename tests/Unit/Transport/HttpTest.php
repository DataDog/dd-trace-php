<?php

namespace DDTrace\Tests\Unit\Transport;

use DDTrace\Contracts\Tracer as TracerInterface;
use DDTrace\Encoder;
use DDTrace\Encoders\Json;
use DDTrace\Log\Logger;
use DDTrace\Tests\Common\TestLogger;
use DDTrace\Tests\Unit\BaseTestCase;
use DDTrace\Tests\Unit\CleanEnvTrait;
use DDTrace\Tracer;
use DDTrace\Transport\Http;

final class FooEncoder implements Encoder
{
    private $data;

    public $payload = '';

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function encodeTraces(TracerInterface $tracer)
    {
        return $this->payload = json_encode($this->data);
    }

    public function getContentType()
    {
        return '';
    }
}

final class HttpTest extends BaseTestCase
{
    use CleanEnvTrait;

    public function getCleanEnvs()
    {
        return ['DD_AGENT_HOST', 'DD_TRACE_AGENT_PORT'];
    }

    public function testConfigWithDefaultValues()
    {
        $httpTransport = new Http(new Json());
        $this->assertEquals('http://localhost:8126/v0.3/traces', $httpTransport->getConfig()['endpoint']);
    }

    public function testConfig()
    {
        $endpoint = '__end_point___';
        $httpTransport = new Http(new Json(), ['endpoint' => $endpoint]);
        $this->assertEquals($endpoint, $httpTransport->getConfig()['endpoint']);
    }

    public function testConfigPortFromEnv()
    {
        putenv('DD_TRACE_AGENT_PORT=8888');
        $httpTransport = new Http(new Json());
        $this->assertEquals('http://localhost:8888/v0.3/traces', $httpTransport->getConfig()['endpoint']);
    }

    public function testConfigHostFromEnv()
    {
        putenv('DD_AGENT_HOST=other_host');
        $httpTransport = new Http(new Json());
        $this->assertEquals('http://other_host:8126/v0.3/traces', $httpTransport->getConfig()['endpoint']);
    }

    public function testPayloadsOver10MBFail()
    {
        $logger = new TestLogger();
        Logger::set($logger);
        // Once encoded, this will send a payload that is 4 bytes over 10MB due to the added: [""]
        $tenMBString = str_repeat('a', Http::AGENT_REQUEST_BODY_LIMIT);
        $httpTransport = new Http(new FooEncoder([$tenMBString]));
        $httpTransport->send(Tracer::noop());
        $this->assertContains('dropping request', $logger->lastLog);
    }

    public function testPayloadsExactly10MBPass()
    {
        $logger = new TestLogger();
        Logger::set($logger);
        // The -4 bytes is to account for the added: [""]
        $tenMBString = str_repeat('a', Http::AGENT_REQUEST_BODY_LIMIT - 4);
        $encoder = new FooEncoder([$tenMBString]);
        $httpTransport = new Http($encoder);
        $httpTransport->send(Tracer::noop());
        $this->assertNotContains('dropping request', $logger->lastLog);
        $this->assertSame(Http::AGENT_REQUEST_BODY_LIMIT, strlen($encoder->payload));
    }
}
