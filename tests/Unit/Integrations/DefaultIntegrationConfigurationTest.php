<?php

namespace DDTrace\Tests\Unit\Integrations;

use DDTrace\Integrations\DefaultIntegrationConfiguration;
use DDTrace\Tests\Common\BaseTestCase;

final class DefaultIntegrationConfigurationTest extends BaseTestCase
{
    protected function ddSetUp()
    {
        parent::ddSetUp();
        self::putenv('DD_TRACE_ANALYTICS_ENABLED');
        self::putenv('DD_TRACE_PDO_ANALYTICS_ENABLED');
        self::putenv('DD_TRACE_PDO_ANALYTICS_SAMPLE_RATE');
        self::putenv('DD_PDO_ANALYTICS_ENABLED');
        self::putenv('DD_PDO_ANALYTICS_SAMPLE_RATE');
    }

    public function testTraceAnalyticsOffByDefault()
    {
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertFalse($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsIfIntegrationEnabled()
    {
        self::putenv('DD_TRACE_PDO_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsIfIntegrationEnabledDeprecated()
    {
        self::putenv('DD_PDO_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsIfIntegrationEnabledWithDeprecatedPrecedence()
    {
        self::putenv('DD_PDO_ANALYTICS_ENABLED=false');
        self::putenv('DD_TRACE_PDO_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalEnabledAndNotRequiresExplicit()
    {
        self::putenv('DD_TRACE_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo', false);
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalEnabledAndRequiresExplicit()
    {
        self::putenv('DD_TRACE_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertFalse($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalDisabledIntegrationEnabledRequiresExplicit()
    {
        self::putenv('DD_TRACE_ANALYTICS_ENABLED=false');
        self::putenv('DD_TRACE_PDO_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalDisabledIntegrationEnabledRequiresExplicitDeprecated()
    {
        self::putenv('DD_TRACE_ANALYTICS_ENABLED=false');
        self::putenv('DD_PDO_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsSampleRateDefaultTo1()
    {
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertEquals(1.0, $conf->getTraceAnalyticsSampleRate());
    }

    public function testTraceAnalyticsSampleRateCanBeSet()
    {
        self::putenv('DD_TRACE_PDO_ANALYTICS_SAMPLE_RATE=0.3');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertEquals(0.3, $conf->getTraceAnalyticsSampleRate());
    }

    public function testTraceAnalyticsSampleRateCanBeSetDeprecated()
    {
        self::putenv('DD_PDO_ANALYTICS_SAMPLE_RATE=0.3');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertEquals(0.3, $conf->getTraceAnalyticsSampleRate());
    }

    public function testTraceAnalyticsOffIfGlobalAndIntegrationNotSetAndNotRequiresExplicit()
    {
        $conf = new DefaultIntegrationConfiguration('pdo', false);
        self::assertFalse($conf->isTraceAnalyticsEnabled());
    }
}
