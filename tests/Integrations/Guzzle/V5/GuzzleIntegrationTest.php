<?php

namespace DDTrace\Tests\Integrations\Guzzle\V5;

use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tracer;
use DDTrace\Tag;
use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Ring\Client\MockHandler;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\GlobalTracer;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

function find_span_name(array $trace, $name)
{
    foreach ($trace as $span) {
        if ($span['name'] == $name) {
            return $span;
        }
    }
    return null;
}

class GuzzleIntegrationTest extends IntegrationTestCase
{
    const URL = 'http://' . HTTPBIN_INTEGRATION . '';

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
    }

    /**
     * @param array|null $responseStack
     * @return Client
     */
    protected function getMockedClient(array $responseStack = null)
    {
        $handler = new MockHandler(['status' => 200]);
        return new Client(['handler' => $handler]);
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED',
            'DD_CURL_ANALYTICS_ENABLED',
            'DD_DISTRIBUTED_TRACING',
            'DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN',
            'DD_TRACE_MEMORY_LIMIT',
            'DD_TRACE_SPANS_LIMIT',
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
            SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
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
        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
                ->withExactTags([
                    'http.method' => 'PUT',
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'guzzle'
                ]),
        ]);
    }

    public function testGet()
    {
        $traces = $this->isolateTracer(function () {
            $this->getMockedClient()->get('http://example.com');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
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
            SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
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
        $client = new Client();
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

            $found = $response->json();
            $span->finish();
        });

        // trace is: custom
        self::assertSame($traces[0][0]['trace_id'], $found['headers']['X-Datadog-Trace-Id']);

        // Find either the guzzle or curl span; prefer the latter
        $guzzleSpan = find_span_name($traces[0], 'GuzzleHttp\\Client.send');
        $curlSpan = find_span_name($traces[0], 'curl_exec');

        $span = $curlSpan !== null ? $curlSpan : $guzzleSpan;

        if ($span === null) {
            self::fail('Unable to find a guzzle or curl span!');
        }

        self::assertSame($span['span_id'], $found['headers']['X-Datadog-Parent-Id']);

        self::assertSame('1', $found['headers']['X-Datadog-Sampling-Priority']);
        // existing headers are honored
        self::assertSame('preserved_value', $found['headers']['Honored']);
    }

    public function testDistributedTracingIsNotPropagatedIfDisabled()
    {
        self::putenv('DD_DISTRIBUTED_TRACING=false');
        $client = new Client();
        $found = [];

        $this->isolateTracer(function () use (&$found, $client) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $tracer->setPrioritySampling(PrioritySampling::AUTO_KEEP);
            $span = $tracer->startActiveSpan('custom')->getSpan();

            $response = $client->get(self::URL . '/headers');

            $found = $response->json();
            $span->finish();
        });

        self::assertArrayNotHasKey('X-Datadog-Trace-Id', $found['headers']);
        self::assertArrayNotHasKey('X-Datadog-Parent-Id', $found['headers']);
        self::assertArrayNotHasKey('X-Datadog-Sampling-Priority', $found['headers']);
    }

    public function testDistributedTracingIsPropagatedForMultiHandler()
    {
        $headers1 = [];
        $headers2 = [];

        $traces = $this->inWebServer(
            function ($execute) use (&$headers1, &$headers2) {
                list($headers1, $headers2) = json_decode($execute(GetSpec::create(
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
                    SpanAssertion::exists('GuzzleHttp\Client.send'),
                    SpanAssertion::exists('GuzzleHttp\Client.send'),
                ])
        );

        $rootSpan = $traces[0][0];
        self::assertSame(
            (float) $rootSpan['metrics']['_sampling_priority_v1'],
            (float) $headers1['headers']['X-Datadog-Sampling-Priority']
        );
        self::assertSame(
            (float) $rootSpan['metrics']['_sampling_priority_v1'],
            (float) $headers2['headers']['X-Datadog-Sampling-Priority']
        );

        /*
         * Unlike Guzzle 6, async requests in Guzzle 5 are not truly async
         * without an event loop.
         * @see https://github.com/guzzle/guzzle/issues/1439
         */
        self::assertDistributedTracingSpan($traces[0][6], $headers1['headers']);
        self::assertDistributedTracingSpan($traces[0][3], $headers2['headers']);
    }

    private static function assertDistributedTracingSpan($span, $headers)
    {
        self::assertSame(
            $span['span_id'],
            $headers['X-Datadog-Parent-Id']
        );
        self::assertSame(
            $span['trace_id'],
            $headers['X-Datadog-Trace-Id']
        );
        self::assertSame('preserved_value', $headers['Honored']);
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

            $found = $response->json();
            $span->finish();
        });

        self::assertEquals(1, sizeof($traces[0]));

        // trace is: custom
        self::assertSame($traces[0][0]['trace_id'], $found['headers']['X-Datadog-Trace-Id']);
        self::assertSame($traces[0][0]['span_id'], $found['headers']['X-Datadog-Parent-Id']);

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
            SpanAssertion::build('GuzzleHttp\Client.send', 'host-example.com', 'http', 'send')
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
            SpanAssertion::build('GuzzleHttp\Client.send', 'host-example.com', 'http', 'send')
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
                'DD_TRACE_GENERATE_ROOT_SPAN' => true,
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'top_level_app', 'web', 'GET /guzzle_in_web_request.php')
                ->withExistingTagsNames(['http.method', 'http.url', 'http.status_code'])
                ->withChildren([
                    SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
                        ->withExactTags([
                            'http.method' => 'GET',
                            'http.url' => self::URL . '/status/200',
                            'http.status_code' => '200',
                            'network.destination.name' => HTTPBIN_SERVICE_HOST,
                            TAG::SPAN_KIND => 'client',
                            Tag::COMPONENT => 'guzzle',
                            '_dd.base_service' => 'top_level_app',
                        ])
                        ->withChildren([
                            SpanAssertion::exists('curl_exec'),
                        ])
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
        $this->assertSpans($traces, [
        SpanAssertion::build('GuzzleHttp\Client.send', 'guzzle', 'http', 'send')
            ->withExactTags([
                'http.method' => 'PUT',
                'http.url' => 'http://example.com',
                'http.status_code' => '200',
                'network.destination.name' => 'example.com',
                TAG::SPAN_KIND => 'client',
                Tag::COMPONENT => 'guzzle',
                'peer.service' => 'example.com',
                '_dd.peer.service.source' => 'network.destination.name',
            ]),
        ]);
    }
}
