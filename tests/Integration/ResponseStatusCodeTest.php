<?php


namespace DDTrace\Tests\Integration;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use PHPUnit\Framework\TestCase;

class ResponseStatusCodeTest extends WebFrameworkTestCase {
    protected static function getEnvs() {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_NO_AUTOLOADER' => '1',
            'DD_TRACE_SPANS_LIMIT'   => '1',
        ]);
    }

    /**
     * Testing the successful response code (200)
     * root span + custom span generated in index.php script.
     */
    public function testResponseStatusCodeSuccess() {
        $traces = $this->tracesFromWebRequest(
            function () {
                $response = $this->call(GetSpec::create('Root', '/index.php'));
                // We explicitly assert the configured value of 'DD_TRACE_SPANS_LIMIT' echoed by the web app
                // because if we add tests to this test case that require a larger limit the current test would still pass
                // but would not test the specific edge case.
                TestCase::assertSame('1', $response);
            }
        );

        $this->assertExpectedSpans(
            $traces, [
                SpanAssertion::build('web.request', 'web.request', 'web', 'web.request')->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/index.php',
                    'http.status_code' => '200',
                    'integration.name' => 'web',
                ]),
            ]
        );
    }

    /**
     * Testing the successful response code (500)
     * root span + custom span generated in index.php script.
     */
    public function testResponseStatusCodeError() {
        $traces = $this->tracesFromWebRequest(
            function () {
                $response = $this->call(GetSpec::create('Root', '/error.php'));
                // We explicitly assert the configured value of 'DD_TRACE_SPANS_LIMIT' echoed by the web app
                // because if we add tests to this test case that require a larger limit the current test would still pass
                // but would not test the specific edge case.
                TestCase::assertSame('1', $response);
            }
        );

        $this->assertExpectedSpans(
            $traces, [
                       SpanAssertion::build('web.request', 'web.request', 'web', 'web.request')->withExactTags(
                           [
                               'http.method'      => 'GET',
                               'http.url'         => '/error.php',
                               'http.status_code' => '500',
                               'integration.name' => 'web',
                           ]
                       ),
                   ]
        );
    }
}
