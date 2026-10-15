<?php

namespace DDTrace\Tests\Common;

use DDTrace\Util\BaseConverter;
use DDTrace\Util\SpanKindNames;
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
    /** @var array The chunk fields of each flattened span's chunk, by span id */
    private $chunkFields = [];

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

    /** Test-side mapping: expectations name these span properties by their legacy tag key, as $span->meta does. */
    const PROPERTY_KEYS = ['env' => 'env', 'version' => 'version', 'component' => 'component', 'span.kind' => 'span_kind'];

    /** Attributes not compared unless an assertion names them: sampler and runtime bookkeeping. */
    const IGNORED_UNLESS_EXPECTED = [
        '_dd.agent_psr', '_dd.rule_psr', '_dd.limit_psr', '_dd.p.ksr', '_dd.top_level', '_top_level',
        'runtime-id', 'http.client_ip', 'php.compilation.total_time_ms',
        'php.memory.peak_usage_bytes', 'php.memory.peak_real_usage_bytes',
    ];

    /**
     * The typed attributes of a span, plus the span properties that the legacy tag API exposes under
     * a tag name (env, version, component and span.kind), so assertions can name them as attributes.
     * With $strict, a property key written as a raw attribute fails; a non-canonical span.kind has no
     * property, so it stays a valid attribute.
     */
    public static function attributeView(array $span, $strict = true)
    {
        $attributes = isset($span['attributes']) ? $span['attributes'] : [];
        foreach (self::PROPERTY_KEYS as $key => $field) {
            if ($strict && \array_key_exists($key, $attributes)
                && ($field !== 'span_kind' || \in_array($attributes[$key], SpanKindNames::NAMES, true))) {
                TestCase::fail(\sprintf(
                    "'%s' must be set through the span property, not as a raw attribute: %s",
                    $key,
                    print_r($span, true)
                ));
            }
            $value = isset($span[$field]) ? $span[$field] : '';
            if ($field === 'span_kind') {
                $value = isset(SpanKindNames::NAMES[$value]) ? SpanKindNames::NAMES[$value] : '';
            }
            if ($value !== '') {
                $attributes[$key] = $value;
            }
        }
        return $attributes;
    }

    /**
     * Typed attributes in the v0.4 wire's form, as libdatadog's v0.4 downgrade writes them: lists and maps
     * flatten into dotted keys and bools become "true"/"false". Spans from that wire compare loosely.
     */
    private static function toV04Attributes(array $attributes)
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            self::flattenV04((string)$key, $value, $out);
        }
        return $out;
    }

    private static function flattenV04($key, $value, array &$out)
    {
        if (\is_array($value)) {
            foreach ($value as $member => $item) {
                self::flattenV04("$key.$member", $item, $out);
            }
        } else {
            $out[$key] = \is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }
    }

    /**
     * A span link's ids as hex (32-digit trace_id, 16-digit span_id) plus its trace state, from the
     * v0.4 shape (decimal ids, trace_id_high).
     */
    public static function hexSpanLink(array $link)
    {
        $high = isset($link['trace_id_high']) ? $link['trace_id_high'] : 0;
        $hex = [
            'trace_id' => self::decimalToHex($high, 16) . self::decimalToHex($link['trace_id'], 16),
            'span_id' => self::decimalToHex($link['span_id'], 16),
        ];
        foreach (['trace_state', 'tracestate'] as $key) {
            if (!empty($link[$key])) {
                $hex['tracestate'] = $link[$key];
            }
        }
        return $hex;
    }

    /** The low 64 bits of a chunk's trace_id in decimal, as x-datadog-trace-id carries it. */
    public static function traceIdLow(array $chunk)
    {
        return BaseConverter::convert(substr($chunk['trace_id'], 16), 16, 10);
    }

    /** Decimal (int or numeric string, possibly above PHP_INT_MAX) to zero-padded lowercase hex. */
    public static function decimalToHex($decimal, $width)
    {
        return str_pad(BaseConverter::convert((string)$decimal, 10, 16), $width, '0', STR_PAD_LEFT);
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
            $dumpAttributes = self::attributeView($span, false);
            foreach (['http.client_ip', 'process_id',
                'php.compilation.total_time_ms', 'php.memory.peak_usage_bytes', 'php.memory.peak_real_usage_bytes'] as $key) {
                unset($dumpAttributes[$key]);
            }
            foreach ($dumpAttributes as $k => $v) {
                $out .= str_repeat(' ', $indent) . '  ' . $k . ' => ' . (is_scalar($v) ? var_export($v, true) : json_encode($v)) . "\n";
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

        $attributes = self::attributeView($span);
        // A span parsed from the v0.4 wire only carries the v0.4 form of its attributes.
        $isV04 = isset($span['_wire']);

        $namePrefix = $exp->getOperationName() . ': ';

        // Checking status code here because this can be tested also when we want to check only for existence
        if ($exp->getStatusCode() !== SpanAssertion::NOT_TESTED) {
            $actualStatusCode
                = isset($attributes['http.status_code']) ? $attributes['http.status_code'] : '';
            $expectedStatusCode = strval($exp->getStatusCode());
            if ($actualStatusCode !== $expectedStatusCode) {
                TestCase::assertSame(
                    $exp->getStatusCode(),
                    $actualStatusCode,
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
                !empty($span['error']),
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
        foreach ($exp->getExactChunkFields() as $field => $value) {
            $chunk = isset($this->chunkFields[$span['span_id']]) ? $this->chunkFields[$span['span_id']] : [];
            TestCase::assertSame(
                $value,
                isset($chunk[$field]) ? $chunk[$field] : null,
                $namePrefix . "Wrong value for chunk field '$field' " . print_r($chunk, true)
            );
        }

        // A list or map attribute only has dotted keys on the v0.4 wire.
        $isOrUnder = function ($key, $name) {
            return $key === $name || strpos($key, "$name.") === 0;
        };
        $assertExisting = function ($names) use ($attributes, $isOrUnder, $namePrefix) {
            foreach ($names as $name) {
                TestCase::assertNotEmpty(
                    array_filter_by_key(function ($key) use ($name, $isOrUnder) {
                        return $isOrUnder($key, $name);
                    }, $attributes),
                    $namePrefix . "Expected attribute $name not found"
                );
            }
        };
        $assertExisting($exp->getExistingAttributeNames(true));
        if ($exp->hasSpanLinks()) {
            TestCase::assertNotEmpty($span['span_links'] ?? null, $namePrefix . 'Expected span links');
        }

        if ($exp->isOnlyCheckExistence()) {
            return;
        }

        if ($exp->getExactAttributes() !== SpanAssertion::NOT_TESTED) {
            $assertExisting($exp->getExistingAttributeNames(isset($span['parent_id'])));
            $expected = $isV04 ? self::toV04Attributes($exp->getExactAttributes()) : $exp->getExactAttributes();
            $filtered = array_filter_by_key(function ($key) use ($exp, $expected, $isOrUnder) {
                if (\array_key_exists($key, $expected)) {
                    return true;
                }
                foreach ($exp->getExistingAttributeNames() as $name) {
                    if ($isOrUnder($key, $name)) {
                        return false;
                    }
                }
                return true;
            }, $attributes);
            foreach ($exp->getSkippedAttributePatterns() as $pattern) {
                $filtered = array_filter_by_key(function ($key) use ($pattern) {
                    // keep if it *doesn't* match
                    return !\preg_match($pattern, $key);
                }, $filtered);
            }
            foreach (self::IGNORED_UNLESS_EXPECTED as $key) {
                if (!\array_key_exists($key, $expected)) {
                    unset($filtered[$key]);
                }
            }
            if (!isset($expected['_dd.code_origin.type'])) {
                foreach ($filtered as $key => $value) {
                    if (strpos($key, '_dd.code_origin.') === 0) {
                        unset($filtered[$key]);
                    }
                }
            }
            foreach ($expected as $name => $value) {
                TestCase::assertArrayHasKey(
                    $name,
                    $filtered,
                    $namePrefix . 'Expected attribute ' . $name . ' not found'
                );
                if (is_string($value)) {
                    TestCase::assertTrue(
                        is_string($filtered[$name]),
                        $namePrefix . "Expected a string for '$name', got " . var_export($filtered[$name], true)
                    );
                    TestCase::assertStringMatchesFormat(
                        $value,
                        $filtered[$name],
                        $namePrefix . "Expected format for '$name' does not match actual value"
                    );
                } elseif ($isV04) {
                    TestCase::assertEquals(
                        $value,
                        $filtered[$name],
                        $namePrefix . "Exp. value for '$name' does not match actual | "
                            . var_export($value, true) . ' != ' . var_export($filtered[$name], true)
                    );
                } else {
                    TestCase::assertSame(
                        $value,
                        $filtered[$name],
                        $namePrefix . "Exp. value for '$name' does not match actual | "
                            . var_export($value, true) . ' !== ' . var_export($filtered[$name], true)
                    );
                }
                unset($filtered[$name]);
            }
            TestCase::assertEmpty(
                $filtered,
                $namePrefix . "Unexpected extra attributes:\n" . var_export($filtered, true)
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
            $chunkFields = $trace;
            unset($chunkFields['spans']);
            foreach ($trace['spans'] ?? [] as $span) {
                $this->chunkFields[$span['span_id']] = $chunkFields;
                $result[] = $span;
            }
        }

        return $result;
    }
}
