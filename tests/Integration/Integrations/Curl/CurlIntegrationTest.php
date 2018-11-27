<?php

namespace DDTrace\Tests\Integration\Integrations\Curl;

use DDTrace\Integrations\Curl\CurlIntegration;
use DDTrace\Tests\Integration\Common\IntegrationTestCase;
use DDTrace\Tests\Integration\Common\SpanAssertion;


final class CurlIntegrationTest extends IntegrationTestCase
{
    const URL = 'http://httpbin_integration';
    const URL_NOT_EXISTS = '__i_am_not_real__.com';

    public static function setUpBeforeClass()
    {
        CurlIntegration::load();
    }

    public function testLoad200UrlOnInit()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
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
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://httpbin_integration/status/404')
                ->withExactTags([
                    'http.url' => self::URL . '/status/404',
                    'http.status_code' => '404',
                ]),
        ]);
    }

    public function testNonExistingHost()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL_NOT_EXISTS);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertFalse($response);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'curl', 'http', 'http://__i_am_not_real__.com/')
                ->withExactTags([
                    'http.url' => 'http://__i_am_not_real__.com/',
                    'http.status_code' => '0',
                    'error.msg' => 'Could not resolve host: __i_am_not_real__.com',
                    'error.type' => 'curl error',
                ])
                ->setError(),
        ]);
    }
}
