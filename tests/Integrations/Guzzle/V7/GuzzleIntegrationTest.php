<?php

namespace DDTrace\Tests\Integrations\Guzzle\V7;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tracer;
use DDTrace\Tag;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\GlobalTracer;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

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
                            TAG::SPAN_KIND => 'client',
                            Tag::COMPONENT => 'guzzle'
                        ]),
                ])
        ]);
    }
}
