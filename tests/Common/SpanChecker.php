<?php

namespace DDTrace\Tests\Common;

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
        $tracesReferences = array_map(function (array $span) {
            return $span['name'];
        }, $flattenTraces);

        $expectedOperationsAndResources = array_map(function (SpanAssertion $assertion) {
            return $assertion->getOperationName() . ' - ' . ($assertion->getResource() ?: 'not specified');
        }, $expectedSpans);
        $actualOperationsAndResources = array_map(function (array $span) {
            return $span['name'] . ' - ' . $span['resource'];
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
     * @param array $span
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
            $span['name'],
            $namePrefix . "Wrong value for 'operation name'"
        );
        $this->testCase->assertSame(
            $exp->hasError(),
            1 === $span['error'],
            $namePrefix . "Wrong value for 'error'"
        );
        if ($exp->getExactTags() != SpanAssertion::NOT_TESTED) {
            $filtered = [];
            foreach ($span['meta'] as $key => $value) {
                if (!in_array($key, $exp->getExistingTagNames())) {
                    $filtered[$key] = $value;
                }
            }
            $this->testCase->assertEquals(
                $exp->getExactTags(),
                $filtered,
                $namePrefix . "Wrong value for 'tags'"
            );
            foreach ($exp->getExistingTagNames(isset($span['parent_id'])) as $tagName) {
                $this->testCase->assertArrayHasKey($tagName, $span['meta']);
            }
        }
        if ($exp->getExactMetrics() !== SpanAssertion::NOT_TESTED) {
            $this->testCase->assertEquals(
                self::filterArrayByKey($exp->getExactMetrics(), $exp->getNotTestedMetricNames(), false),
                self::filterArrayByKey(
                    isset($span['metrics']) ? $span['metrics'] : [],
                    $exp->getNotTestedMetricNames(),
                    false
                ),
                $namePrefix . "Wrong value for 'metrics'"
            );
        }
        if ($exp->getService() != SpanAssertion::NOT_TESTED) {
            $this->testCase->assertSame(
                $exp->getService(),
                $span['service'],
                $namePrefix . "Wrong value for 'service'"
            );
        }
        if ($exp->getType() != SpanAssertion::NOT_TESTED) {
            $this->testCase->assertSame(
                $exp->getType(),
                $span['type'],
                $namePrefix . "Wrong value for 'type'"
            );
        }
        if ($exp->getResource() != SpanAssertion::NOT_TESTED) {
            $this->testCase->assertSame(
                $exp->getResource(),
                $span['resource'],
                $namePrefix . "Wrong value for 'resource'"
            );
        }
    }

    /**
     * @param array[] $traces
     * @return array
     */
    public function flattenTraces($traces)
    {
        $result = [];

        foreach ($traces as $trace) {
            array_walk($trace, function (array $span) use (&$result) {
                $result[] = $span;
            });
        }

        return $result;
    }

    /**
     * PHP < 5.6 does not offer a way to filter only specific elements in an array by key.
     *
     * @param array $associative
     * @param string[] $allowedKeys
     * @param bool $include
     * @return array
     */
    private static function filterArrayByKey($associative, $allowedKeys, $include = true)
    {
        $result = [];

        foreach ($associative as $key => $value) {
            if (($include && in_array($key, $allowedKeys)) || (!$include && !in_array($key, $allowedKeys))) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
