<?php

namespace DDTrace\Tests\Unit\Transport;

use DDTrace\Encoders\Json;
use DDTrace\Tracer;
use DDTrace\Transport\Stdout;
use PHPUnit\Framework;

final class StdoutTest extends Framework\TestCase
{
    public function testOutputTraces()
    {
        $stdoutTransport = new Stdout(new Json());
        $tracer = new Tracer($stdoutTransport);

        $span = $tracer->startSpan('test');
        $span->finish();    

        $traces = [[$span]];

        ob_start();
        $stdoutTransport->send($traces);
        $output = ob_get_clean();

        $this->assertNotEmpty($output);
    }
}
