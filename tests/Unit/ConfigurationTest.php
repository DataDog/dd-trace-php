<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Configuration;

final class ConfigurationTest extends BaseTestCase
{
    protected function setUp()
    {
        parent::setUp();
        putenv('DD_DISTRIBUTED_TRACING');
        putenv('DD_INTEGRATIONS_DISABLED');
        putenv('DD_PRIORITY_SAMPLING');
        putenv('DD_TRACE_ANALYTICS_ENABLED');
        putenv('DD_TRACE_DEBUG');
        putenv('DD_TRACE_ENABLED');
    }

    public function testTracerEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isEnabled());
    }

    public function testTracerDisabled()
    {
        putenv('DD_TRACE_ENABLED=false');
        $this->assertFalse(Configuration::get()->isEnabled());
    }

    public function testDebugModeDisabledByDefault()
    {
        $this->assertFalse(Configuration::get()->isDebugModeEnabled());
    }

    public function testDebugModeCanBeEnabled()
    {
        putenv('DD_TRACE_DEBUG=true');
        $this->assertTrue(Configuration::get()->isDebugModeEnabled());
    }

    public function testDistributedTracingEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isDistributedTracingEnabled());
    }

    public function testDistributedTracingDisabled()
    {
        putenv('DD_DISTRIBUTED_TRACING=false');
        $this->assertFalse(Configuration::get()->isDistributedTracingEnabled());
    }

    public function testPrioritySamplingEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isPrioritySamplingEnabled());
    }

    public function testPrioritySamplingDisabled()
    {
        putenv('DD_PRIORITY_SAMPLING=false');
        $this->assertFalse(Configuration::get()->isPrioritySamplingEnabled());
    }

    public function testAllIntegrationsEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isIntegrationEnabled('any_one'));
    }

    public function testIntegrationsDisabled()
    {
        putenv('DD_INTEGRATIONS_DISABLED=one,two');
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('one'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('two'));
        $this->assertTrue(Configuration::get()->isIntegrationEnabled('three'));
    }

    public function testIntegrationsDisabledIfGlobalDisabled()
    {
        putenv('DD_INTEGRATIONS_DISABLED=one');
        putenv('DD_TRACE_ENABLED=false');
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('one'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('two'));
    }

    public function testAppNameFallbackPriorities()
    {
        putenv('ddtrace_app_name');
        putenv('DD_TRACE_APP_NAME');
        $this->assertSame(
            'fallback_name',
            Configuration::get()->appName('fallback_name')
        );

        putenv('ddtrace_app_name=foo_app');
        $this->assertSame('foo_app', Configuration::get()->appName());

        Configuration::clear();
        putenv('ddtrace_app_name=foo_app');
        putenv('DD_TRACE_APP_NAME=bar_app');
        $this->assertSame('bar_app', Configuration::get()->appName());
    }

    public function testServiceName()
    {
        putenv('DD_SERVICE_NAME');
        putenv('DD_TRACE_APP_NAME');
        putenv('ddtrace_app_name');
        Configuration::clear();

        $this->assertSame('__default__', Configuration::get()->appName('__default__'));

        putenv('DD_SERVICE_NAME=my_app');
        $this->assertSame('my_app', Configuration::get()->appName('my_app'));
    }

    public function testServiceNameHasPrecedenceOverDeprecatedMethods()
    {
        Configuration::clear();

        putenv('DD_SERVICE_NAME=my_app');
        putenv('DD_TRACE_APP_NAME=wrong_app');
        putenv('ddtrace_app_name=wrong_app');
        $this->assertSame('my_app', Configuration::get()->appName('my_app'));
    }

    public function testAnalyticsDisabledByDefault()
    {
        $this->assertFalse(Configuration::get()->isAnalyticsEnabled());
    }

    public function testAnalyticsCanBeGloballyEnabled()
    {
        putenv('DD_TRACE_ANALYTICS_ENABLED=true');
        $this->assertTrue(Configuration::get()->isAnalyticsEnabled());
    }
}
