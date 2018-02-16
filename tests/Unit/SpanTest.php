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
    const ANOTHER_NAME = 'test_span2';
    const ANOTHER_SERVICE = 'test_service2';
    const ANOTHER_RESOURCE = 'test_resource2';
    const ANOTHER_TYPE = 'test_type2';
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

    public function testOverwriteOperationNameSuccess()
    {
        $span = $this->createSpan();
        $span->overwriteOperationName(self::ANOTHER_NAME);
        $this->assertSame(self::ANOTHER_NAME, $span->getOperationName());
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
        $this->assertEquals($span->getTag(Tags\ERROR_MSG), self::EXCEPTION_MESSAGE);
        $this->assertEquals($span->getTag(Tags\ERROR_TYPE), Exception::class);
    }

    public function testSpanErrorRemainsImmutableAfterFinishing()
    {
        $span = $this->createSpan();
        $span->finish();

        $span->setError(new Exception());
        $this->assertFalse($span->hasError());
    }

    public function testAddCustomTagsSuccess()
    {
        $span = $this->createSpan();
        $span->setTags([
            Tags\SERVICE_NAME => self::ANOTHER_SERVICE,
            Tags\RESOURCE_NAME => self::ANOTHER_RESOURCE,
            Tags\SPAN_TYPE => self::ANOTHER_TYPE,
        ]);

        $this->assertEquals(self::ANOTHER_SERVICE, $span->getService());
        $this->assertEquals(self::ANOTHER_RESOURCE, $span->getResource());
        $this->assertEquals(self::ANOTHER_TYPE, $span->getType());
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
