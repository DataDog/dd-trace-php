<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\TestCase;

function array_filter_by_key($fn, array $input)
{
    $output = [];
    foreach ($input as $key => $value) {
        if ($fn($key)) {
            $output[$key] = $value;
        }
    }
    return $output;
}

/**
 * @see https://phpunit.de/manual/5.7/en/extending-phpunit.html#extending-phpunit.custom-assertions
 */
final class SpanChecker
{
    /**
     * Asserts a flame graph with parent child relations.
     *
     * @param array $traces
     * @param SpanAssertion[] $expectedFlameGraph
     */
    public function assertFlameGraph(array $traces, array $expectedFlameGraph)
    {
        $flattenTraces = $this->flattenTraces($traces);
        $actualGraph = $this->buildSpansGraph($flattenTraces);
        foreach ($expectedFlameGraph as $oneTrace) {
            if ($oneTrace->isToBeSkipped()) {
                continue;
            }
            $this->assertNode($actualGraph, $oneTrace, 'root', 'root');
        }
    }

    /**
     * @param array $graph
     * @param SpanAssertion $expectedNodeRoot
     */
    private function assertNode(array $graph, SpanAssertion $expectedNodeRoot, $parentName, $parentResource)
    {
        $node = $this->findOne($graph, $expectedNodeRoot, $parentName, $parentResource);
        $this->assertSpan($node['span'], $expectedNodeRoot);

        $actualChildrenCount = count($node['children']);
        $expectedChildrenCount = count($expectedNodeRoot->getChildren());

        if ($actualChildrenCount !== $expectedChildrenCount) {
            $expectedNames = array_map(function (SpanAssertion $spanAssertion) {
                return $spanAssertion->getOperationName();
            }, $expectedNodeRoot->getChildren());
            sort($expectedNames);
            $actualNames = array_map(function (array $child) {
                return $child['span']['name'];
            }, $node['children']);
            sort($actualNames);
            TestCase::fail(sprintf(
                "Wrong number of children (actual %d, expected %d) for operation/resource: %s/%s."
                    . "\n\nExpected:\n%s\n\nActual:\n%s\n",
                $actualChildrenCount,
                $expectedChildrenCount,
                $expectedNodeRoot->getOperationName(),
                $expectedNodeRoot->getResource(),
                implode("\n", $expectedNames),
                implode("\n", $actualNames)
            ));
            return;
        }

        foreach ($expectedNodeRoot->getChildren() as $child) {
            $this->assertNode(
                $node['children'],
                $child,
                $expectedNodeRoot->getOperationName(),
                $expectedNodeRoot->getResource()
            );
        }
    }

    private function findOne(array $graph, SpanAssertion $expectedNodeRoot, $parentName, $parenResource)
    {
        if ($expectedNodeRoot->getResource()) {
            // If the resource is specified, then we use it
            $found = array_values(array_filter($graph, function (array $node) use ($expectedNodeRoot) {
                return empty($node['__visited'])
                    && $this->matches($node['span']['name'], $expectedNodeRoot->getOperationName())
                    && $this->matches($node['span']['resource'], $expectedNodeRoot->getResource(), $wildcards = true);
            }));
        } else {
            // If the resource is NOT specified, then we use only the operation name
            $found = array_values(array_filter($graph, function (array $node) use ($expectedNodeRoot) {
                return empty($node['__visited'])
                    && $this->matches($node['span']['name'], $expectedNodeRoot->getOperationName());
            }));
        }

        if (count($found) > 1) {
            // Not using a TestCase::markTestAsIncomplete() because it exits immediately,
            // while with an error log we are still able to proceed with tests.
            error_log(sprintf(
                "WARNING: More then one candidate found for '%s' at the same level. "
                    . "Proceeding in the order they appears. "
                    . "This might not work if this span is not a leaf span.",
                $expectedNodeRoot
            ));
        } elseif (count($found) === 0) {
            TestCase::fail(
                sprintf(
                    "Cannot find span\n  - Current level: %s\n  - Span not found: %s",
                    $parentName . '/' . $parenResource,
                    $expectedNodeRoot->getOperationName() . '/' . $expectedNodeRoot->getResource()
                )
            );
            return;
        }

        $found[0]['__visited'] = true;
        return $found[0];
    }

    /**
     * Normalize a raw string removing white spaces when possible
     */
    private function normalizeString($raw)
    {
        if (null === $raw) {
            return null;
        }
        return trim(preg_replace('/\s+/', ' ', $raw));
    }

    /**
     * Given an actual and an expected span, it tells if the two matches
     * normalizing resource names.
     */
    private function matches($actual, $expectation, $wildcards = false)
    {
        if ($actual === null && $expectation === null) {
            return true;
        }

        if (!is_string($actual) || !is_string($expectation)) {
            return false;
        }

        $normalizedActual = $this->normalizeString($actual);
        $normalizedExpectation = $this->normalizeString($expectation);

        return $wildcards
            ? $this->exactWildcardsMatches($normalizedExpectation, $normalizedActual)
            : $normalizedExpectation === $normalizedActual;
    }

    /**
     * Tells if two strings match. Support wildcard '*' at the begin and at the end of the string.
     *
     * @param string $expected
     * @param string $actual
     * @return boolean
     */
    private function exactWildcardsMatches($expected, $actual)
    {
        $normalizedExpected = $expected;
        $normalizedActual = $actual;

        if (substr($normalizedExpected, -1) === '*') {
            // Ends with *
            $length = strlen($normalizedExpected) - 1;
            $normalizedExpected = substr($normalizedExpected, 0, $length);
            $normalizedActual = substr($normalizedActual, 0, $length);
        }

        if (substr($normalizedExpected, 0, 1) === '*') {
            // Starts with *
            $length = strlen($normalizedExpected) - 1;
            $normalizedExpected = substr($normalizedExpected, -$length);
            $normalizedActual = substr($normalizedActual, -$length);
        }

        return $normalizedExpected === $normalizedActual;
    }

    /**
     * @param array $flatSpans
     * @return array
     */
    private function buildSpansGraph(array $flatSpans)
    {
        $byId = [];
        foreach ($flatSpans as $span) {
            $byId[$span['span_id']] = ['span' => $span, 'children' => []];
        }

        do {
            $lastCount = count($byId);
            foreach ($byId as $id => $data) {
                $span = $data['span'];
                $hasPendingChildren = false;
                foreach ($byId as $candidateId => $candidateData) {
                    if ($candidateId === $id) {
                        continue;
                    }
                    $candidateSpan = $candidateData['span'];
                    if (!empty($candidateSpan['parent_id']) && $candidateSpan['parent_id'] === $id) {
                        $hasPendingChildren = true;
                        break;
                    }
                }
                // If has pending children we cannot move it yet
                if ($hasPendingChildren) {
                    continue;
                }

                if (!empty($span['parent_id']) && array_key_exists($span['parent_id'], $byId)) {
                    $byId[$span['parent_id']]['children'][] = $data;
                    unset($byId[$span['span_id']]);
                }
            }
        } while (count($byId) !== $lastCount);

        return array_values($byId);
    }

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
        TestCase::assertNotNull($span, 'Expected span was not found \'' . $exp->getOperationName() . '\'.');

        $spanMeta = isset($span['meta']) ? $span['meta'] : [];

        $namePrefix = $exp->getOperationName() . ': ';

        // Checking status code here because this can be tested also when we want to check only for existence
        if ($exp->getStatusCode() !== SpanAssertion::NOT_TESTED) {
            $actualStatusCode
                = isset($span['meta']['http.status_code']) ? $span['meta']['http.status_code'] : '';
            $expectedStatusCode = strval($exp->getStatusCode());
            if ($actualStatusCode !== $expectedStatusCode) {
                TestCase::assertSame(
                    $exp->getStatusCode(),
                    isset($span['meta']['http.status_code']) ? $span['meta']['http.status_code'] : '',
                    $namePrefix . "Wrong value for 'status code'. "
                        . "Expected: $expectedStatusCode. Actual: $actualStatusCode"
                );
            }
        }

        if ($exp->isOnlyCheckExistence()) {
            return;
        }

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

            $skipPatterns = $exp->getSkippedTagPatterns();
            $out = $filtered;
            foreach ($skipPatterns as $pattern) {
                $out = array_filter_by_key(
                    function ($key) use ($pattern) {
                        // keep if it *doesn't* match
                        return !\preg_match($pattern, $key);
                    },
                    $out
                );
            }

            $filtered = $out;
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
                        $namePrefix . "Expected tag format for '$tagName' does not match actual value"
                    );
                } else {
                    TestCase::assertEquals(
                        $tagValue,
                        $filtered[$tagName],
                        $namePrefix . "Expected tag value for '$tagName' does not match actual value"
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
        $metrics = $exp->getExactMetrics();
        if ($metrics !== SpanAssertion::NOT_TESTED) {
            // Ignore compilation-time metric unless explicitly tested
            if (!isset($metrics['php.compilation.total_time_ms'])) {
                unset($span['metrics']['php.compilation.total_time_ms']);
            }
            TestCase::assertEquals(
                $metrics,
                isset($span['metrics']) ? $span['metrics'] : [],
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
            $expectedResource = $exp->getResource();
            $actualResource = isset($span['resource']) ? $span['resource'] : '';
            TestCase::assertTrue(
                $this->exactWildcardsMatches($expectedResource, $actualResource),
                $namePrefix . "Wrong value for 'resource'. Exp: '$expectedResource' - Act: '$actualResource'"
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
}
