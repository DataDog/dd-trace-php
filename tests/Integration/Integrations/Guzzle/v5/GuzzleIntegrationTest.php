<?php

namespace DDTrace\Tests\Integration\Integrations\Guzzle\V5;

use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Ring\Client\MockHandler;
use DDTrace\Tests\Integration\Common\SpanAssertion;
use DDTrace\Integrations\Guzzle\V5\GuzzleIntegration;
use DDTrace\Tests\Integration\Common\IntegrationTestCase;

final class GuzzleIntegrationTest extends IntegrationTestCase
{
    /** @var Client */
    private $client;

    public static function setUpBeforeClass()
    {
        if(!GuzzleIntegration::load()) {
            self::markTestSkipped('Guzzle required to run tests.');
        }
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
            SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
                ->withExactTags([
                    'http.method' => strtoupper($method),
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
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
            SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
                ->withExactTags([
                    'http.method' => 'PUT',
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
                ]),
        ]);
    }
}
