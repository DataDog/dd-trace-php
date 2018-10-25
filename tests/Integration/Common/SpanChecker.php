<?php

namespace DDTrace\Tests\Integration\Common;

use DDTrace\Span;
use PHPUnit\Framework\TestCase;

/**
 * TODO: in the future this may be transformed to a PHPUnit contraint
 * @see https://phpunit.de/manual/5.7/en/extending-phpunit.html#extending-phpunit.custom-assertions
 */
final class SpanChecker
{
    /**
     * @var TestCase
     */
    private $testCase;

    /**
     * @param TestCase $testCase
     */
    public function __construct($testCase)
    {
        $this->testCase = $testCase;
    }

    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param $traces
     * @param SpanAssertion[] $expectedSpans
     */
    public function assertSpans($traces, $expectedSpans)
    {
        // First we assert that ALL the expected spans are in the actual traces and no unexpected span exists.
        $expectedSpansReferences = array_map(function (SpanAssertion $assertion) {
            return $assertion->getOperationName();
        }, $expectedSpans);
        sort($expectedSpansReferences);
        $tracesReferences = array_map(function (Span $span) {
            return $span->getOperationName();
        }, $this->flattenTraces($traces));
        sort($tracesReferences);

        $this->testCase->assertEquals($expectedSpansReferences, $tracesReferences, 'Missing or additional spans.');

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
        $this->testCase->assertNotNull($span, 'Expected span was not \'' . $exp->getOperationName() . '\' found.');

        if ($exp->isOnlyCheckExistence()) {
            return;
        }

        $this->testCase->assertSame(
            $exp->getOperationName(),
            $span->getOperationName(),
            "Wrong value for 'operation name'"
        );
        $this->testCase->assertSame(
            $exp->hasError(),
            $span->hasError(),
            "Wrong value for 'error'"
        );
        if ($exp->getExactTags() != SpanAssertion::NOT_TESTED) {
            $filtered = [];
            foreach ($span->getAllTags() as $key => $value) {
                if (!in_array($key, $exp->getExistingTagNames())) {
                    $filtered[$key] = $value;
                }
            }
            $this->testCase->assertEquals(
                $exp->getExactTags(),
                $filtered,
                "Wrong value for 'tags'"
            );
            foreach ($exp->getExistingTagNames() as $tagName) {
                $this->testCase->assertArrayHasKey($tagName, $span->getAllTags());
            }
        }
        if ($exp->getService() != SpanAssertion::NOT_TESTED) {
            $this->testCase->assertSame(
                $exp->getService(),
                $span->getService(),
                "Wrong value for 'service'"
            );
        }
        if ($exp->getType() != SpanAssertion::NOT_TESTED) {
            $this->testCase->assertSame(
                $exp->getType(),
                $span->getType(),
                "Wrong value for 'type'"
            );
        }
        if ($exp->getResource() != SpanAssertion::NOT_TESTED) {
            $this->testCase->assertSame(
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

        array_walk_recursive($traces, function (Span $span) use (&$result) {
            $result[] = $span;
        });

        return $result;
    }
}
