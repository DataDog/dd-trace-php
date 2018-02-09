<?php

namespace DDTrace\Tests\Unit;

use DDTrace\SpanContext;
use DDTrace\Tags;
use DDTrace\Span;
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
        $span->setTags([self::META_KEY => self::META_VALUE]);

        $this->assertSame(self::NAME, $span->getOperationName());
        $this->assertSame(self::SERVICE, $span->getService());
        $this->assertSame(self::RESOURCE, $span->getResource());
        $this->assertSame(self::META_VALUE, $span->getTag(self::META_KEY));
    }

    public function testSpanMetaRemainsImmutableAfterFinishing()
    {
        $span = $this->createSpan();
        $span->finish();

        $span->setTags([self::META_KEY => self::META_VALUE]);
        $this->assertNull($span->getTag(self::META_KEY));
    }

    public function testSpanErrorAddsExpectedMeta()
    {
        $span = $this->createSpan();
        $span->setError(new Exception(self::EXCEPTION_MESSAGE));

        $this->assertTrue($span->hasError());
        $this->assertEquals($span->getTag(Tags\ERROR_MSG_KEY), self::EXCEPTION_MESSAGE);
        $this->assertEquals($span->getTag(Tags\ERROR_TYPE_KEY), Exception::class);
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
        $context = SpanContext::createAsRoot();

        $span = new Span(
            self::NAME,
            $context,
            self::SERVICE,
            self::RESOURCE
        );

        return $span;
    }
}
