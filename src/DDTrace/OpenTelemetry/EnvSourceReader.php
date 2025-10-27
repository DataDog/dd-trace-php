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
                        $protocol = 'http/protobuf';
                    }

                    // Determine endpoint

                    // Check for general OTLP endpoint (only when requesting metrics endpoint)
                    if ($name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') {
                        $generalEndpoint = \OpenTelemetry\SDK\Common\Configuration\Configuration::getString('OTEL_EXPORTER_OTLP_ENDPOINT');
                        if ($generalEndpoint !== null) {
                            // May need to add subpath for metrics endpoint with HTTP protocol
                            if ($protocol !== 'grpc') {
                                return rtrim($generalEndpoint, '/') . '/v1/metrics';
                            }
                            return $generalEndpoint;
                        }
                    }

                    // Get agent host from DD_AGENT_HOST or DD_TRACE_AGENT_URL
                    $host = null;
                    $scheme = 'http';
                    $port = null;

                    // First check DD_TRACE_AGENT_URL for unix sockets or full URLs
                    $agentUrl = \dd_trace_env_config('DD_TRACE_AGENT_URL');
                    if ($agentUrl !== '') {
                        $component = \parse_url($agentUrl);
                        if ($component !== false) {
                            $scheme = $component['scheme'] ?? 'http';

                            // Handle unix scheme - return as-is
                            if ($scheme === 'unix') {
                                // Unix sockets: pass through the full URL
                                // The SDK must be configured with a URL in the format unix:///path/to/socket.sock
                                return $agentUrl;
                            }

                            $host = $component['host'] ?? null;
                            $port = $component['port'] ?? null;
                        }
                    }

                    // Fall back to DD_AGENT_HOST if no URL was set
                    if ($host === null) {
                        $ddAgentHost = \dd_trace_env_config('DD_AGENT_HOST');
                        if ($ddAgentHost !== '') {
                            $host = $ddAgentHost;
                        }
                    }

                    // Build endpoint: {scheme}://{host}:{port}
                    if ($host === '') {
                        $host = 'localhost';
                    }

                    // Determine port based on protocol if not already set
                    if ($port === null) {
                        $port = ($protocol === 'grpc') ? '4317' : '4318';
                    }

                    $endpoint = $scheme . '://' . $host . ':' . $port;

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