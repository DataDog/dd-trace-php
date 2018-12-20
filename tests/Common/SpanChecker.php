<?php

namespace DDTrace\Tests\Common;

use DDTrace\Span;
use PHPUnit\Framework\TestCase;

/**
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
        $flattenTraces = $this->flattenTraces($traces);
        // First we assert that ALL the expected spans are in the actual traces and no unexpected span exists.
        $expectedSpansReferences = array_map(function (SpanAssertion $assertion) {
            return $assertion->getOperationName();
        }, $expectedSpans);
        $tracesReferences = array_map(function (Span $span) {
            return $span->getOperationName();
        }, $flattenTraces);

        $expectedOperationsAndResources = array_map(function (SpanAssertion $assertion) {
            return $assertion->getOperationName() . ' - ' . ($assertion->getResource() ?: 'not specified');
        }, $expectedSpans);
        $actualOperationsAndResources = array_map(function (Span $span) {
            return $span->getOperationName() . ' - ' . $span->getResource();
        }, $flattenTraces);
        $this->testCase->assertEquals(
            $expectedSpansReferences,
            $tracesReferences,
            'Missing or additional spans. Expected: ' . print_r($expectedOperationsAndResources, 1) .
            "\n Found: " . print_r($actualOperationsAndResources, 2)
        );

        // Then we assert content on each individual received span
        for ($i = 0; $i < count($flattenTraces); $i++) {
            $this->assertSpan($flattenTraces[$i], $expectedSpans[$i]);
        }
    }

    /**
     * Checks that a span expectation is matched in a collection on Spans.
     *
     * @param Span $span
     * @param SpanAssertion $exp
     */
    public function assertSpan($span, SpanAssertion $exp)
    {
        $this->testCase->assertNotNull($span, 'Expected span was not \'' . $exp->getOperationName() . '\' found.');

        if ($exp->isOnlyCheckExistence()) {
            return;
        }

        $namePrefix = $exp->getOperationName() . ': ';

        $this->testCase->assertSame(
            $exp->getOperationName(),
            $span->getOperationName(),
            $namePrefix . "Wrong value for 'operation name'"
        );
        $this->testCase->assertSame(
            $exp->hasError(),
            $span->hasError(),
            $namePrefix . "Wrong value for 'error'"
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
                $namePrefix . "Wrong value for 'tags'"
            );
            foreach ($exp->getExistingTagNames($span->getParentId() !== null) as $tagName) {
                $this->testCase->assertArrayHasKey($tagName, $span->getAllTags());
            }
        }
        if ($exp->getService() != SpanAssertion::NOT_TESTED) {
            $this->testCase->assertSame(
                $exp->getService(),
                $span->getService(),
                $namePrefix . "Wrong value for 'service'"
            );
        }
        if ($exp->getType() != SpanAssertion::NOT_TESTED) {
            $this->testCase->assertSame(
                $exp->getType(),
                $span->getType(),
                $namePrefix . "Wrong value for 'type'"
            );
        }
        if ($exp->getResource() != SpanAssertion::NOT_TESTED) {
            $this->testCase->assertSame(
                $exp->getResource(),
                $span->getResource(),
                $namePrefix . "Wrong value for 'resource'"
            );
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
