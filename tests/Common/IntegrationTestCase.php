<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\TestCase;

/**
 * A basic class to be extended when testing integrations.
 */
abstract class IntegrationTestCase extends TestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param array[] $traces
     * @param SpanAssertion[] $expectedSpans
     * @param bool $isSandbox
     */
    public function assertSpans($traces, $expectedSpans, $isSandbox = false)
    {
        $this->assertExpectedSpans($traces, $expectedSpans, $isSandbox);
    }

    /**
     * Checks that the provide span exists in the provided traces and matches expectations.
     *
     * @param array[] $traces
     * @param SpanAssertion $expectedSpan
     */
    public function assertOneSpan($traces, SpanAssertion $expectedSpan)
    {
        $this->assertOneExpectedSpan($traces, $expectedSpan);
    }
}
