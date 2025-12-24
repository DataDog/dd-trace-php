<?php
// This file does not actually replace the CompositeResolver, but it's guaranteed to be autoloaded before the actual CompositeResolver.
// We just hook the CompositeResolver to track it.

namespace DDTrace\OpenTelemetry;

use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\API\Signals;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Resolver\ResolverInterface;

class DatadogResolver implements ResolverInterface
{
    private const DEFAULT_PROTOCOL = 'http/protobuf';
    private const GRPC_PORT = '4317';
    private const HTTP_PORT = '4318';
    private const DEFAULT_HOST = 'localhost';
    private const DEFAULT_SCHEME = 'http';

    public function retrieveValue(string $name): mixed
    {
        if (!$this->isMetricsEnabled($name)) {
            return null;
        }

        if ($name === 'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE') {
            return 'delta';
        }

        if ($name === 'OTEL_EXPORTER_OTLP_ENDPOINT' || $name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') {
            return $this->resolveEndpoint($name);
        }

        return null;
    }

    public function hasVariable(string $variableName): bool
    {
        if ($variableName === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT' ||
            $variableName === 'OTEL_EXPORTER_OTLP_ENDPOINT' ||
            $variableName === 'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE') {
            return \dd_trace_env_config('DD_METRICS_OTEL_ENABLED');
        }
        return false;
    }

    private function isMetricsEnabled(string $name): bool
    {
        $metricsOnlySettings = [
            'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE',
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT',
        ];

        if (in_array($name, $metricsOnlySettings, true)) {
            return \dd_trace_env_config('DD_METRICS_OTEL_ENABLED');
        }

        return true;
    }

    private function resolveEndpoint(string $name): string
    {
        $isMetricsEndpoint = ($name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT');
        $protocol = $this->resolveProtocol($isMetricsEndpoint);

        // Check for user-configured general OTLP endpoint (only when requesting metrics endpoint)
        if ($isMetricsEndpoint && Configuration::has('OTEL_EXPORTER_OTLP_ENDPOINT')) {
            return $this->buildMetricsEndpointFromGeneral($protocol);
        }

        return $this->buildEndpointFromAgent($protocol, $isMetricsEndpoint);
    }

    private function resolveProtocol(bool $metricsSpecific): ?string
    {
        if ($metricsSpecific && Configuration::has('OTEL_EXPORTER_OTLP_METRICS_PROTOCOL')) {
            return Configuration::getEnum('OTEL_EXPORTER_OTLP_METRICS_PROTOCOL');
        }

        // Call getEnum without has() check to match original behavior -
        // allows SDK defaults to be applied if they exist
        $protocol = Configuration::getEnum('OTEL_EXPORTER_OTLP_PROTOCOL');

        return $protocol ?? self::DEFAULT_PROTOCOL;
    }

    private function buildMetricsEndpointFromGeneral(string $protocol): string
    {
        $generalEndpoint = rtrim(Configuration::getString('OTEL_EXPORTER_OTLP_ENDPOINT'), '/');

        if ($this->isGrpc($protocol)) {
            return $generalEndpoint . OtlpUtil::method(Signals::METRICS);
        }

        return $generalEndpoint . '/v1/metrics';
    }

    private function buildEndpointFromAgent(string $protocol, bool $isMetricsEndpoint): string
    {
        $agentInfo = $this->resolveAgentInfo();

        // Unix sockets: pass through the full URL
        if ($agentInfo['scheme'] === 'unix') {
            return $agentInfo['url'];
        }

        $port = $this->isGrpc($protocol) ? self::GRPC_PORT : self::HTTP_PORT;
        $endpoint = $agentInfo['scheme'] . '://' . $agentInfo['host'] . ':' . $port;

        if ($isMetricsEndpoint) {
            return $this->appendMetricsPath($endpoint, $protocol);
        }

        return $endpoint;
    }

    /**
     * Resolves agent connection info from DD_TRACE_AGENT_URL or DD_AGENT_HOST.
     *
     * @return array{scheme: string, host: string, url?: string}
     */
    private function resolveAgentInfo(): array
    {
        $scheme = self::DEFAULT_SCHEME;
        $host = null;

        $agentUrl = \dd_trace_env_config('DD_TRACE_AGENT_URL');

        if ($agentUrl !== '') {
            $component = \parse_url($agentUrl);
            if ($component !== false) {
                $scheme = $component['scheme'] ?? self::DEFAULT_SCHEME;

                // Handle unix scheme - return full URL for pass-through
                if ($scheme === 'unix') {
                    return ['scheme' => 'unix', 'host' => '', 'url' => $agentUrl];
                }

                $host = $component['host'] ?? null;
            }
        }

        // Fall back to DD_AGENT_HOST if no host was found
        if ($host === null) {
            $ddAgentHost = \dd_trace_env_config('DD_AGENT_HOST');
            if ($ddAgentHost !== '') {
                $host = $ddAgentHost;
            }
        }

        // Default to localhost if host is still empty
        if ($host === null || $host === '') {
            $host = self::DEFAULT_HOST;
        }

        return ['scheme' => $scheme, 'host' => $host];
    }

    private function appendMetricsPath(string $endpoint, string $protocol): string
    {
        if ($this->isGrpc($protocol)) {
            return $endpoint . OtlpUtil::method(Signals::METRICS);
        }

        return $endpoint . '/v1/metrics';
    }

    private function isGrpc(string $protocol): bool
    {
        return strtolower($protocol) === 'grpc';
    }
}

\DDTrace\install_hook(
    'OpenTelemetry\SDK\Common\Configuration\Resolver\CompositeResolver::__construct',
    null,
    function (\DDTrace\HookData $hook) {
        $this->addResolver(new DatadogResolver());
    }
);