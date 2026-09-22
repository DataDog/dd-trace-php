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

    public static function dumpTracesGraph(array $traces)
    {
        $self = new self;
        $flattened = $self->flattenTraces($traces);
        $actualGraph = $self->buildSpansGraph($flattened);
        return self::dumpSpansGraph($actualGraph);
    }

    /**
     * Derives the legacy flat `meta` (string tags) and `metrics` (numeric tags) views from a span.
     *
     * The V1 introspection shape (`dd_trace_serialize_closed_spans()`) merges the old string `meta`
     * and numeric `metrics` maps into a single typed `attributes` map, and promotes some keys to
     * top-level span fields. The v0.4 agent wire (parsed from the request replayer) keeps the old
     * flat `meta`/`metrics`. Tests assert against the legacy model, so normalize both shapes to it.
     *
     * Reconstruction is the exact inverse of the reshape, so the derived `meta`/`metrics` match what
     * master's introspection returned for the same span.
     *
     * @return array{0: array, 1: array} `[$meta, $metrics]`
     */
    public static function extractMetaMetrics(array $span)
    {
        // Old flat shape (v0.4 wire): already split into meta/metrics.
        if (\array_key_exists('meta', $span) || \array_key_exists('metrics', $span)) {
            return [
                isset($span['meta']) ? $span['meta'] : [],
                isset($span['metrics']) ? $span['metrics'] : [],
            ];
        }

        // New V1 introspection shape: split attributes back by type and restore promoted keys.
        $meta = [];
        $metrics = [];
        foreach (isset($span['attributes']) ? $span['attributes'] : [] as $key => $value) {
            if (\is_int($value) || \is_float($value)) {
                $metrics[$key] = $value;
            } else {
                // Strings and nested array/map attributes belong to the meta view.
                $meta[$key] = $value;
            }
        }
        // Promoted string keys move back into meta under their original tag names.
        if (isset($span['env'])) {
            $meta['env'] = $span['env'];
        }
        if (isset($span['version'])) {
            $meta['version'] = $span['version'];
        }
        if (isset($span['component'])) {
            $meta['component'] = $span['component'];
        }
        if (isset($span['origin'])) {
            $meta['_dd.origin'] = $span['origin'];
        }
        if (isset($span['trace_id_high'])) {
            $meta['_dd.p.tid'] = $span['trace_id_high'];
        }
        // span_kind is an int enum, ALWAYS present; 1 (Internal) is the default and was not emitted
        // as a `span.kind` meta tag in the old shape, so only restore the explicit kinds (2-5).
        if (isset($span['span_kind'])) {
            $kinds = [2 => 'server', 3 => 'client', 4 => 'producer', 5 => 'consumer'];
            if (isset($kinds[$span['span_kind']])) {
                $meta['span.kind'] = $kinds[$span['span_kind']];
            }
        }
        // sampling_mechanism is the unsigned _dd.p.dm value (sign is always '-').
        if (isset($span['sampling_mechanism'])) {
            $meta['_dd.p.dm'] = '-' . $span['sampling_mechanism'];
        }
        // sampling_priority (int) was the numeric _sampling_priority_v1 metric.
        if (isset($span['sampling_priority'])) {
            $metrics['_sampling_priority_v1'] = (float) $span['sampling_priority'];
        }
        return [$meta, $metrics];
    }

    /**
     * Converts a V1 introspection span into the flat v0.4 shape the test agent expects, so
     * introspection traces (from `flushAndGetTraces()`) can be sent to the agent for snapshotting.
     * Old flat spans are returned unchanged.
     */
    public static function spanToWireShape(array $span)
    {
        if (\array_key_exists('meta', $span) || \array_key_exists('metrics', $span)) {
            return $span;
        }
        list($meta, $metrics) = self::extractMetaMetrics($span);
        unset(
            $span['attributes'],
            $span['span_kind'],
            $span['env'],
            $span['version'],
            $span['component'],
            $span['origin'],
            $span['trace_id_high'],
            $span['sampling_priority'],
            $span['sampling_mechanism'],
            $span['dropped_trace']
        );
        if ($meta) {
            $span['meta'] = $meta;
        }
        if ($metrics) {
            $span['metrics'] = $metrics;
        }
        return $span;
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
            list($dumpMeta, $dumpMetrics) = self::extractMetaMetrics($span);
            unset($dumpMeta['_dd.p.dm']);
            unset($dumpMeta['_dd.p.tid']);
            unset($dumpMeta['http.client_ip']);
            foreach ($dumpMeta as $k => $v) {
                $out .= str_repeat(' ', $indent) . '  ' . $k . ' => ' . (is_scalar($v) ? $v : json_encode($v)) . "\n";
            }
            unset($dumpMetrics['php.compilation.total_time_ms']);
            unset($dumpMetrics['php.memory.peak_usage_bytes']);
            unset($dumpMetrics['php.memory.peak_real_usage_bytes']);
            unset($dumpMetrics['process_id']);
            foreach ($dumpMetrics as $k => $v) {
                $out .= str_repeat(' ', $indent) . '  ' . $k . ' => ' . (is_scalar($v) ? $v : json_encode($v)) . "\n";
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
                "WARNING: More than one candidate found for '%s' at the same level. "
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

        list($spanMeta, $spanMetrics) = self::extractMetaMetrics($span);

        $namePrefix = $exp->getOperationName() . ': ';

        // Checking status code here because this can be tested also when we want to check only for existence
        if ($exp->getStatusCode() !== SpanAssertion::NOT_TESTED) {
            $actualStatusCode
                = isset($spanMeta['http.status_code']) ? $spanMeta['http.status_code'] : '';
            $expectedStatusCode = strval($exp->getStatusCode());
            if ($actualStatusCode !== $expectedStatusCode) {
                TestCase::assertSame(
                    $exp->getStatusCode(),
                    isset($spanMeta['http.status_code']) ? $spanMeta['http.status_code'] : '',
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
            // Ignore _dd.p.ksr unless explicitly tested
            if (!isset($expectedTags['_dd.p.ksr'])) {
                unset($filtered['_dd.p.ksr']);
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
            if (!isset($expectedTags['_dd.code_origin.type'])) {
                foreach ($filtered as $key => $value) {
                    if (strpos($key, '_dd.code_origin.') === 0) {
                        unset($filtered[$key]);
                    }
                }
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
            if (!isset($metrics['php.memory.peak_usage_bytes'])) {
                unset($spanMetrics['php.memory.peak_usage_bytes']);
            }
            if (!isset($metrics['php.memory.peak_real_usage_bytes'])) {
                unset($spanMetrics['php.memory.peak_real_usage_bytes']);
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
     * @throws \InvalidArgumentException if $traces is not an array
     */
    public function flattenTraces($traces)
    {
        if (!is_array($traces)) {
            throw new \InvalidArgumentException(sprintf(
                'Argument $traces must be of type array, %s given',
                is_object($traces) ? get_class($traces) : gettype($traces)
            ));
        }

        $result = [];

        foreach ($traces as $index => $trace) {
            if (!is_array($trace)) {
                throw new \InvalidArgumentException(sprintf(
                    'Each trace must be an array, %s given at index %d',
                    is_object($trace) ? get_class($trace) : gettype($trace),
                    $index
                ));
            }
            array_walk($trace, function (array $span) use (&$result) {
                $result[] = $span;
            });
        }

        return $result;
    }
}
