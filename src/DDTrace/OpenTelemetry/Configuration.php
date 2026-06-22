<?php
// This file hooks the OpenTelemetry Configuration class to track OTel metrics configuration access for telemetry

// Whitelist of OTel configurations we want to track for telemetry
const OTEL_CONFIG_WHITELIST = [
    // OpenTelemetry Metrics SDK Configurations
    'OTEL_RESOURCE_ATTRIBUTES',
    'OTEL_METRICS_EXPORTER',
    'OTEL_METRIC_EXPORT_INTERVAL',
    'OTEL_METRIC_EXPORT_TIMEOUT',

    // OpenTelemetry Logs SDK Configurations
    'OTEL_LOGS_EXPORTER',
    'OTEL_BLRP_SCHEDULE_DELAY',
    'OTEL_BLRP_MAX_QUEUE_SIZE',
    'OTEL_BLRP_MAX_EXPORT_BATCH_SIZE',
    'OTEL_BLRP_EXPORT_TIMEOUT',

    // OTLP Exporter Configurations
    'OTEL_EXPORTER_OTLP_METRICS_PROTOCOL',
    'OTEL_EXPORTER_OTLP_LOGS_PROTOCOL',
    'OTEL_EXPORTER_OTLP_PROTOCOL',
    'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT',
    'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT',
    'OTEL_EXPORTER_OTLP_ENDPOINT',
    // The OTLP header configurations (OTEL_EXPORTER_OTLP_HEADERS,
    // OTEL_EXPORTER_OTLP_METRICS_HEADERS, OTEL_EXPORTER_OTLP_LOGS_HEADERS) are
    // sensitive and intentionally not tracked for configuration telemetry.
    'OTEL_EXPORTER_OTLP_METRICS_TIMEOUT',
    'OTEL_EXPORTER_OTLP_LOGS_TIMEOUT',
    'OTEL_EXPORTER_OTLP_TIMEOUT',
    'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE',
];

// Helper function to track config access
function track_otel_config_if_whitelisted(string $name, $value): void
{
    if (in_array($name, OTEL_CONFIG_WHITELIST, true)) {
        // Convert value to string for telemetry
        if (is_bool($value)) {
            $value_str = $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            $value_str = '';
        } elseif (is_array($value)) {
            $value_str = json_encode($value);
        } elseif (is_object($value)) {
            $value_str = get_class($value);
        } else {
            $value_str = (string)$value;
        }

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
