<?php

namespace DDTrace\Tests\Unit\Integrations;

use DDTrace\Tests\Unit\BaseTestCase;

final class AbstractIntegrationConfigurationTest extends BaseTestCase
{
    protected function setUp()
    {
        parent::setUp();
        putenv('DD_DUMMY_SAMPLE_BOOL');
        putenv('DD_DUMMY_SAMPLE_FLOAT');
        putenv('DD_DUMMY_SOMETHING_SAMPLE_FLOAT');
    }

    public function testVariableNameSimple()
    {
        putenv('DD_DUMMY_SAMPLE_FLOAT=0.8');
        $config = new DummyIntegrationConfiguration('dummy');
        $this->assertSame(0.8, $config->getSampleFloat());
    }

    public function testVariableNameDash()
    {
        putenv('DD_DUMMY_SOMETHING_SAMPLE_FLOAT=0.8');
        $config = new DummyIntegrationConfiguration('dummy-something');
        $this->assertSame(0.8, $config->getSampleFloat());
    }

    public function testBoolValueSet()
    {
        putenv('DD_DUMMY_SAMPLE_BOOL=false');
        $config = new DummyIntegrationConfiguration('dummy');
        $this->assertSame(false, $config->getSampleBool());
    }

    public function testBoolValueDefault()
    {
        $config = new DummyIntegrationConfiguration('dummy');
        $this->assertSame(true, $config->getSampleBool());
    }

    public function testFloatValueSet()
    {
        putenv('DD_DUMMY_SAMPLE_FLOAT=0.8');
        $config = new DummyIntegrationConfiguration('dummy');
        $this->assertSame(0.8, $config->getSampleFloat());
    }

    public function testFloatValueDefault()
    {
        $config = new DummyIntegrationConfiguration('dummy');
        $this->assertSame(1.23, $config->getSampleFloat());
    }
}
