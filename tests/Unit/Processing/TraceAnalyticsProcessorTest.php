<?php

namespace DDTrace\Tests\Unit\Processing;

use DDTrace\Processing\TraceAnalyticsProcessor;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;

final class TraceAnalyticsProcessorTest extends BaseTestCase
{
    public function testTrueIsNoOp()
    {
        $metrics = [];
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, true);
        $this->assertArrayNotHasKey(Tag::ANALYTICS_KEY, $metrics);
    }

    public function testFalseIsNoOp()
    {
        $metrics = [
            Tag::ANALYTICS_KEY => 0.2,
        ];
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, false);
        $this->assertSame(0.2, $metrics[Tag::ANALYTICS_KEY]);
    }

    public function testNumericValueIsNoOp()
    {
        $metrics = [];
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, 0.4);
        $this->assertArrayNotHasKey(Tag::ANALYTICS_KEY, $metrics);
    }

    public function testDoesNotMutateExistingMetrics()
    {
        $metrics = ['foo' => 1.0];
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, true);
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, -0.1);
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, 1.1);
        $this->assertSame(['foo' => 1.0], $metrics);
    }
}
