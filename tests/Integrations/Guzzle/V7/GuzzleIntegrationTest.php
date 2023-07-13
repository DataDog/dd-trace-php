<?php

namespace DDTrace\Tests\Integrations\Guzzle\V7;

use DDTrace\Tag;
use GuzzleHttp\Psr7\Request;
use DDTrace\Tests\Common\SpanAssertion;

class GuzzleIntegrationTest extends \DDTrace\Tests\Integrations\Guzzle\V6\GuzzleIntegrationTest
{
    public function testSendRequest()
    {
        $traces = $this->isolateTracer(function () {
            $request = new Request('put', 'http://example.com');
            $this->getMockedClient()->sendRequest($request);
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build('Psr\Http\Client\ClientInterface.sendRequest', 'psr18', 'http', 'sendRequest')
                ->withExactTags([
                    'http.method' => 'PUT',
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'psr18'
                ])
                ->withChildren([
                    SpanAssertion::build('GuzzleHttp\Client.transfer', 'guzzle', 'http', 'transfer')
                        ->setTraceAnalyticsCandidate()
                        ->withExactTags([
                            'http.method' => 'PUT',
                            'http.url' => 'http://example.com',
                            'http.status_code' => '200',
                            'network.destination.name' => 'example.com',
                            TAG::SPAN_KIND => 'client',
                            Tag::COMPONENT => 'guzzle'
                        ]),
                ])
        ]);
    }
}
