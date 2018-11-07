<?php

namespace DDTrace\Tests\Integration\Common;

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

    public function __construct()
    {
        parent::__construct();
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
}
