<?php

namespace DDTrace\Tests\Integration;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class ResponseStatusCodeTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/ResponseStatusCodeTest_files/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_NO_AUTOLOADER' => '1',
        ]);
    }

    /**
     * Testing the successful response code (200)
     * root span + custom span generated in index.php script.
     */
    public function testResponseStatusCodeSuccess()
    {
        $traces = $this->tracesFromWebRequest(
            function () {
                $this->call(GetSpec::create('Root', '/success'));
            }
        );

        $this->assertExpectedSpans(
            $traces,
            [
                SpanAssertion::build('web.request', 'web.request', 'web', 'GET /success')->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost/success',
                    'http.status_code' => '200',
                ]),
            ]
        );
    }

    /**
     * Testing the successful response code (500)
     * root span + custom span generated in index.php script.
     */
    public function testResponseStatusCodeError()
    {
        $traces = $this->tracesFromWebRequest(
            function () {
                $this->call(GetSpec::create('Root', '/error')->expectStatusCode(500));
            }
        );

        $this->assertExpectedSpans(
            $traces,
            [
                SpanAssertion::build('web.request', 'web.request', 'web', 'GET /error')->withExactTags(
                    [
                        'http.method'      => 'GET',
                        'http.url'         => 'http://localhost/error',
                        'http.status_code' => '500',
                    ]
                )->setError(),
            ]
        );
    }
}
