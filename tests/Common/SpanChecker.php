<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tag;
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
     * @param bool $assertExactCount
     */
    public function assertFlameGraph(array $traces, array $expectedFlameGraph, bool $assertExactCount = true)
    {
        $flattenTraces = $this->flattenTraces($traces);
        $actualGraph = $this->buildSpansGraph($flattenTraces);
        if ($assertExactCount && \count($actualGraph) != \count($expectedFlameGraph)) {
            TestCase::fail(\sprintf(
                'Wrong number of root spans. Expected %d, actual: %s',
                \count($expectedFlameGraph),
                \count($actualGraph)
            ));
        }

        try {
            foreach ($expectedFlameGraph as $oneTrace) {
                if ($oneTrace->isToBeSkipped()) {
                    continue;
                }
                $this->assertNode($actualGraph, $oneTrace, 'root', 'root', $assertExactCount);
            }
        } catch (\Exception $e) {
            (function () use ($actualGraph) {
                $this->message .= "\nReceived Spans graph:\n" . SpanChecker::dumpSpansGraph($actualGraph);
            })->call($e);
            throw $e;
        }
    }

    public static function dumpSpansGraph(array $spansGraph, int $indent = 0)
    {
        $out = "";
        foreach ($spansGraph as $node) {
            $span = $node['span'];
            $values = [];
            if (isset($span['service'])) {
                $values[] = "service: {$span['service']}";
            }
            if (isset($span['resource'])) {
                $values[] = "resource: {$span['resource']}";
            }
            if (isset($span['type'])) {
                $values[] = "type: {$span['type']}";
            }

            $out .= str_repeat(' ', $indent);
            $out .= $span['name'] ?? "<empty span name>";
            if (!empty($values)) {
                $out .= ' (' . implode(', ', $values) . ')';
            }
            $out .= "\n";
            if (isset($span['meta'])) {
                unset($span['meta']['_dd.p.dm']);
                unset($span['meta']['_dd.p.tid']);
                unset($span['meta']['http.client_ip']);
                foreach ($span['meta'] as $k => $v) {
                    $out .= str_repeat(' ', $indent) . '  ' . $k . ' => ' . $v . "\n";
                }
            }
            if (isset($span['metrics'])) {
                unset($span['metrics']['php.compilation.total_time_ms']);
                unset($span['metrics']['process_id']);
                foreach ($span['metrics'] as $k => $v) {
                    $out .= str_repeat(' ', $indent) . '  ' . $k . ' => ' . $v . "\n";
                }
            }
            $out .= self::dumpSpansGraph($node['children'], $indent + 2);
        }
        return $out;
    }

    /**
     * @param array $graph
     * @param SpanAssertion $expectedNodeRoot
     * @param $parentName
     * @param $parentResource
     * @param bool $assertExactCount
     */
    private function assertNode(
        array $graph,
        SpanAssertion $expectedNodeRoot,
        $parentName,
        $parentResource,
        bool $assertExactCount = true
    ) {
        $node = $this->findOne($graph, $expectedNodeRoot, $parentName, $parentResource);
        $this->assertSpan($node['span'], $expectedNodeRoot);

        $actualChildrenCount = count($node['children']);
        $expectedChildrenCount = count($expectedNodeRoot->getChildren());

        if ($assertExactCount && $actualChildrenCount !== $expectedChildrenCount) {
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
            try {
                $this->assertNode(
                    $node['children'],
                    $child,
                    $expectedNodeRoot->getOperationName(),
                    $expectedNodeRoot->getResource(),
                    $assertExactCount
                );
            } catch (\Exception $e) {
                (function () use ($expectedNodeRoot, $node) {
                    if (
                        strpos($this->message, "Cannot find span") === 0
                        && strpos($this->message, "parent operation/resource") === false
                    ) {
                        $actualNames = array_map(function (array $child) {
                            return $child['span']['name'] . "/" . $child['span']['resource'];
                        }, $node['children']);
                        sort($actualNames);
                        $this->message .= "\n\nAvailable spans:\n" . implode("\n", $actualNames) . "\n";
                    }
                    $this->message .= sprintf(
                        "\nparent operation/resource: %s/%s",
                        $expectedNodeRoot->getOperationName(),
                        $expectedNodeRoot->getResource()
                    );
                })->call($e);
                throw $e;
            }
        }
    }

    private function findOne(array $graph, SpanAssertion $expectedNodeRoot, $parentName, $parenResource)
    {
        $expectedNodeRootResource = $expectedNodeRoot->getResource();
        if ($expectedNodeRootResource && $expectedNodeRootResource !== SpanAssertion::NOT_TESTED) {
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

        if (substr($normalizedExpected ?? '', -1) === '*') {
            // Ends with *
            $length = strlen($normalizedExpected) - 1;
            $normalizedExpected = substr($normalizedExpected, 0, $length);
            $normalizedActual = substr($normalizedActual, 0, $length);
        }

        if (substr($normalizedExpected ?? '', 0, 1) === '*') {
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
        // Note 1: PHP 5.6 and 7.* handle differently the way a foreach is done while removing in the loop items
        // from the array itself. As a quick fix, we iterate over keys instead of elements themselves.
        // Note 2: On PHP 7.4 - at least - $array['123'] = 'a' is converted to $array[123] = 'a'. So we need to convert
        // IDs back to string.
        $spanIds = \array_map('strval', \array_keys($byId));

        do {
            $lastCount = count($byId);
            foreach ($spanIds as $id) {
                if (!\array_key_exists($id, $byId)) {
                    continue;
                }
                $data = $byId[$id];
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
     */
    public function assertSpans($traces, $expectedSpans)
    {
        $flattenTraces = $this->flattenTraces($traces);
        // The sandbox API pops closed spans off a stack so spans will be in reverse order
        $flattenTraces = array_reverse($flattenTraces);

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
        $spanMetrics = isset($span['metrics']) ? $span['metrics'] : [];

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
                        . print_r($span, true)
                );
            }
        }

        if ($exp->getTestTime()) {
            TestCase::assertGreaterThanOrEqual($_SERVER["REQUEST_TIME_FLOAT"] * 1e9, $span['start']);
            TestCase::assertLessThan(microtime(true) * 1e9, $span['start']);
            TestCase::assertLessThan((microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1e9, $span['duration']);
            TestCase::assertGreaterThan(0, $span['duration']);
        }

        TestCase::assertSame(
            $exp->getOperationName(),
            isset($span['name']) ? $span['name'] : '',
            $namePrefix . "Wrong value for 'operation name': " . print_r($span, true)
        );
        if ($exp->hasError() !== SpanAssertion::NOT_TESTED) {
            TestCase::assertSame(
                $exp->hasError(),
                isset($span['error']) && 1 === $span['error'],
                $namePrefix . "Wrong value for 'error': " . print_r($span, true)
            );
        }
        if ($exp->getService() !== SpanAssertion::NOT_TESTED) {
            TestCase::assertSame(
                $exp->getService(),
                isset($span['service']) ? $span['service'] : '',
                $namePrefix . "Wrong value for 'service' " . print_r($span, true)
            );
        }
        if ($exp->getResource() !== SpanAssertion::NOT_TESTED) {
            $expectedResource = $exp->getResource();
            $actualResource = isset($span['resource']) ? $span['resource'] : '';
            TestCase::assertTrue(
                $this->exactWildcardsMatches($expectedResource, $actualResource),
                $namePrefix . "Wrong value for 'resource'. Exp: '$expectedResource' - Act: '$actualResource' "
                . print_r($span, true)
            );
        }

        foreach ($exp->getExistingTagNames(true) as $key) {
            TestCase::assertArrayHasKey($key, $spanMeta);
        }

        if ($exp->isOnlyCheckExistence()) {
            return;
        }

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
            // Ignore _dd.p.dm unless explicitly tested
            if (!isset($expectedTags['_dd.p.dm'])) {
                unset($filtered['_dd.p.dm']);
            }
            // Ignore _dd.p.tid unless explicitly tested
            if (!isset($expectedTags['_dd.p.tid'])) {
                unset($filtered['_dd.p.tid']);
            }
            // Ignore runtime-id unless explicitly tested
            if (!isset($expectedTags['runtime-id'])) {
                unset($filtered['runtime-id']);
            }
            // http.client_ip is present depending on target SAPI and not helpful here to test
            if (!isset($expectedTags['http.client_ip'])) {
                unset($filtered['http.client_ip']);
            }
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
                    $actual = $filtered[$tagName];
                    TestCase::assertEquals(
                        $tagValue,
                        $actual,
                        $namePrefix . "Exp. value for '$tagName' does not match actual | '$tagValue' != '$actual'"
                    );
                }
                unset($filtered[$tagName]);
            }
            TestCase::assertEmpty(
                $filtered,
                $namePrefix . "Unexpected extra values for 'tags':\n" . print_r($filtered, true)
            );
            foreach ($exp->getExistingTagNames(isset($span['parent_id'])) as $tagName) {
                if ($tagName === Tag::PID) {
                    TestCase::assertArrayHasKey($tagName, $spanMetrics);
                    unset($spanMetrics[Tag::PID]);
                } else {
                    TestCase::assertArrayHasKey($tagName, $spanMeta);
                }
            }
        }
        $metrics = $exp->getExactMetrics();
        if ($metrics !== SpanAssertion::NOT_TESTED) {
            // Ignore compilation-time metric unless explicitly tested
            if (!isset($metrics['php.compilation.total_time_ms'])) {
                unset($spanMetrics['php.compilation.total_time_ms']);
            }
            if (isset($metrics['process_id'])) {
                unset($metrics['process_id']);
            }
            if (isset($spanMetrics["process_id"])) {
                unset($spanMetrics['process_id']);
            }
            if (isset($spanMetrics["_top_level"])) {
                // Set by sidecar only
                unset($spanMetrics['_top_level']);
            }
            TestCase::assertEquals(
                $metrics,
                $spanMetrics,
                $namePrefix . "Wrong value for 'metrics' " . print_r($span, true)
            );
        }
        if ($exp->getType() != SpanAssertion::NOT_TESTED) {
            TestCase::assertSame(
                $exp->getType(),
                isset($span['type']) ? $span['type'] : '',
                $namePrefix . "Wrong value for 'type' " . print_r($span, true)
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
