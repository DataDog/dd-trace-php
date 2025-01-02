<?php

require_once __DIR__."/includes/autoload.php";
skip_if_not_php5();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

$output = runCLI('-v', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
]);

// Let time to the fork to write the telemetry log
usleep(5000);

$format = <<<EOS
{
    "metadata": {
        "runtime_name": "php",
        "runtime_version": "5.%d",
        "language_name": "php",
        "language_version": "5.%d",
        "tracer_version": "%s",
        "pid": %d
    },
    "points": [
        {
            "name": "library_entrypoint.abort",
            "tags": [
                "reason:eol_runtime"
            ]
        },
        {
            "name": "library_entrypoint.abort.runtime"
        }
    ]
}
EOS;
assertTelemetry($telemetryLogPath, $format);
