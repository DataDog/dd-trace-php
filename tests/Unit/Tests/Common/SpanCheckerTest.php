<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tag;
use DDTrace\Tracer;
use PHPUnit_Framework_AssertionFailedError;

class SpanCheckerTest extends IntegrationTestCase
{
    /**
     * @expectedException PHPUnit_Framework_AssertionFailedError
     * @expectedExceptionMessage Failed asserting that an array is empty.
     */
    public function testAssertExtraTagsOnTraces()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.name', [
                'tags' => [
                    Tag::SERVICE_NAME => 'servicefoo',
                    Tag::SPAN_TYPE => 'typefoo',
                    Tag::RESOURCE_NAME => 'resourcefoo',
                    'customtag1' => 'foo1',
                    'customtag2' => 'foo2',
                ],
            ]);
            $scope->getSpan()->finish();
        });

        $expected = SpanAssertion::build('custom.name', 'servicefoo', 'typefoo', 'resourcefoo')
            ->withExactTags([]);

        $spanChecker = new SpanChecker();
        $spanChecker->assertSpans($traces, [$expected]);
    }

    /**
     * @expectedException PHPUnit_Framework_AssertionFailedError
     * @expectedExceptionMessage custom.name: Expected tag name customtag1 not found
     */
    public function testAssertMissingTagsOnTraces()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.name', [
                'tags' => [
                    Tag::SERVICE_NAME => 'servicefoo',
                    Tag::SPAN_TYPE => 'typefoo',
                    Tag::RESOURCE_NAME => 'resourcefoo',
                ],
            ]);
            $scope->getSpan()->finish();
        });

        $expected = SpanAssertion::build('custom.name', 'servicefoo', 'typefoo', 'resourcefoo')
            ->withExactTags(['customtag1' => 'foo1', 'customtag2' => 'foo2']);

        $spanChecker = new SpanChecker();
        $spanChecker->assertSpans($traces, [$expected]);
    }

    public function testAssertExactEmptyTags()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.name', [
                'tags' => [
                    Tag::SERVICE_NAME => 'servicefoo',
                    Tag::SPAN_TYPE => 'typefoo',
                    Tag::RESOURCE_NAME => 'resourcefoo',
                ],
            ]);
            $scope->getSpan()->finish();
        });

        $expected = SpanAssertion::build('custom.name', 'servicefoo', 'typefoo', 'resourcefoo')
            ->withExactTags([]);

        $spanChecker = new SpanChecker();
        $spanChecker->assertSpans($traces, [$expected]);
    }

    public function testAssertExactTags()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.name', [
                'tags' => [
                    Tag::SERVICE_NAME => 'servicefoo',
                    Tag::SPAN_TYPE => 'typefoo',
                    Tag::RESOURCE_NAME => 'resourcefoo',
                    'customtag1' => 'foo1',
                    'customtag2' => 'foo2',
                ],
            ]);
            $scope->getSpan()->finish();
        });

        $expected = SpanAssertion::build('custom.name', 'servicefoo', 'typefoo', 'resourcefoo')
            ->withExactTags(['customtag1' => 'foo1', 'customtag2' => 'foo2']);

        $spanChecker = new SpanChecker();
        $spanChecker->assertSpans($traces, [$expected]);
    }
}
