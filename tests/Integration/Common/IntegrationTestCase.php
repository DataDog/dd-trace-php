<?php

namespace DDTrace\Tests\Integration\Common;

use DDTrace\Span;
use DDTrace\Tracer;
use DDTrace\Tests\DebugTransport;

use PHPUnit\Framework\TestCase;
use OpenTracing\GlobalTracer;

/**
 * A basic class to be extended when testing integrations.
 */
abstract class IntegrationTestCase extends TestCase
{
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
     * @param $fn
     * @return Span[][]
     */
    public function withTracer($fn)
    {
        $transport = new DebugTransport();
        $tracer = new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);
        $tracer->flush();

        return $transport->getTraces();
    }

    /**
     * @param $name string
     * @param $fn
     * @return Span[][]
     */
    public function inTestScope($name, $fn)
    {
        return $this->withTracer(function ($tracer) use ($fn, $name) {
            $scope = $tracer->startActiveSpan($name);
            $fn($tracer);
            $scope->close();
        });
    }

    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param $traces
     * @param SpanAssertion[] $expectedSpans
     */
    public function assertSpans($traces, $expectedSpans)
    {
        $this->spanChecker->assertSpans($traces, $expectedSpans);
    }
}
