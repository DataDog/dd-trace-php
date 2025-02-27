<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$output = runCLI('-v', true, ['DD_TRACE_DEBUG=1']);
assertContains($output, 'Found extension file');
assertContains($output, 'Extension \'ddtrace\' is not loaded');

preg_match('/Found extension file: ([^\n]*)/', $output, $matches);
$ext = $matches[1];
$tmp = tempnam(sys_get_temp_dir(), 'test_loader_');
copy($ext, $tmp);

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

$output = runCLI('-dextension='.$tmp.' -v', true, [
    'DD_TRACE_DEBUG=1',
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
]);
assertContains($output, 'Found extension file');
assertContains($output, 'Extension \'ddtrace\' is already loaded, unregister the injected extension');
assertContains($output, 'with ddtrace v');
assertContains($output, 'with dd_library_loader v');

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
            "name": "library_entrypoint.abort",
            "tags": [
                "reason:already_loaded",
                "product:ddtrace"
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
            "name": "library_entrypoint.error",
            "tags": [
                "error_type:so_not_found",
                "product:datadog-profiling"
            ]
        }
    ]
}
EOS;
}

assertTelemetry($telemetryLogPath, $metrics);
