<?php

namespace DDTrace\Tests\Unit\OpenTracer;

use DDTrace\OpenTracer\Tracer;
use DDTrace\Transport\Noop;
use PHPUnit\Framework\TestCase;

final class TracerTest extends TestCase
{
    const OPERATION_NAME = 'test_span';

    public function testCreateSpanWithExpectedValues()
    {
        $tracer = Tracer::make(new Noop());
        $span = $tracer->startSpan(self::OPERATION_NAME);

        $this->assertEquals(self::OPERATION_NAME, $span->getOperationName());
    }
}
