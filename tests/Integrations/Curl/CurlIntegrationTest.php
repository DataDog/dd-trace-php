<?php

namespace DDTrace\Tests\Integrations\Curl;

use DDTrace\Format;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\StartSpanOptionsFactory;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tracer;
use DDTrace\Util\ArrayKVStore;
use DDTrace\GlobalTracer;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class PrivateCallbackRequest
{
    private static function parseResponseHeaders($ch, $headers)
    {
        return strlen($headers);
    }

    public function request()
    {
        $ch = curl_init(CurlIntegrationTest::URL . '/status/200');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, __CLASS__ . '::parseResponseHeaders');
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}

final class CurlIntegrationTest extends IntegrationTestCase
{
    const URL = 'http://httpbin_integration';
    const URL_NOT_EXISTS = 'http://__i_am_not_real__.invalid/';

    public function ddSetUp()
    {
        $this->cleanUp();
        parent::ddSetUp();
        IntegrationsLoader::load();
    }

    public function ddTearDown()
    {
        parent::ddTearDown();
        $this->cleanUp();
    }

    private function cleanUp()
    {
        putenv('DD_CURL_ANALYTICS_ENABLED');
        putenv('DD_DISTRIBUTED_TRACING');
        putenv('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN');
        putenv('DD_TRACE_MEMORY_LIMIT');
        putenv('DD_TRACE_SPANS_LIMIT');
    }

    private static function commonCurlInfoTags()
    {
        $tags = [
            'duration',
            'network.bytes_read',
            'network.bytes_written',
        ];
        if (\version_compare(\PHP_VERSION, '5.4.7', '>=')) {
            $tags += \array_merge(
                $tags,
                ['network.client.ip', 'network.client.port', 'network.destination.ip', 'network.destination.port']
            );
        }
        return $tags;
    }

    public function testLoad200UrlOnInit()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/?')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ]);
    }

    public function testSampleExternalAgent()
    {
        $traces = $this->simulateAgent(function () {
            $ch = curl_init(self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/?')
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ]);
    }

    public function testLoad200UrlAsOpt()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/?')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ]);
    }

    public function testDoesNotInheritTopLevelAppName()
    {
        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('GET', '/curl_in_web_request.php'));
            },
            __DIR__ . '/curl_in_web_request.php',
            [
                'DD_SERVICE' => 'top_level_app',
                'DD_TRACE_NO_AUTOLOADER' => true,
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'top_level_app', 'web', 'GET /curl_in_web_request.php')
                ->withExistingTagsNames(['http.method', 'http.url', 'http.status_code'])
                ->withChildren([
                    SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/?')
                        ->setTraceAnalyticsCandidate()
                        ->withExactTags([
                            'http.url' => self::URL . '/status/200',
                            'http.status_code' => '200',
                        ])
                        ->withExistingTagsNames(self::commonCurlInfoTags())
                        ->skipTagsLike('/^curl\..*/'),
                ]),
        ]);
    }

    public function testPrivateCallbackForResponseHeaders()
    {
        $traces = $this->isolateTracer(function () {
            $foo = new PrivateCallbackRequest();
            $response = $foo->request();
            $this->assertEmpty($response);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/?')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ]);
    }

    public function testLoad404UrlOnInit()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL . '/status/404');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/?')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/404',
                    'http.status_code' => '404',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ]);
    }

    public function testLoadUnroutableIP()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "http://10.255.255.1/");
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 100);
            curl_exec($ch);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://10.255.255.1/')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => 'http://10.255.255.1/',
                    'http.status_code' => '0',
                ])
                ->withExistingTagsNames(['error.msg'])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/')
                ->setError('curl error'),
        ]);
    }

    public function testLoadOperationTimeout()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "http://10.255.255.1/");
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
            curl_exec($ch);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://10.255.255.1/')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => 'http://10.255.255.1/',
                    'http.status_code' => '0',
                ])
                ->withExistingTagsNames(['error.msg'])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/')
                ->setError('curl error'),
        ]);
    }

    public function testNonExistingHost()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL_NOT_EXISTS);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertFalse($response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://__i_am_not_real__.invalid/')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => 'http://__i_am_not_real__.invalid/',
                    'http.status_code' => '0',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/')
                ->setError('curl error', 'Could not resolve host: __i_am_not_real__.invalid'),
        ]);
    }

    public function testDistributedTracingIsPropagated()
    {
        $found = [];
        $traces = $this->isolateTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $headers = [
                'x-datadog-trace-id' => '1337',
                'x-datadog-parent-id' => '42',
                'x-datadog-sampling-priority' => (string) PrioritySampling::AUTO_KEEP,
            ];
            $span = $tracer->startRootSpan(
                'custom',
                StartSpanOptionsFactory::createForWebRequest($tracer, [], $headers)
            )->getSpan();

            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'honored: preserved_value',
            ]);
            $found = json_decode(curl_exec($ch), 1);

            $span->finish();
        });

        self::assertTrue(function_exists('DDTrace\\Bridge\\curl_inject_distributed_headers'));

        // trace is: custom
        self::assertSame($traces[0][0]['trace_id'], (int) $found['headers']['X-Datadog-Trace-Id']);
        // parent is: curl_exec
        self::assertSame($traces[0][1]['span_id'], (int) $found['headers']['X-Datadog-Parent-Id']);
        self::assertSame('1', $found['headers']['X-Datadog-Sampling-Priority']);
        self::assertSame($traces[0][0]['metrics']['_sampling_priority_v1'], PrioritySampling::AUTO_KEEP);
        // existing headers are honored
        self::assertSame('preserved_value', $found['headers']['Honored']);
    }

    public function testOriginIsPropagatedAndSetsRootSpanTag()
    {
        $found = [];
        $traces = $this->isolateTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $headers = [
                'x-datadog-trace-id' => '1337',
                'x-datadog-parent-id' => '42',
                'x-datadog-origin' => 'foo_origin',
            ];
            $span = $tracer->startRootSpan(
                'custom',
                StartSpanOptionsFactory::createForWebRequest($tracer, [], $headers)
            )->getSpan();

            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $found = json_decode(curl_exec($ch), 1);

            $span->finish();
        });

        $this->assertSame('foo_origin', $found['headers']['X-Datadog-Origin']);
        $this->assertSame('foo_origin', $traces[0][0]['meta']['_dd.origin']);
    }

    public function testDistributedTracingIsPropagatedOnCopiedHandle()
    {
        $found = [];
        $traces = $this->isolateTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $headers = [
                'x-datadog-trace-id' => '1337',
                'x-datadog-parent-id' => '42',
                'x-datadog-sampling-priority' => (string) PrioritySampling::AUTO_KEEP,
            ];
            $span = $tracer->startRootSpan(
                'custom',
                StartSpanOptionsFactory::createForWebRequest($tracer, [], $headers)
            )->getSpan();

            $ch1 = \curl_init(self::URL . '/headers');
            \curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch1, CURLOPT_HTTPHEADER, [
                'honored: preserved_value',
            ]);
            $ch2 = \curl_copy_handle($ch1);
            \curl_close($ch1);
            $found = \json_decode(\curl_exec($ch2), 1);

            $span->finish();
        });

        // trace is: custom
        $this->assertSame($traces[0][0]['trace_id'], (int) $found['headers']['X-Datadog-Trace-Id']);
        // parent is: curl_exec
        $this->assertSame($traces[0][1]['span_id'], (int) $found['headers']['X-Datadog-Parent-Id']);
        $this->assertSame('1', $found['headers']['X-Datadog-Sampling-Priority']);
        // existing headers are honored
        $this->assertSame('preserved_value', $found['headers']['Honored']);
    }

    public function testDistributedTracingIsNotPropagatedIfDisabled()
    {
        putenv('DD_DISTRIBUTED_TRACING=false');
        $found = [];

        $this->isolateTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $headers = [
                'x-datadog-trace-id' => '1337',
                'x-datadog-parent-id' => '42',
                'x-datadog-sampling-priority' => (string) PrioritySampling::AUTO_KEEP,
            ];
            $span = $tracer->startRootSpan(
                'custom',
                StartSpanOptionsFactory::createForWebRequest($tracer, [], $headers)
            )->getSpan();

            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $found = json_decode(curl_exec($ch), 1);
            $span->finish();
        });

        $this->assertArrayNotHasKey('X-Datadog-Trace-Id', $found['headers']);
        $this->assertArrayNotHasKey('X-Datadog-Parent-Id', $found['headers']);
        $this->assertArrayNotHasKey('X-Datadog-Sampling-Priority', $found['headers']);
    }

    public function testTracerIsRunningAtLimitedCapacityWeStillPropagateTheSpan()
    {
        putenv('DD_TRACE_SPANS_LIMIT=0');
        dd_trace_internal_fn('ddtrace_reload_config');
        $found = [];
        $traces = $this->isolateTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $headers = [
                'x-datadog-trace-id' => '1337',
                'x-datadog-parent-id' => '42',
                'x-datadog-sampling-priority' => (string) PrioritySampling::AUTO_KEEP,
            ];
            $span = $tracer->startRootSpan(
                'custom',
                StartSpanOptionsFactory::createForWebRequest($tracer, [], $headers)
            )->getSpan();

            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'honored: preserved_value',
            ]);
            $found = json_decode(curl_exec($ch), 1);

            $span->finish();
        });
        putenv('DD_TRACE_MEMORY_LIMIT');
        dd_trace_internal_fn('ddtrace_reload_config');

        $this->assertSame('1', $found['headers']['X-Datadog-Sampling-Priority']);
        // existing headers are honored
        $this->assertSame('preserved_value', $found['headers']['Honored']);

        $this->assertEquals(1, sizeof($traces[0]));

        // trace is: custom
        $this->assertSame($traces[0][0]['trace_id'], (int) $found['headers']['X-Datadog-Trace-Id']);
        // parent is: custom
        $this->assertSame($traces[0][0]['span_id'], (int) $found['headers']['X-Datadog-Parent-Id']);
    }

    public function testTracerRunningAtLimitedCapacityCurlWorksWithoutARootSpan()
    {
        $found = [];
        $traces = $this->isolateLimitedTracer(function () use (&$found) {
            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'honored: preserved_value',
            ]);
            $found = json_decode(curl_exec($ch), 1);
        });

        // existing headers are honored
        $this->assertSame('preserved_value', $found['headers']['Honored']);

        $this->assertArrayNotHasKey('X-Datadog-Trace-Id', $found['headers']);
        $this->assertArrayNotHasKey('X-Datadog-Parent-Id', $found['headers']);
        $this->assertArrayNotHasKey('X-Datadog-Sampling-Priority', $found['headers']);

        $this->assertEmpty($traces);
    }

    public function testAppendHostnameToServiceName()
    {
        putenv('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN=true');

        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'curl_exec',
                'host-httpbin_integration',
                'http',
                'http://httpbin_integration/status/?'
            )
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ]);
    }

    public function testHttpHeadersIsCorrectlySetAgain()
    {
        $this->inRootSpan(function () {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => self::URL . '/headers',
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Host: test.invalid'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FAILONERROR => false,
                CURLOPT_HEADER => false,
            ]);
            $found = json_decode(curl_exec($ch), 1);

            $this->assertSame('test.invalid', $found['headers']['Host']);
            $this->assertSame('application/json', $found['headers']['Accept']);
            $this->assertSame('1', $found['headers']['X-Datadog-Sampling-Priority']);
        });
    }

    /**
     * @dataProvider dataProviderTestTraceAnalytics
     */
    public function testTraceAnalytics($envsOverride, $expectedSampleRate)
    {
        $env = array_merge(['DD_SERVICE' => 'top_level_app'], $envsOverride);

        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('GET', '/curl_in_web_request.php'));
            },
            __DIR__ . '/curl_in_web_request.php',
            $env
        );

        $metrics = [ '_sampling_priority_v1' => 1 ];
        if (null !== $expectedSampleRate) {
            $metrics = array_merge($metrics, [ '_dd1.sr.eausr' => $expectedSampleRate ]);
        }

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'top_level_app', 'web', 'GET /curl_in_web_request.php')
                ->withExistingTagsNames(['http.method', 'http.url', 'http.status_code'])
                ->withChildren([
                    SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/?')
                        ->setTraceAnalyticsCandidate()
                        ->withExactTags([
                            'http.url' => self::URL . '/status/200',
                            'http.status_code' => '200',
                        ])
                        ->withExistingTagsNames(self::commonCurlInfoTags())
                        ->withExactMetrics($metrics)
                        ->skipTagsLike('/^curl\..*/'),
                ]),
        ]);
    }

    public function dataProviderTestTraceAnalytics()
    {
        return [
            'not set' => [
                [],
                null,
            ],
            'off no rate' => [
                [
                    'DD_TRACE_CURL_ANALYTICS_ENABLED' => false,
                ],
                null,
            ],
            'off legacy name no rate' => [
                [
                    'DD_CURL_ANALYTICS_ENABLED' => false,
                ],
                null,
            ],
            'off with rate' => [
                [
                    'DD_TRACE_CURL_ANALYTICS_ENABLED' => false,
                    'DD_TRACE_CURL_ANALYTICS_SAMPLE_RATE' => 0.7,
                ],
                null,
            ],
            'off legacy name with rate' => [
                [
                    'DD_CURL_ANALYTICS_ENABLED' => false,
                    'DD_CURL_ANALYTICS_SAMPLE_RATE' => 0.7,
                ],
                null,
            ],
            'enabled default rate' => [
                [
                    'DD_TRACE_CURL_ANALYTICS_ENABLED' => true,
                ],
                1.0,
            ],
            'enabled legacy name default rate' => [
                [
                    'DD_CURL_ANALYTICS_ENABLED' => true,
                ],
                1.0,
            ],
            'enabled specific rate' => [
                [
                    'DD_TRACE_CURL_ANALYTICS_ENABLED' => true,
                    'DD_TRACE_CURL_ANALYTICS_SAMPLE_RATE' => 0.7,
                ],
                0.7,
            ],
            'enabled legacy name specific rate' => [
                [
                    'DD_CURL_ANALYTICS_ENABLED' => true,
                    'DD_CURL_ANALYTICS_SAMPLE_RATE' => 0.7,
                ],
                0.7,
            ],
        ];
    }
}
