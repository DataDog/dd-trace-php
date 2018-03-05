<?php

namespace DDTrace\Tests\Integration\Transport;

use DDTrace\Encoders\Json;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\Transport\Noop;
use Exception;
use PHPUnit_Framework_TestCase;

final class HttpTest extends PHPUnit_Framework_TestCase
{
    public function testSpanReportingFailsOnUnavailableAgent()
    {
        $tracer = new Tracer(new Noop);

        $httpTransport = new Http(new Json, [
            'endpoint' => 'http://0.0.0.0:8127/v0.3/traces'
        ]);

        $span = $tracer->startSpan('test', [
            'tags' => [
                'key1' => 'value1',
            ]
        ]);

        $span->finish();

        $traces = [
            [$span],
        ];

        try {
            $httpTransport->send($traces);
            $this->fail('Sending expected to fail.');
        } catch (Exception $e) {
        }
    }

    public function testSpanReportingSuccess()
    {
        $tracer = new Tracer(new Noop);

        $httpTransport = new Http(new Json, [
            'endpoint' => 'http://0.0.0.0:8126/v0.3/traces'
        ]);

        $span = $tracer->startSpan('test', [
            'tags' => [
                'key1' => 'value1',
            ]
        ]);

        $childSpan = $tracer->startSpan('child_test', [
            'child_of' => $span,
            'tags' => [
                'key2' => 'value2',
            ]
        ]);

        $childSpan->finish();

        $span->finish();

        $traces = [
            [$span, $childSpan],
        ];

        try {
            $httpTransport->send($traces);
        } catch (Exception $e) {
            $this->fail(sprintf('Sending expected to not to fail: %s', $e->getMessage()));
        }
    }
}
