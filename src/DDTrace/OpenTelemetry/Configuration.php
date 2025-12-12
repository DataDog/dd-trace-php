<?php
// This file hooks the OpenTelemetry Configuration class to track OTel metrics configuration access for telemetry

// Whitelist of OTel configurations we want to track for telemetry
const OTEL_CONFIG_WHITELIST = [
    // OpenTelemetry Metrics SDK Configurations
    'OTEL_RESOURCE_ATTRIBUTES',
    'OTEL_METRICS_EXPORTER',
    'OTEL_METRIC_EXPORT_INTERVAL',
    'OTEL_METRIC_EXPORT_TIMEOUT',

    // OTLP Exporter Configurations
    'OTEL_EXPORTER_OTLP_METRICS_PROTOCOL',
    'OTEL_EXPORTER_OTLP_PROTOCOL',
    'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT',
    'OTEL_EXPORTER_OTLP_ENDPOINT',
    'OTEL_EXPORTER_OTLP_METRICS_HEADERS',
    'OTEL_EXPORTER_OTLP_HEADERS',
    'OTEL_EXPORTER_OTLP_METRICS_TIMEOUT',
    'OTEL_EXPORTER_OTLP_TIMEOUT',
    'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE',
];

// Helper function to track config access
function track_otel_config_if_whitelisted(string $name, mixed $value): void
{
    if (in_array($name, OTEL_CONFIG_WHITELIST, true)) {
        // Convert value to string for telemetry
        $value_str = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => '',
            is_array($value) => json_encode($value),
            is_object($value) => get_class($value),
            default => (string)$value,
        };

        \dd_trace_internal_fn('track_otel_config', $name, $value_str);
    }
}

// Helper function to install config tracking hooks
function install_config_tracking_hook(string $methodName): void
{
    \DDTrace\install_hook(
        "OpenTelemetry\\SDK\\Common\\Configuration\\Configuration::$methodName",
        function (\DDTrace\HookData $hook) {
            $name = $hook->args[0] ?? null;
            if ($name && is_string($name)) {
                $hook->data = $name;
            }
        },
        function (\DDTrace\HookData $hook) {
            if (isset($hook->data) && $hook->returned !== null) {
                track_otel_config_if_whitelisted($hook->data, $hook->returned);
            }
        }
    );
}

// Install hooks for all Configuration getter methods
foreach (['getString', 'getInt', 'getBoolean', 'getMixed', 'getMap', 'getList', 'getEnum'] as $method) {
    install_config_tracking_hook($method);
}
