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
     * @var SpanChecker
     */
    private $spanChecker;

    protected function setUp()
    {
        parent::setUp();
        $this->spanChecker = new SpanChecker($this);
    }

    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param $traces
     * @param SpanAssertion[] $expectedSpans
     */
    public function assertSpans($traces, $expectedSpans)
    {
        $this->assertExpectedSpans($this, $traces, $expectedSpans);
    }

    /**
     * Checks that the provide span exists in the provided traces and matches expectations.
     *
     * @param Span[][] $traces
     * @param SpanAssertion $expectedSpan
     */
    public function assertOneSpan($traces, SpanAssertion $expectedSpan)
    {
        $this->assertOneExpectedSpan($this, $traces, $expectedSpan);
    }
}
