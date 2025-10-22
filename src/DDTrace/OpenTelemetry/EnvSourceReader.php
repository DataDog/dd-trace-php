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
                if ($name === 'OTEL_EXPORTER_OTLP_ENDPOINT') {
                    $url = \dd_trace_env_config('DD_TRACE_AGENT_URL');
                    $component = \parse_url($url);

                    $host = $component['host'] ?? null;
                    $scheme = $component['scheme'] ?? null;

                    $protocol = \OpenTelemetry\SDK\Common\Configuration\Configuration::getString('OTEL_EXPORTER_OTLP_PROTOCOL');

                    $port = null;
                    if ($protocol === 'grpc') {
                        $port = '4317';
                    }
                    if ($protocol === 'http') {
                        $port = '4318';
                    }

                    $new_url = $scheme . '://' . $host . ':' . $port;
                    return $new_url;
                }

                // Explicitly return null to match the original implicit behavior.
                return null;
            }
        };

        $hook->overrideArguments($args);
    }
);
