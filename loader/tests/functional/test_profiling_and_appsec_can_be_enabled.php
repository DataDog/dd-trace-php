<?php

require_once __DIR__."/includes/autoload.php";
skip_if_not_at_least_php71();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

// Profiling enabled by env var
$output = runCLI('-r "echo ini_get(\"datadog.profiling.enabled\");"', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
    'DD_PROFILING_ENABLED=1',
]);
assertContains($output, '1');

// Profiling enabled by ini setting
$output = runCLI('-ddatadog.profiling.enabled=1 -r "echo ini_get(\"datadog.profiling.enabled\");"', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
]);
assertContains($output, '1');

// Profiling enabled by stable config
$output = runCLI('-r "echo ini_get(\"datadog.profiling.enabled\");"', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
    '_DD_TEST_LIBRARY_CONFIG_FLEET_FILE='.__DIR__.'/fixtures/stable_config/default.yaml',
    '_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE='.__DIR__.'/fixtures/stable_config/default.yaml',
]);
assertContains($output, '1');

// AppSec enabled by env var
$output = runCLI('-r "echo ini_get(\"datadog.appsec.enabled\");"', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
    'DD_APPSEC_ENABLED=1',
]);
assertContains($output, '1');

// AppSec enabled by ini setting
$output = runCLI('-ddatadog.appsec.enabled=1 -r "echo ini_get(\"datadog.appsec.enabled\");"', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
]);
assertContains($output, '1');

// AppSec enabled by stable config
$output = runCLI('-r "echo ini_get(\"datadog.appsec.enabled\");"', true, [
    'FAKE_FORWARDER_LOG_PATH='.$telemetryLogPath,
    'DD_TELEMETRY_FORWARDER_PATH='.__DIR__.'/../../bin/fake_forwarder.sh',
    '_DD_TEST_LIBRARY_CONFIG_FLEET_FILE='.__DIR__.'/fixtures/stable_config/default.yaml',
    '_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE='.__DIR__.'/fixtures/stable_config/default.yaml',
]);
assertContains($output, '1');
