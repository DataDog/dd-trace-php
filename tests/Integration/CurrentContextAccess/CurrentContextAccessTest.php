<?php

namespace DDTrace\Tests\Integration\CurrentContextAccess;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanChecker;
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

        $chunk = $traces[0];
        $this->assertCount(2, $chunk['spans']);
        $this->assertNotEquals(0, SpanChecker::traceIdLow($chunk));

        foreach ($chunk['spans'] as $span) {
            $spanId = $span['span_id'];
            $this->assertNotEquals(0, $spanId);
            $this->assertSame($spanId, $span['attributes']['extracted_span_id']);
            $this->assertSame($chunk['trace_id'], $span['attributes']['extracted_trace_id']);
        }
    }

    public function testInShortRunningCliScript()
    {
        list($traces) = $this->inCli(__DIR__ . '/short-running.php', ['DD_TRACE_GENERATE_ROOT_SPAN' => 'true']);

        $chunk = $traces[0];
        $this->assertCount(2, $chunk['spans']);

        $traceId = SpanChecker::traceIdLow($chunk);
        $this->assertNotEquals(0, $traceId);

        foreach ($chunk['spans'] as $span) {
            $spanId = $span['span_id'];
            $this->assertNotEquals(0, $spanId);
            $this->assertSame($spanId, $span['attributes']['extracted_span_id']);
            $this->assertSame($traceId, $span['attributes']['extracted_trace_id']);
        }
    }

    public function testInLongRunningCliScript()
    {
        list($traces) = $this->inCli(
            __DIR__ . '/long-running.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => true,
                'DD_TRACE_GENERATE_ROOT_SPAN' => false,
            ]
        );

        $chunk = $traces[0];
        $this->assertCount(2, $chunk['spans']);

        $traceId = SpanChecker::traceIdLow($chunk);
        $this->assertNotEquals(0, $traceId);
        $this->assertSame('root_span', $chunk['spans'][0]['name']);
        $this->assertSame('internal_span', $chunk['spans'][1]['name']);

        foreach ($chunk['spans'] as $span) {
            $spanId = $span['span_id'];
            $this->assertNotEquals(0, $spanId);
            $this->assertSame($spanId, $span['attributes']['extracted_span_id']);
            $this->assertSame($traceId, $span['attributes']['extracted_trace_id']);
        }
    }
}
