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
    public function assertSpans($traces, SpanAssertion ...$expectedSpans)
    {
        // First we assert that ALL the expected spans are in the actual traces and no unexpected span exists.
        $expectedSpansReferences = array_map(function(SpanAssertion $assertion) {
            return $assertion->getOperationName();
        }, $expectedSpans);
        sort($expectedSpansReferences);
        $tracesReferences = array_map(function(Span $span) {
            return $span->getOperationName();
        }, $this->flattenTraces($traces));
        sort($tracesReferences);

        $this->assertEquals($expectedSpansReferences, $tracesReferences, 'Missing or additional spans.');

        // Then we assert content on each individual received span
        foreach ($expectedSpans as $ex) {
            $this->assertSpan($traces, $ex);
        }
    }

    /**
     * Checks that a span expectation is matched in a collection on Spans.
     *
     * @param $traces
     * @param SpanAssertion $exp
     */
    public function assertSpan($traces, SpanAssertion $exp)
    {
        $span = $this->findSpan($traces, $exp->getOperationName());
        $this->assertNotNull($span, 'Expected span was not \'' . $exp->getOperationName() . '\' found.');

        if ($exp->isOnlyCheckExistence()) {
            return;
        }

        $this->assertSame(
            $exp->getOperationName(),
            $span->getOperationName(),
            "Wrong value for 'operation name'"
        );
        $this->assertSame(
            $exp->hasError(),
            $span->hasError(),
            "Wrong value for 'error'"
        );
        if ($exp->getExactTags() != SpanAssertion::NOT_TESTED) {
            $filtered = array_filter($span->getAllTags(), function($key) use ($exp){
                return !in_array($key, $exp->getExistingTagNames());
            }, ARRAY_FILTER_USE_KEY);
            $this->assertEquals(
                $exp->getExactTags(),
                $filtered,
                "Wrong value for 'tags'"
            );
            foreach ($exp->getExistingTagNames() as $tagName) {
                $this->assertArrayHasKey($tagName, $span->getAllTags());
            }
        }
        if ($exp->getService() != SpanAssertion::NOT_TESTED) {
            $this->assertSame(
                $exp->getService(),
                $span->getService(),
                "Wrong value for 'service'"
            );
        }
        if ($exp->getType() != SpanAssertion::NOT_TESTED) {
            $this->assertSame(
                $exp->getType(),
                $span->getType(),
                "Wrong value for 'type'"
            );
        }
        if ($exp->getResource() != SpanAssertion::NOT_TESTED) {
            $this->assertSame(
                $exp->getResource(),
                $span->getResource(),
                "Wrong value for 'resource'"
            );
        }
    }

    /**
     * @param $traces Span[][]
     * @param $name string
     * @return Span|null
     */
    public function findSpan($traces, $name)
    {
        foreach ($traces as $block) {
            /** @var Span $trace */
            foreach ($block as $trace) {
                if ($trace->getOperationName() == $name) {
                    return $trace;
                }
            }
        }
    }

    /**
     * @param Span[][] $traces
     * @return Span[]
     */
    public function flattenTraces($traces)
    {
        $result = [];

        array_walk_recursive($traces, function(Span $span) use (&$result) {
            $result[] = $span;
        });

        return $result;
    }
}
