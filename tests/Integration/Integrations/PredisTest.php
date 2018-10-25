<?php

namespace DDTrace\Tests\Integration\Integrations;

use DDTrace\Encoders\Json;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\Transport\Stream;
use DDTrace\Integrations\Predis;
use DDTrace\Tests\DebugTransport;

use PHPUnit\Framework\TestCase;
use OpenTracing\GlobalTracer;

final class PredisTest extends TestCase
{
    public static function setUpBeforeClass()
    {
        Predis::load();
    }

    public function redisHostname()
    {
        return $_SERVER["REDIS_HOSTNAME"] ? $_SERVER["REDIS_HOSTNAME"] : '0.0.0.0';
    }

    public function withTracer($fn)
    {
        $transport = new DebugTransport();
        $tracer = new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);
        $tracer->flush();

        return $transport->getTraces();
    }

    public function inTestScope($name, $fn)
    {
        return $this->withTracer(function ($tracer) use ($fn, $name) {
            $scope = $tracer->startActiveSpan($name);
            $fn($tracer);
            $scope->close();
        });
    }

    public function testPredisIntegrationCreatesSpans()
    {
        $traces = $this->inTestScope('redis.test', function () {
            $client = new \Predis\Client([ "host" => $this->redisHostname() ]);
            $value = 'bar';

            $client->set('foo', $value);

            $this->assertEquals($client->get('foo'), $value);
        });

        $this->assertCount(1, $traces);
        $trace = $traces[0];

        $this->assertContainsOnlyInstancesOf("\OpenTracing\Span", $trace);
        $this->assertGreaterThan(2, count($trace)); # two Redis operations -> at least 2 spans
    }

    public function testPredisSetCommandSpanExists()
    {
        $client = new \Predis\Client([ "host" => $this->redisHostname() ]);

        $traces = $this->withTracer(function () use ($client) {
            $client->set('foo', 'value');
        });
        $this->assertCount(1, $traces);
        $this->assertCount(1, $traces[0]);

        $span = $traces[0][0];

        $this->assertEquals('SET foo value', $span->getResource());
        $this->assertEquals('redis', $span->getType());
        $this->assertEquals('redis', $span->getService());

        $this->assertNotEmpty($span->getTraceId());
        $this->assertNotEmpty($span->getSpanId());
        $this->assertEquals($span->getTraceId(), $span->getSpanId());
    }
}
