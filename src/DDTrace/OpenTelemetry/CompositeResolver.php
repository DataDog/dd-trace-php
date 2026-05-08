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
        if (!$this->isSignalEnabled($name)) {
            return null;
        }

        if ($name === 'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE') {
            return 'delta';
        }

        if ($name === 'OTEL_EXPORTER_OTLP_ENDPOINT'
            || $name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'
            || $name === 'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT') {
            return $this->resolveEndpoint($name);
        }

        return null;
    }

    public function hasVariable(string $variableName): bool
    {
        if ($variableName === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'
            || $variableName === 'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE') {
            return \dd_trace_env_config('DD_METRICS_OTEL_ENABLED');
        }

        if ($variableName === 'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT') {
            return \dd_trace_env_config('DD_LOGS_OTEL_ENABLED');
        }

        if ($variableName === 'OTEL_EXPORTER_OTLP_ENDPOINT') {
            return \dd_trace_env_config('DD_METRICS_OTEL_ENABLED')
                || \dd_trace_env_config('DD_LOGS_OTEL_ENABLED');
        }

        return false;
    }

    private function isSignalEnabled(string $name): bool
    {
        if (in_array($name, [
            'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE',
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT',
        ], true)) {
            return \dd_trace_env_config('DD_METRICS_OTEL_ENABLED');
        }

        if ($name === 'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT') {
            return \dd_trace_env_config('DD_LOGS_OTEL_ENABLED');
        }

        return true;
    }

    private function resolveEndpoint(string $name): string
    {
        $isMetricsEndpoint = ($name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT');
        $isLogsEndpoint = ($name === 'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT');
        $protocol = $this->resolveProtocol($isMetricsEndpoint, $isLogsEndpoint);

        // For signal-specific endpoints, check whether the user configured a general OTLP endpoint
        // and derive the signal path from it rather than the agent address.
        if ($isMetricsEndpoint && Configuration::has('OTEL_EXPORTER_OTLP_ENDPOINT')) {
            return $this->buildSignalEndpointFromGeneral($protocol, Signals::METRICS);
        }

        if ($isLogsEndpoint && Configuration::has('OTEL_EXPORTER_OTLP_ENDPOINT')) {
            return $this->buildSignalEndpointFromGeneral($protocol, Signals::LOGS);
        }

        return $this->buildEndpointFromAgent($protocol, $name);
    }

    private function resolveProtocol(bool $metricsSpecific, bool $logsSpecific): string
    {
        if ($metricsSpecific && Configuration::has('OTEL_EXPORTER_OTLP_METRICS_PROTOCOL')) {
            return $this->validateProtocol(Configuration::getEnum('OTEL_EXPORTER_OTLP_METRICS_PROTOCOL'));
        }

        if ($logsSpecific && Configuration::has('OTEL_EXPORTER_OTLP_LOGS_PROTOCOL')) {
            return $this->validateProtocol(Configuration::getEnum('OTEL_EXPORTER_OTLP_LOGS_PROTOCOL'));
        }

        // Call getEnum without has() check to match original behavior —
        // allows SDK defaults to be applied if they exist.
        $protocol = Configuration::getEnum('OTEL_EXPORTER_OTLP_PROTOCOL');

        return $this->validateProtocol($protocol ?? self::DEFAULT_PROTOCOL);
    }

    private function validateProtocol(string $protocol): string
    {
        static $valid = ['grpc', 'http/protobuf', 'http/json', 'http/ndjson'];
        if (!in_array($protocol, $valid, true)) {
            trigger_error(
                "OTEL_EXPORTER_OTLP_PROTOCOL '$protocol' is not recognized. "
                . "Valid values are: grpc, http/protobuf, http/json, http/ndjson. "
                . "Falling back to 'http/protobuf'.",
                E_USER_WARNING
            );
            return self::DEFAULT_PROTOCOL;
        }
        return $protocol;
    }

    private function buildSignalEndpointFromGeneral(string $protocol, string $signal): string
    {
        $generalEndpoint = rtrim(Configuration::getString('OTEL_EXPORTER_OTLP_ENDPOINT'), '/');

        if ($this->isGrpc($protocol)) {
            return $generalEndpoint . OtlpUtil::method($signal);
        }

        return $generalEndpoint . '/v1/' . $signal;
    }

    private function buildEndpointFromAgent(string $protocol, string $endpointName): string
    {
        $agentInfo = $this->resolveAgentInfo();

        // Unix sockets: pass through the full URL
        if ($agentInfo['scheme'] === 'unix') {
            return $agentInfo['url'];
        }

        $port = $this->isGrpc($protocol) ? self::GRPC_PORT : self::HTTP_PORT;
        $endpoint = $agentInfo['scheme'] . '://' . $agentInfo['host'] . ':' . $port;

        if ($endpointName === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') {
            return $this->appendSignalPath($endpoint, $protocol, Signals::METRICS);
        }

        if ($endpointName === 'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT') {
            return $this->appendSignalPath($endpoint, $protocol, Signals::LOGS);
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

    private function appendSignalPath(string $endpoint, string $protocol, string $signal): string
    {
        if ($this->isGrpc($protocol)) {
            return $endpoint . OtlpUtil::method($signal);
        }

        return $endpoint . '/v1/' . $signal;
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
