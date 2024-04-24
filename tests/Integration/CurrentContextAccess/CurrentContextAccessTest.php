<?php

namespace DDTrace\Tests\Integration\CurrentContextAccess;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class CurrentContextAccessTest extends IntegrationTestCase
{
    // Source: https://magp.ie/2015/09/30/convert-large-integer-to-hexadecimal-without-php-math-extension/
    public function largeBaseConvert($numString, $fromBase, $toBase)
    {
        $chars = "0123456789abcdefghijklmnopqrstuvwxyz";
        $toString = substr($chars, 0, $toBase);

        $length = strlen($numString);
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $number[$i] = strpos($chars, $numString[$i]);
        }
        do {
            $divide = 0;
            $newLen = 0;
            for ($i = 0; $i < $length; $i++) {
                $divide = $divide * $fromBase + $number[$i];
                if ($divide >= $toBase) {
                    $number[$newLen++] = (int)($divide / $toBase);
                    $divide = $divide % $toBase;
                } elseif ($newLen > 0) {
                    $number[$newLen++] = 0;
                }
            }
            $length = $newLen;
            $result = $toString[$divide] . $result;
        } while ($newLen != 0);

        return $result;
    }

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
        $tid = $trace[0]['meta']['_dd.p.tid'];
        $this->assertNotEquals(0, $traceId);

        $traceIdHex = str_pad(self::largeBaseConvert($traceId, 10, 16), 16, '0', STR_PAD_LEFT);

        foreach ($trace as $span) {
            $spanId = $span['span_id'];
            $this->assertNotEquals(0, $spanId);
            $this->assertSame($traceId, $span['trace_id']);
            $this->assertSame($spanId, $span['meta']['extracted_span_id']);
            $this->assertSame($tid . $traceIdHex, $span['meta']['extracted_trace_id']);
        }
    }

    public function testInShortRunningCliScript()
    {
        list($traces) = $this->inCli(__DIR__ . '/short-running.php', ['DD_TRACE_GENERATE_ROOT_SPAN' => 'true']);

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
