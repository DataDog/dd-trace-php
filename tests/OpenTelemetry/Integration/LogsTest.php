<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenTelemetry\Integration;

use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\TracerTestTrait;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;

/**
 * Tests for OpenTelemetry Logs integration
 * @coversNothing
 */
final class LogsTest extends BaseTestCase
{
    use TracerTestTrait;

    private static function getOtelVersion(): array
    {
        // Try Composer\InstalledVersions (modern way, used by OTel SDK 1.x)
        if (class_exists('Composer\InstalledVersions')) {
            foreach (['open-telemetry/sdk', 'open-telemetry/api', 'open-telemetry/opentelemetry'] as $package) {
                if (\Composer\InstalledVersions::isInstalled($package)) {
                    $version = \Composer\InstalledVersions::getPrettyVersion($package);
                    if ($version !== null) {
                        // Strip pre-release (-beta1, -RC1) and build metadata (+build.123) per semver spec
                        $version = preg_replace('/[-+].*$/', '', $version);
                        $parts = explode('.', $version);
                        return [
                            (int)($parts[0] ?? 0),
                            (int)($parts[1] ?? 0),
                            (int)($parts[2] ?? 0),
                        ];
                    }
                }
            }
        }

        // Fallback: Check for SDK classes that only exist in 1.x
        if (class_exists('OpenTelemetry\SDK\Logs\LoggerProvider')) {
            return [1, 0, 0]; // Assume 1.x if SDK classes exist
        }

        return [0, 0, 0];
    }

    private static function isOtelVersionSupported(): bool
    {
        $version = self::getOtelVersion();
        // v1.0.0 is the first stable version that exposes logs in the public API
        return $version[0] >= 1;
    }

    private static function hasExportersInstalled(): bool
    {
        return class_exists('OpenTelemetry\Contrib\Otlp\OtlpUtil');
    }

    protected function ddSetUp(): void
    {
        \dd_trace_serialize_closed_spans();
        parent::ddSetUp();
    }

    public function ddTearDown(): void
    {
        if (class_exists(ContextStorage::class)) {
            Context::setStorage(new ContextStorage()); // Reset OpenTelemetry context
        }
        parent::ddTearDown();
        self::putEnv("DD_LOGS_OTEL_ENABLED=");
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=");
        self::putEnv("DD_AGENT_HOST=");
        \dd_trace_serialize_closed_spans();
    }

    /**
     * Test that the OpenTelemetry SDK classes exist when the SDK is installed
     */
    public function testOtelSdkClassesExist()
    {
        if (!self::isOtelVersionSupported()) {
            $this->markTestSkipped('OpenTelemetry version 1.0 or higher is required for these tests');
        }

        $this->assertTrue(
            class_exists('OpenTelemetry\SDK\Logs\LoggerProvider'),
            'OpenTelemetry SDK LoggerProvider should be available'
        );

        $this->assertTrue(
            class_exists('OpenTelemetry\SDK\Resource\ResourceInfo'),
            'OpenTelemetry SDK ResourceInfo should be available'
        );
    }

    /**
     * Test that the OTLP logs exporter is available when the contrib package is installed
     */
    public function testOtelLogsExporterInstalled()
    {
        if (!self::isOtelVersionSupported()) {
            $this->markTestSkipped('OpenTelemetry version 1.0 or higher is required for these tests');
        }

        if (!self::hasExportersInstalled()) {
            $this->markTestSkipped('Tests only compatible with the opentelemetry exporters installed');
        }

        $this->assertTrue(
            class_exists('OpenTelemetry\Contrib\Otlp\LogsExporter'),
            'OTLP LogsExporter should be available'
        );

        $this->assertTrue(
            class_exists('OpenTelemetry\Contrib\Otlp\OtlpUtil'),
            'OtlpUtil should be available for endpoint configuration'
        );
    }

    /**
     * Test that the OpenTelemetry LoggerProvider is accessible when DD_LOGS_OTEL_ENABLED is set
     */
    public function testOtelLogsEnabled()
    {
        if (!self::isOtelVersionSupported()) {
            $this->markTestSkipped('OpenTelemetry version 1.0 or higher is required for these tests');
        }

        if (!self::hasExportersInstalled()) {
            $this->markTestSkipped('Tests only compatible with the opentelemetry exporters installed');
        }

        self::putEnvAndReloadConfig(['DD_LOGS_OTEL_ENABLED=true']);

        $loggerProvider = \OpenTelemetry\API\Globals::loggerProvider();

        $this->assertNotNull(
            $loggerProvider,
            'OpenTelemetry logger provider should be available when DD_LOGS_OTEL_ENABLED is set'
        );
    }

    /**
     * Test that the LoggerProvider is a proxy/noop when DD_LOGS_OTEL_ENABLED is not set
     * @dataProvider disabledLogsProvider
     */
    public function testOtelLogsDisabledAndUnset(?string $envValue)
    {
        if (!self::isOtelVersionSupported()) {
            $this->markTestSkipped('OpenTelemetry version 1.0 or higher is required for these tests');
        }

        if (!self::hasExportersInstalled()) {
            $this->markTestSkipped('Tests only compatible with the opentelemetry exporters installed');
        }

        if ($envValue === null) {
            self::putEnv("DD_LOGS_OTEL_ENABLED=");
        } else {
            self::putEnvAndReloadConfig(["DD_LOGS_OTEL_ENABLED=$envValue"]);
        }

        // Get the logger provider — should be a proxy/noop when not enabled
        $loggerProvider = \OpenTelemetry\API\Globals::loggerProvider();

        $providerClass = get_class($loggerProvider);
        $isProxyOrNoop = (
            $loggerProvider === null ||
            strpos($providerClass, 'Proxy') !== false ||
            strpos($providerClass, 'Noop') !== false
        );

        $this->assertTrue(
            $isProxyOrNoop,
            "OpenTelemetry logs provider should not be auto-configured when DD_LOGS_OTEL_ENABLED is '$envValue'. Got: $providerClass"
        );
    }

    public static function disabledLogsProvider(): array
    {
        return [
            'unset' => [null],
            'false' => ['false'],
        ];
    }

    /**
     * Test that DD_LOGS_OTEL_ENABLED configuration option is recognized
     */
    public function testDdLogsOtelEnabledConfigExists()
    {
        self::putEnvAndReloadConfig(['DD_LOGS_OTEL_ENABLED=true']);
        $this->assertTrue(
            \dd_trace_env_config('DD_LOGS_OTEL_ENABLED'),
            'DD_LOGS_OTEL_ENABLED should be true when set'
        );

        self::putEnvAndReloadConfig(['DD_LOGS_OTEL_ENABLED=false']);
        $this->assertFalse(
            \dd_trace_env_config('DD_LOGS_OTEL_ENABLED'),
            'DD_LOGS_OTEL_ENABLED should be false when set to false'
        );
    }

    /**
     * Test that DatadogResolver synthesizes the OTLP logs endpoint with the
     * correct scheme/port/path when DD_LOGS_OTEL_ENABLED=true and no explicit
     * OTEL_EXPORTER_OTLP_*ENDPOINT is set. This is the load-bearing wiring
     * that lets users opt into OTel logs with a single env var. The exact
     * host depends on the test environment's DD_AGENT_HOST, so we assert on
     * the shape (http scheme + port 4318 + /v1/logs path).
     */
    public function testDatadogResolverDerivesLogsEndpointFromAgent()
    {
        if (!self::isOtelVersionSupported() || !self::hasExportersInstalled()) {
            $this->markTestSkipped('OpenTelemetry SDK with OTLP exporters required');
        }

        self::putEnvAndReloadConfig(['DD_LOGS_OTEL_ENABLED=true']);

        // Touch an OpenTelemetry class so dd-trace-php's autoload populates the
        // OTel bridge — DatadogResolver lives there and isn't otherwise loaded.
        \OpenTelemetry\API\Globals::loggerProvider();

        $resolver = new \DDTrace\OpenTelemetry\DatadogResolver();

        $this->assertTrue(
            $resolver->hasVariable('OTEL_EXPORTER_OTLP_LOGS_ENDPOINT'),
            'DatadogResolver should claim OTEL_EXPORTER_OTLP_LOGS_ENDPOINT when DD_LOGS_OTEL_ENABLED=true'
        );

        $endpoint = $resolver->retrieveValue('OTEL_EXPORTER_OTLP_LOGS_ENDPOINT');
        $this->assertStringStartsWith(
            'http://',
            $endpoint,
            'OTLP logs endpoint should use http scheme when no protocol is configured'
        );
        $this->assertStringEndsWith(
            ':4318/v1/logs',
            $endpoint,
            'OTLP logs endpoint should target the agent HTTP port and /v1/logs path'
        );
    }
}
