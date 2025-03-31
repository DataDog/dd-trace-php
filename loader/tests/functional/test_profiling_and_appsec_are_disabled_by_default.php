<?php

require_once __DIR__."/includes/autoload.php";
skip_if_not_at_least_php71();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

$output = runCLI('-r "echo ini_get(\"datadog.profiling.enabled\");"', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
]);
assertContains($output, '0');

$output = runCLI('-r "echo ini_get(\"datadog.appsec.enabled\");"', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
]);
assertContains($output, 'false');
