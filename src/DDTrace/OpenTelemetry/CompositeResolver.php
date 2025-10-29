<?php
// This file does not actually replace the CompositeResolver, but it's guaranteed to be autoloaded before the actual CompositeResolver.
// We just hook the CompositeResolver to track it.

\DDTrace\install_hook(
    'OpenTelemetry\SDK\Common\Configuration\Resolver\CompositeResolver::__construct',
    null,
    function (\DDTrace\HookData $hook) {

        $this->addResolver(new class () implements \OpenTelemetry\SDK\Common\Configuration\Resolver\ResolverInterface {
            public function retrieveValue(string $name): mixed
            {
                if ($name === 'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE') {
                    return "delta";
                }
                if ($name === 'OTEL_EXPORTER_OTLP_ENDPOINT' || $name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') {
                    // Determine protocol
                    $protocol = null;

                    if ($name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') {
                        // Get metrics-specific protocol
                        $protocol = \OpenTelemetry\SDK\Common\Configuration\Configuration::getEnum('OTEL_EXPORTER_OTLP_METRICS_PROTOCOL');
                    }

                    if ($protocol === null) {
                        // Get general OTLP protocol
                        $protocol = \OpenTelemetry\SDK\Common\Configuration\Configuration::getEnum('OTEL_EXPORTER_OTLP_PROTOCOL');
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
                    $port = ($protocol === 'grpc') ? '4317' : '4318';
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

            public function hasVariable(string $variableName): bool {
                // For temporality preference, only claim to have it if it's not set in the environment
                // This allows environment variables set by tests to take precedence
                if ($variableName === 'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE') {
                    return getenv('OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE') === false;
                }

                return $variableName === 'OTEL_EXPORTER_OTLP_ENDPOINT' || $variableName === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT';
            }
        });
    }
);