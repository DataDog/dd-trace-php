<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Configuration;
use DDTrace\Tests\Common\BaseTestCase;

final class ConfigurationTest extends BaseTestCase
{
    const INTEGRATION_ERROR = <<<'EOD'

This could mean that a new integration was added in userland but was not added
to the `ddtrace_integration_name` enum and the `ddtrace_integrations` array
found in integrations.{h,c}. Integration-specific config for this integration will
fall back to the defaults if they have not been added at the extension level.
EOD;

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->cleanUpEnvs();
    }

    protected function ddTearDown()
    {
        $this->cleanUpEnvs();
        parent::ddTearDown();
    }

    private function cleanUpEnvs()
    {
        self::putenv('DD_DISTRIBUTED_TRACING');
        self::putenv('DD_ENV');
        self::putenv('DD_INTEGRATIONS_DISABLED');
        self::putenv('DD_PRIORITY_SAMPLING');
        self::putenv('DD_SAMPLING_RATE');
        self::putenv('DD_SERVICE_MAPPING');
        self::putenv('DD_SERVICE_NAME');
        self::putenv('DD_SERVICE');
        self::putenv('DD_TAGS');
        self::putenv('DD_TRACE_ANALYTICS_ENABLED');
        self::putenv('DD_TRACE_DEBUG');
        self::putenv('DD_TRACE_ENABLED');
        self::putenv('DD_TRACE_GLOBAL_TAGS');
        self::putenv('DD_TRACE_PDO_ENABLED');
        self::putenv('DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST');
        self::putenv('DD_TRACE_SAMPLE_RATE');
        self::putenv('DD_TRACE_SAMPLING_RULES');
        self::putenv('DD_TRACE_SLIM_ENABLED');
        self::putenv('DD_TRACE_HEADER_TAGS');
        self::putenv('DD_VERSION');
    }

    public function testTracerEnabledByDefault()
    {
        $this->assertTrue(\ddtrace_config_trace_enabled());
    }

    public function testTracerDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_ENABLED=false']);
        $this->assertFalse(\ddtrace_config_trace_enabled());
    }

    public function testDebugModeDisabledByDefault()
    {
        $this->assertFalse(\ddtrace_config_debug_enabled());
    }

    public function testDebugModeCanBeEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_DEBUG=true']);
        $this->assertTrue(\ddtrace_config_debug_enabled());
    }

    public function testDistributedTracingEnabledByDefault()
    {
        $this->assertTrue(\ddtrace_config_distributed_tracing_enabled());
    }

    public function testDistributedTracingDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_DISTRIBUTED_TRACING=false']);
        $this->assertFalse(\ddtrace_config_distributed_tracing_enabled());
    }

    public function testPrioritySamplingEnabledByDefault()
    {
        $this->assertTrue(\ddtrace_config_priority_sampling_enabled());
    }

    public function testPrioritySamplingDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_PRIORITY_SAMPLING=false']);
        $this->assertFalse(\ddtrace_config_priority_sampling_enabled());
    }

    public function testAllIntegrationsEnabledByDefault()
    {
        $this->assertTrue(\ddtrace_config_integration_enabled('pdo'));
    }

    public function testIntegrationsDisabledDeprecatedEnv()
    {
        $this->putEnvAndReloadConfig(['DD_INTEGRATIONS_DISABLED=pdo,slim']);
        $this->assertFalse(\ddtrace_config_integration_enabled('pdo'));
        $this->assertFalse(\ddtrace_config_integration_enabled('slim'));
        $this->assertTrue(\ddtrace_config_integration_enabled('mysqli'));
    }

    public function testIntegrationsDisabledIfGlobalDisabledDeprecatedEnv()
    {
        $this->putEnvAndReloadConfig(['DD_INTEGRATIONS_DISABLED=pdo', 'DD_TRACE_ENABLED=false']);
        $this->assertFalse(\ddtrace_config_integration_enabled('pdo'));
        $this->assertFalse(\ddtrace_config_integration_enabled('mysqli'));
    }

    public function testIntegrationsDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PDO_ENABLED=false', 'DD_TRACE_SLIM_ENABLED=false']);
        $this->assertFalse(\ddtrace_config_integration_enabled('pdo'));
        $this->assertFalse(\ddtrace_config_integration_enabled('slim'));
        $this->assertTrue(\ddtrace_config_integration_enabled('mysqli'));
    }

    public function testIntegrationsDisabledIfGlobalDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PDO_ENABLED=false', 'DD_TRACE_ENABLED=false']);
        $this->assertFalse(\ddtrace_config_integration_enabled('pdo'));
        $this->assertFalse(\ddtrace_config_integration_enabled('mysqli'));
    }

    public function testIntegrationsDisabledPrecedenceWithDeprecatedEnv()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PDO_ENABLED=true', 'DD_INTEGRATIONS_DISABLED=pdo,slim']);
        if (PHP_VERSION_ID < 70000) {
            $this->assertTrue(\ddtrace_config_integration_enabled('pdo'));
        } else {
            // We cannot distinguish not set vs set to default value anymore, hence the behaviour is changed slightly
            $this->assertFalse(\ddtrace_config_integration_enabled('pdo'));
        }
        $this->assertFalse(\ddtrace_config_integration_enabled('slim'));
    }

    public function testAllIntegrationsEnabledToggleConfig()
    {
        $integrations = self::getIntegrationsUpper();
        foreach ($integrations as $integration) {
            $this->putEnvAndReloadConfig(["DD_TRACE_{$integration}_ENABLED=false"]);

            $lower = strtolower($integration);
            $error = "'{$lower}' was expected to be disabled." . self::INTEGRATION_ERROR;
            self::assertFalse(\ddtrace_config_integration_enabled($lower), $error);

            // Reset
            self::putenv("DD_TRACE_{$integration}_ENABLED");
        }

        // Make sure we're not testing the default fallback
        self::assertTrue(\ddtrace_config_integration_enabled('foo_invalid'));
    }

    public function testAllIntegrationsAnalyticsEnabledToggleConfig()
    {
        $integrations = self::getIntegrationsUpper();
        foreach ($integrations as $integration) {
            $this->putEnvAndReloadConfig(["DD_TRACE_{$integration}_ANALYTICS_ENABLED=true"]);

            $lower = strtolower($integration);
            self::assertTrue(
                \DDTrace\Config\integration_analytics_enabled($lower),
                "App analytics for '{$lower}' was expected to be enabled." . self::INTEGRATION_ERROR
            );

            // Reset
            self::putenv("DD_TRACE_{$integration}_ANALYTICS_ENABLED");
        }

        // Make sure we're not testing the default fallback
        self::assertFalse(\DDTrace\Config\integration_analytics_enabled('foo_invalid'));
    }

    public function testAllIntegrationsAnalyticsSampleRateConfig()
    {
        $integrations = self::getIntegrationsUpper();
        foreach ($integrations as $integration) {
            $this->putEnvAndReloadConfig(["DD_TRACE_{$integration}_ANALYTICS_SAMPLE_RATE=0.42"]);

            $lower = strtolower($integration);
            self::assertSame(
                0.42,
                \DDTrace\Config\integration_analytics_sample_rate($lower),
                "Invalid app analytics sample rate for '{$lower}'." . self::INTEGRATION_ERROR
            );

            // Reset
            self::putenv("DD_TRACE_{$integration}_ANALYTICS_SAMPLE_RATE");
        }

        // Make sure we're not testing the default fallback
        self::assertSame(\DDTrace\Config\integration_analytics_sample_rate('foo_invalid'), 1.0);
    }

    private static function getIntegrationsUpper()
    {
        $dirs = glob(__DIR__ . '/../../src/DDTrace/Integrations/*', GLOB_ONLYDIR);
        return array_map(function ($entry) {
            return strtoupper(substr($entry, strrpos($entry, '/') + 1));
        }, $dirs);
    }

    public function testAppNameFallbackPriorities()
    {
        // we do not support these fallbacks anymore; testing that we ignore them
        $this->putEnvAndReloadConfig(['ddtrace_app_name', 'DD_TRACE_APP_NAME']);
        $this->assertSame(
            'fallback_name',
            \ddtrace_config_app_name('fallback_name')
        );

        $this->putEnvAndReloadConfig(['ddtrace_app_name=foo_app']);
        $this->assertSame('fallback_name', \ddtrace_config_app_name('fallback_name'));

        $this->putEnvAndReloadConfig(['ddtrace_app_name=foo_app', 'DD_TRACE_APP_NAME=bar_app']);
        $this->assertSame('fallback_name', \ddtrace_config_app_name('fallback_name'));
    }

    public function testServiceName()
    {
        $this->putEnvAndReloadConfig(['DD_SERVICE', 'DD_TRACE_APP_NAME', 'ddtrace_app_name']);

        $this->assertSame('__default__', \ddtrace_config_app_name('__default__'));

        $this->putEnvAndReloadConfig(['DD_SERVICE=my_app']);
        $this->assertSame('my_app', \ddtrace_config_app_name('__default__'));
    }

    public function testServiceNameViaDDServiceNameForBackwardCompatibility()
    {
        $this->putEnvAndReloadConfig(['DD_SERVICE_NAME=my_app']);
        $this->assertSame('my_app', \ddtrace_config_app_name('__default__'));
    }

    public function testAnalyticsDisabledByDefault()
    {
        $this->assertFalse(\ddtrace_config_analytics_enabled());
    }

    public function testAnalyticsCanBeGloballyEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_ANALYTICS_ENABLED=true']);
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
            'DD_TRACE_SAMPLING_RULES escaped' => [
                '\'[{"name": "^a.*b$", "sample_rate": 0.3}]\'',
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
                ['service1' => 'service2'],
            ],
            'multiple service mappings' => [
                'service1:service2,service3:service4',
                ['service1' => 'service2', 'service3' => 'service4'],
            ],
            'tolerant to extra whitespace' => [
                'service1 :    service2 ,         service3 : service4                    ',
                ['service1' => 'service2', 'service3' => 'service4'],
            ],
        ];
    }

    public function testEnv()
    {
        $this->putEnvAndReloadConfig(['DD_ENV=my-env']);
        $this->assertSame('my-env', \ddtrace_config_env());
    }

    public function testEnvNotSet()
    {
        $this->putEnvAndReloadConfig(['DD_ENV']);
        $this->assertEmpty(\ddtrace_config_env());
    }

    public function testVersion()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION=1.2.3']);
        $this->assertSame('1.2.3', \ddtrace_config_service_version());
    }

    public function testVersionNotSet()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION']);
        $this->assertEmpty(\ddtrace_config_service_version());
    }

    public function testUriAsResourceNameEnabledDefault()
    {
        $this->assertTrue(\ddtrace_config_url_resource_name_enabled());
    }

    public function testUriAsResourceNameCanBeDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=false']);
        $this->assertFalse(\ddtrace_config_url_resource_name_enabled());
    }

    public function testGlobalTags()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=key1:value1,key2:value2']);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \ddtrace_config_global_tags());
    }

    public function testGlobalTagsLegacyEnv()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_GLOBAL_TAGS=key1:value1,key2:value2']);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \ddtrace_config_global_tags());
    }

    public function testGlobalTagsWrongValueJustResultsInNoTags()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=wrong_key_value']);
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

    public function testRedisClientSplitHostNotSet()
    {
        $this->assertFalse(\ddtrace_config_redis_client_split_by_host_enabled());
    }

    public function testRedisClientSplitHostSetFalse()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST=false',
        ]);
        $this->assertFalse(\ddtrace_config_redis_client_split_by_host_enabled());
    }

    public function testRedisClientSplitHostSetTrue()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST=true',
        ]);
        $this->assertTrue(\ddtrace_config_redis_client_split_by_host_enabled());
    }

    public function testHttpHeadersDefaultsToEmpty()
    {
        $this->assertEmpty(\ddtrace_config_http_headers());
    }

    public function testHttpHeadersCanSetOne()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_HEADER_TAGS=A-Header',
        ]);
        $this->assertSame(['a-header'], \ddtrace_config_http_headers());
    }

    public function testHttpHeadersCanSetMultiple()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_HEADER_TAGS=A-Header   ,Any-Name    ,    cOn7aining-!spe_cial?:ch/ars    ',
        ]);
        // Same behavior as python tracer:
        // https://github.com/DataDog/dd-trace-py/blob/f1298cb8100f146059f978b58c88641bd7424af8/ddtrace/http/headers.py
        $this->assertSame(['a-header', 'any-name', 'con7aining-!spe_cial?:ch/ars'], \ddtrace_config_http_headers());
    }
}
