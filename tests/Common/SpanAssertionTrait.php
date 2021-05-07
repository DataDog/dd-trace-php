<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\TestCase;

trait SpanAssertionTrait
{
    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param array[] $traces
     * @param SpanAssertion[] $expectedSpans
     * @param bool $isSandbox
     */
    public function assertExpectedSpans($traces, $expectedSpans, $isSandbox = false)
    {
        (new SpanChecker())->assertSpans($traces, $expectedSpans, $isSandbox);
    }

    /**
     * Checks the exact match of a set of SpanAssertion with the provided Spans.
     *
     * @param array[] $traces
     * @param SpanAssertion[] $expectedSpans
     */
    public function assertFlameGraph($traces, $expectedSpans)
    {
        (new SpanChecker())->assertFlameGraph($traces, $expectedSpans);
    }

    /**
     * Checks that the provide span exists in the provided traces and matches expectations.
     *
     * @param array[] $traces
     * @param SpanAssertion $expectedSpan
     */
    public function assertOneExpectedSpan($traces, SpanAssertion $expectedSpan)
    {
        $spanChecker = new SpanChecker();

        $found = array_values(array_filter($spanChecker->flattenTraces($traces), function ($span) use ($expectedSpan) {
            return $span['name'] === $expectedSpan->getOperationName();
        }));

        if (empty($found)) {
            TestCase::fail('Span not found in traces: ' . $expectedSpan->getOperationName());
        } else {
            $spanChecker->assertSpan($found[0], $expectedSpan);
        }
    }

    /**
     * Asserts a $span's error reporting, including stack traces.
     *
     * @param array $span One specific span as parsed from the request replayer
     * @param string $message A message fragment. No extact match, $message is expected to be CONTAINED in the actual
     *      message.
     * @param string[][] $expectedStackLinesGroups An array of arrays containing each one a single exception's stack
     *      trace's lines. Last exception comes first. It checks that the provided line is CONTAINED in the actual
     *      line.
     */
    public function assertError(array $span, $message, array $expectedStackLinesGroups)
    {
        $this->assertSame(1, $span['error']);

        // message contains
        $this->assertNotSame(
            false,
            \strpos($span['meta']['error.msg'], $message),
            \sprintf('Message "%s" does not contain "%s"', $span['meta']['error.msg'], $message)
        );

        $stackGroups = \explode('stack groups separator', $span['meta']['error.stack']);
        if (count($stackGroups) !== count($expectedStackLinesGroups)) {
            $this->fail(
                \sprintf(
                    'Found %d stack groups, expected %d: %s',
                    count($stackGroups),
                    count($expectedStackLinesGroups),
                    $span['meta']['error.stack']
                )
            );
        }
        for ($stackGroupIndex = 0; $stackGroupIndex < \count($expectedStackLinesGroups); $stackGroupIndex++) {
            $stackLines = \explode(\PHP_EOL, $stackGroups[$stackGroupIndex]);
            $numberOfLinesActual = count($stackLines);
            $numberOfLinesExpected = count($expectedStackLinesGroups[$stackGroupIndex]);
            if ($numberOfLinesExpected !== $numberOfLinesActual) {
                $this->fail(
                    \sprintf(
                        'Found %d lines in stack group %d, expected %d: %s',
                        $numberOfLinesActual,
                        $stackGroupIndex,
                        $numberOfLinesExpected,
                        $stackGroups[$stackGroupIndex]
                    )
                );
            }
            for ($lineIndex = 0; $lineIndex < $numberOfLinesActual; $lineIndex++) {
                $this->assertNotSame(
                    false,
                    \strpos($stackLines[$lineIndex], $expectedStackLinesGroups[$stackGroupIndex][$lineIndex]),
                    \sprintf(
                        'Line "%s" of group %d does not contain "%s"',
                        $stackLines[$lineIndex],
                        $stackGroupIndex,
                        $expectedStackLinesGroups[$stackGroupIndex][$lineIndex]
                    )
                );
            }
        }
    }
}
