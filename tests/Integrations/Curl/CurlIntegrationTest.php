<?php

namespace DDTrace\Tests\Integrations\Curl;

use DDTrace\Tag;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
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
    const URL_WITH_CREDENTIALS = 'http://my_user:my_password@httpbin_integration';
    const URL_NOT_EXISTS = 'http://__i_am_not_real__.invalid/';

    public function ddSetUp()
    {
        parent::ddSetUp();
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
            'DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED',
            'DD_TRACE_PROPAGATION_STYLE_INJECT',
        ];
    }

    public static function getTestedLibrary()
    {
        return 'ext-curl';
    }

    private static function commonCurlInfoTags()
    {
        $tags = [
            'curl.total_time',
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
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'span.kind' => 'client',
                    'network.destination.name' => 'httpbin_integration',
                    Tag::COMPONENT => 'curl',
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
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'span.kind' => 'client',
                    'network.destination.name' => 'httpbin_integration',
                    Tag::COMPONENT => 'curl',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ]);
    }

    public function testInlineCredentials()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::URL_WITH_CREDENTIALS . '/basic-auth/my_user/my_password');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertStringContains('my_user', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://?:?@httpbin_integration/basic-auth/my_user/my_password')
                ->withExactTags([
                    'http.url' => 'http://?:?@httpbin_integration/basic-auth/my_user/my_password',
                    'http.status_code' => '200',
                    'span.kind' => 'client',
                    'network.destination.name' => 'httpbin_integration',
                    Tag::COMPONENT => 'curl',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ]);
    }

    public function testCredentialsViaBasicAuth()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::URL . '/basic-auth/my_user/my_password');
            curl_setopt($ch, CURLOPT_USERPWD, "my_user:my_password");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertStringContains('my_user', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/basic-auth/my_user/my_password')
                ->withExactTags([
                    'http.url' => self::URL . '/basic-auth/my_user/my_password',
                    'http.status_code' => '200',
                    'span.kind' => 'client',
                    'network.destination.name' => 'httpbin_integration',
                    Tag::COMPONENT => 'curl',
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
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'top_level_app', 'web', 'GET /curl_in_web_request.php')
                ->withExistingTagsNames(['http.method', 'http.url', 'http.status_code'])
                ->withChildren([
                    SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/?')
                        ->withExactTags([
                            'http.url' => self::URL . '/status/200',
                            'http.status_code' => '200',
                            'span.kind' => 'client',
                            'network.destination.name' => 'httpbin_integration',
                            Tag::COMPONENT => 'curl',
                            '_dd.base_service' => 'top_level_app',
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
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'span.kind' => 'client',
                    'network.destination.name' => 'httpbin_integration',
                    Tag::COMPONENT => 'curl',
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
                ->withExactTags([
                    'http.url' => self::URL . '/status/404',
                    'http.status_code' => '404',
                    'span.kind' => 'client',
                    'network.destination.name' => 'httpbin_integration',
                    Tag::COMPONENT => 'curl',
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
                ->withExactTags([
                    'http.url' => 'http://10.255.255.1/',
                    'http.status_code' => '0',
                    'span.kind' => 'client',
                    'network.destination.name' => '10.255.255.1',
                    Tag::COMPONENT => 'curl',
                ])
                ->withExistingTagsNames([Tag::ERROR_MSG])
                ->withExistingTagsNames([Tag::ERROR_STACK])
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
                ->withExactTags([
                    'http.url' => 'http://10.255.255.1/',
                    'http.status_code' => '0',
                    'span.kind' => 'client',
                    'network.destination.name' => '10.255.255.1',
                    Tag::COMPONENT => 'curl',
                ])
                ->withExistingTagsNames([Tag::ERROR_MSG])
                ->withExistingTagsNames([Tag::ERROR_STACK])
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
                ->withExactTags([
                    'http.url' => 'http://__i_am_not_real__.invalid/',
                    'http.status_code' => '0',
                    'span.kind' => 'client',
                    'network.destination.name' => '__i_am_not_real__.invalid',
                    Tag::COMPONENT => 'curl',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/')
                ->setError('curl error', 'Could not resolve host: __i_am_not_real__.invalid', true),
        ]);
    }

    public function testOriginIsPropagatedAndSetsRootSpanTag()
    {
        ini_set("datadog.trace.propagation_style_inject", "");

        $found = [];
        $traces = $this->inWebServer(
            function ($execute) use (&$found) {
                $found = json_decode($execute(GetSpec::create(
                    __FUNCTION__,
                    '/curl_request_headers.php',
                    [
                        'x-datadog-trace-id: 1337',
                        'x-datadog-parent-id: 42',
                        'x-datadog-origin: foo_origin',
                    ]
                )), 1);
            },
            __DIR__ . '/curl_request_headers.php',
            [
                'DD_TRACE_GENERATE_ROOT_SPAN' => true,
            ]
        );

        $this->assertSame('foo_origin', $found['headers']['X-Datadog-Origin']);
        $this->assertSame('foo_origin', $traces[0][0]['meta']['_dd.origin']);
    }

    public function testDistributedTracingIsPropagatedOnCopiedHandle()
    {
        ini_set("datadog.trace.propagation_style_inject", "");

        $found = [];
        $traces = $this->inWebServer(
            function ($execute) use (&$found) {
                $found = json_decode($execute(GetSpec::create(
                    __FUNCTION__,
                    '/curl_request_headers_with_copied_handle.php',
                    [
                        'x-datadog-trace-id: 1337',
                        'x-datadog-parent-id: 42',
                        'x-datadog-sampling-priority: ' . PrioritySampling::AUTO_KEEP,
                    ]
                )), 1);
            },
            __DIR__ . '/curl_request_headers_with_copied_handle.php'
        );

        // trace is: custom
        $this->assertSame($traces[0][0]['trace_id'], $found['headers']['X-Datadog-Trace-Id']);
        // parent is: curl_exec
        $this->assertSame($traces[0][1]['span_id'], $found['headers']['X-Datadog-Parent-Id']);
        $this->assertSame('1', $found['headers']['X-Datadog-Sampling-Priority']);
        // existing headers are honored
        $this->assertSame('preserved_value', $found['headers']['Honored']);
    }

    public function testDistributedTracingIsNotPropagatedIfDisabled()
    {
        ini_set("datadog.trace.propagation_style_inject", "");

        $this->inWebServer(
            function ($execute) use (&$found) {
                $found = json_decode($execute(GetSpec::create(
                    __FUNCTION__,
                    '/curl_request_headers.php',
                    [
                        'x-datadog-trace-id: 1337',
                        'x-datadog-parent-id: 42',
                        'x-datadog-sampling-priority: ' . PrioritySampling::AUTO_KEEP,
                    ]
                )), 1);
            },
            __DIR__ . '/curl_request_headers.php',
            [
                'DD_DISTRIBUTED_TRACING' => 'false'
            ]
        );

        $this->assertArrayNotHasKey('X-Datadog-Trace-Id', $found['headers']);
        $this->assertArrayNotHasKey('X-Datadog-Parent-Id', $found['headers']);
        $this->assertArrayNotHasKey('X-Datadog-Sampling-Priority', $found['headers']);
    }

    public function testTracerIsRunningAtLimitedCapacityWeStillPropagateTheSpan()
    {
        ini_set("datadog.trace.propagation_style_inject", "");

        $traces = $this->inWebServer(
            function ($execute) use (&$found) {
                $found = json_decode($execute(GetSpec::create(
                    __FUNCTION__,
                    '/curl_request_headers_with_copied_handle.php',
                    [
                        'x-datadog-trace-id: 1337',
                        'x-datadog-parent-id: 42',
                        'x-datadog-sampling-priority: ' . PrioritySampling::AUTO_KEEP,
                    ]
                )), 1);
            },
            __DIR__ . '/curl_request_headers_with_copied_handle.php',
            [
                'DD_TRACE_SPANS_LIMIT' => '0'
            ]
        );

        $this->assertSame('1', $found['headers']['X-Datadog-Sampling-Priority']);
        // existing headers are honored
        $this->assertSame('preserved_value', $found['headers']['Honored']);

        $this->assertEquals(1, sizeof($traces[0]));

        // trace is: custom
        $this->assertSame($traces[0][0]['trace_id'], $found['headers']['X-Datadog-Trace-Id']);
        // parent is: custom
        $this->assertSame($traces[0][0]['span_id'], $found['headers']['X-Datadog-Parent-Id']);
    }

    public function testAppendHostnameToServiceName()
    {
        self::putenv('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN=true');

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
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'span.kind' => 'client',
                    'network.destination.name' => 'httpbin_integration',
                    Tag::COMPONENT => 'curl',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ]);
    }

    public function testAppendHostnameToServiceNameInlineCredentials()
    {
        self::putenv('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN=true');

        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL_WITH_CREDENTIALS . '/status/200');
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
                'http://?:?@httpbin_integration/status/?'
            )
                ->withExactTags([
                    'http.url' => 'http://?:?@httpbin_integration/status/200',
                    'http.status_code' => '200',
                    'span.kind' => 'client',
                    'network.destination.name' => 'httpbin_integration',
                    Tag::COMPONENT => 'curl',
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

    public function testMulti()
    {
        $traces = $this->isolateTracer(function () {
            $ch1 = curl_init(self::URL . '/status/200');
            curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
            $ch2 = curl_init(self::URL_NOT_EXISTS);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);

            $mh = curl_multi_init();
            curl_multi_add_handle($mh, $ch1);
            curl_multi_add_handle($mh, $ch2);

            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh);
                }
                while (false !== curl_multi_info_read($mh));
            } while ($active && $status == CURLM_OK);

            $response = curl_multi_getcontent($ch1);
            $this->assertSame('', $response);
            $response = curl_multi_getcontent($ch2);
            $this->assertSame('', $response);
            curl_multi_close($mh);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('curl_multi_exec', 'curl', 'http', 'curl_multi_exec')
                ->withExactTags([
                    Tag::COMPONENT => 'curl',
                ])->withChildren([
                    SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/?')
                        ->withExactTags([
                            'http.url' => self::URL . '/status/200',
                            'http.status_code' => '200',
                            'span.kind' => 'client',
                            'network.destination.name' => 'httpbin_integration',
                            Tag::COMPONENT => 'curl',
                        ])
                        ->withExistingTagsNames(self::commonCurlInfoTags())
                        ->skipTagsLike('/^curl\..*/'),
                    SpanAssertion::build('curl_exec', 'curl', 'http', 'http://__i_am_not_real__.invalid/')
                        ->withExactTags([
                            'http.url' => 'http://__i_am_not_real__.invalid/',
                            'http.status_code' => '0',
                            'span.kind' => 'client',
                            'network.destination.name' => '__i_am_not_real__.invalid',
                            Tag::COMPONENT => 'curl',
                        ])
                        ->withExistingTagsNames(self::commonCurlInfoTags())
                        ->skipTagsLike('/^curl\..*/')
                        ->setError('curl error', "Couldn't resolve host name", true),
                ]),
        ]);
    }

    /**
     * @dataProvider dataProviderTestTraceAnalytics
     */
    public function testTraceAnalytics($envsOverride, $expectedSampleRate)
    {
        $env = array_merge(['DD_SERVICE' => 'top_level_app', 'DD_TRACE_GENERATE_ROOT_SPAN' => 'true'], $envsOverride);

        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('GET', '/curl_in_web_request.php'));
            },
            __DIR__ . '/curl_in_web_request.php',
            $env
        );

        $metrics = [];
        if (null !== $expectedSampleRate) {
            $metrics = array_merge($metrics, [ '_dd1.sr.eausr' => $expectedSampleRate ]);
        }

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'top_level_app', 'web', 'GET /curl_in_web_request.php')
                ->withExistingTagsNames(['http.method', 'http.url', 'http.status_code'])
                ->withExactMetrics(['_sampling_priority_v1' => 1, 'process_id' => getmypid()])
                ->withChildren([
                    SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/?')
                        ->withExactTags([
                            'http.url' => self::URL . '/status/200',
                            'http.status_code' => '200',
                            'span.kind' => 'client',
                            'network.destination.name' => 'httpbin_integration',
                            Tag::COMPONENT => 'curl',
                            '_dd.base_service' => 'top_level_app',
                        ])
                        ->withExistingTagsNames(self::commonCurlInfoTags())
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

    public function testPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
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
                    'span.kind' => 'client',
                    'network.destination.name' => 'httpbin_integration',
                    Tag::COMPONENT => 'curl',
                    'peer.service' => 'httpbin_integration',
                    '_dd.peer.service.source' => 'network.destination.name',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ]);
    }

    public function testNoFakeServices()
    {
        $this->putEnvAndReloadConfig([
            'DD_SERVICE=configured_service',
            'DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED=true',
            'DD_TRACE_GENERATE_ROOT_SPAN=true',
        ]);

        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'configured_service', 'http', 'http://httpbin_integration/status/?')
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'span.kind' => 'client',
                    'network.destination.name' => 'httpbin_integration',
                    Tag::COMPONENT => 'curl',
                ])
                ->withExistingTagsNames(self::commonCurlInfoTags())
                ->skipTagsLike('/^curl\..*/'),
        ], false);
    }
}
