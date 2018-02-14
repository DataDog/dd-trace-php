<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Tags;
use DDTrace\Span;
use DDTrace\Tracer;
use Exception;
use PHPUnit_Framework_TestCase;

final class SpanTest extends PHPUnit_Framework_TestCase
{
    const NAME = 'test_span';
    const SERVICE = 'test_service';
    const RESOURCE = 'test_resource';
    const META_KEY = 'test_key';
    const META_VALUE = 'test_value';
    const EXCEPTION_MESSAGE = 'exception message';

    public function testCreateSpanSuccess()
    {
        $span = $this->createSpan();
        $span->setMeta(self::META_KEY, self::META_VALUE);

        $this->assertSame(self::NAME, $span->getName());
        $this->assertSame(self::SERVICE, $span->getService());
        $this->assertSame(self::RESOURCE, $span->getResource());
        $this->assertSame(self::META_VALUE, $span->getMeta(self::META_KEY));
    }

    public function testSpanMetaRemainsImmutableAfterFinishing()
    {
        $span = $this->createSpan();
        $span->finish();

        $span->setMeta(self::META_KEY, self::META_VALUE);
        $this->assertNull($span->getMeta(self::META_KEY));
    }

    public function testSpanErrorAddsExpectedMeta()
    {
        $span = $this->createSpan();
        $span->setError(new Exception(self::EXCEPTION_MESSAGE));

        $this->assertTrue($span->hasError());
        $this->assertEquals($span->getMeta(Tags\ERROR_MSG), self::EXCEPTION_MESSAGE);
        $this->assertEquals($span->getMeta(Tags\ERROR_TYPE), Exception::class);
    }

    public function testSpanErrorRemainsImmutableAfterFinishing()
    {
        $span = $this->createSpan();
        $span->finish();

        $span->setError(new Exception());
        $this->assertFalse($span->hasError());
    }

    private function createSpan()
    {
        $tracer = Tracer::noop();
        $span = new Span(
            $tracer,
            self::NAME,
            self::SERVICE,
            self::RESOURCE,
            'abc123',
            'abc123'
        );

        return $span;
    }
}
