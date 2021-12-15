<?php

namespace DDTrace\Tests\Integration;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/BootstrapTest_files/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_SPANS_LIMIT' => '1',
        ]);
    }

    /**
     * Testing that the tracer is still flushed even if the span limit `DD_TRACE_SPANS_LIMIT=1` is reached because of
     * root span + custom span generated in index.php script.
     */
    public function testTracerFlushedWhenSpanLimitExceeded()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/'));
            // We explicitly assert the configured value of 'DD_TRACE_SPANS_LIMIT' echoed by the web app
            // because if we add tests to this test case that require a larger limit the current test would still pass
            // but would not test the specific edge case.
            TestCase::assertSame('1', $response);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('web.request')
                ->withChildren([
                    SpanAssertion::exists('my_span')
                ]),
        ]);
    }
}
