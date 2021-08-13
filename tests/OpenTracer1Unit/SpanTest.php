<?php

namespace DDTrace\Tests\OpenTracer1Unit;

use DDTrace\OpenTracer1\Span;
use DDTrace\Span as DDSpan;
use DDTrace\SpanContext as DDSpanContext;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use Exception;

final class SpanTest extends BaseTestCase
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
        $this->assertSame(self::SERVICE, $span->unwrapped()->getService());
        $this->assertSame(self::RESOURCE, $span->unwrapped()->getResource());
        $this->assertSame(self::TAG_VALUE, $span->unwrapped()->getTag(self::TAG_KEY));
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
        $this->assertEmpty($span->unwrapped()->getTag(self::TAG_KEY));
    }

    public function testSpanTagWithErrorCreatesExpectedTags()
    {
        $span = $this->createSpan();
        $span->setTag(Tag::ERROR, new Exception(self::EXCEPTION_MESSAGE));

        $this->assertTrue($span->unwrapped()->hasError());
        $this->assertEquals($span->unwrapped()->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
        $this->assertEquals($span->unwrapped()->getTag(Tag::ERROR_TYPE), 'Exception');
    }

    public function testSpanTagWithErrorBoolProperlyMarksError()
    {
        $span = $this->createSpan();

        $span->setTag(Tag::ERROR, true);
        $this->assertTrue($span->unwrapped()->hasError());

        $span->setTag(Tag::ERROR, false);
        $this->assertFalse($span->unwrapped()->hasError());
    }

    public function testLogWithErrorBoolProperlyMarksError()
    {
        $span = $this->createSpan();

        $span->log([Tag::LOG_ERROR => true]);
        $this->assertTrue($span->unwrapped()->hasError());

        $span->log([Tag::LOG_ERROR => false]);
        $this->assertFalse($span->unwrapped()->hasError());
    }

    public function testLogWithEventErrorMarksSpanWithError()
    {
        $span = $this->createSpan();

        $span->log([Tag::LOG_EVENT => 'error']);
        $this->assertTrue($span->unwrapped()->hasError());
    }

    public function testLogWithOtherEventDoesNotMarkSpanWithError()
    {
        $span = $this->createSpan();

        $span->log([Tag::LOG_EVENT => 'some other event']);
        $this->assertFalse($span->unwrapped()->hasError());

        $span->log([Tag::LOG_ERROR => false]);
        $this->assertFalse($span->unwrapped()->hasError());
    }

    public function testSpanLogWithErrorCreatesExpectedTags()
    {
        foreach ([Tag::LOG_ERROR, Tag::LOG_ERROR_OBJECT] as $key) {
            $span = $this->createSpan();
            $span->log([$key => new Exception(self::EXCEPTION_MESSAGE)]);

            $this->assertTrue($span->unwrapped()->hasError());
            $this->assertEquals($span->unwrapped()->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
            $this->assertEquals($span->unwrapped()->getTag(Tag::ERROR_TYPE), 'Exception');
        }
    }

    public function testSpanLogStackAddsExpectedTag()
    {
        $span = $this->createSpan();
        $span->log([Tag::LOG_STACK => self::DUMMY_STACK_TRACE]);

        $this->assertFalse($span->unwrapped()->hasError());
        $this->assertEquals($span->unwrapped()->getTag(Tag::ERROR_STACK), self::DUMMY_STACK_TRACE);
    }

    public function testSpanLogMessageAddsExpectedTag()
    {
        $span = $this->createSpan();
        $span->log([Tag::LOG_MESSAGE => self::EXCEPTION_MESSAGE]);

        $this->assertFalse($span->unwrapped()->hasError());
        $this->assertEquals($span->unwrapped()->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
    }

    public function testAddCustomTagsSuccess()
    {
        $span = $this->createSpan();
        $span->setTag(Tag::SERVICE_NAME, self::ANOTHER_SERVICE);
        $span->setTag(Tag::RESOURCE_NAME, self::ANOTHER_RESOURCE);
        $span->setTag(Tag::SPAN_TYPE, self::ANOTHER_TYPE);

        $this->assertEquals(self::ANOTHER_SERVICE, $span->unwrapped()->getService());
        $this->assertEquals(self::ANOTHER_RESOURCE, $span->unwrapped()->getResource());
        $this->assertEquals(self::ANOTHER_TYPE, $span->unwrapped()->getType());
    }

    private function createSpan()
    {
        if (PHP_VERSION_ID < 70000) {
            $span = new DDSpan(
                self::OPERATION_NAME,
                DDSpanContext::createAsRoot(),
                self::SERVICE,
                self::RESOURCE
            );
        } else {
            $internalSpan = \DDTrace\start_span();
            $internalSpan->name = self::OPERATION_NAME;
            $internalSpan->service = self::SERVICE;
            $internalSpan->resource = self::RESOURCE;
            $span = new DDSpan($internalSpan, DDSpanContext::createAsRoot());
        }
        return new Span($span);
    }
}
