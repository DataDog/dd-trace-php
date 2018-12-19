<?php

namespace DDTrace\Tests\Common;

use DDTrace\Span;
use PHPUnit\Framework\TestCase;

trait SpanAssertionTrait
{
    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param $testCase
     * @param $traces
     * @param SpanAssertion[] $expectedSpans
     */
    public function assertExpectedSpans($testCase, $traces, $expectedSpans)
    {
        (new SpanChecker($testCase))->assertSpans($traces, $expectedSpans);
    }

    /**
     * Checks that the provide span exists in the provided traces and matches expectations.
     *
     * @param TestCase $testCase
     * @param Span[][] $traces
     * @param SpanAssertion $expectedSpan
     */
    public function assertOneExpectedSpan($testCase, $traces, SpanAssertion $expectedSpan)
    {
        $spanChecker = new SpanChecker($testCase);

        $found = array_filter($spanChecker->flattenTraces($traces), function (Span $span) use ($expectedSpan) {
            return $span->getOperationName() === $expectedSpan->getOperationName();
        });

        if (empty($found)) {
            $testCase->fail('Span not found in traces: ' . $expectedSpan->getOperationName());
        } else {
            $spanChecker->assertSpan($found[0], $expectedSpan);
        }
    }
}
