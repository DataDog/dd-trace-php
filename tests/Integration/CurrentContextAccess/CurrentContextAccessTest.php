<?php

namespace DDTrace\Tests\Integration\CurrentContextAccess;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class CurrentContextAccessTest extends IntegrationTestCase
{
    public function testInWebRequest()
    {
        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('GET', '/web.php'));
            },
            __DIR__ . '/web.php',
            [
                'DD_SERVICE' => 'top_level_app',
                'DD_TRACE_NO_AUTOLOADER' => true,
            ]
        );

        $trace = $traces[0];
        $this->assertCount(2, $trace);

        $traceId = $trace[0]['trace_id'];
        $this->assertNotEquals(0, $traceId);

        foreach ($trace as $span) {
            $spanId = $span['span_id'];
            $this->assertNotEquals(0, $spanId);
            $this->assertSame($traceId, $span['trace_id']);
            $this->assertSame($spanId, $span['meta']['extracted_span_id']);
            $this->assertSame($traceId, $span['meta']['extracted_trace_id']);
        }
    }

    public function testInShortRunningCliScript()
    {
        list($traces) = $this->inCli(__DIR__ . '/short-running.php');

        $trace = $traces[0];
        $this->assertCount(2, $trace);

        $traceId = $trace[0]['trace_id'];
        $this->assertNotEquals(0, $traceId);

        foreach ($trace as $span) {
            $spanId = $span['span_id'];
            $this->assertNotEquals(0, $spanId);
            $this->assertSame($traceId, $span['trace_id']);
            $this->assertSame($spanId, $span['meta']['extracted_span_id']);
            $this->assertSame($traceId, $span['meta']['extracted_trace_id']);
        }
    }

    public function testInLongRunningCliScript()
    {
        if (\PHP_MAJOR_VERSION === 5) {
            return $this->markTestSkipped('Long running processes are currently not supported on PHP 5');
        }

        list($traces) = $this->inCli(
            __DIR__ . '/long-running.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => true,
                'DD_TRACE_GENERATE_ROOT_SPAN' => false,
            ]
        );

        $trace = $traces[0];
        $this->assertCount(2, $trace);

        $traceId = $trace[0]['trace_id'];
        $this->assertNotEquals(0, $traceId);
        $this->assertSame('root_span', $trace[0]['name']);
        $this->assertSame('internal_span', $trace[1]['name']);

        foreach ($trace as $span) {
            $spanId = $span['span_id'];
            $this->assertNotEquals(0, $spanId);
            $this->assertSame($traceId, $span['trace_id']);
            $this->assertSame($spanId, $span['meta']['extracted_span_id']);
            $this->assertSame($traceId, $span['meta']['extracted_trace_id']);
        }
    }
}
