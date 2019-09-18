<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\TestCase;

/**
 * @see https://phpunit.de/manual/5.7/en/extending-phpunit.html#extending-phpunit.custom-assertions
 */
final class SpanChecker
{
    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param $traces
     * @param SpanAssertion[] $expectedSpans
     * @param bool $isSandbox
     */
    public function assertSpans($traces, $expectedSpans, $isSandbox = false)
    {
        $flattenTraces = $this->flattenTraces($traces);
        if (true === $isSandbox) {
            // The sandbox API pops closed spans off a stack so spans will be in reverse order
            $flattenTraces = array_reverse($flattenTraces);
        }
        // First we assert that ALL the expected spans are in the actual traces and no unexpected span exists.
        $expectedSpansReferences = array_map(function (SpanAssertion $assertion) {
            return $assertion->getOperationName();
        }, $expectedSpans);
        $tracesReferences = array_map(function (array $span) {
            return isset($span['name']) ? $span['name'] : '';
        }, $flattenTraces);

        $expectedOperationsAndResources = array_map(function (SpanAssertion $assertion) {
            return $assertion->getOperationName() . ' - ' . ($assertion->getResource() ?: 'not specified');
        }, $expectedSpans);
        $actualOperationsAndResources = array_map(function (array $span) {
            if (!isset($span['name'], $span['resource'])) {
                return '';
            }
            return $span['name'] . ' - ' . $span['resource'];
        }, $flattenTraces);
        TestCase::assertEquals(
            $expectedSpansReferences,
            $tracesReferences,
            'Missing or additional spans. Expected: ' . print_r($expectedOperationsAndResources, true) .
            "\n Found: " . print_r($actualOperationsAndResources, true)
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
        TestCase::assertNotNull($span, 'Expected span was not \'' . $exp->getOperationName() . '\' found.');

        $spanMeta = isset($span['meta']) ? $span['meta'] : [];

        if ($exp->isOnlyCheckExistence()) {
            return;
        }

        $namePrefix = $exp->getOperationName() . ': ';

        TestCase::assertSame(
            $exp->getOperationName(),
            isset($span['name']) ? $span['name'] : '',
            $namePrefix . "Wrong value for 'operation name'"
        );
        TestCase::assertSame(
            $exp->hasError(),
            isset($span['error']) && 1 === $span['error'],
            $namePrefix . "Wrong value for 'error'"
        );
        if ($exp->getExactTags() !== SpanAssertion::NOT_TESTED) {
            $filtered = [];
            foreach ($spanMeta as $key => $value) {
                if (!in_array($key, $exp->getExistingTagNames())) {
                    $filtered[$key] = $value;
                }
            }
            $expectedTags = $exp->getExactTags();
            foreach ($expectedTags as $tagName => $tagValue) {
                TestCase::assertArrayHasKey(
                    $tagName,
                    $filtered,
                    $namePrefix . 'Expected tag name ' . $tagName . ' not found'
                );
                if (!isset($filtered[$tagName])) {
                    continue;
                }
                if (is_string($tagValue)) {
                    TestCase::assertStringMatchesFormat(
                        $tagValue,
                        $filtered[$tagName],
                        $namePrefix . 'Expected tag format does not match actual value'
                    );
                } else {
                    TestCase::assertEquals(
                        $tagValue,
                        $filtered[$tagName],
                        $namePrefix . 'Expected tag value does not match actual value'
                    );
                }
                unset($filtered[$tagName]);
            }
            TestCase::assertEmpty(
                $filtered,
                $namePrefix . "Unexpected extra values for 'tags':\n" . print_r($filtered, true)
            );
            foreach ($exp->getExistingTagNames(isset($span['parent_id'])) as $tagName) {
                TestCase::assertArrayHasKey($tagName, $spanMeta);
            }
        }
        if ($exp->getExactMetrics() !== SpanAssertion::NOT_TESTED) {
            TestCase::assertEquals(
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
            TestCase::assertSame(
                $exp->getService(),
                isset($span['service']) ? $span['service'] : '',
                $namePrefix . "Wrong value for 'service'"
            );
        }
        if ($exp->getType() != SpanAssertion::NOT_TESTED) {
            TestCase::assertSame(
                $exp->getType(),
                isset($span['type']) ? $span['type'] : '',
                $namePrefix . "Wrong value for 'type'"
            );
        }
        if ($exp->getResource() != SpanAssertion::NOT_TESTED) {
            TestCase::assertSame(
                $exp->getResource(),
                isset($span['resource']) ? $span['resource'] : '',
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
