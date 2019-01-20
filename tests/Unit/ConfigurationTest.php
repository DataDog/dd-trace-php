<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Configuration;

final class ConfigurationTest extends BaseTestCase
{
    protected function setUp()
    {
        parent::setUp();
        putenv('DD_TRACE_ENABLED');
        putenv('DD_DISTRIBUTED_TRACING');
        putenv('DD_PRIORITY_SAMPLING');
        putenv('DD_INTEGRATIONS_DISABLED');
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
}
