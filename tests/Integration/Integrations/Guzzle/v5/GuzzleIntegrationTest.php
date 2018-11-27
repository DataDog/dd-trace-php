<?php

namespace DDTrace\Tests\Integration\Integrations\Guzzle\v5;

use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Ring\Client\MockHandler;
use DDTrace\Tests\Integration\Common\SpanAssertion;
use DDTrace\Integrations\Guzzle\v5\GuzzleIntegration;
use DDTrace\Tests\Integration\Common\IntegrationTestCase;

final class GuzzleIntegrationTest extends IntegrationTestCase
{
    /** @var Client */
    private $client;

    public static function setUpBeforeClass()
    {
        GuzzleIntegration::load();
    }

    protected function setUp()
    {
        parent::setUp();
        $handler = new MockHandler(['status' => 200]);
        $this->client = new Client(['handler' => $handler]);
    }

    /**
     * @dataProvider providerHttpMethods
     */
    public function testAliasMethods($method)
    {
        $traces = $this->isolateTracer(function () use ($method) {
            $this->client->$method('http://example.com');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'guzzle', 'send')
                ->withExactTags([
                    'http.method' => strtoupper($method),
                ]),
        ]);
    }

    public function providerHttpMethods()
    {
        return [
            ['get'],
            ['delete'],
            ['head'],
            ['options'],
            ['patch'],
            ['post'],
            ['put'],
        ];
    }

    public function testSend()
    {
        $traces = $this->isolateTracer(function () {
            $request = new Request('put', 'http://example.com');
            $this->client->send($request);
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'guzzle', 'send')
                ->withExactTags([
                    'http.method' => 'PUT',
                ]),
        ]);
    }
}
