<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

$output = runCLI('-v', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
    'DD_LOADER_PACKAGE_PATH=/foo/bar'
]);

// Let time to the fork to write the telemetry log
usleep(5000);

$metrics = [<<<EOS
{
    "metadata": {
        "runtime_name": "php",
        "runtime_version": "%d.%d.%d%S",
        "language_name": "php",
        "language_version": "%d.%d.%d%S",
        "tracer_version": "%s",
        "pid": %d
    },
    "points": [
        {
            "name": "library_entrypoint.error",
            "tags": [
                "error_type:so_not_found",
                "product:ddtrace"
            ]
        }
    ]
}
EOS
,
<<<EOS
{
    "metadata": {
        "runtime_name": "php",
        "runtime_version": "%d.%d.%d%S",
        "language_name": "php",
        "language_version": "%d.%d.%d%S",
        "tracer_version": "%s",
        "pid": %d
    },
    "points": [
        {
            "name": "library_entrypoint.error",
            "tags": [
                "error_type:so_not_found",
                "product:ddappsec"
            ]
        }
    ]
}
EOS
];

if ('7.0' === php_minor_version()) {
    $metrics[] = <<<EOS
{
    "metadata": {
        "runtime_name": "php",
        "runtime_version": "%d.%d.%d%S",
        "language_name": "php",
        "language_version": "%d.%d.%d%S",
        "tracer_version": "%s",
        "pid": %d
    },
    "points": [
        {
            "name": "library_entrypoint.abort",
            "tags": [
                "reason:incompatible_runtime",
                "product:datadog-profiling"
            ]
        },
        {
            "name": "library_entrypoint.abort.runtime"
        }
    ]
}
EOS
;
} else {
    $metrics[] = <<<EOS
{
    "metadata": {
        "runtime_name": "php",
        "runtime_version": "%d.%d.%d%S",
        "language_name": "php",
        "language_version": "%d.%d.%d%S",
        "tracer_version": "%s",
        "pid": %d
    },
    "points": [
        {
            "name": "library_entrypoint.error",
            "tags": [
                "error_type:so_not_found",
                "product:datadog-profiling"
            ]
        }
    ]
}
EOS
;
}

assertTelemetry($telemetryLogPath, $metrics);
