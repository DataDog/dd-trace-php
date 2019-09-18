<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\TestCase;

trait SpanAssertionTrait
{
    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param array[] $traces
     * @param SpanAssertion[] $expectedSpans
     * @param bool $isSandbox
     */
    public function assertExpectedSpans($traces, $expectedSpans, $isSandbox = false)
    {
        (new SpanChecker())->assertSpans($traces, $expectedSpans, $isSandbox);
    }

    /**
     * Checks that the provide span exists in the provided traces and matches expectations.
     *
     * @param array[] $traces
     * @param SpanAssertion $expectedSpan
     */
    public function assertOneExpectedSpan($traces, SpanAssertion $expectedSpan)
    {
        $spanChecker = new SpanChecker();

        $found = array_values(array_filter($spanChecker->flattenTraces($traces), function ($span) use ($expectedSpan) {
            return $span['name'] === $expectedSpan->getOperationName();
        }));

        if (empty($found)) {
            TestCase::fail('Span not found in traces: ' . $expectedSpan->getOperationName());
        } else {
            $spanChecker->assertSpan($found[0], $expectedSpan);
        }
    }
}
