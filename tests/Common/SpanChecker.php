<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\TestCase;

/**
 * @see https://phpunit.de/manual/5.7/en/extending-phpunit.html#extending-phpunit.custom-assertions
 */
final class SpanChecker
{
    /**
     * Asserts a flame graph with parent child relations.
     *
     * @param array $traces
     * @param array $expectedFlameGraph
     */
    public function assertFlameGraph(array $traces, array $expectedFlameGraph)
    {
        $flattenTraces = $this->flattenTraces($traces);
        $actualGraph = $this->buildSpansGraph($flattenTraces);
        foreach ($expectedFlameGraph as $oneTrace) {
            $this->assertNode($actualGraph, $oneTrace);
        }
    }

    /**
     * @param array $graph
     * @param SpanAssertion $expectedNodeRoot
     */
    private function assertNode($graph, SpanAssertion $expectedNodeRoot)
    {
        $found = array_values(array_filter($graph, function (array $node) use ($expectedNodeRoot) {
            return $node['span']['name'] === $expectedNodeRoot->getOperationName()
                && $node['span']['resource'] === $expectedNodeRoot->getResource();
        }));

        if (count($found) > 1) {
            TestCase::fail(
                'Edge case not handled, more than one span with same name and resource at the same level: '
                . $expectedNodeRoot->getOperationName() . '/' . $expectedNodeRoot->getResource()
            );
            return;
        } elseif (count($found) === 0) {
            TestCase::fail(
                'Cannot find at the current level name/resource: '
                . $expectedNodeRoot->getOperationName() . '/' . $expectedNodeRoot->getResource()
            );
            return;
        }

        $node = $found[0];
        $this->assertSpan($node['span'], $expectedNodeRoot);

        $actualChildrenCount = count($node['children']);
        $expectedChildrenCount = count($expectedNodeRoot->getChildren());

        if ($actualChildrenCount !== $expectedChildrenCount) {
            TestCase::fail(sprintf(
                'Wrong number of children (actual %d, expected %d) for operation/resource: %s/%s',
                $actualChildrenCount,
                $expectedChildrenCount,
                $expectedNodeRoot->getOperationName(),
                $expectedNodeRoot->getResource()
            ));
            return;
        }

        foreach ($expectedNodeRoot->getChildren() as $child) {
            $this->assertNode($node['children'], $child);
        }
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
                $exp->getExactMetrics(),
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
}
