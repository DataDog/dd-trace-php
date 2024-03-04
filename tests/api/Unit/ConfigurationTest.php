<?php

namespace DDTrace\Tests\Api\Unit;

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
        Configuration::clear();
        $this->cleanUpEnvs();
    }

    protected function ddTearDown()
    {
        $this->cleanUpEnvs();
        Configuration::clear();
        parent::ddTearDown();
    }

    private function cleanUpEnvs()
    {
        self::putenv('DD_DISTRIBUTED_TRACING');
        self::putenv('DD_ENV');
        self::putenv('DD_SAMPLING_RATE');
        self::putenv('DD_SERVICE_MAPPING');
        self::putenv('DD_SERVICE');
        self::putenv('DD_TAGS');
        self::putenv('DD_TRACE_ANALYTICS_ENABLED');
        self::putenv('DD_TRACE_DEBUG');
        self::putenv('DD_TRACE_ENABLED');
        self::putenv('DD_TRACE_GLOBAL_TAGS');
        self::putenv('DD_TRACE_SAMPLE_RATE');
        self::putenv('DD_TRACE_SAMPLING_RULES');
        self::putenv('DD_TRACE_SLIM_ENABLED');
        self::putenv('DD_TRACE_PDO_ENABLED');
        self::putenv('DD_VERSION');
    }

    public function testTracerEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isEnabled());
    }

    public function testTracerDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_ENABLED=false']);
        $this->assertFalse(Configuration::get()->isEnabled());
    }

    public function testDebugModeDisabledByDefault()
    {
        $this->assertFalse(Configuration::get()->isDebugModeEnabled());
    }

    public function testDebugModeCanBeEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_DEBUG=true']);
        $this->assertTrue(Configuration::get()->isDebugModeEnabled());
    }

    public function testDistributedTracingEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isDistributedTracingEnabled());
    }

    public function testDistributedTracingDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_DISTRIBUTED_TRACING=false']);
        $this->assertFalse(Configuration::get()->isDistributedTracingEnabled());
    }

    public function testAllIntegrationsEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isIntegrationEnabled('pdo'));
    }

    public function testIntegrationsDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PDO_ENABLED=false', 'DD_TRACE_SLIM_ENABLED=false']);
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('pdo'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('slim'));
        $this->assertTrue(Configuration::get()->isIntegrationEnabled('mysqli'));
    }

    public function testIntegrationsDisabledIfGlobalDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PDO_ENABLED=false', 'DD_TRACE_ENABLED=false']);
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('pdo'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('mysqli'));
    }

    public function testAllIntegrationsEnabledToggleConfig()
    {
        $integrations = self::getIntegrationsUpper();
        foreach ($integrations as $integration) {
            $this->putEnvAndReloadConfig(["DD_TRACE_{$integration}_ENABLED=false"]);

            $lower = strtolower($integration);
            $error = "'{$lower}' was expected to be disabled." . self::INTEGRATION_ERROR;
            self::assertFalse(Configuration::get()->isIntegrationEnabled($lower), $error);

            // Reset
            self::putenv("DD_TRACE_{$integration}_ENABLED");
        }

        // Make sure we're not testing the default fallback
        self::assertTrue(Configuration::get()->isIntegrationEnabled('foo_invalid'));
    }

    private static function getIntegrationsUpper()
    {
        $dirs = glob(__DIR__ . '/../../src/DDTrace/Integrations/*', GLOB_ONLYDIR);
        return array_map(function ($entry) {
            return strtoupper(substr($entry, strrpos($entry, '/') + 1));
        }, $dirs);
    }

    public function testServiceName()
    {
        $this->putEnvAndReloadConfig(['DD_SERVICE']);

        $this->assertSame('__default__', Configuration::get()->appName('__default__'));

        $this->putEnvAndReloadConfig(['DD_SERVICE=my_app']);
        Configuration::clear();
        $this->assertSame('my_app', Configuration::get()->appName('__default__'));
    }

    public function testAnalyticsDisabledByDefault()
    {
        $this->assertFalse(Configuration::get()->isAnalyticsEnabled());
    }

    public function testAnalyticsCanBeGloballyEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_ANALYTICS_ENABLED=true']);
        $this->assertTrue(Configuration::get()->isAnalyticsEnabled());
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
     * @param float $expected
     */
    public function testTraceSampleRate($envs, $expected)
    {
        foreach ($envs as $env) {
            $this->putEnvAndReloadConfig([$env]);
        }

        $this->assertEquals($expected, Configuration::get()->getSamplingRate());
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
            // i.e. default sample rate: will be 1 then
            'DD_TRACE_SAMPLE_RATE has a minimum of 0.0' => [
                [
                    'DD_TRACE_SAMPLE_RATE=-0.1',
                ],
                1,
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
    }

    public function testEnvNotSet()
    {
        $this->putEnvAndReloadConfig(['DD_ENV']);
        $this->assertEmpty(Configuration::get()->getEnv());
    }

    public function testVersion()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION=1.2.3']);
        $this->assertSame('1.2.3', Configuration::get()->getServiceVersion());
    }

    public function testVersionNotSet()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION']);
        $this->assertEmpty(Configuration::get()->getServiceVersion());
    }

    public function testUriAsResourceNameEnabledDefault()
    {
        $this->assertTrue(Configuration::get()->isURLAsResourceNameEnabled());
    }

    public function testUriAsResourceNameCanBeDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=false']);
        $this->assertFalse(Configuration::get()->isURLAsResourceNameEnabled());
    }

    public function testGlobalTags()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=key1:value1,key2:value2']);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], Configuration::get()->getGlobalTags());
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \dd_trace_env_config("DD_TAGS"));
    }

    public function testGlobalTagsLegacyEnv()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_GLOBAL_TAGS=key1:value1,key2:value2']);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], Configuration::get()->getGlobalTags());
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \dd_trace_env_config("DD_TAGS"));
    }

    public function testGlobalTagsNewEnvWinsOverLegacyEnv()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_GLOBAL_TAGS=key10:value10,key20:value20',
            'DD_TAGS=key1:value1,key2:value2',
        ]);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], Configuration::get()->getGlobalTags());
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \dd_trace_env_config("DD_TAGS"));
    }

    public function testGlobalTagsWrongValueJustResultsInNoTags()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=wrong_key_value']);
        $this->assertEquals([], Configuration::get()->getGlobalTags());
    }
}
