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
        self::putenv('DD_SERVICE_MAPPING');
        self::putenv('DD_SERVICE');
        self::putenv('DD_TAGS');
        self::putenv('DD_TRACE_ANALYTICS_ENABLED');
        self::putenv('DD_TRACE_DEBUG');
        self::putenv('DD_TRACE_ENABLED');
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
        $this->assertFalse(\dd_trace_env_config("DD_TRACE_DEBUG"));
    }

    public function testDebugModeCanBeEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_DEBUG=true']);
        $this->assertTrue(\dd_trace_env_config("DD_TRACE_DEBUG"));
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

    public function testAllIntegrationsEnabledByDefault()
    {
        $this->assertTrue(\ddtrace_config_integration_enabled('pdo'));
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

    public function testServiceName()
    {
        ini_set("datadog.service", "");
        $this->assertSame('__default__', \ddtrace_config_app_name('__default__'));

        $this->putEnvAndReloadConfig(['DD_SERVICE=my_app']);
        $this->assertSame('my_app', \ddtrace_config_app_name('__default__'));
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

        $this->assertSame($expected, \dd_trace_env_config("DD_SERVICE_MAPPING"));
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
        $this->assertSame('my-env', \dd_trace_env_config("DD_ENV"));
    }

    public function testEnvNotSet()
    {
        ini_set("datadog.env", "");
        $this->assertEmpty(\dd_trace_env_config("DD_ENV"));
    }

    public function testVersion()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION=1.2.3']);
        $this->assertSame('1.2.3', \dd_trace_env_config("DD_VERSION"));
    }

    public function testVersionNotSet()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION']);
        $this->assertEmpty(\dd_trace_env_config("DD_VERSION"));
    }

    public function testUriAsResourceNameEnabledDefault()
    {
        $this->assertTrue(\dd_trace_env_config("DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED"));
    }

    public function testUriAsResourceNameCanBeDisabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=false']);
        $this->assertFalse(\dd_trace_env_config("DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED"));
    }

    public function testGlobalTagsCommaSeparated()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=key1:value1,key2:value2']);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \dd_trace_env_config("DD_TAGS"));
    }

    public function testGlobalTagsWhitespaceSeparated()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=key1:value1 key2:value2']);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \dd_trace_env_config("DD_TAGS"));
    }

    public function testGlobalTagsWhitespaceAndCommaSeparated()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=key1:value1, key2:value2']);
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], \dd_trace_env_config("DD_TAGS"));
    }

    public function testGlobalTagsNoDelimiter()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=only_key_no_value']);
        $this->assertEquals(["only_key_no_value" => ""], \dd_trace_env_config("DD_TAGS"));
    }

    public function testGlobalTagsDelimterPrecedence()
    {
        $this->putEnvAndReloadConfig(['DD_TAGS=env:test     bKey :bVal dKey: dVal cKey:']);
        $this->assertEquals(["env" => "test", "bKey"  => "", "dKey"  => "", "dVal"  => "", "cKey"  => ""], \dd_trace_env_config("DD_TAGS"));
    }

    public function testHttpHeadersDefaultsToEmpty()
    {
        $this->assertEmpty(\dd_trace_env_config("DD_TRACE_HEADER_TAGS"));
    }

    public function testHttpHeadersCanSetOne()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_HEADER_TAGS=A-Header',
        ]);
        $this->assertSame(['a-header'], array_keys(\dd_trace_env_config("DD_TRACE_HEADER_TAGS")));
    }

    public function testHttpHeadersCanSetMultiple()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_HEADER_TAGS=A-Header   ,Any-Name    ,    cOn7aining-!spe_cial?:ch/ars    , valueless:, Some-Header:with-colon-Key',
        ]);
        $this->assertSame(['a-header', 'any-name', 'con7aining-!spe_cial?', 'valueless', 'some-header'], array_keys(\dd_trace_env_config("DD_TRACE_HEADER_TAGS")));
        $this->assertEquals(['a-header' => '', 'any-name' => '', 'con7aining-!spe_cial?' => 'ch/ars', 'valueless' => '', 'some-header' => 'with-colon-Key'], \dd_trace_env_config("DD_TRACE_HEADER_TAGS"));
    }
}
