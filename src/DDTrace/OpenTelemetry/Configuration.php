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
            default => (string)$value,
        };

        \dd_trace_internal_fn('track_otel_config', $name, $value_str);
    }
}

// Hook Configuration::getString
\DDTrace\install_hook(
    'OpenTelemetry\SDK\Common\Configuration\Configuration::getString',
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

// Hook Configuration::getInt
\DDTrace\install_hook(
    'OpenTelemetry\SDK\Common\Configuration\Configuration::getInt',
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

// Hook Configuration::getBoolean
\DDTrace\install_hook(
    'OpenTelemetry\SDK\Common\Configuration\Configuration::getBoolean',
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

// Hook Configuration::getMixed
\DDTrace\install_hook(
    'OpenTelemetry\SDK\Common\Configuration\Configuration::getMixed',
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

// Hook Configuration::getMap
\DDTrace\install_hook(
    'OpenTelemetry\SDK\Common\Configuration\Configuration::getMap',
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

// Hook Configuration::getList
\DDTrace\install_hook(
    'OpenTelemetry\SDK\Common\Configuration\Configuration::getList',
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

// Hook Configuration::getEnum
\DDTrace\install_hook(
    'OpenTelemetry\SDK\Common\Configuration\Configuration::getEnum',
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

// Proactively read all OTel configurations from environment variables at startup
// This ensures configs are captured BEFORE telemetry is sent, even if MeterProvider
// is initialized later or not at all during the request
foreach (OTEL_CONFIG_WHITELIST as $config_name) {
    $value = getenv($config_name);
    if ($value !== false && $value !== '') {
        track_otel_config_if_whitelisted($config_name, $value);
    }
}

