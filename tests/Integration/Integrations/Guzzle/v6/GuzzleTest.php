<?php

namespace DDTrace\Tests\Integration\Integrations\Guzzle\v6;

use GuzzleHttp\Client;
use DDTrace\Integrations;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use DDTrace\Tests\Integration\Common\SpanAssertion;
use DDTrace\Tests\Integration\Common\IntegrationTestCase;

final class GuzzleTest extends IntegrationTestCase
{
    /**
     * @var Client
     */
    private $client;

    public static function setUpBeforeClass()
    {
        Integrations\Guzzle\v6\GuzzleIntegration::load();
    }

    protected function setUp()
    {
        parent::setUp();
        $mock = new MockHandler([
            new Response(200),
        ]);
        $handler = HandlerStack::create($mock);
        $this->client = new Client(['handler' => $handler]);
    }

    /**
     * @dataProvider providerHttpMethods
     */
    public function testMagicMethods($method)
    {
        $traces = $this->isolateTracer(function () use ($method) {
            $this->client->$method('http://example.com');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.request', 'guzzle', 'guzzle', 'request')
                ->withExactTags([
                    'http.method' => strtoupper($method),
                    'guzzle.command' => 'request',
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

    public function testRequest()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->request('get', 'http://example.com');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.request', 'guzzle', 'guzzle', 'request')
                ->withExactTags([
                    'http.method' => 'GET',
                    'guzzle.command' => 'request',
                ]),
        ]);
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
                    'guzzle.command' => 'send',
                ]),
        ]);
    }
}
