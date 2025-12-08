<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenTelemetry\Integration;

use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\TracerTestTrait;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;

/**
 * Tests for OpenTelemetry Metrics integration
 * @coversNothing
 */
final class MetricsTest extends BaseTestCase
{
    use TracerTestTrait;

    private static function getOtelVersion(): array
    {
        if (!class_exists('OpenTelemetry\API\Common\Version')) {
            return [0, 0, 0];
        }
        $version = \OpenTelemetry\API\Common\Version::VERSION;
        $parts = explode('.', $version);
        return [
            (int)($parts[0] ?? 0),
            (int)($parts[1] ?? 0),
            (int)($parts[2] ?? 0),
        ];
    }

    private static function isOtelVersionSupported(): bool
    {
        $version = self::getOtelVersion();
        // v1.0.0 is the first stable version that exposes metrics in the public API
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
        self::putEnv("DD_METRICS_OTEL_ENABLED=");
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=");
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

        // Check that basic OTel SDK classes exist
        $this->assertTrue(
            class_exists('OpenTelemetry\SDK\Trace\TracerProvider'),
            'OpenTelemetry SDK TracerProvider should be available'
        );

        $this->assertTrue(
            class_exists('OpenTelemetry\SDK\Resource\ResourceInfo'),
            'OpenTelemetry SDK ResourceInfo should be available'
        );
    }

    /**
     * Test that OTLP exporters are available when the contrib package is installed
     */
    public function testOtelMetricsExporterInstalled()
    {
        if (!self::isOtelVersionSupported()) {
            $this->markTestSkipped('OpenTelemetry version 1.0 or higher is required for these tests');
        }

        if (!self::hasExportersInstalled()) {
            $this->markTestSkipped('Tests only compatible with the opentelemetry exporters installed');
        }

        // Check if gRPC/protobuf exporter is available
        $this->assertTrue(
            class_exists('OpenTelemetry\Contrib\Otlp\MetricExporter'),
            'OTLPMetricExporter should be available'
        );

        // Check if OtlpUtil is available (used for endpoint configuration)
        $this->assertTrue(
            class_exists('OpenTelemetry\Contrib\Otlp\OtlpUtil'),
            'OtlpUtil should be available for endpoint configuration'
        );
    }

    /**
     * Test that the OpenTelemetry MeterProvider is accessible when DD_METRICS_OTEL_ENABLED is set
     */
    public function testOtelMetricsEnabled()
    {
        if (!self::isOtelVersionSupported()) {
            $this->markTestSkipped('OpenTelemetry version 1.0 or higher is required for these tests');
        }

        if (!self::hasExportersInstalled()) {
            $this->markTestSkipped('Tests only compatible with the opentelemetry exporters installed');
        }

        self::putEnvAndReloadConfig(['DD_METRICS_OTEL_ENABLED=true']);

        // Get the meter provider
        $meterProvider = \OpenTelemetry\API\Metrics\Globals::meterProvider();

        $this->assertNotNull(
            $meterProvider,
            'OpenTelemetry meter provider should be available when DD_METRICS_OTEL_ENABLED is set'
        );
    }

    /**
     * Test that the MeterProvider is a proxy/noop when DD_METRICS_OTEL_ENABLED is not set
     * @dataProvider disabledMetricsProvider
     */
    public function testOtelMetricsDisabledAndUnset(?string $envValue)
    {
        if (!self::isOtelVersionSupported()) {
            $this->markTestSkipped('OpenTelemetry version 1.0 or higher is required for these tests');
        }

        if (!self::hasExportersInstalled()) {
            $this->markTestSkipped('Tests only compatible with the opentelemetry exporters installed');
        }

        if ($envValue === null) {
            self::putEnv("DD_METRICS_OTEL_ENABLED=");
        } else {
            self::putEnvAndReloadConfig(["DD_METRICS_OTEL_ENABLED=$envValue"]);
        }

        // Get the meter provider - should be a proxy/noop when not enabled
        $meterProvider = \OpenTelemetry\API\Metrics\Globals::meterProvider();

        // When not explicitly configured, should be null or a proxy provider
        $providerClass = get_class($meterProvider);
        $isProxyOrNoop = (
            $meterProvider === null ||
            strpos($providerClass, 'Proxy') !== false ||
            strpos($providerClass, 'Noop') !== false
        );

        $this->assertTrue(
            $isProxyOrNoop,
            "OpenTelemetry metrics provider should not be auto-configured when DD_METRICS_OTEL_ENABLED is '$envValue'. Got: $providerClass"
        );
    }

    public static function disabledMetricsProvider(): array
    {
        return [
            'unset' => [null],
            'false' => ['false'],
        ];
    }

    /**
     * Test that DD_METRICS_OTEL_ENABLED configuration option is recognized
     */
    public function testDdMetricsOtelEnabledConfigExists()
    {
        // Test that the config can be read
        self::putEnvAndReloadConfig(['DD_METRICS_OTEL_ENABLED=true']);
        $this->assertTrue(
            \dd_trace_env_config('DD_METRICS_OTEL_ENABLED'),
            'DD_METRICS_OTEL_ENABLED should be true when set'
        );

        self::putEnvAndReloadConfig(['DD_METRICS_OTEL_ENABLED=false']);
        $this->assertFalse(
            \dd_trace_env_config('DD_METRICS_OTEL_ENABLED'),
            'DD_METRICS_OTEL_ENABLED should be false when set to false'
        );
    }
}

