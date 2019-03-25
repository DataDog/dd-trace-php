<?php

namespace DDTrace\Tests\Unit\Integrations;

use DDTrace\Integrations\DefaultIntegrationConfiguration;
use DDTrace\Tests\Unit\BaseTestCase;

final class DefaultIntegrationConfigurationTest extends BaseTestCase
{
    protected function setUp()
    {
        parent::setUp();
        putenv('DD_TRACE_ANALYTICS_ENABLED');
        putenv('DD_DUMMY_ANALYTICS_ENABLED');
        putenv('DD_DUMMY_ANALYTICS_SAMPLE_RATE');
    }

    public function testTraceAnalyticsOffByDefault()
    {
        $conf = new DefaultIntegrationConfiguration('dummy');
        $this->assertFalse($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsIfIntegrationEnabled()
    {
        putenv('DD_DUMMY_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('dummy');
        $this->assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalEnabledAndNotRequiresExplicit()
    {
        putenv('DD_TRACE_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('dummy', false);
        $this->assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalEnabledAndRequiresExplicit()
    {
        putenv('DD_TRACE_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('dummy');
        $this->assertFalse($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsIntegrationEnabledAndRequiresExplicit()
    {
        putenv('DD_DUMMY_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('dummy');
        $this->assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalDisabledIntegrationEnabledRequiresExplicit()
    {
        putenv('DD_TRACE_ANALYTICS_ENABLED=false');
        putenv('DD_DUMMY_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('dummy');
        $this->assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsSampleRateDefaultTo1()
    {
        $conf = new DefaultIntegrationConfiguration('dummy');
        $this->assertEquals(1.0, $conf->getTraceAnalyticsSampleRate());
    }

    public function testTraceAnalyticsSampleRateCanBeSet()
    {
        putenv('DD_DUMMY_ANALYTICS_SAMPLE_RATE=0.3');
        $conf = new DefaultIntegrationConfiguration('dummy');
        $this->assertEquals(0.3, $conf->getTraceAnalyticsSampleRate());
    }

    public function testTraceAnalyticsOffIfGlobalAndIntegrationNotSetAndNotRequiresExplicit()
    {
        $conf = new DefaultIntegrationConfiguration('dummy', false);
        $this->assertFalse($conf->isTraceAnalyticsEnabled());
    }
}
