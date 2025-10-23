<?php
// This file does not actually replace the EnvSourceReader, but it's guaranteed to be autoloaded before the actual EnvSourceReader.
// We just hook the EnvSourceReader to track it.

\DDTrace\install_hook(
    'OpenTelemetry\Config\SDK\Configuration\Environment\EnvSourceReader::__construct',
    function (\DDTrace\HookData $hook) {
        $args = \is_object($hook->args) ? \iterator_to_array($hook->args) : $hook->args;

        $args[] = new class () implements \OpenTelemetry\Config\SDK\Configuration\Environment\EnvSource {
            public function readRaw(string $name): mixed
            {
                if ($name === 'OTEL_EXPORTER_OTLP_ENDPOINT' || $name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') {
                    // Determine protocol
                    $protocol = null;

                    if ($name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') {
                        // Get metrics-specific protocol
                        $protocol = \OpenTelemetry\SDK\Common\Configuration\Configuration::getString('OTEL_EXPORTER_OTLP_METRICS_PROTOCOL');
                    }

                    if ($protocol === null) {
                        // Get general OTLP protocol
                        $protocol = \OpenTelemetry\SDK\Common\Configuration\Configuration::getString('OTEL_EXPORTER_OTLP_PROTOCOL');
                    }

                    if ($protocol === null) {
                        // Use language default
                        $protocol = 'http'; // is this just http? or http/protobuf
                    }

                    // Determine endpoint

                    // Check for metrics-specific endpoint (use exactly as-is)
                    if ($name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') {
                        $metricsEndpoint = \OpenTelemetry\SDK\Common\Configuration\Configuration::getString('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT');
                        if ($metricsEndpoint !== null) {
                            return $metricsEndpoint;
                        }
                    }

                    // Check for general OTLP endpoint
                    $generalEndpoint = \OpenTelemetry\SDK\Common\Configuration\Configuration::getString('OTEL_EXPORTER_OTLP_ENDPOINT');
                    if ($generalEndpoint !== null) {
                        // May need to add subpath for metrics endpoint with HTTP protocol
                        if ($name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT' && $protocol !== 'grpc') {
                            return rtrim($generalEndpoint, '/') . '/v1/metrics';
                        }
                        return $generalEndpoint;
                    }

                    // Get agent host from DD_AGENT_HOST or DD_TRACE_AGENT_URL
                    $host = \dd_trace_env_config('DD_AGENT_HOST');

                    if ($host === null || $host === '') {
                        // Try to extract from DD_TRACE_AGENT_URL
                        $agentUrl = \dd_trace_env_config('DD_TRACE_AGENT_URL');
                        if ($agentUrl !== null && $agentUrl !== '') {
                            $component = \parse_url($agentUrl);
                            if ($component !== false) {
                                $host = $component['host'] ?? null;
                            }
                        }
                    }

                    // Build endpoint: http://{host}:{port}
                    if ($host === null || $host === '') {
                        $host = 'localhost';
                    }

                    // Determine port based on protocol
                    $port = ($protocol === 'grpc') ? '4317' : '4318';

                    $endpoint = 'http://' . $host . ':' . $port;

                    // Add subpath for metrics endpoint with HTTP protocol
                    if ($name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT' && $protocol !== 'grpc') {
                        $endpoint .= '/v1/metrics';
                    }

                    return $endpoint;
                }

                // Explicitly return null to match the original implicit behavior.
                return null;
            }
        };

        $hook->overrideArguments($args);
    }
);