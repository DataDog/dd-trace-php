<?php

namespace DDTrace\Tests\Integration\Common;


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
}
