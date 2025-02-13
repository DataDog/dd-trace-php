<?php

namespace DDTrace\Tests\OpenTelemetry\Integration;

use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\TracerProvider;

class SpanProvenanceTest extends BaseTestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

    function testOtelSpanMarked()
    {
        $traces = $this->isolateTracer(function () {
            $context = Configurator::create()
                ->withTracerProvider(new TracerProvider)
                ->storeInContext();
            Context::storage()->attach($context);

            file_get_contents("/etc/passwd");
        });
        $this->assertEquals($traces[0][0]["resource"], "file_get_contents");
        $this->assertEquals($traces[0][0]["name"], "internal");
        $this->assertEquals($traces[0][0]["meta"]["component"], "otel.io");
    }
}
