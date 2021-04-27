<?php

namespace DDTrace\Tests\Unit\Processing;

use DDTrace\Processing\TraceAnalyticsProcessor;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;

final class TraceAnalyticsProcessorTest extends BaseTestCase
{
    public function testTrueIs1()
    {
        $metrics = [
        ];
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, true);
        $this->assertSame(1.0, $metrics[Tag::ANALYTICS_KEY]);
    }

    public function testFalseIsUnset()
    {
        $metrics = [
            Tag::ANALYTICS_KEY => 0.2,
        ];
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, false);
        $this->assertArrayNotHasKey(Tag::ANALYTICS_KEY, $metrics);
    }

    public function testNumericValueBetweenZeroAndOne()
    {
        $metrics = [
        ];
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, 0.4);
        $this->assertSame(0.4, $metrics[Tag::ANALYTICS_KEY]);
    }

    public function testValueLessThan0()
    {
        $metrics = [];
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, -0.1);
        $this->assertArrayNotHasKey(Tag::ANALYTICS_KEY, $metrics);
    }

    public function testValueGreaterThan1()
    {
        $metrics = [];
        TraceAnalyticsProcessor::normalizeAnalyticsValue($metrics, 1.1);
        $this->assertArrayNotHasKey(Tag::ANALYTICS_KEY, $metrics);
    }
}
