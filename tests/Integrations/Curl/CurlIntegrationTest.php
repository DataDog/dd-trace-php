<?php

namespace DDTrace\Tests\Integrations\Curl;

use DDTrace\Configuration;
use DDTrace\Formats;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tracer;
use DDTrace\Util\ArrayKVStore;
use DDTrace\GlobalTracer;

final class CurlIntegrationTest extends IntegrationTestCase
{
    const URL = 'http://httpbin_integration';
    const URL_NOT_EXISTS = '__i_am_not_real__.invalid';

    public static function setUpBeforeClass()
    {
        IntegrationsLoader::load();
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
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/200')
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                ]),
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
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/200')
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                ]),
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
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/404')
                ->withExactTags([
                    'http.url' => self::URL . '/status/404',
                    'http.status_code' => '404',
                ]),
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
                    'error.type' => 'curl error',
                ])
                ->withExistingTagsNames(['error.msg'])
                ->setError(),
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
                    'error.type' => 'curl error',
                ])
                ->withExistingTagsNames(['error.msg'])
                ->setError(),
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
                    'error.msg' => 'Could not resolve host: __i_am_not_real__.invalid',
                    'error.type' => 'curl error',
                ])
                ->setError(),
        ]);
    }

    public function testKVStoreIsCleanedOnCurlClose()
    {
        $ch = curl_init(self::URL . '/status/200');
        curl_setopt($ch, CURLOPT_HTTPHEADER, []);
        $this->assertNotSame('default', ArrayKVStore::getForResource($ch, Formats\CURL_HTTP_HEADERS, 'default'));
        curl_close($ch);
        $this->assertSame('default', ArrayKVStore::getForResource($ch, Formats\CURL_HTTP_HEADERS, 'default'));
    }

    public function testDistributedTracingIsPropagated()
    {
        $found = [];

        $traces = $this->isolateTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $tracer->setPrioritySampling(PrioritySampling::AUTO_KEEP);
            $span = $tracer->startActiveSpan('some_operation')->getSpan();

            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'honored: preserved_value',
            ]);
            $found = json_decode(curl_exec($ch), 1);

            $span->finish();
        });

        // trace is: some_operation
        $this->assertSame($traces[0][0]->getContext()->getSpanId(), $found['headers']['X-Datadog-Trace-Id']);
        // parent is: curl_exec
        $this->assertSame($traces[0][1]->getContext()->getSpanId(), $found['headers']['X-Datadog-Parent-Id']);
        $this->assertSame('1', $found['headers']['X-Datadog-Sampling-Priority']);
        // existing headers are honored
        $this->assertSame('preserved_value', $found['headers']['Honored']);
    }

    public function testDistributedTracingIsNotPropagatedIfDisabled()
    {
        $found = [];
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isDistributedTracingEnabled' => false,
            'isPrioritySamplingEnabled' => false,
        ]));

        $this->isolateTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $tracer->setPrioritySampling(PrioritySampling::AUTO_KEEP);
            $span = $tracer->startActiveSpan('some_operation')->getSpan();

            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $found = json_decode(curl_exec($ch), 1);

            $span->finish();
        });

        $this->assertArrayNotHasKey('X-Datadog-Trace-Id', $found['headers']);
        $this->assertArrayNotHasKey('X-Datadog-Parent-Id', $found['headers']);
        $this->assertArrayNotHasKey('X-Datadog-Sampling-Priority', $found['headers']);
    }
}
