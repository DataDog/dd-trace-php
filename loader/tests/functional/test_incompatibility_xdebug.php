<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

if (!getenv("XDEBUG_SO_NAME")) {
    echo "Skip: test requires XDEBUG_SO_NAME env var (i.e. XDEBUG_SO_NAME=xdebug-3.3.0.so)\n";
    exit(0);
}

$msg_disabled = "Potentially incompatible extension detected: Xdebug. ddtrace will be disabled unless the environment DD_INJECT_FORCE is set to '1', 'true', 'yes' or 'on'";
$msg_forced = "Potentially incompatible extension detected: Xdebug. Ignoring as DD_INJECT_FORCE is enabled";

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

$output = runCLI('-dzend_extension='.getenv("XDEBUG_SO_NAME").' -v', true, [
    'DD_TRACE_DEBUG=1',
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
]);
assertContains($output, 'Found extension file');
assertContains($output, $msg_disabled);
assertNotContains($output, $msg_forced);
assertContains($output, 'with dd_library_loader v');
assertContains($output, 'with Xdebug v');
assertContains($output, 'with ddtrace v');

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
        "pid": %d,
        "result": "abort",
        "result_reason": "The PHP tracer was disabled because potentially incompatible extension 'Xdebug' is loaded. Set DD_INJECT_FORCE to force tracing.",
        "result_class": "incompatible_runtime"
    },
    "points": [
        {
            "name": "library_entrypoint.abort",
            "tags": [
                "reason:incompatible_runtime",
                "product:ddtrace"
            ]
        },
        {
            "name": "library_entrypoint.abort.runtime"
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
        "pid": %d,
        "result": "abort",
        "result_reason": "%s",
        "result_class": "incompatible_runtime"
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
EOS;
}

assertTelemetry($telemetryLogPath, $metrics);

$output = runCLI('-dzend_extension='.getenv("XDEBUG_SO_NAME").' -v', true, ['DD_TRACE_DEBUG=1', 'DD_INJECT_FORCE=1']);
assertContains($output, 'Found extension file');
assertNotContains($output, $msg_disabled);
assertContains($output, $msg_forced);
assertContains($output, 'with dd_library_loader v');
assertContains($output, 'with Xdebug v');
assertContains($output, 'with ddtrace v');
