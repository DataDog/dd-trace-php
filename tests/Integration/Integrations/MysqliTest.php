<?php

namespace DDTrace\Tests\Integration\Integrations;

use DDTrace\Integrations\Mysqli;
use DDTrace\Tracer;
use DDTrace\Transport;
use OpenTracing\GlobalTracer;
use PHPUnit\Framework;

final class Sink implements Transport
{
    public $traces = [];
    public $headers = [];

    public function send(array $traces)
    {
        $this->traces = $traces;
    }

    public function setHeader($key, $value)
    {
        $this->headers[$key] = $value;
    }
}

final class MysqliTest extends Framework\TestCase
{
    public function testProceduralConnect()
    {
        $sink = new Sink();
        $tracer = new Tracer($sink);
        GlobalTracer::set($tracer);
        Mysqli::load();

        $mysql = mysqli_connect('127.0.0.1', 'test', 'test', 'test');

        $tracer->flush();

        $spans = $sink->traces[0];
        $this->assertEquals(1, count($spans));
    }

    public function testProceduralConnectError()
    {
        $sink = new Sink();
        $tracer = new Tracer($sink);
        GlobalTracer::set($tracer);
        Mysqli::load();

        $mysql = @mysqli_connect('0.0.0.0');

        $tracer->flush();

        $spans = $sink->traces[0];
        $this->assertEquals(1, count($spans));
        $span = $spans[0];
        $this->assertTrue($span->hasError());
    }
}
