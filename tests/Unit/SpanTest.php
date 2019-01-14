<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\Tag;
use Exception;
use PHPUnit\Framework;

final class SpanTest extends Framework\TestCase
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
    const DUMMY_STACK_TRACE = 'dummy stack trace';

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

    public function testSpanTagWithErrorCreatesExpectedTags()
    {
        $span = $this->createSpan();
        $span->setTag(Tag::ERROR, new Exception(self::EXCEPTION_MESSAGE));

        $this->assertTrue($span->hasError());
        $this->assertEquals($span->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
        $this->assertEquals($span->getTag(Tag::ERROR_TYPE), 'Exception');
    }

    public function testSpanTagWithErrorBoolProperlyMarksError()
    {
        $span = $this->createSpan();

        $span->setTag(Tag::ERROR, true);
        $this->assertTrue($span->hasError());

        $span->setTag(Tag::ERROR, false);
        $this->assertFalse($span->hasError());
    }

    public function testLogWithErrorBoolProperlyMarksError()
    {
        $span = $this->createSpan();

        $span->log([Tag::LOG_ERROR => true]);
        $this->assertTrue($span->hasError());

        $span->log([Tag::LOG_ERROR => false]);
        $this->assertFalse($span->hasError());
    }

    public function testLogWithEventErrorMarksSpanWithError()
    {
        $span = $this->createSpan();

        $span->log([Tag::LOG_EVENT => 'error']);
        $this->assertTrue($span->hasError());
    }

    public function testLogWithOtherEventDoesNotMarkSpanWithError()
    {
        $span = $this->createSpan();

        $span->log([Tag::LOG_EVENT => 'some other event']);
        $this->assertFalse($span->hasError());

        $span->log([Tag::LOG_ERROR => false]);
        $this->assertFalse($span->hasError());
    }


    public function testSpanLogWithErrorCreatesExpectedTags()
    {
        foreach ([Tag::LOG_ERROR, Tag::LOG_ERROR_OBJECT] as $key) {
            $span = $this->createSpan();
            $span->log([$key => new Exception(self::EXCEPTION_MESSAGE)]);

            $this->assertTrue($span->hasError());
            $this->assertEquals($span->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
            $this->assertEquals($span->getTag(Tag::ERROR_TYPE), 'Exception');
        }
    }

    public function testSpanLogStackAddsExpectedTag()
    {
        $span = $this->createSpan();
        $span->log([Tag::LOG_STACK => self::DUMMY_STACK_TRACE]);

        $this->assertFalse($span->hasError());
        $this->assertEquals($span->getTag(Tag::ERROR_STACK), self::DUMMY_STACK_TRACE);
    }

    public function testSpanLogMessageAddsExpectedTag()
    {
        $span = $this->createSpan();
        $span->log([Tag::LOG_MESSAGE => self::EXCEPTION_MESSAGE]);

        $this->assertFalse($span->hasError());
        $this->assertEquals($span->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
    }

    public function testSpanErrorAddsExpectedTags()
    {
        $span = $this->createSpan();
        $span->setError(new Exception(self::EXCEPTION_MESSAGE));

        $this->assertTrue($span->hasError());
        $this->assertEquals($span->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
        $this->assertNotEmpty($span->getTag(Tag::ERROR_STACK));
        $this->assertEquals($span->getTag(Tag::ERROR_TYPE), 'Exception');
    }

    public function testSpanErrorRemainsImmutableAfterFinishing()
    {
        $span = $this->createSpan();
        $span->finish();

        $span->setError(new Exception());
        $this->assertFalse($span->hasError());
    }

    /**
     * @expectedException \DDTrace\Exceptions\InvalidSpanArgument
     * @expectedExceptionMessage Error should be either Exception or Throwable, got integer.
     */
    public function testSpanErrorFailsForInvalidError()
    {
        $span = $this->createSpan();
        $span->setError(1);
    }

    public function testAddCustomTagsSuccess()
    {
        $span = $this->createSpan();
        $span->setTag(Tag::SERVICE_NAME, self::ANOTHER_SERVICE);
        $span->setTag(Tag::RESOURCE_NAME, self::ANOTHER_RESOURCE);
        $span->setTag(Tag::SPAN_TYPE, self::ANOTHER_TYPE);

        $this->assertEquals(self::ANOTHER_SERVICE, $span->getService());
        $this->assertEquals(self::ANOTHER_RESOURCE, $span->getResource());
        $this->assertEquals(self::ANOTHER_TYPE, $span->getType());
    }

    /**
     * @expectedException \DDTrace\Exceptions\InvalidSpanArgument
     * @expectedExceptionMessage Invalid key type in given span tags. Expected string, got integer.
     */
    public function testAddTagsFailsForInvalidTagKey()
    {
        $span = $this->createSpan();
        $span->setTag(1, self::TAG_VALUE);
    }

    private function createSpan()
    {
        $context = SpanContext::createAsRoot();

        $span = new Span(
            self::OPERATION_NAME,
            $context,
            self::SERVICE,
            self::RESOURCE
        );

        return $span;
    }
}
