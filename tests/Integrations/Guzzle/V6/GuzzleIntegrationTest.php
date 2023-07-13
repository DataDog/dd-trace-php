<?php

namespace DDTrace\Tests\Integrations\Guzzle\V6;

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

class GuzzleIntegrationTest extends IntegrationTestCase
{
    const URL = 'http://httpbin_integration';

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        IntegrationsLoader::load();
    }

    protected function getMockedClient(array $responseStack = null)
    {
        $responseStack = null === $responseStack ? [new Response(200)] : $responseStack;
        $handler = new MockHandler($responseStack);
        return new Client(['handler' => $handler]);
    }

    protected function getRealClient()
    {
        return new Client();
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED',
            'DD_DISTRIBUTED_TRACING',
            'DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN',
            'DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED',
            'DD_SERVICE',
        ];
    }

    /**
     * @dataProvider providerHttpMethods
     */
    public function testAliasMethods($method)
    {
        $traces = $this->isolateTracer(function () use ($method) {
            $this->getMockedClient()->$method('http://example.com/?foo=secret');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.transfer', 'guzzle', 'http', 'transfer')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.method' => strtoupper($method),
                    'http.url' => 'http://example.com/?foo=secret',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'guzzle'
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
            $this->getMockedClient()->send($request);
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
                ->withExactTags([
                    'http.method' => 'PUT',
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'guzzle'
                ])
                ->withChildren([
                    SpanAssertion::build('GuzzleHttp\Client.transfer', 'guzzle', 'http', 'transfer')
                        ->setTraceAnalyticsCandidate()
                        ->withExactTags([
                            'http.method' => 'PUT',
                            'http.url' => 'http://example.com',
                            'network.destination.name' => 'example.com',
                            'http.status_code' => '200',
                            TAG::SPAN_KIND => 'client',
                            Tag::COMPONENT => 'guzzle'
                        ]),
                ])
        ]);
    }

    public function testGet()
    {
        $traces = $this->isolateTracer(function () {
            $this->getMockedClient()->get('http://example.com');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.transfer', 'guzzle', 'http', 'transfer')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'guzzle'
                ]),
        ]);
    }

    public function testGetInlineCredentials()
    {
        $traces = $this->isolateTracer(function () {
            $this->getMockedClient()->get('http://my_user:my_password@example.com');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.transfer', 'guzzle', 'http', 'transfer')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://?:?@example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'guzzle'
                ]),
        ]);
    }

    public function testDistributedTracingIsPropagated()
    {
        $client = $this->getRealClient();
        $found = [];

        $traces = $this->isolateTracer(function () use (&$found, $client) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $tracer->setPrioritySampling(PrioritySampling::AUTO_KEEP);
            $span = $tracer->startActiveSpan('custom')->getSpan();

            $response = $client->get(self::URL . '/headers', [
                'headers' => [
                    'honored' => 'preserved_value',
                ],
            ]);

            $found = json_decode($response->getBody(), 1);
            $span->finish();
        });

        // trace is: custom
        self::assertSame($traces[0][0]['trace_id'], $found['headers']['X-Datadog-Trace-Id']);

        // parent is: curl_exec, used under the hood
        $curl_exec = null;
        foreach ($traces[0] as $span) {
            if ($span['name'] === 'curl_exec') {
                $curl_exec = $span;
                break;
            }
        }
        self::assertNotNull($curl_exec, 'Unable to find curl_exec in spans!');
        self::assertSame($curl_exec['span_id'], $found['headers']['X-Datadog-Parent-Id']);

        self::assertSame('1', $found['headers']['X-Datadog-Sampling-Priority']);
        // existing headers are honored
        self::assertSame('preserved_value', $found['headers']['Honored']);
    }

    public function testDistributedTracingIsNotPropagatedIfDisabled()
    {
        self::putenv('DD_DISTRIBUTED_TRACING=false');
        $client = $this->getRealClient();
        $found = [];

        $this->isolateTracer(function () use (&$found, $client) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $tracer->setPrioritySampling(PrioritySampling::AUTO_KEEP);
            $span = $tracer->startActiveSpan('custom')->getSpan();

            $response = $client->get(self::URL . '/headers');

            $found = json_decode($response->getBody(), 1);
            $span->finish();
        });

        self::assertArrayNotHasKey('X-Datadog-Trace-Id', $found['headers']);
        self::assertArrayNotHasKey('X-Datadog-Parent-Id', $found['headers']);
        self::assertArrayNotHasKey('X-Datadog-Sampling-Priority', $found['headers']);
    }

    public function testDistributedTracingIsPropagatedForMultiHandler()
    {
        $found = [];

        $traces = $this->inWebServer(
            function ($execute) use (&$found) {
                $found = json_decode($execute(GetSpec::create(
                    __FUNCTION__,
                    '/guzzle_in_distributed_web_request.php',
                    [
                    'x-datadog-sampling-priority: ' . PrioritySampling::AUTO_KEEP,
                    ]
                )), 1);
            },
            __DIR__ . '/guzzle_in_distributed_web_request.php'
        );

        $this->assertOneSpan(
            $traces,
            SpanAssertion::forOperation('web.request')
                ->withChildren([
                    SpanAssertion::exists('GuzzleHttp\Client.transfer'),
                    SpanAssertion::exists('GuzzleHttp\Client.transfer'),
            ])
        );

        foreach ($found as $data) {
            /*
             * Ideally the distributed traces for curl multi would be children
             * of the GuzzleHttp\Client.transfer span, but we do not currently
             * support this concurrency model so the parent span of curl multi
             * distributed traces will be whichever span resolves the promise.
             * In this particular case it is the root span that is active when
             * $curl->tick() is called.
             */
            $rootSpan = $traces[0][0];
            self::assertSame(
                $rootSpan['span_id'],
                $data['headers']['X-Datadog-Parent-Id']
            );
            self::assertSame(
                $rootSpan['trace_id'],
                $data['headers']['X-Datadog-Trace-Id']
            );
            self::assertSame(
                (float) $rootSpan['metrics']['_sampling_priority_v1'],
                (float) $data['headers']['X-Datadog-Sampling-Priority']
            );
            self::assertSame('preserved_value', $data['headers']['Honored']);
        }
    }

    public function testLimitedTracer()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $this->getMockedClient()->get('http://example.com');

            $request = new Request('put', 'http://example.com');
            $this->getMockedClient()->send($request);
        });

        self::assertEmpty($traces);
    }

    // Test for APMS-5427
    public function testAppendHostnameToServiceNameNoSchema()
    {
        self::putenv('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN=true');

        $traces = $this->isolateTracer(function () {
            $response = $this->getMockedClient()->get('example.com');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.transfer', 'host-example.com', 'http', 'transfer')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'guzzle'
                ]),
        ]);
    }

    public function testLimitedTracerDistributedTracingIsPropagated()
    {
        $client = new Client();
        $found = [];

        $traces = $this->isolateLimitedTracer(function () use (&$found, $client) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $tracer->setPrioritySampling(PrioritySampling::AUTO_KEEP);
            $span = $tracer->startRootSpan('custom')->getSpan();

            $response = $client->get(self::URL . '/headers', [
                'headers' => [
                    'honored' => 'preserved_value',
                ],
            ]);

            $found = json_decode($response->getBody(), 1);
            $span->finish();
        });

        // trace is: custom
        self::assertSame($traces[0][0]['trace_id'], $found['headers']['X-Datadog-Trace-Id']);
        self::assertSame($traces[0][0]['span_id'], $found['headers']['X-Datadog-Parent-Id']);
        self::assertEquals(1, sizeof($traces[0]));

        self::assertSame('1', $found['headers']['X-Datadog-Sampling-Priority']);
        // existing headers are honored
        self::assertSame('preserved_value', $found['headers']['Honored']);
    }

    public function testAppendHostnameToServiceName()
    {
        self::putenv('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN=true');

        $traces = $this->isolateTracer(function () {
            $this->getMockedClient()->get('http://example.com');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.transfer', 'host-example.com', 'http', 'transfer')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'guzzle'
                ]),
        ]);
    }

    public function testAppendHostnameToServiceNameInlineCredentials()
    {
        self::putenv('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN=true');

        $traces = $this->isolateTracer(function () {
            $this->getMockedClient()->get('http://my_user:my_password@example.com');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.transfer', 'host-example.com', 'http', 'transfer')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://?:?@example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'guzzle'
                ]),
        ]);
    }

    public function testDoesNotInheritTopLevelAppName()
    {
        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('GET', '/guzzle_in_web_request.php'));
            },
            __DIR__ . '/guzzle_in_web_request.php',
            [
                'DD_SERVICE' => 'top_level_app',
                'DD_TRACE_NO_AUTOLOADER' => true,
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'top_level_app', 'web', 'GET /guzzle_in_web_request.php')
                ->withExistingTagsNames(['http.method', 'http.url', 'http.status_code'])
                ->withChildren([
                    SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
                        ->setTraceAnalyticsCandidate()
                        ->withExactTags([
                            'http.method' => 'GET',
                            'http.url' => self::URL . '/status/200',
                            'http.status_code' => '200',
                            'network.destination.name' => 'httpbin_integration',
                            TAG::SPAN_KIND => 'client',
                            Tag::COMPONENT => 'guzzle'
                        ])
                        ->withChildren([
                            SpanAssertion::exists('GuzzleHttp\Client.transfer')
                                ->withChildren([
                                    SpanAssertion::exists('curl_exec'),
                                ]),
                        ]),
                ]),
        ]);
    }

    public function testPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $request = new Request('put', 'http://example.com');
            $this->getMockedClient()->send($request);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
                ->withExactTags([
                    'http.method' => 'PUT',
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'guzzle',
                ])
                ->withChildren([
                    SpanAssertion::build('GuzzleHttp\Client.transfer', 'guzzle', 'http', 'transfer')
                        ->setTraceAnalyticsCandidate()
                        ->withExactTags([
                            'http.method' => 'PUT',
                            'http.url' => 'http://example.com',
                            'network.destination.name' => 'example.com',
                            'http.status_code' => '200',
                            TAG::SPAN_KIND => 'client',
                            Tag::COMPONENT => 'guzzle',
                            'peer.service' => 'example.com',
                            'peer.service' => 'example.com',
                            '_dd.peer.service.source' => 'network.destination.name',
                        ]),
                ])
        ]);
    }

    public function testNoFakeServices()
    {
        $this->putEnvAndReloadConfig([
            'DD_SERVICE=configured_service',
            'DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED=true',
        ]);

        $traces = $this->isolateTracer(function () {
            $this->getMockedClient()->get('http://example.com');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.transfer', 'configured_service', 'http', 'transfer')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'guzzle'
                ]),
        ]);
    }
}
