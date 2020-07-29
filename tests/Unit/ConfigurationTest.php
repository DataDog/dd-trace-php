<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Configuration;

final class ConfigurationTest extends BaseTestCase
{
    protected function setUp()
    {
        parent::setUp();
        $this->cleanUpEnvs();
    }

    protected function tearDown()
    {
        $this->cleanUpEnvs();
        parent::tearDown();
    }

    private function cleanUpEnvs()
    {
        putenv('DD_DISTRIBUTED_TRACING');
        putenv('DD_ENV');
        putenv('DD_INTEGRATIONS_DISABLED');
        putenv('DD_PRIORITY_SAMPLING');
        putenv('DD_SAMPLING_RATE');
        putenv('DD_SERVICE_MAPPING');
        putenv('DD_SERVICE_NAME');
        putenv('DD_SERVICE');
        putenv('DD_TAGS');
        putenv('DD_TRACE_ANALYTICS_ENABLED');
        putenv('DD_TRACE_DEBUG');
        putenv('DD_TRACE_ENABLED');
        putenv('DD_TRACE_GLOBAL_TAGS');
        putenv('DD_TRACE_SAMPLE_RATE');
        putenv('DD_TRACE_SAMPLING_RULES');
        putenv('DD_TRACE_SLIM_ENABLED');
        putenv('DD_TRACE_PDO_ENABLED');
        putenv('DD_VERSION');
    }

    public function testTracerEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isEnabled());
        $this->assertTrue(\ddtrace_config_trace_enabled());
    }

    public function testTracerDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_ENABLED=false']);
        $this->assertFalse(Configuration::get()->isEnabled());
        $this->assertFalse(\ddtrace_config_trace_enabled());
    }

    public function testDebugModeDisabledByDefault()
    {
        $this->assertFalse(Configuration::get()->isDebugModeEnabled());
        $this->assertFalse(\ddtrace_config_debug_enabled());
    }

    public function testDebugModeCanBeEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_DEBUG=true']);
        $this->assertTrue(Configuration::get()->isDebugModeEnabled());
        $this->assertTrue(\ddtrace_config_debug_enabled());
    }

    public function testDistributedTracingEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isDistributedTracingEnabled());
        $this->assertTrue(\ddtrace_config_distributed_tracing_enabled());
    }

    public function testDistributedTracingDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_DISTRIBUTED_TRACING=false']);
        $this->assertFalse(Configuration::get()->isDistributedTracingEnabled());
        $this->assertFalse(\ddtrace_config_distributed_tracing_enabled());
    }

    public function testPrioritySamplingEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isPrioritySamplingEnabled());
        $this->assertTrue(\ddtrace_config_priority_sampling_enabled());
    }

    public function testPrioritySamplingDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_PRIORITY_SAMPLING=false']);
        $this->assertFalse(Configuration::get()->isPrioritySamplingEnabled());
        $this->assertFalse(\ddtrace_config_priority_sampling_enabled());
    }

    public function testAllIntegrationsEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isIntegrationEnabled('pdo'));
        $this->assertTrue(\ddtrace_config_integration_enabled('pdo'));
    }

    public function testIntegrationsDisabledDeprecatedEnv()
    {
        $this->putEnvAndReloadConfig(['DD_INTEGRATIONS_DISABLED=pdo,slim']);
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('pdo'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('slim'));
        $this->assertTrue(Configuration::get()->isIntegrationEnabled('mysqli'));
        $this->assertFalse(\ddtrace_config_integration_enabled('pdo'));
        $this->assertFalse(\ddtrace_config_integration_enabled('slim'));
        $this->assertTrue(\ddtrace_config_integration_enabled('mysqli'));
    }

    public function testIntegrationsDisabledIfGlobalDisabledDeprecatedEnv()
    {
        $this->putEnvAndReloadConfig(['DD_INTEGRATIONS_DISABLED=pdo', 'DD_TRACE_ENABLED=false']);
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('pdo'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('mysqli'));
        $this->assertFalse(\ddtrace_config_integration_enabled('pdo'));
        $this->assertFalse(\ddtrace_config_integration_enabled('mysqli'));
    }

    public function testIntegrationsDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PDO_ENABLED=false', 'DD_TRACE_SLIM_ENABLED=false']);
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('pdo'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('slim'));
        $this->assertTrue(Configuration::get()->isIntegrationEnabled('mysqli'));
        $this->assertFalse(\ddtrace_config_integration_enabled('pdo'));
        $this->assertFalse(\ddtrace_config_integration_enabled('slim'));
        $this->assertTrue(\ddtrace_config_integration_enabled('mysqli'));
    }

    public function testIntegrationsDisabledIfGlobalDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PDO_ENABLED=false', 'DD_TRACE_ENABLED=false']);
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('pdo'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('mysqli'));
        $this->assertFalse(\ddtrace_config_integration_enabled('pdo'));
        $this->assertFalse(\ddtrace_config_integration_enabled('mysqli'));
    }

    public function testIntegrationsDisabledPrecedenceWithDeprecatedEnv()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PDO_ENABLED=true', 'DD_INTEGRATIONS_DISABLED=pdo,slim']);
        $this->assertTrue(Configuration::get()->isIntegrationEnabled('pdo'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('slim'));
        $this->assertTrue(\ddtrace_config_integration_enabled('pdo'));
        $this->assertFalse(\ddtrace_config_integration_enabled('slim'));
    }

    public function testAppNameFallbackPriorities()
    {
        // we do not support these fallbacks anymore; testing that we ignore them
        $this->putEnvAndReloadConfig(['ddtrace_app_name', 'DD_TRACE_APP_NAME']);
        $this->assertSame(
            'fallback_name',
            Configuration::get()->appName('fallback_name')
        );
        $this->assertSame(
            'fallback_name',
            \ddtrace_config_app_name('fallback_name')
        );

        $this->putEnvAndReloadConfig(['ddtrace_app_name=foo_app']);
        $this->assertSame('fallback_name', Configuration::get()->appName('fallback_name'));
        $this->assertSame('fallback_name', \ddtrace_config_app_name('fallback_name'));

        $this->putEnvAndReloadConfig(['ddtrace_app_name=foo_app', 'DD_TRACE_APP_NAME=bar_app']);
        $this->assertSame('fallback_name', Configuration::get()->appName('fallback_name'));
        $this->assertSame('fallback_name', \ddtrace_config_app_name('fallback_name'));
    }

    public function testServiceName()
    {
        $this->putEnvAndReloadConfig(['DD_SERVICE', 'DD_TRACE_APP_NAME', 'ddtrace_app_name']);

        $this->assertSame('__default__', Configuration::get()->appName('__default__'));
        $this->assertSame('__default__', \ddtrace_config_app_name('__default__'));

        $this->putEnvAndReloadConfig(['DD_SERVICE=my_app']);
        $this->assertSame('my_app', Configuration::get()->appName('__default__'));
        $this->assertSame('my_app', \ddtrace_config_app_name('__default__'));
    }

    public function testServiceNameViaDDServiceWinsOverDDServiceName()
    {
        $this->putEnvAndReloadConfig(['DD_SERVICE=my_app', 'DD_SERVICE_NAME=legacy']);
        $this->assertSame('my_app', Configuration::get()->appName('__default__'));
        $this->assertSame('my_app', \ddtrace_config_app_name('__default__'));
    }

    public function testServiceNameViaDDServiceNameForBackwardCompatibility()
    {
        $this->putEnvAndReloadConfig(['DD_SERVICE_NAME=my_app']);
        $this->assertSame('my_app', Configuration::get()->appName('__default__'));
        $this->assertSame('my_app', \ddtrace_config_app_name('__default__'));
    }

    public function testServiceNameHasPrecedenceOverDeprecatedMethods()
    {
        $this->putEnvAndReloadConfig([
            'DD_SERVICE=my_app',
            'DD_TRACE_APP_NAME=wrong_app',
            'ddtrace_app_name=wrong_app',
        ]);
        $this->assertSame('my_app', Configuration::get()->appName('my_app'));
        $this->assertSame('my_app', \ddtrace_config_app_name('my_app'));
    }

    public function testAnalyticsDisabledByDefault()
    {
        $this->assertFalse(Configuration::get()->isAnalyticsEnabled());
        $this->assertFalse(\ddtrace_config_analytics_enabled());
    }

    public function testAnalyticsCanBeGloballyEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_ANALYTICS_ENABLED=true']);
        $this->assertTrue(Configuration::get()->isAnalyticsEnabled());
        $this->assertTrue(\ddtrace_config_analytics_enabled());
    }

    /**
     * @dataProvider dataProviderTestTraceSamplingRules
     * @param mixed $rules
     * @param array $expected
     */
    public function testTraceSamplingRules($rules, $expected)
    {
        if (false !== $rules) {
            $this->putEnvAndReloadConfig(['DD_TRACE_SAMPLING_RULES=' . $rules]);
        }

        $this->assertSame($expected, Configuration::get()->getSamplingRules());
        $this->assertSame($expected, \ddtrace_config_sampling_rules());
    }

    public function dataProviderTestTraceSamplingRules()
    {
        return [
            'DD_TRACE_SAMPLING_RULES not defined' => [
                false,
                [],
            ],
            'DD_TRACE_SAMPLING_RULES empty string' => [
                '',
                [],
            ],
            'DD_TRACE_SAMPLING_RULES not a valid json' => [
                '[a!}',
                [],
            ],
            'DD_TRACE_SAMPLING_RULES empty array' => [
                '[]',
                [],
            ],
            'DD_TRACE_SAMPLING_RULES empty object' => [
                '[{}]',
                [],
            ],
            'DD_TRACE_SAMPLING_RULES only rate' => [
                '[{"sample_rate": 0.3}]',
                [
                    [
                        'service' => '.*',
                        'name' => '.*',
                        'sample_rate' => 0.3,
                    ],
                ],
            ],
            'DD_TRACE_SAMPLING_RULES service defined' => [
                '[{"service": "my_service", "sample_rate": 0.3}]',
                [
                    [
                        'service' => 'my_service',
                        'name' => '.*',
                        'sample_rate' => 0.3,
                    ],
                ],
            ],
            'DD_TRACE_SAMPLING_RULES named defined' => [
                '[{"name": "my_name", "sample_rate": 0.3}]',
                [
                    [
                        'service' => '.*',
                        'name' => 'my_name',
                        'sample_rate' => 0.3,
                    ],
                ],
            ],
            'DD_TRACE_SAMPLING_RULES multiple values keeps order' => [
                '[{"name": "my_name", "sample_rate": 0.3}, {"service": "my_service", "sample_rate": 0.7}]',
                [
                    [
                        'service' => '.*',
                        'name' => 'my_name',
                        'sample_rate' => 0.3,
                    ],
                    [
                        'service' => 'my_service',
                        'name' => '.*',
                        'sample_rate' => 0.7,
                    ],
                ],
            ],
            'DD_TRACE_SAMPLING_RULES values converted to proper type' => [
                '[{"name": 1, "sample_rate": "0.3"}]',
                [
                    [
                        'service' => '.*',
                        'name' => '1',
                        'sample_rate' => 0.3,
                    ],
                ],
            ],
            'DD_TRACE_SAMPLING_RULES regex can be provided' => [
                '[{"name": "^a.*b$", "sample_rate": 0.3}]',
                [
                    [
                        'service' => '.*',
                        'name' => '^a.*b$',
                        'sample_rate' => 0.3,
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataProviderTestTraceSampleRate
     * @param mixed $envs
     * @param array $expected
     */
    public function testTraceSampleRate($envs, $expected)
    {
        foreach ($envs as $env) {
            $this->putEnvAndReloadConfig([$env]);
        }

        $this->assertSame($expected, Configuration::get()->getSamplingRate());
        $this->assertSame($expected, \ddtrace_config_sampling_rate());
    }

    public function dataProviderTestTraceSampleRate()
    {
        return [
            'defaults to 1.0 when nothing is set' => [
                [],
                1.0,
            ],
            'DD_TRACE_SAMPLE_RATE can be set' => [
                [
                    'DD_TRACE_SAMPLE_RATE=0.7',
                ],
                0.7,
            ],
            'DD_TRACE_SAMPLE_RATE has a minimum of 0.0' => [
                [
                    'DD_TRACE_SAMPLE_RATE=-0.1',
                ],
                0.0,
            ],
            'DD_TRACE_SAMPLE_RATE has a maximum of 1.0' => [
                [
                    'DD_TRACE_SAMPLE_RATE=1.1',
                ],
                1.0,
            ],
            'deprecated DD_SAMPLING_RATE can still be used' => [
                [
                    'DD_SAMPLING_RATE=0.7',
                ],
                0.7,
            ],
            'DD_TRACE_SAMPLE_RATE wins over deprecated DD_SAMPLING_RATE' => [
                [
                    'DD_SAMPLING_RATE=0.3',
                    'DD_TRACE_SAMPLE_RATE=0.7',
                ],
                0.7,
            ],
        ];
    }

    /**
     * @dataProvider dataProviderTestServiceMapping
     * @param mixed $envs
     * @param array $expected
     */
    public function testTraceServiceMapping($env, $expected)
    {
        if (false !== $env) {
            $this->putEnvAndReloadConfig(["DD_SERVICE_MAPPING=$env"]);
        }

        $this->assertSame($expected, Configuration::get()->getServiceMapping());
        $this->assertSame($expected, \ddtrace_config_service_mapping());
    }

    public function dataProviderTestServiceMapping()
    {
        return [
            'not set' => [
                false,
                [],
            ],
            'empty' => [
                false,
                [],
            ],
            'one service mapping' => [
                'service1:service2',
                [ 'service1' => 'service2' ],
            ],
            'multiple service mappings' => [
                'service1:service2,service3:service4',
                [ 'service1' => 'service2', 'service3' => 'service4' ],
            ],
            'tolerant to extra whitespace' => [
                'service1 :    service2 ,         service3 : service4                    ',
                [ 'service1' => 'service2', 'service3' => 'service4' ],
            ],
        ];
    }

    public function testEnv()
    {
        $this->putEnvAndReloadConfig(['DD_ENV=my-env']);
        $this->assertSame('my-env', Configuration::get()->getEnv());
        $this->assertSame('my-env', \ddtrace_config_env());
    }

    public function testEnvNotSet()
    {
        $this->putEnvAndReloadConfig(['DD_ENV']);
        $this->assertNull(Configuration::get()->getEnv());
        $this->assertNull(\ddtrace_config_env());
    }

    public function testVersion()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION=1.2.3']);
        $this->assertSame('1.2.3', Configuration::get()->getServiceVersion());
        $this->assertSame('1.2.3', \ddtrace_config_service_version());
    }

    public function testVersionNotSet()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION']);
        $this->assertNull(Configuration::get()->getServiceVersion());
        $this->assertNull(\ddtrace_config_service_version());
    }

    public function testUriAsResourceNameEnabledDefault()
    {
        $this->assertTrue(Configuration::get()->isURLAsResourceNameEnabled());
        $this->assertTrue(\ddtrace_config_url_resource_name_enabled());
    }

    public function testUriAsResourceNameCanBeDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=false']);
        $this->assertFalse(Configuration::get()->isURLAsResourceNameEnabled());
        $this->assertFalse(\ddtrace_config_url_resource_name_enabled());
    }

    public function testGlobalTags()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=key1:value1,key2:value2']);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], Configuration::get()->getGlobalTags());
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \ddtrace_config_global_tags());
    }

    public function testGlobalTagsLegacyEnv()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_GLOBAL_TAGS=key1:value1,key2:value2']);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], Configuration::get()->getGlobalTags());
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \ddtrace_config_global_tags());
    }

    public function testGlobalTagsNewEnvWinsOverLegacyEnv()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_GLOBAL_TAGS=key10:value10,key20:value20',
            'DD_TAGS=key1:value1,key2:value2',
        ]);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], Configuration::get()->getGlobalTags());
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \ddtrace_config_global_tags());
    }

    public function testGlobalTagsWrongValueJustResultsInNoTags()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=wrong_key_value']);
        $this->assertEquals([], Configuration::get()->getGlobalTags());
        $this->assertEquals([], \ddtrace_config_global_tags());
    }

    public function testUriNormalizationSettingWhenNotSet()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING',
        ]);

        $this->assertSame([], \ddtrace_config_path_fragment_regex());
        $this->assertSame([], \ddtrace_config_path_mapping_incoming());
        $this->assertSame([], \ddtrace_config_path_mapping_outgoing());
    }

    public function testUriNormalizationSettingWheSet()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX=/a/',
            'DD_TRACE_RESOURCE_URI_MAPPING_INCOMING=path/*',
            'DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING=path/*',
        ]);

        $this->assertSame(['/a/'], \ddtrace_config_path_fragment_regex());
        $this->assertSame(['path/*'], \ddtrace_config_path_mapping_incoming());
        $this->assertSame(['path/*'], \ddtrace_config_path_mapping_outgoing());
    }
}
