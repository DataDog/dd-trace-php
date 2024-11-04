<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

$output = runCLI('-v', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
]);

// Let time to the fork to write the telemetry log
usleep(5000);

$raw = file_get_contents($telemetryLogPath);
$payload = json_decode($raw, true);

$format = <<<EOS
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
            "name": "library_entrypoint.complete",
            "tags": [
                "injection_forced:false"
            ]
        }
    ]
}
EOS;

assertMatchesFormat(json_encode($payload, JSON_PRETTY_PRINT), $format);
