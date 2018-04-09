<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Exceptions\InvalidSpanArgument;
use DDTrace\SpanContext;
use DDTrace\Tags;
use DDTrace\Span;
use Exception;
use OpenTracing\NoopScopeManager;
use PHPUnit_Framework_TestCase;

final class SpanTest extends PHPUnit_Framework_TestCase
{
    const OPERATION_NAME = 'test_span';
    const SERVICE = 'test_service';
    const RESOURCE = 'test_resource';
    const ANOTHER_NAME = 'test_span2';
    const ANOTHER_SERVICE = 'test_service2';
    const ANOTHER_RESOURCE = 'test_resource2';
    const ANOTHER_TYPE = 'test_type2';
    const TAG_KEY = 'test_key';
    const TAG_VALUE = 'test_value';
    const EXCEPTION_MESSAGE = 'exception message';

    public function testCreateSpanSuccess()
    {
        $span = $this->createSpan();
        $span->setTag(self::TAG_KEY, self::TAG_VALUE);

        $this->assertSame(self::OPERATION_NAME, $span->getOperationName());
        $this->assertSame(self::SERVICE, $span->getService());
        $this->assertSame(self::RESOURCE, $span->getResource());
        $this->assertSame(self::TAG_VALUE, $span->getTag(self::TAG_KEY));
    }

    public function testOverwriteOperationNameSuccess()
    {
        $span = $this->createSpan();
        $span->overwriteOperationName(self::ANOTHER_NAME);
        $this->assertSame(self::ANOTHER_NAME, $span->getOperationName());
    }

    public function testSpanTagsRemainImmutableAfterFinishing()
    {
        $span = $this->createSpan();
        $span->finish();

        $span->setTag(self::TAG_KEY, self::TAG_VALUE);
        $this->assertNull($span->getTag(self::TAG_KEY));
    }

    public function testSpanErrorAddsExpectedTags()
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

    public function testSpanErrorFailsForInvalidError()
    {
        $this->expectException(InvalidSpanArgument::class);
        $this->expectExceptionMessage('Error should be either Exception or Throwable, got integer.');
        $span = $this->createSpan();
        $span->setError(1);
    }

    public function testAddCustomTagsSuccess()
    {
        $span = $this->createSpan();
        $span->setTag(Tags\SERVICE_NAME, self::ANOTHER_SERVICE);
        $span->setTag(Tags\RESOURCE_NAME, self::ANOTHER_RESOURCE);
        $span->setTag(Tags\SPAN_TYPE, self::ANOTHER_TYPE);

        $this->assertEquals(self::ANOTHER_SERVICE, $span->getService());
        $this->assertEquals(self::ANOTHER_RESOURCE, $span->getResource());
        $this->assertEquals(self::ANOTHER_TYPE, $span->getType());
    }

    public function testAddTagsFailsForInvalidTagKey()
    {
        $this->expectException(InvalidSpanArgument::class);
        $this->expectExceptionMessage('Invalid key type in given span tags. Expected string, got integer.');
        $span = $this->createSpan();
        $span->setTag(1, self::TAG_VALUE);
    }

    private function createSpan()
    {
        $context = SpanContext::createAsRoot();

        $span = new Span(
            new NoopScopeManager(),
            self::OPERATION_NAME,
            $context,
            self::SERVICE,
            self::RESOURCE
        );

        return $span;
    }
}
