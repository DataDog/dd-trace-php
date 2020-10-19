<?php

namespace DDTrace\Tests\Unit\Integrations;

use DDTrace\Integrations\DefaultIntegrationConfiguration;
use DDTrace\Tests\Common\BaseTestCase;

final class DefaultIntegrationConfigurationTest extends BaseTestCase
{
    protected function ddSetUp()
    {
        parent::ddSetUp();
        putenv('DD_TRACE_ANALYTICS_ENABLED');
        putenv('DD_TRACE_PDO_ANALYTICS_ENABLED');
        putenv('DD_TRACE_PDO_ANALYTICS_SAMPLE_RATE');
        putenv('DD_PDO_ANALYTICS_ENABLED');
        putenv('DD_PDO_ANALYTICS_SAMPLE_RATE');
    }

    public function testTraceAnalyticsOffByDefault()
    {
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertFalse($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsIfIntegrationEnabled()
    {
        putenv('DD_TRACE_PDO_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsIfIntegrationEnabledDeprecated()
    {
        putenv('DD_PDO_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsIfIntegrationEnabledWithDeprecatedPrecedence()
    {
        putenv('DD_PDO_ANALYTICS_ENABLED=false');
        putenv('DD_TRACE_PDO_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalEnabledAndNotRequiresExplicit()
    {
        putenv('DD_TRACE_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo', false);
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalEnabledAndRequiresExplicit()
    {
        putenv('DD_TRACE_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertFalse($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalDisabledIntegrationEnabledRequiresExplicit()
    {
        putenv('DD_TRACE_ANALYTICS_ENABLED=false');
        putenv('DD_TRACE_PDO_ANALYTICS_ENABLED=true');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertTrue($conf->isTraceAnalyticsEnabled());
    }

    public function testTraceAnalyticsGlobalDisabledIntegrationEnabledRequiresExplicitDeprecated()
    {
        putenv('DD_TRACE_ANALYTICS_ENABLED=false');
        putenv('DD_PDO_ANALYTICS_ENABLED=true');
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
        putenv('DD_TRACE_PDO_ANALYTICS_SAMPLE_RATE=0.3');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertEquals(0.3, $conf->getTraceAnalyticsSampleRate());
    }

    public function testTraceAnalyticsSampleRateCanBeSetDeprecated()
    {
        putenv('DD_PDO_ANALYTICS_SAMPLE_RATE=0.3');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertEquals(0.3, $conf->getTraceAnalyticsSampleRate());
    }

    public function testTraceAnalyticsSampleRateCanBeSetWithDeprecatedPrecedence()
    {
        putenv('DD_TRACE_PDO_ANALYTICS_SAMPLE_RATE=0.2');
        putenv('DD_PDO_ANALYTICS_SAMPLE_RATE=0.4');
        $conf = new DefaultIntegrationConfiguration('pdo');
        self::assertEquals(0.2, $conf->getTraceAnalyticsSampleRate());
    }

    public function testTraceAnalyticsOffIfGlobalAndIntegrationNotSetAndNotRequiresExplicit()
    {
        $conf = new DefaultIntegrationConfiguration('pdo', false);
        self::assertFalse($conf->isTraceAnalyticsEnabled());
    }
}
