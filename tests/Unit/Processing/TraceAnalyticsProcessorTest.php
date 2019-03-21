<?php

namespace DDTrace\Tests\Unit\Processing;

use DDTrace\Processing\TraceAnalyticsProcessor;
use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\Tag;
use DDTrace\Tests\Common\Model\DummyIntegration;
use DDTrace\Tests\Unit\BaseTestCase;

final class TraceAnalyticsProcessorTest extends BaseTestCase
{
    /**
     * @var TraceAnalyticsProcessor
     */
    private $processor;

    protected function setUp()
    {
        parent::setUp();
        $this->processor = new TraceAnalyticsProcessor();
    }

    public function testShouldBeMarkedForTraceAnalytics()
    {
        $integration = DummyIntegration::create()->withTraceAnalyticsConfiguration(true, 0.3);
        $span = new Span('operation', SpanContext::createAsRoot(), 'service', 'resource');
        $span->setIntegration($integration);

        $span->setTraceAnalyticsCandidate();
        $this->processor->process($span);
        $this->assertSame(0.3, $span->getMetrics()[Tag::ANALYTICS_KEY]);
    }

    public function testShouldBeMarkedForTraceAnalyticsEvenIfSpanClosed()
    {
        $integration = DummyIntegration::create()->withTraceAnalyticsConfiguration(true, 0.3);
        $span = new Span('operation', SpanContext::createAsRoot(), 'service', 'resource');
        $span->setIntegration($integration);

        $span->setTraceAnalyticsCandidate();
        $span->finish();
        $this->processor->process($span);
        $this->assertSame(0.3, $span->getMetrics()[Tag::ANALYTICS_KEY]);
    }

    public function testNotProcessedIfNotATraceAnalyticsCandidate()
    {
        $integration = DummyIntegration::create()->withTraceAnalyticsConfiguration(true, 0.3);
        $span = new Span('operation', SpanContext::createAsRoot(), 'service', 'resource');
        $span->setIntegration($integration);

        $this->processor->process($span);
        $this->assertArrayNotHasKey(Tag::ANALYTICS_KEY, $span->getMetrics());
    }

    public function testShouldNotBeMarkedForTraceAnalyticsWhenDisabled()
    {
        $integration = DummyIntegration::create()->withTraceAnalyticsConfiguration(false, 0.3);
        $span = new Span('operation', SpanContext::createAsRoot(), 'service', 'resource');
        $span->setIntegration($integration);

        $span->setTraceAnalyticsCandidate();
        $this->processor->process($span);
        $this->assertArrayNotHasKey(Tag::ANALYTICS_KEY, $span->getMetrics());
    }

    public function testUserValueIsRespectedIfProvided()
    {
        $integration = DummyIntegration::create()->withTraceAnalyticsConfiguration(false, 0.3);
        $span = new Span('operation', SpanContext::createAsRoot(), 'service', 'resource');
        $span->setIntegration($integration);

        $span->setTraceAnalyticsCandidate();
        $span->setMetric(Tag::ANALYTICS_KEY, 0.7);
        $this->processor->process($span);
        $this->assertSame(0.7, $span->getMetrics()[Tag::ANALYTICS_KEY]);
    }

    public function testSpanWithNoIntegrationIsNotProcessed()
    {
        $span = new Span('operation', SpanContext::createAsRoot(), 'service', 'resource');

        $span->setTraceAnalyticsCandidate();
        $this->processor->process($span);
        $this->assertArrayNotHasKey(Tag::ANALYTICS_KEY, $span->getMetrics());
    }
}
