<?php

namespace DDTrace\Tests\Unit\Transport;

use DDTrace\Encoders\Json;
use DDTrace\Tests\Unit\BaseTestCase;
use DDTrace\Tracer;
use DDTrace\Transport\Stream;

final class StreamTest extends BaseTestCase
{
    public function testOutputTraces()
    {
        $transport = new Stream(new Json());
        $tracer = new Tracer($transport);

        $span = $tracer->startSpan('test');
        $span->finish();

        ob_start();
        $transport->send($tracer);
        $json = ob_get_clean();

        $this->assertJson($json);

        $decoded = json_decode($json);
        $this->assertObjectHasAttribute('headers', $decoded);
        $this->assertObjectHasAttribute('traces', $decoded);
    }

    public function testMemoryStream()
    {
        $memStream = fopen('php://memory', 'rw');
        $transport = new Stream(new Json(), $memStream);
        $tracer = new Tracer($transport);

        $span = $tracer->startSpan('test');
        $span->finish();

        ob_start();
        $transport->send($tracer);
        $output = ob_get_clean();

        $this->assertEmpty($output);

        rewind($memStream);
        $json = stream_get_contents($memStream);

        $this->assertJson($json);

        $decoded = json_decode($json);
        $this->assertObjectHasAttribute('headers', $decoded);
        $this->assertObjectHasAttribute('traces', $decoded);
    }
}
